<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Generates Subject Offerings for one Curriculum, into one Academic
 * Term, for a registrar-chosen set of Sections.
 *
 * Pipeline: Curriculum + Academic Term's semester -> that Curriculum's
 * active Curriculum Items at that semester -> every selected Section
 * at the matching year_level. Every matching (Section, Curriculum
 * Item) pair becomes exactly one Subject Offering, status Generated,
 * with a snapshot of the Subject's Units/Hours/Classification/Room
 * Type and a freshly generated EDP Code.
 *
 * Generation is additive and non-destructive: generate() never
 * deletes or overwrites an existing Subject Offering. Running it again
 * for the same Curriculum/Term — e.g. after checking one more Section
 * — only ever fills in whatever's missing.
 */
class SubjectOfferingGeneratorService
{
    /**
     * Running EDP sequence counters, keyed by
     * EdpCodeService::scopeKey() — reset for every generate() call,
     * lazily seeded (per scope) from the highest sequence already in
     * the DB the first time that scope is touched.
     *
     * @var array<string, int>
     */
    private array $sequences = [];

    /**
     * @param  int[]  $sectionIds
     */
    public function generate(
        AcademicTerm $academicTerm,
        Curriculum $curriculum,
        array $sectionIds,
        User $generatedBy
    ): array {
        $this->sequences = [];

        $summary = [
            'sections_scanned' => 0,
            'items_matched' => 0,
            'created' => 0,
            'skipped_existing' => 0,
            'skipped_unresolved' => 0,
        ];

        DB::transaction(function () use ($academicTerm, $curriculum, $sectionIds, $generatedBy, &$summary) {

            $sections = Section::where('curriculum_id', $curriculum->id)
                ->whereIn('id', $sectionIds)
                ->where('status', 'Active')
                ->orderBy('section_code')
                ->get();

            $summary['sections_scanned'] = $sections->count();

            $items = CurriculumItem::where('curriculum_id', $curriculum->id)
                ->where('semester', $academicTerm->semester)
                ->where('active', true)
                ->whereNotNull('subject_id')
                ->with('subject')
                ->orderBy('sort_order')
                ->get()
                ->groupBy('year_level');

            $existingPairs = SubjectOffering::where('academic_term_id', $academicTerm->id)
                ->where('curriculum_id', $curriculum->id)
                ->get(['section_id', 'curriculum_item_id'])
                ->map(fn ($offering) => $offering->section_id . ':' . $offering->curriculum_item_id)
                ->flip();

            foreach ($sections as $section) {

                $itemsForYearLevel = $items->get($section->year_level, collect());

                foreach ($itemsForYearLevel as $item) {

                    $summary['items_matched']++;

                    $pairKey = $section->id . ':' . $item->id;

                    if (isset($existingPairs[$pairKey])) {
                        $summary['skipped_existing']++;
                        continue;
                    }

                    $created = $this->createOffering($academicTerm, $curriculum, $section, $item, $generatedBy);

                    if ($created) {
                        $summary['created']++;
                        $existingPairs[$pairKey] = true;
                    } else {
                        $summary['skipped_unresolved']++;
                    }
                }
            }

        });

        return $summary;
    }

    /**
     * Create a single Subject Offering for one (Section, Curriculum
     * Item) pair. Returns false (and creates nothing) if an EDP
     * prefix can't be resolved — e.g. a BSCRIM Curriculum whose
     * Specialization is missing its short code.
     */
    private function createOffering(
        AcademicTerm $academicTerm,
        Curriculum $curriculum,
        Section $section,
        CurriculumItem $item,
        User $generatedBy
    ): bool {
        $program = $curriculum->program;
        $subject = $item->subject;

        $prefix = EdpCodeService::prefixFor($program, $curriculum->specialization);

        if (! $prefix) {
            return false;
        }

        $scopeKey = EdpCodeService::scopeKey($prefix, $academicTerm, $section->year_level);

        if (! array_key_exists($scopeKey, $this->sequences)) {
            $this->sequences[$scopeKey] = $this->highestExistingSequence($scopeKey, $academicTerm);
        }

        $this->sequences[$scopeKey]++;

        $edpCode = EdpCodeService::build(
            $prefix,
            $academicTerm,
            $section->year_level,
            $this->sequences[$scopeKey]
        );

        SubjectOffering::create([
            'academic_term_id' => $academicTerm->id,
            'curriculum_id' => $curriculum->id,
            'curriculum_item_id' => $item->id,
            'program_id' => $program->id,
            'subject_id' => $subject->id,
            'section_id' => $section->id,
            'year_level' => $item->year_level,
            'semester' => $item->semester,
            'units' => $subject->units,
            'hours' => $subject->total_hours,
            'classification' => $subject->is_major
                ? SubjectOffering::CLASSIFICATION_MAJOR
                : SubjectOffering::CLASSIFICATION_MINOR,
            'room_type' => $subject->required_room_type,
            'edp_code' => $edpCode,
            'status' => SubjectOffering::STATUS_GENERATED,
            'created_by' => $generatedBy->id,
        ]);

        return true;
    }

    /**
     * The highest EDP sequence number already used within this scope
     * for this Academic Term — numbering continues after it rather
     * than restarting at 1 and colliding with another Section's codes.
     *
     * Delegates to EdpCodeService::highestSequence() — the query used
     * to live here, but was moved to EdpCodeService so this batch
     * generator and EdpCodeService::generate() (the single-shot path
     * used by the Irregular Section subject picker) share exactly one
     * implementation of "what's the next sequence number" instead of
     * two copies that could drift apart.
     */
    private function highestExistingSequence(string $scopeKey, AcademicTerm $academicTerm): int
    {
        return EdpCodeService::highestSequence($scopeKey, $academicTerm);
    }
}