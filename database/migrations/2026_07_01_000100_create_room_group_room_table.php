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
        Schema::create('room_group_room', function (Blueprint $table) {

            $table->id();

            $table->foreignId('room_id')
                ->constrained('rooms')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Room Group (Program)
            |--------------------------------------------------------------------------
            |
            | Which program(s) a room is available to. A room can have zero
            | rows here (not expected in practice, but not enforced at the
            | DB level), a single "General" row (available to every
            | program), a single department row (Exclusive), or several
            | department rows (Shared — e.g. a laboratory used by both
            | BSHM and BSTM).
            |
            | Same pattern already used for Subject::required_room_group
            | via room_group_subject — reused here rather than inventing a
            | second way to model the same "one or more programs" idea.
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

            // A room can only be linked to a given program once.
            $table->unique(['room_id', 'room_group']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_group_room');
    }
};