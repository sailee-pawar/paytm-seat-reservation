<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservationAttempt extends Model
{
    protected $fillable = [
        'show_id',
        'user_id',
        'reason',
        'request_id',
    ];
}