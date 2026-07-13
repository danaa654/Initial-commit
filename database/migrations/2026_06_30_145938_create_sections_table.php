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
        Schema::create('sections', function (Blueprint $table) {

            $table->id();

            $table->foreignId('curriculum_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('section_code', 20)
                ->unique();

            $table->string('section_name');

            $table->unsignedTinyInteger('year_level');

            $table->char('section_letter', 1);

            $table->unsignedInteger('capacity');

            $table->enum('status', ['Active', 'Inactive'])
                ->default('Active');

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};