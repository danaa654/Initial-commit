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
        Schema::create('room_group_subject', function (Blueprint $table) {

            $table->id();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Room Group (Program)
            |--------------------------------------------------------------------------
            |
            | Replaces the old single required_room_group column. A subject can now
            | belong to any number of programs (e.g. Business Marketing -> BSHM +
            | BSTM), and a subject is considered applicable to a program if it has
            | any row here matching that program.
            |
            | This mirrors the value set already used by Room::room_group — General,
            | BSIT, BSED, BSHM, BSTM, BSCRIM. Criminalistics specializations
            | (FB/LD/QD/FI) still collapse to BSCRIM upstream of this table.
            |
            */

            $table->enum('room_group', [
                'General',
                'BSIT',
                'BSED',
                'BSHM',
                'BSTM',
                'BSCRIM',
            ]);

            $table->timestamps();

            // A subject can only be linked to a given program once.
            $table->unique(['subject_id', 'room_group']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_group_subject');
    }
};