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
        Schema::create('academic_terms', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Academic Year
            |--------------------------------------------------------------------------
            */

            // Example: 2026-2027

            $table->string('academic_year', 9);

            /*
            |--------------------------------------------------------------------------
            | Semester
            |--------------------------------------------------------------------------
            */

            // 1 = First Semester
            // 2 = Second Semester
            // 3 = Summer

            $table->unsignedTinyInteger('semester');

            /*
            |--------------------------------------------------------------------------
            | Class Period
            |--------------------------------------------------------------------------
            */

            $table->date('class_start_date');
            $table->date('class_end_date');

            /*
            |--------------------------------------------------------------------------
            | School Operating Hours
            |--------------------------------------------------------------------------
            */

            $table->time('school_start_time');
            $table->time('school_end_time');

            /*
            |--------------------------------------------------------------------------
            | Lunch Break
            |--------------------------------------------------------------------------
            */

            $table->time('lunch_start_time')->nullable();
            $table->time('lunch_end_time')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Scheduler Settings
            |--------------------------------------------------------------------------
            */

            // minutes
            // Example: 30

            $table->unsignedTinyInteger('time_interval')
                ->default(30);

            /*
            |--------------------------------------------------------------------------
            | Active School Days
            |--------------------------------------------------------------------------
            */

            $table->boolean('monday')->default(true);
            $table->boolean('tuesday')->default(true);
            $table->boolean('wednesday')->default(true);
            $table->boolean('thursday')->default(true);
            $table->boolean('friday')->default(true);
            $table->boolean('saturday')->default(false);
            $table->boolean('sunday')->default(false);

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'Draft',
                'Published',
                'Archived',
            ])->default('Draft');

            /*
            |--------------------------------------------------------------------------
            | Active Term
            |--------------------------------------------------------------------------
            */

            // Only one Academic Term should be active.

            $table->boolean('active')->default(false);

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Terms
            |--------------------------------------------------------------------------
            */

            $table->unique([
                'academic_year',
                'semester',
            ]);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_terms');
    }
};