<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Show extends Model
{
    protected $fillable = [
        'name',
        'price_paise',
        'per_user_limit',
    ];

    public function seats(): HasMany
    {
        return $this->hasMany(ShowSeat::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function userLimits(): HasMany
    {
        return $this->hasMany(ShowUserLimit::class);
    }
}