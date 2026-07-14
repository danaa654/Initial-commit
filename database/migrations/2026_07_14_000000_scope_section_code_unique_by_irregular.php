<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Section Code (e.g. "BSIT-1B") used to be globally unique — one
 * Program + Year Level + Letter could only ever exist once. That's too
 * strict for how Irregular Sections are actually used: a school can
 * legitimately want a Regular "BSIT-1B" (auto-generated Subject
 * Offerings from the curriculum) AND a separate Irregular "BSIT-1B"
 * (hand-picked Subjects) existing side by side, distinguished only by
 * the Irregular badge/status — not by their letter.
 *
 * This drops the single-column unique index and replaces it with a
 * composite one on (section_code, is_irregular), so the same code can
 * exist at most twice: once Regular, once Irregular. Two Regular (or
 * two Irregular) sections with the same code are still blocked, same
 * as before — see SectionController::processSection()'s duplicate
 * check, which now scopes by is_irregular to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique(['section_code']);

            $table->unique(['section_code', 'is_irregular']);
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique(['section_code', 'is_irregular']);

            $table->unique('section_code');
        });
    }
};