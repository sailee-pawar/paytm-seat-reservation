<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\ShowSeat;
use Illuminate\Http\Response;
use App\Models\ReservationAttempt;

class MetricsController extends Controller
{
   public function index(): Response
{
    $confirmedReservations = Reservation::where('status', 'confirmed')->count();

    $availableSeats = ShowSeat::where('status', 'available')->count();
    $heldSeats = ShowSeat::where('status', 'held')->count();
    $confirmedSeats = ShowSeat::where('status', 'confirmed')->count();

    $declinedReasons = ReservationAttempt::selectRaw(
        'reason, COUNT(*) as total'
    )
        ->groupBy('reason')
        ->pluck('total', 'reason');

    $body = implode("\n", [
        '# HELP reservations_confirmed_total Total confirmed reservations.',
        '# TYPE reservations_confirmed_total counter',
        "reservations_confirmed_total {$confirmedReservations}",

        '# HELP reservations_declined_total Total declined reservation attempts by reason.',
        '# TYPE reservations_declined_total counter',

        'reservations_declined_total{reason="seat_taken"} '
            . ($declinedReasons['seat_taken'] ?? 0),

        'reservations_declined_total{reason="per_user_limit"} '
            . ($declinedReasons['per_user_limit'] ?? 0),

        'reservations_declined_total{reason="idempotency_conflict"} '
            . ($declinedReasons['idempotency_conflict'] ?? 0),

        'reservations_declined_total{reason="seat_not_found"} '
            . ($declinedReasons['seat_not_found'] ?? 0),

        'reservations_declined_total{reason="unknown"} '
            . ($declinedReasons['unknown'] ?? 0),

        '# HELP seats_available Current number of available seats.',
        '# TYPE seats_available gauge',
        "seats_available {$availableSeats}",

        '# HELP seats_held Current number of held seats.',
        '# TYPE seats_held gauge',
        "seats_held {$heldSeats}",

        '# HELP seats_confirmed Current number of confirmed seats.',
        '# TYPE seats_confirmed gauge',
        "seats_confirmed {$confirmedSeats}",

        '',
    ]);

    return response($body, 200)
        ->header('Content-Type', 'text/plain; version=0.0.4');
}
}