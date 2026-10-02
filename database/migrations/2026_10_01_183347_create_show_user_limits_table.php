<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('show_user_limits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('show_id')
                ->constrained('shows')
                ->cascadeOnDelete();

            $table->string('user_id');

            $table->unsignedInteger('reserved_seats')->default(0);

            $table->timestamps();

            // One locking row per user per show.
            $table->unique([
                'show_id',
                'user_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('show_user_limits');
    }
};