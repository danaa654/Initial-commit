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
        /*
        |--------------------------------------------------------------------------
        | Replace curriculum_subjects with curriculum_items
        |--------------------------------------------------------------------------
        |
        | curriculum_subjects only ever pointed at a Subject. curriculum_items
        | generalizes that pivot into a polymorphic-by-column "item" that can be
        | a Subject today and other types (OJT, and anything future) later,
        | without ever touching the Subjects master list.
        |
        */

        Schema::dropIfExists('curriculum_subjects');

        Schema::create('curriculum_items', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Curriculum
            |--------------------------------------------------------------------------
            */

            $table->foreignId('curriculum_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Item Type
            |--------------------------------------------------------------------------
            |
            | Drives which of the fields below are required. Kept as a plain
            | enum column (rather than a separate item_types table) since the
            | list of types is small, fixed at the code level, and referenced
            | directly by the scheduler.
            |
            */

            $table->enum('item_type', ['Subject', 'OJT'])
                ->default('Subject');

            /*
            |--------------------------------------------------------------------------
            | Subject (item_type = Subject only)
            |--------------------------------------------------------------------------
            |
            | References the master subject list. Nullable because OJT items
            | (and any future non-subject item type) never populate this.
            |
            */

            $table->foreignId('subject_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | OJT Fields (item_type = OJT only)
            |--------------------------------------------------------------------------
            */

            $table->string('title')->nullable();

            $table->unsignedSmallInteger('ojt_hours')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Curriculum Placement
            |--------------------------------------------------------------------------
            |
            | Where the item sits within THIS curriculum. Shared by every item
            | type, which is why it lives here and not on Subject.
            |
            */

            $table->unsignedTinyInteger('year_level');

            // 1 = First Semester
            // 2 = Second Semester
            // 3 = Summer

            $table->unsignedTinyInteger('semester');

            /*
            |--------------------------------------------------------------------------
            | Display Order
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('sort_order')->default(0);

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('active')->default(true);

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Subject Assignment
            |--------------------------------------------------------------------------
            |
            | A given subject can only be attached to a given curriculum once.
            | OJT rows (and any future type) always have a null subject_id, and
            | both MySQL and PostgreSQL treat NULLs as distinct for the purpose
            | of a unique index, so this never blocks multiple OJT items.
            |
            */

            $table->unique(
                ['curriculum_id', 'subject_id'],
                'curriculum_items_curriculum_subject_unique'
            );

            /*
            |--------------------------------------------------------------------------
            | Lookup Index
            |--------------------------------------------------------------------------
            |
            | Every read path (Manage page, scheduler) filters/groups by these
            | three columns together.
            |
            */

            $table->index(['curriculum_id', 'year_level', 'semester']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curriculum_items');

        // Note: intentionally does not recreate curriculum_subjects — that
        // table's structure lived in a migration this one now replaces.
    }
};