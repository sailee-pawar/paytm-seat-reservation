<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('show_id')
                ->nullable()
                ->constrained('shows')
                ->nullOnDelete();

            $table->string('user_id')->nullable();
            $table->string('reason');
            $table->string('request_id')->nullable();

            $table->timestamps();

            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_attempts');
    }
};