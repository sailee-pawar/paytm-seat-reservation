<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('show_seats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('show_id')
                ->constrained('shows')
                ->cascadeOnDelete();

            $table->string('seat_number');
            $table->string('status')->default('available');
            $table->timestamp('held_until')->nullable();

            $table->timestamps();

            $table->unique(['show_id', 'seat_number']);
            $table->index(['show_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('show_seats');
    }
};