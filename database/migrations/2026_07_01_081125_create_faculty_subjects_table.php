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
        Schema::create('faculty_subjects', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Relationships
            |--------------------------------------------------------------------------
            */

            $table->foreignId('faculty_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Assignment Settings
            |--------------------------------------------------------------------------
            */

            // Preferred faculty for this subject
            $table->boolean('preferred')->default(false);

            // Can currently teach this subject
            $table->boolean('active')->default(true);

            /*
            |--------------------------------------------------------------------------
            | Remarks
            |--------------------------------------------------------------------------
            */

            $table->string('remarks')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate assignments
            |--------------------------------------------------------------------------
            */

            $table->unique([
                'faculty_id',
                'subject_id',
            ]);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_subjects');
    }
};