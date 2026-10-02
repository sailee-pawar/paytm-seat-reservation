<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    protected $fillable = [
        'show_id',
        'user_id',
        'status',
        'amount_paise',
        'idempotency_key',
        'request_hash',
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