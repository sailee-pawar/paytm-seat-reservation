<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Show;
use App\Models\ShowSeat;
use App\Models\ShowUserLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use App\Models\ReservationAttempt;

class ReservationController extends Controller
{
    public function store(Request $request, Show $show): JsonResponse
    {
        $validated = $request->validate([
            'seats' => ['required', 'array', 'min:1', 'max:4'],
            'seats.*' => ['required', 'string', 'distinct'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $userId = $request->attributes->get('user_id');

        // Sort seats so concurrent requests lock them in the same order.
        $seatNumbers = collect($validated['seats'])
            ->unique()
            ->sort()
            ->values()
            ->all();

        $requestHash = hash(
            'sha256',
            json_encode([
                'seats' => $seatNumbers,
            ], JSON_THROW_ON_ERROR)
        );

        try {
            $reservation = DB::transaction(function () use (
                $show,
                $userId,
                $seatNumbers,
                $validated,
                $requestHash
            ) {

            
                /*
                * 1. Ensure the show/user synchronization row exists.
                */
                ShowUserLimit::insertOrIgnore([
                    'show_id' => $show->id,
                    'user_id' => $userId,
                    'reserved_seats' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                /*
                * 2. Lock the show/user row.
                *
                * This row acts as the synchronization point for:
                * - idempotency
                * - per-user reservation limit
                */
                $userLimit = ShowUserLimit::where('show_id', $show->id)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                * 3. Check idempotency.
                *
                * Because the user-limit row is locked first,
                * concurrent requests from the same user for the same
                * show cannot pass this check simultaneously.
                */
                $existingReservation = Reservation::where('show_id', $show->id)
                    ->where('user_id', $userId)
                    ->where('idempotency_key', $validated['idempotency_key'])
                    ->first();

                if ($existingReservation) {
                    if ($existingReservation->request_hash !== $requestHash) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => [
                                'This idempotency key was already used with a different request.',
                            ],
                        ]);
                    }

                    return $existingReservation->load('reservationSeats.showSeat');
                }

                /*
                * 4. Check per-user reservation limit.
                */
                $requestedSeatCount = count($seatNumbers);

                if (
                    $userLimit->reserved_seats + $requestedSeatCount
                    > $show->per_user_limit
                ) {
                    throw ValidationException::withMessages([
                        'seats' => [
                            'Per-user seat limit exceeded.',
                        ],
                    ]);
                }


                /*
                 * 3. Lock all requested seats in deterministic order.
                 */
                $seats = ShowSeat::where('show_id', $show->id)
                    ->whereIn('seat_number', $seatNumbers)
                    ->orderBy('seat_number')
                    ->lockForUpdate()
                    ->get();

                /*
                 * 4. Make sure every requested seat exists.
                 */
                if ($seats->count() !== count($seatNumbers)) {
                    throw ValidationException::withMessages([
                        'seats' => [
                            'One or more requested seats do not exist.',
                        ],
                    ]);
                }

                /*
                 * 5. All-or-nothing:
                 * every requested seat must be available.
                 */
                $unavailableSeats = $seats
                    ->where('status', '!=', 'available')
                    ->pluck('seat_number')
                    ->values()
                    ->all();

                if (!empty($unavailableSeats)) {
                    throw ValidationException::withMessages([
                        'seats' => [
                            'One or more requested seats are unavailable.',
                        ],
                    ]);
                }

                /*
                 * 6. Calculate amount in paise.
                 */
                $amountPaise = $show->price_paise * $requestedSeatCount;

                /*
                 * 7. Create reservation.
                 */
                $reservation = Reservation::create([
                    'show_id' => $show->id,
                    'user_id' => $userId,
                    'status' => 'confirmed',
                    'amount_paise' => $amountPaise,
                    'idempotency_key' => $validated['idempotency_key'],
                    'request_hash' => $requestHash,
                ]);

                /*
                 * 8. Mark seats confirmed and create relationships.
                 */
                foreach ($seats as $seat) {
                    $seat->update([
                        'status' => 'confirmed',
                    ]);

                    $reservation->reservationSeats()->create([
                        'show_seat_id' => $seat->id,
                    ]);
                }

                /*
                 * 9. Update per-user reservation count.
                 */
                $userLimit->update([
                    'reserved_seats' =>
                        $userLimit->reserved_seats + $requestedSeatCount,
                ]);

                return $reservation->load('reservationSeats.showSeat');
            });

            Log::info('reservation.confirmed', [
                'reservation_id' => $reservation->id,
                'show_id' => $show->id,
                'user_id' => $userId,
                'seats' => $seatNumbers,
                'amount_paise' => $reservation->amount_paise,
            ]);

            return response()->json([
                'id' => $reservation->id,
                'show_id' => $reservation->show_id,
                'user_id' => $reservation->user_id,
                'status' => $reservation->status,
                'amount_paise' => $reservation->amount_paise,
                'seats' => $reservation->reservationSeats
                    ->map(fn ($reservationSeat) => [
                        'seat_number' => $reservationSeat->showSeat->seat_number,
                    ])
                    ->values(),
            ], 201);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            $reason = 'unknown';

            if (isset($errors['idempotency_key'])) {
                $reason = 'idempotency_conflict';
            } elseif (isset($errors['seats'])) {
                $seatErrors = implode(' ', $errors['seats']);

                if (str_contains($seatErrors, 'Per-user seat limit exceeded')) {
                    $reason = 'per_user_limit';
                } elseif (str_contains($seatErrors, 'unavailable')) {
                    $reason = 'seat_taken';
                } elseif (str_contains($seatErrors, 'do not exist')) {
                    $reason = 'seat_not_found';
                }
            }

            ReservationAttempt::create([
                'show_id' => $show->id,
                'user_id' => $userId,
                'reason' => $reason,
                'request_id' => $request->attributes->get('request_id'),
            ]);

            Log::warning('reservation.declined', [
                'show_id' => $show->id,
                'user_id' => $userId,
                'seats' => $seatNumbers,
                'reason' => $reason,
                'errors' => $errors,
            ]);

            return response()->json([
                'message' => 'Reservation declined.',
                'errors' => $errors,
            ], 409);
        }
    }

    public function cancel(Request $request,Reservation $reservation): JsonResponse {
        $userId = $request->attributes->get('user_id');

    try {
        DB::transaction(function () use ($reservation, $userId) {

            /*
             * 1. Lock the reservation.
             */
            $reservation = Reservation::where('id', $reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 2. Only the owner can cancel the reservation.
             */
            if ($reservation->user_id !== $userId) {
                throw ValidationException::withMessages([
                    'reservation' => [
                        'You can only cancel your own reservation.',
                    ],
                ]);
            }

            /*
             * 3. If already cancelled, nothing else needs to be done.
             */
            if ($reservation->status === 'cancelled') {
                return;
            }

            /*
             * 4. Lock the user's limit row.
             */
            $userLimit = ShowUserLimit::where('show_id', $reservation->show_id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 5. Get the seats belonging to this reservation.
             */
            $reservationSeats = $reservation->reservationSeats()
                ->orderBy('show_seat_id')
                ->get();

            $seatIds = $reservationSeats
                ->pluck('show_seat_id')
                ->sort()
                ->values()
                ->all();

            /*
             * 6. Lock those seats in deterministic order.
             */
            $seats = ShowSeat::whereIn('id', $seatIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /*
             * 7. Release only seats currently confirmed
             *    for this reservation.
             */
            foreach ($seats as $seat) {
                if ($seat->status === 'confirmed') {
                    $seat->update([
                        'status' => 'available',
                    ]);
                }
            }

            /*
             * 8. Reduce the user's reserved-seat count.
             */
            $reservedSeatCount = $reservationSeats->count();

            $userLimit->update([
                'reserved_seats' => max(
                    0,
                    $userLimit->reserved_seats - $reservedSeatCount
                ),
            ]);

            /*
             * 9. Mark reservation as cancelled.
             */
            $reservation->update([
                'status' => 'cancelled',
            ]);
        });

        return response()->json([
            'message' => 'Reservation cancelled successfully.',
            'reservation_id' => $reservation->id,
            'status' => 'cancelled',
        ]);

    } catch (ValidationException $e) {
        return response()->json([
            'message' => 'Reservation cancellation declined.',
            'errors' => $e->errors(),
        ], 409);
    }
}
}