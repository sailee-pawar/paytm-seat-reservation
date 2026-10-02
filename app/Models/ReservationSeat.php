<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationSeat extends Model
{
    protected $fillable = [
        'reservation_id',
        'show_seat_id',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function showSeat(): BelongsTo
    {
        return $this->belongsTo(ShowSeat::class);
    }
}