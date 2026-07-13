<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Room Identity
            |--------------------------------------------------------------------------
            |
            | room_code is the sole display identifier everywhere (index,
            | dropdowns, the future scheduler) — e.g. "Room 108",
            | "Room 304 (ICT Workshop)", "Room 201 (Forensic BSCRIM Lab)".
            | There is no separate room_name; a descriptive detail like
            | "ICT Workshop" just goes in the code itself.
            |
            */

            $table->string('room_code')->unique();

            $table->enum('room_type', [
                'Lecture',
                'Laboratory',
            ]);

            $table->string('building');

            $table->string('floor')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Capacity
            |--------------------------------------------------------------------------
            |
            | Defaults to 30. Business rule of "must be between 20 and 45"
            | is enforced in RoomController::rules(), not here — this
            | default is just the fallback for a column value, not the
            | valid range.
            |
            */

            $table->unsignedInteger('capacity')->default(30);

            $table->boolean('active')->default(true);

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};