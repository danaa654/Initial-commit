<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Specialization;

/**
 * Generates system-assigned Section Codes and resolves which Curriculum
 * a Section should attach to, based on the Program / Specialization /
 * Year Level / Section Letter the user picks on the Create/Edit form.
 *
 * This is the authoritative (server-side) version of the same logic
 * mirrored in resources/js/Composables/useSectionCodeGenerator.js for
 * the live preview. Keep both in sync if the generation rules change —
 * same pattern as AcademicTerm's academicYearDateRange()/
 * useAcademicTermForm.js split.
 */
class SectionCodeService
{
    /**
     * Whether a Program requires a Specialization to be selected before
     * a Section Code can be generated.
     *
     * Data-driven, not hardcoded to any particular Program code: a
     * Program "requires" a Specialization simply by having one or more
     * active Specializations of its own (BSCRIM, BSED, or any future
     * Program set up the same way). A Program with no active
     * Specializations is treated as specialization-less for Section
     * purposes (BSIT, BSHM, BSTM, ...).
     *
     * If `specializations` is already eager-loaded (e.g.
     * SectionController::programOptions(), which loads only the active
     * ones), that in-memory collection is used instead of firing an
     * extra query.
     */
    public static function requiresSpecialization(?Program $program): bool
    {
        if (! $program) {
            return false;
        }

        if ($program->relationLoaded('specializations')) {
            return $program->specializations->contains(fn (Specialization $specialization) => $specialization->active);
        }

        return $program->specializations()->where('active', true)->exists();
    }

    /**
     * Build the Section Code string from its components.
     *
     * Returns null if any required piece is missing — callers (both the
     * controller and the Vue preview) use that null to mean "not enough
     * information yet to generate a code."
     *
     *   No active Specializations  -> PROGRAM-YEARLETTER      (e.g. BSIT-1A)
     *   Has active Specializations -> PROGRAM-SPEC-YEARLETTER (e.g. BSCRIM-FI-4A, BSED-ENG-1A)
     *
     * Which branch applies is decided by requiresSpecialization() above,
     * not by checking the Program's code. The Specialization "code"
     * used in the second branch comes straight from specializations.code
     * (FI, FB, ENG, etc.) rather than a hardcoded name-to-code map, so it
     * stays correct if a specialization is ever renamed or a new one is
     * added — as long as its `code` column is set.
     */
    public static function generate(
        ?Program $program,
        ?Specialization $specialization,
        ?int $yearLevel,
        ?string $letter
    ): ?string {
        if (! $program || ! $yearLevel || ! $letter) {
            return null;
        }

        $letter = strtoupper($letter);

        if (self::requiresSpecialization($program)) {
            if (! $specialization || empty($specialization->code)) {
                return null;
            }

            return strtoupper($program->code)
                . '-' . strtoupper($specialization->code)
                . '-' . $yearLevel . $letter;
        }

        return strtoupper($program->code) . '-' . $yearLevel . $letter;
    }

    /**
     * Resolve which Curriculum a Section should attach to, given the
     * Program/Specialization picked on the form.
     *
     * Sections still hang off a Curriculum (curriculum_id is unchanged
     * and required) — the new Program/Specialization inputs just let the
     * user express that in a more direct, code-generation-friendly way
     * instead of picking a Curriculum row out of a dropdown. When more
     * than one Curriculum matches (e.g. multiple effective years for the
     * same Program/Specialization), the active one with the highest
     * effective_year wins.
     */
    public static function resolveCurriculum(int $programId, ?int $specializationId): ?Curriculum
    {
        return Curriculum::where('program_id', $programId)
            ->where('specialization_id', $specializationId)
            ->orderByDesc('active')
            ->orderByDesc('effective_year')
            ->first();
    }
}