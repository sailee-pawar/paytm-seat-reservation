<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_seats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reservation_id')
                ->constrained('reservations')
                ->cascadeOnDelete();

            $table->foreignId('show_seat_id')
                ->constrained('show_seats')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'reservation_id',
                'show_seat_id'
            ]);

            $table->index('show_seat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_seats');
    }
};