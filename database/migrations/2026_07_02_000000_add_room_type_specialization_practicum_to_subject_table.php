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
        Schema::table('subjects', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Required Room Type
            |--------------------------------------------------------------------------
            |
            | Coarse room classification used by the auto-scheduler to decide
            | whether a subject needs a lecture room, a laboratory, or no
            | room at all (Practicum/OJT). This is distinct from the more
            | granular `required_room` enum already on this table.
            |
            */

            $table->enum('required_room_type', [
                'Lecture',
                'Laboratory',
                'None',
            ])->default('Lecture')->after('required_room');

            /*
            |--------------------------------------------------------------------------
            | Required Specialization
            |--------------------------------------------------------------------------
            |
            | Maps a subject to the department/room specialization group the
            | scheduler should prefer when assigning a room.
            |
            */

            $table->enum('required_specialization', [
                'General',
                'IT',
                'HM',
                'TM',
                'ED',
                'FB',
                'LD',
                'QD',
                'FI',
            ])->default('General')->after('required_room_type');

            /*
            |--------------------------------------------------------------------------
            | Practicum / OJT Flag
            |--------------------------------------------------------------------------
            |
            | Subjects flagged as Practicum/OJT are excluded from the
            | automatic scheduler entirely.
            |
            */

            $table->boolean('is_practicum')->default(false)->after('required_specialization');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {

            $table->dropColumn([
                'required_room_type',
                'required_specialization',
                'is_practicum',
            ]);

        });
    }
};