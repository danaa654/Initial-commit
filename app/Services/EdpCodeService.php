<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\CurriculumItem;
use App\Models\Program;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Subject;
use App\Models\SubjectOffering;
use Illuminate\Support\Facades\DB;

/**
 * Generates EDP Codes for Subject Offerings.
 *
 * Format: PREFIX-YYSYNNN
 *   PREFIX  = Program prefix, or Specialization code for programs that
 *             require one (see prefixFor() below)
 *   YY      = Academic Year start, last 2 digits (2026 -> "26")
 *   S       = Semester (1, 2, or 3 for Summer)
 *   Y       = Year Level (1-4)
 *   NNN     = 3-digit sequential number, scoped to
 *             (PREFIX + YY + S + Y) — i.e. every offering generated for
 *             the same program/specialization, academic year, semester,
 *             and year level shares one running sequence, regardless of
 *             which Section it belongs to.
 *
 * Examples: IT-2611001, HM-2611001, TM-2611001, ED-2611001,
 *           FB-2611001, FI-2611001, LD-2611001, QD-2611001
 *
 * Prefix rule is intentionally NOT the same "does this Program have an
 * active Specialization?" check SectionCodeService uses for Section
 * Codes. That check is too broad for EDP purposes: BSED has active
 * Specializations (English, Math, ...) for Section/Curriculum purposes,
 * but its EDP prefix must stay "ED" regardless — a Section Code like
 * BSED-ENG-1A does NOT mean the EDP prefix is "ENG". Only BSCRIM
 * actually wants its EDP prefix driven by the Specialization:
 *   - Programs NOT in SPECIALIZATION_PREFIXED_PROGRAMS (BSIT, BSHM,
 *     BSTM, BSED, ...) use their own Program code with the leading
 *     "BS" stripped (BSIT -> IT, BSHM -> HM, BSTM -> TM, BSED -> ED —
 *     no matter which Specialization the Curriculum/Section has).
 *   - Programs IN SPECIALIZATION_PREFIXED_PROGRAMS (BSCRIM today) use
 *     the Specialization's own short code instead (FB, FI, LD, QD).
 *
 * This mirrors SectionCodeService's split (server-side generator here;
 * nothing client-side needs to preview an EDP Code today since offerings
 * are never manually created).
 *
 * This is the ONLY place EDP Code generation rules live. There are two
 * callers, both of which route through the prefixFor()/scopeKey()/
 * build()/highestSequence() helpers below rather than re-implementing
 * any part of the format or sequencing themselves:
 *
 *   - SubjectOfferingGeneratorService::generate() — the bulk "Generate
 *     Subject Offerings" flow for Regular Sections. It processes many
 *     offerings per request and keeps its own short-lived in-memory
 *     counter per scope purely for performance (so it doesn't have to
 *     re-query the highest sequence for every single row), but that
 *     counter is seeded from highestSequence() below and every code it
 *     produces still comes from build() below.
 *
 *   - generate() below — a single-shot convenience wrapper used by the
 *     Irregular Section subject picker (see
 *     SubjectOfferingController::storeIrregular()), where exactly one
 *     (Section, Subject) pair needs an EDP Code at a time and there is
 *     no batch to amortize a cache over.
 */
class EdpCodeService
{
    /**
     * Programs whose EDP Code prefix comes from the Specialization
     * rather than the Program's own code. Deliberately a hardcoded
     * allow-list (not "any Program with active Specializations", which
     * is what SectionCodeService uses for Section Codes) — add a
     * Program code here only when its EDP prefix should genuinely
     * follow the Specialization instead of the Program.
     */
    private const SPECIALIZATION_PREFIXED_PROGRAMS = ['BSCRIM'];

    /**
     * Resolve the EDP Code prefix for a Program (+ its Specialization,
     * when that Program is specialization-prefixed). Returns null if a
     * required Specialization is missing or has no code — callers
     * should treat that as "cannot generate yet" rather than falling
     * back to something guessed.
     */
    public static function prefixFor(Program $program, ?Specialization $specialization): ?string
    {
        $code = strtoupper($program->code);

        if (in_array($code, self::SPECIALIZATION_PREFIXED_PROGRAMS, true)) {
            if (! $specialization || empty($specialization->code)) {
                return null;
            }

            return strtoupper($specialization->code);
        }

        // Strip a leading "BS" the same way abbreviateProgramName() in
        // useSectionCodeGenerator.js strips "Bachelor of (Science in)?"
        // from the full name — this is the code-side equivalent.
        return str_starts_with($code, 'BS') ? substr($code, 2) : $code;
    }

    /**
     * Build the (PREFIX + YY + S + Y) scope key used to group offerings
     * into one sequential-numbering run. Same shape the generator uses
     * to keep an in-memory counter per scope while it works through a
     * batch of Sections/Curriculum Items.
     */
    public static function scopeKey(string $prefix, AcademicTerm $term, int $yearLevel): string
    {
        return $prefix . '-' . self::yearSemesterYearLevel($term, $yearLevel);
    }

