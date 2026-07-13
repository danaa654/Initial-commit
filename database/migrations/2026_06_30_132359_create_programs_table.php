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
        Schema::create('programs', function (Blueprint $table) {

            $table->id();

            // Home College
            $table->foreignId('department_id')
                ->constrained()
                ->cascadeOnDelete();

            // Example: BSIT
            $table->string('code')->unique();

            // Example:
            // Bachelor of Science in Information Technology
            $table->string('name');

            // Number of years
            $table->unsignedTinyInteger('years')->default(4);

            // Active / Inactive
            $table->boolean('active')->default(true);

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};