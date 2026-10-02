<?php

namespace App\Http\Controllers;

use App\Models\Show;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShowController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'seats' => ['required', 'array', 'min:1'],
            'seats.*' => ['required', 'string', 'distinct'],
            'price_paise' => ['required', 'integer', 'min:0'],
        ]);

        $show = DB::transaction(function () use ($validated) {
            $show = Show::create([
                'name' => $validated['name'],
                'price_paise' => $validated['price_paise'],
                'per_user_limit' => 4,
            ]);

            foreach ($validated['seats'] as $seatNumber) {
                $show->seats()->create([
                    'seat_number' => $seatNumber,
                    'status' => 'available',
                ]);
            }

            return $show->load('seats');
        });

        return response()->json([
            'id' => $show->id,
            'name' => $show->name,
            'price_paise' => $show->price_paise,
            'per_user_limit' => $show->per_user_limit,
            'seats' => $show->seats->map(function ($seat) {
                return [
                    'id' => $seat->id,
                    'seat_number' => $seat->seat_number,
                    'status' => $seat->status,
                ];
            })->values(),
        ], 201);
    }

    public function show(Show $show): JsonResponse
    {
        $show->load('seats');

        $seatCounts = [
            'total' => $show->seats->count(),
            'available' => $show->seats->where('status', 'available')->count(),
            'held' => $show->seats->where('status', 'held')->count(),
            'confirmed' => $show->seats->where('status', 'confirmed')->count(),
        ];

        return response()->json([
            'id' => $show->id,
            'name' => $show->name,
            'price_paise' => $show->price_paise,
            'per_user_limit' => $show->per_user_limit,

            'seat_counts' => $seatCounts,

            'seats' => $show->seats
                ->sortBy('seat_number')
                ->values()
                ->map(function ($seat) {
                    return [
                        'id' => $seat->id,
                        'seat_number' => $seat->seat_number,
                        'status' => $seat->status,
                    ];
                }),
        ]);
    }
}