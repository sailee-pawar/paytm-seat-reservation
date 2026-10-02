<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShowSeat extends Model
{
    protected $fillable = [
        'show_id',
        'seat_number',
        'status',
        'held_until',
    ];

    protected $casts = [
        'held_until' => 'datetime',
    ];

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    public function reservationSeats(): HasMany
    {
        return $this->hasMany(ReservationSeat::class);
    }
}