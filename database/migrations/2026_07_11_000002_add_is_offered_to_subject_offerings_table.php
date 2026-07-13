<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule 2 of the Finalize feature needs to skip subjects that were
 * generated but flagged as "not actually offered this term" so they
 * don't block finalization. Added defensively (hasColumn guard) since
 * the real subject_offerings schema isn't fully visible in this repo
 * snapshot — safe to run whether or not the column already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subject_offerings', 'is_offered')) {
            Schema::table('subject_offerings', function (Blueprint $table) {
                $table->boolean('is_offered')->default(true)->after('academic_term_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subject_offerings', 'is_offered')) {
            Schema::table('subject_offerings', function (Blueprint $table) {
                $table->dropColumn('is_offered');
            });
        }
    }
};