    /**
     * Build the full EDP Code from its already-resolved parts and a
     * sequence number (1-based). $sequence is zero-padded to 3 digits —
     * callers are responsible for tracking/incrementing it per
     * scopeKey() so numbers run continuously across every Section in
     * the same program/specialization + academic year + semester +
     * year level.
     */
    public static function build(string $prefix, AcademicTerm $term, int $yearLevel, int $sequence): string
    {
        return $prefix
            . '-'
            . self::yearSemesterYearLevel($term, $yearLevel)
            . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The "YYSY" middle segment shared by scopeKey() and build() — kept
     * in one place so the two can never drift out of sync with each
     * other.
     */
    private static function yearSemesterYearLevel(AcademicTerm $term, int $yearLevel): string
    {
        $yy = substr((string) $term->academic_year, 2, 2);

        return $yy . $term->semester . $yearLevel;
    }

    /**
     * The highest EDP sequence number already used within a given
     * scope, for a given Academic Term — 0 if none exist yet. Moved
     * here (verbatim) from what used to be
     * SubjectOfferingGeneratorService::highestExistingSequence(), so
     * both the batch generator and generate() below share exactly one
     * "what's the next number" rule instead of each maintaining their
     * own copy of this query.
     *
     * Locks the matching rows (lockForUpdate) so two EDP Codes in the
     * same scope can never be handed out concurrently from the same
     * sequence number — relevant for generate() below, where a
     * Registrar attaching several subjects to an Irregular Section in
     * one request calls this once per subject rather than caching a
     * batch counter in memory. Wrap the call in a DB transaction (as
     * generate() does) for the lock to actually take effect.
     */
    public static function highestSequence(string $scopeKey, AcademicTerm $academicTerm): int
    {
        $maxSuffix = SubjectOffering::where('academic_term_id', $academicTerm->id)
            ->where('edp_code', 'like', $scopeKey . '%')
            ->lockForUpdate()
            ->pluck('edp_code')
            ->map(fn ($code) => (int) substr($code, strlen($scopeKey)))
            ->max();

        return (int) $maxSuffix;
    }

    /**
     * Single-shot EDP Code generation for exactly one (Section,
     * Subject) pair — the path the Irregular Section subject picker
     * uses (see SubjectOfferingController::storeIrregular()), where
     * subjects are attached one (or a handful) at a time rather than
     * in the large curriculum-wide batches SubjectOfferingGeneratorService
     * handles.
     *
     * $curriculumItem, when given, is trusted for BOTH the Year Level
     * the EDP Code should encode and the Curriculum (and therefore
     * Program/Specialization) the prefix should resolve from — this
     * matters for Irregular Sections, whose hand-picked Subjects can
     * come from a different Curriculum Item's Year Level than the
     * Section's own year_level (that's the entire point of "irregular").
     * Falls back to the Section's own Curriculum/year_level when no
     * Curriculum Item is given, which is the correct behavior for a
     * Regular Section subject generated without one.
     *
     * $academicTerm falls back to the caller's current Working
     * Academic Term (Planning Term for Admin/Registrar, Active Term
     * otherwise — see SchedulingWorkspaceService::getTermForUser())
     * when omitted, so callers only need to pass it explicitly when
     * they already have it in hand.
     *
     * Wrapped in its own DB transaction with a row lock (see
     * highestSequence() above) so the sequence number it hands out can
     * never collide with another EDP Code being generated for the same
     * scope at the same time, even outside of
     * SubjectOfferingGeneratorService's single-request in-memory cache.
     *
     * @throws \RuntimeException if no Academic Term can be resolved, or
     *                            if the Program/Specialization can't
     *                            resolve to an EDP prefix (e.g. a
     *                            specialization-prefixed Program whose
     *                            Specialization has no short code yet).
     */
    public static function generate(
        Section $section,
        Subject $subject,
        ?CurriculumItem $curriculumItem = null,
        ?AcademicTerm $academicTerm = null
    ): string {
        $academicTerm ??= app(SchedulingWorkspaceService::class)->getTermForUser(auth()->user());

        if (! $academicTerm) {
            throw new \RuntimeException('Cannot generate an EDP Code: no Working Academic Term is set.');
        }

        $curriculum = $curriculumItem
            ? $curriculumItem->curriculum()->with(['program', 'specialization'])->firstOrFail()
            : $section->curriculum()->with(['program', 'specialization'])->firstOrFail();

        $prefix = self::prefixFor($curriculum->program, $curriculum->specialization);

        if (! $prefix) {
            throw new \RuntimeException(
                "Cannot generate an EDP Code for {$curriculum->program->code}"
                . ($curriculum->specialization ? '.' : " — its Specialization has no short code assigned yet.")
            );
        }

        $yearLevel = $curriculumItem->year_level ?? $section->year_level;

        $scopeKey = self::scopeKey($prefix, $academicTerm, $yearLevel);

        return DB::transaction(function () use ($prefix, $academicTerm, $yearLevel, $scopeKey) {
            $sequence = self::highestSequence($scopeKey, $academicTerm) + 1;

            return self::build($prefix, $academicTerm, $yearLevel, $sequence);
        });
    }
}