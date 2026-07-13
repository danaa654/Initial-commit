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
            | Drop Legacy Required Room
            |--------------------------------------------------------------------------
            |
            | Superseded by `required_room_type` (Lecture / Laboratory / None),
            | which now matches PAP's actual room inventory. Keeping both
            | columns would leave two fields answering the same question.
            |
            */

            $table->dropColumn('required_room');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {

            $table->enum('required_room', [
                'Lecture',
                'Computer Laboratory',
                'Science Laboratory',
                'Speech Laboratory',
                'PE Area',
                'Any',
            ])->default('Lecture')->after('is_major');

        });
    }
};