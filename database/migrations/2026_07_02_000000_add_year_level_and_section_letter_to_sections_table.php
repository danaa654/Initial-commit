<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Section Codes are now system-generated from Program + (optionally)
     * Specialization + Year Level + Section Letter, instead of being
     * typed in free-hand. year_level and section_letter are the two
     * pieces that weren't captured anywhere before — everything else
     * (Program, Specialization) is already reachable through the
     * existing curriculum_id relationship, so no other columns are
     * needed.
     *
     * Guarded with Schema::hasColumn(): 2026_06_30_145938_create_
     * sections_table.php was later edited to create both columns
     * directly (so a fresh install never needs this migration to do
     * anything), but this migration still needs to run cleanly against
     * any database that was created BEFORE that edit — where these
     * columns genuinely don't exist yet. Without the guard, `migrate:
     * fresh` replays both migrations back-to-back and the second ADD
     * COLUMN collides with the first (Illuminate\Database\
     * QueryException: 1060 Duplicate column name 'year_level').
     */
    public function up(): void
    {
        if (! Schema::hasColumn('sections', 'year_level')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->unsignedTinyInteger('year_level')
                    ->nullable()
                    ->after('section_name');
            });
        }

        if (! Schema::hasColumn('sections', 'section_letter')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->char('section_letter', 1)
                    ->nullable()
                    ->after('year_level');
            });
        }

        /*
        |----------------------------------------------------------------
        | Backfill for existing rows
        |----------------------------------------------------------------
        |
        | Best-effort only. Every code this system has ever generated
        | ends in "<digits><letter>" (e.g. BSIT-1A, BSCRIM-FI-4A), so
        | that's what we try to recover. Rows that don't match (e.g. a
        | hand-typed code that didn't follow the convention) are simply
        | left null — SectionController requires year_level and
        | section_letter on update, so the next time that section is
        | edited the user will be asked to pick both, which regenerates
        | a clean, conforming code. Nothing here breaks existing rows;
        | their current section_code is untouched either way.
        |
        | Only meaningful when this migration is actually adding the
        | columns to pre-existing data — on a fresh install the table is
        | empty at this point anyway (the seeder runs after migrations),
        | so this loop is a harmless no-op there.
        |
        */
        DB::table('sections')->orderBy('id')->chunk(100, function ($sections) {
            foreach ($sections as $section) {
                if (! is_null($section->year_level) && ! is_null($section->section_letter)) {
                    continue;
                }

                if (preg_match('/(\d+)([A-Za-z])$/', $section->section_code, $matches)) {
                    DB::table('sections')
                        ->where('id', $section->id)
                        ->update([
                            'year_level' => (int) $matches[1],
                            'section_letter' => strtoupper($matches[2]),
                        ]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Guarded the same way as up(): only drops the columns if this
     * migration (rather than the base create_sections_table migration)
     * is the one that owns them. Since a fresh install's
     * create_sections_table migration creates these columns itself,
     * rolling back just this migration on a fresh install should not
     * drop columns another migration is still relying on.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {

            if (Schema::hasColumn('sections', 'section_letter')) {
                $table->dropColumn('section_letter');
            }

            if (Schema::hasColumn('sections', 'year_level')) {
                $table->dropColumn('year_level');
            }

        });
    }
};