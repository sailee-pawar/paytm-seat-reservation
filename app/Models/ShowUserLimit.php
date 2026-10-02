<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowUserLimit extends Model
{
    protected $fillable = [
        'show_id',
        'user_id',
        'reserved_seats',
    ];

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }
}