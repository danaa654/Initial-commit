<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_offerings', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Academic Term / Curriculum
            |--------------------------------------------------------------------------
            |
            | Every offering is generated FROM one Curriculum, INTO one
            | Academic Term. Cascade on academic_term_id: an Academic Term
            | force-deleted takes its generated offerings with it.
            | restrictOnDelete on curriculum_id: a Curriculum that already
            | has generated offerings should never be silently deletable.
            */

            $table->foreignId('academic_term_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('curriculum_id')
                ->constrained()
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Curriculum Item / Program / Subject / Section
            |--------------------------------------------------------------------------
            |
            | curriculum_item_id, program_id, and subject_id are all
            | denormalized so the Index page's filters and the EDP Code
            | generator can query/group by them directly and cheaply,
            | without reaching through curriculum_id every time.
            |
            | curriculum_item_id is nullable and cascades — if the item is
            | ever removed from the Curriculum's prospectus, this link
            | (not the offering) goes with it. program_id/subject_id
            | restrictOnDelete for the same "never orphan history" reason
            | as curriculum_id.
            */

            $table->foreignId('curriculum_item_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('program_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('subject_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('section_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Placement
            |--------------------------------------------------------------------------
            */

            $table->unsignedTinyInteger('year_level');

            // 1 = First Semester, 2 = Second Semester, 3 = Summer
            $table->unsignedTinyInteger('semester');

            /*
            |--------------------------------------------------------------------------
            | Subject Snapshot (as of generation time)
            |--------------------------------------------------------------------------
            |
            | Units / Hours / Classification / Room Type are copied from
            | the Subject at the moment this offering is generated. This
            | is a deliberate snapshot, not a live lookup — if the Subject
            | master record is edited later (e.g. units changed for a
            | future curriculum revision), an already-generated offering
            | should keep reflecting what it was actually generated with.
            */

            $table->unsignedTinyInteger('units')->nullable();
            $table->unsignedSmallInteger('hours')->nullable();

            // 'Major' | 'Minor' — mirrors Subject::is_major at generation time.
            $table->string('classification', 10)->nullable();

            // Mirrors Subject::required_room_type at generation time.
            $table->string('room_type', 50)->nullable();

            /*
            |--------------------------------------------------------------------------
            | EDP Code
            |--------------------------------------------------------------------------
            |
            | System-generated, never entered manually. Format:
            |   PREFIX-YYSYNNN  (e.g. IT-2611001)
            | See EdpCodeService for the generation rules.
            */

            $table->string('edp_code', 20)->unique();

            /*
            |--------------------------------------------------------------------------
            | Status — deliberately NOT a column
            |--------------------------------------------------------------------------
            |
            | Overall Status is fully derived (see
            | SubjectOffering::getOverallStatusAttribute()) from real
            | data — the Academic Term's own status/class_end_date,
            | whether a Teaching Assignment exists, whether a Room is
            | assigned, and whether the future Scheduler has written a
            | schedule row. Storing it as an editable column would let
            | it drift out of sync with the very facts it's supposed to
            | summarize, so there is nothing to store here.
            |
            | NOTE: this table has NO faculty_id, room_id, day, or time
            | column, and never will. Those belong entirely to later
            | modules; every derived status is read from those modules'
            | own tables, never written by this one.
            */

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Generation
            |--------------------------------------------------------------------------
            |
            | Academic Term + Program + Year Level + Section + Subject may
            | only ever exist once. Generation is additive: re-running it
            | for a Curriculum only fills in whatever's missing (a newly
            | checked Section, a newly added Curriculum Item) — this index
            | is the DB-level backstop against that ever producing a
            | duplicate row regardless of how generate() is called.
            */

            $table->unique(
                ['academic_term_id', 'program_id', 'year_level', 'section_id', 'subject_id'],
                'subject_offerings_duplicate_unique'
            );

            /*
            |--------------------------------------------------------------------------
            | Lookup Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(['curriculum_id', 'year_level', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_offerings');
    }
};