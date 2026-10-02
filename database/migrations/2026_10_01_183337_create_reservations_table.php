<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('show_id')
                ->constrained('shows')
                ->cascadeOnDelete();

            $table->string('user_id');

            $table->string('status')->default('confirmed');

            $table->unsignedBigInteger('amount_paise');

            $table->string('idempotency_key');
            $table->string('request_hash');

            $table->timestamps();

            // Same user cannot accidentally create two reservations
            // with the same idempotency key for the same show.
            $table->unique([
                'show_id',
                'user_id',
                'idempotency_key'
            ]);

            $table->index(['show_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};