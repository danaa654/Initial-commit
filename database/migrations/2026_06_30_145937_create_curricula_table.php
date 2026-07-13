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
        Schema::create('curricula', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Program
            |--------------------------------------------------------------------------
            |
            | Every curriculum belongs to one program.
            |
            */

            $table->foreignId('program_id')
                ->constrained()
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Specialization
            |--------------------------------------------------------------------------
            |
            | Nullable because not every program has a specialization.
            |
            */

            $table->foreignId('specialization_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Curriculum Information
            |--------------------------------------------------------------------------
            */

            // Example:
            // BSIT-2025
            // BSCRIM-LEA-2025

            $table->string('code')->unique();

            // Example:
            // BS Information Technology Curriculum 2025

            $table->string('name');

            // Example:
            // 2025-2026

            $table->string('academic_year');

            // Example:
            // 2025

            $table->year('effective_year');

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('active')->default(true);

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate curricula
            |--------------------------------------------------------------------------
            */

            $table->unique([
                'program_id',
                'specialization_id',
                'effective_year'
            ], 'curriculum_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curricula');
    }
};