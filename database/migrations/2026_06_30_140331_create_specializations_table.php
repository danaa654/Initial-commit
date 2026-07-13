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
        Schema::create('specializations', function (Blueprint $table) {

            $table->id();

            // Parent Program
            $table->foreignId('program_id')
                ->constrained()
                ->cascadeOnDelete();

            // Example:
            // Law Enforcement Administration
            // Questioned Documents Examination
            $table->string('name');

            // Optional abbreviation
            // LEA
            // QDE
            $table->string('code', 20)->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Unique Constraints
            |--------------------------------------------------------------------------
            |
            | A program cannot have two specializations with the same code
            | or the same name.
            |
            */

            $table->unique(['program_id', 'code']);
            $table->unique(['program_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('specializations');
    }
};