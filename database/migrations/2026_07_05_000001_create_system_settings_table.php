<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic key/value store for system-wide configuration that must be
 * shared by every user (i.e. NOT session storage, NOT per-user).
 *
 * The first (and currently only) consumer is the Planning Academic
 * Term — see SchedulingWorkspaceService — stored under the key
 * 'planning_academic_term_id'. Modeled as a flexible key/value table
 * rather than a single-purpose column so future system-wide settings
 * don't each need their own migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};