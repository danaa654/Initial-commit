<?php

namespace Database\Seeders;

use App\Models\Subject;
use App\Models\SubjectRoomGroup;
use Illuminate\Database\Seeder;

/**
 * SubjectSeeder
 * ---------------------------------------------------------------------
 * Populates the master `subjects` catalog from the official PAP
 * curricula for BSED (English), BSIT, BSTM, BSHM, and BSCRIM
 * (Questioned Documents / Fingerprint / Firearms / Lie Detection).
 *
 * SCHEMA NOTES / ASSUMPTIONS (read before editing)
 * ---------------------------------------------------------------------
 * 1. This seeder targets the CURRENT columns on `subjects` (after the
 *    required_room -> required_room_type/required_room_group/is_practicum
 *    migrations):
 *        subject_code, descriptive_title, units, lecture_hours,
 *        laboratory_hours, total_hours, is_major, required_room_type,
 *        required_room_group, is_practicum, allow_split_schedule,
 *        prerequisite_id, active
 *    The data arrays below still use a legacy `room` key per row (the
 *    old six-value enum: Lecture, Computer Laboratory, Science
 *    Laboratory, Speech Laboratory, PE Area, Any) because rewriting
 *    ~250 rows by hand would be error-prone. `mapRoomType()` and
 *    `isPracticum()` below translate that legacy value + the row's
 *    lecture/lab hours into the real `required_room_type` enum
 *    (Lecture / Laboratory / None) at insert time — see Pass 1 in run().
 *
 * 2. `is_practicum` now exists as a real column. Rather than trust the
 *    scattered `// PRACTICUM/OJT:` comments, `isPracticum()` detects it
 *    structurally: any subject_code starting with "PRAC" (covers
 *    PRACTICUM, PRACTICUM1-BSTM, PRACTICUM2-BSHM, PRAC101, etc.).
 *    Practicum/OJT subjects get required_room_type = 'None' (they're
 *    excluded from the auto-scheduler via Subject::scopeSchedulable()),
 *    regardless of whatever legacy `room` value the row still carries.
 *
 * 3. `required_room_group` (General/BSIT/BSED/BSHM/BSTM/COC) is
 *    derived per-source-method via `tag()` in run() — each private
 *    catalog method (bsitOnly, bshmOnly, etc.) is tagged with its
 *    program's room group when merged. Only true General Education/
 *    PATHFIT/NSTP subjects (generalEducationAndMinors()) fall through
 *    to the 'General' tag, since they aren't tied to a single program's
 *    specialized rooms. Every row in tourismHospitalityShared() —
 *    BC1/BC2, THC1-10, FLT1-5, RESEARCH, and NON-ABM1-5 — sets an
 *    explicit 'group' => ['BSTM', 'BSHM'] array instead (tag() only
 *    fills in a group when one isn't already set), since the whole
 *    method is BSTM/BSHM-specific major/bridging content, not general
 *    subject matter — Pass 1 in run() inserts one pivot row per program
 *    in the array. All four BSCRIM majors (QD/FI/FAI/LD) collapse to
 *    the single 'COC' room group (college code, matching CCS/CTE/SHTM) per the migration's own design (the
 *    scheduler just needs "a Criminalistics lab", not which
 *    specialization).
 *
 * 4. required_room_type derivation (mapRoomType()):
 *      - Practicum/OJT (any "PRAC*" code)              -> None
 *      - Computer Laboratory / Science Laboratory /
 *        PE Area                                        -> Laboratory
 *      - "Any" (legacy mixed lec+lab courses)            -> Laboratory
 *        if laboratory_hours > 0, else Lecture
 *      - Everything else ("Lecture", or 0 lab hours)      -> Lecture
 *
 * 4. `prerequisite_id` is a single self-referencing FK, but curricula
 *    frequently list multiple or textual prerequisites ("EM 2 & 3",
 *    "All Professional & Major Subjects", "4th Year Standing"). Only
 *    single, unambiguous subject-code prerequisites are resolved to
 *    `prerequisite_id` (second pass below, after all subjects exist).
 *    Everything else is left null with the original text preserved in
 *    a comment next to the record.
 *
 * 5. Deduplication: General Education, PATHFIT, NSTP, and — where the
 *    PDFs show genuinely identical content — cross-program business/
 *    tourism/hospitality bridging courses (NON-ABM, THC, FLT, BC) are
 *    seeded once and reused. BSCRIM's LAW/CRIM/CDI/LEA/CORR/FORENSIC/
 *    CFLM/RESEARCH/ENHANCE/CHEM codes are identical across all four
 *    BSCRIM majors (QD, Fingerprint, Firearms, Lie Detection) and are
 *    also seeded once.
 *
 * 6. Real code collisions across programs were found and resolved with
 *    program suffixes, because the same code means different things in
 *    different programs:
 *      - PRACTICUM1 / PRACTICUM2  -> PRACTICUM1-BSTM / PRACTICUM1-BSHM /
 *                                     PRACTICUM2-BSTM / PRACTICUM2-BSHM /
 *                                     PRACTICUM1-BSCRIM / PRACTICUM2-BSCRIM
 *      - PROF ELECT 1-5           -> PROF-ELECT{n}-BSTM / PROF-ELECT{n}-BSHM
 *    (BSIT's PRAC101 and BSED's PRACTICUM were already unique.)
 *
 * 7. Code normalization: BSCRIM's curriculum PDFs print "MATH" for
 *    Mathematics in the Modern World while every other program prints
 *    "MMW". Normalized to MMW per the dedup rule (obvious typographical
 *    inconsistency, same subject).
 *
 * 8. PATHFIT title variance: PATHFIT 1-4 share the same code, hours,
 *    and units across every program, but BSED and BSCRIM use different
 *    descriptive titles for the same slot (BSCRIM leans martial arts /
 *    marksmanship, BSED leans dance/sport-specific fitness). Since
 *    subject_code is unique, one canonical title (the majority one
 *    used by BSIT/BSTM/BSHM) was kept, with the variants noted in a
 *    comment. Worth confirming with your adviser whether these should
 *    actually be modeled as distinct subjects per program instead.
 *
 * Run with: php artisan db:seed --class=SubjectSeeder
 * Safe to re-run: uses Subject::updateOrCreate() throughout.
 */
class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = array_merge(
            $this->tag($this->generalEducationAndMinors(), 'General'),
            // Every row in tourismHospitalityShared() sets its own explicit
            // 'group' => ['BSTM', 'BSHM'] now, so tag()'s fallback is never
            // actually used here — passing null makes that intent explicit
            // and surfaces loudly (via a missing 'group' key) if a future
            // row is added without one, instead of silently defaulting to
            // 'General'.
            $this->tag($this->tourismHospitalityShared(), null),
            $this->tag($this->bstmOnly(), 'BSTM'),
            $this->tag($this->bshmOnly(), 'BSHM'),
            $this->tag($this->bsitOnly(), 'BSIT'),
            $this->tag($this->bsedOnly(), 'BSED'),
            $this->tag($this->bscrimShared(), 'COC'),
            $this->tag($this->bscrimQuestionedDocuments(), 'COC'),
            $this->tag($this->bscrimFingerprint(), 'COC'),
            $this->tag($this->bscrimFirearms(), 'COC'),
            $this->tag($this->bscrimLieDetection(), 'COC'),
        );

        // ---------------------------------------------------------------
        // Pass 1: create/update every subject (no prerequisite yet).
        // ---------------------------------------------------------------
        foreach ($subjects as $s) {
            $subject = Subject::updateOrCreate(
                ['subject_code' => $s['code']],
                [
                    'descriptive_title'    => $s['title'],
                    'units'                => $s['units'],
                    'lecture_hours'        => $s['lec'],
                    'laboratory_hours'     => $s['lab'],
                    'total_hours'          => $s['lec'] + $s['lab'],
                    'is_major'             => $s['major'],
                    'required_room_type'   => $this->mapRoomType($s),
                    'is_practicum'         => $this->isPracticum($s),
                    'allow_split_schedule' => ($s['lec'] >= 4 || $s['lab'] >= 4),
                    'active'               => true,
                ]
            );

            // required_room_group no longer lives on the subjects table —
            // it moved to the room_group_subject pivot table
            // (Subject::roomGroups()). $s['group'] is normally a single
            // program string per row (set by tag()), giving one pivot row.
            // A handful of rows (e.g. NON-ABM1-5) instead set $s['group']
            // to an array of programs — e.g. ['BSTM', 'BSHM'] — because
            // they're applicable to more than one program; loop over it
            // so each program gets its own pivot row. updateOrCreate()
            // keeps this safe to re-run without duplicate pivot rows.
            foreach ((array) $s['group'] as $group) {
                SubjectRoomGroup::updateOrCreate([
                    'subject_id' => $subject->id,
                    'room_group' => $group,
                ]);
            }
        }

        // ---------------------------------------------------------------
        // Pass 2: resolve single, unambiguous prerequisites now that
        // every subject_code in the catalog exists.
        // ---------------------------------------------------------------
        foreach ($subjects as $s) {
            if (empty($s['prereq'])) {
                continue;
            }

            $subject = Subject::where('subject_code', $s['code'])->first();
            $prereq  = Subject::where('subject_code', $s['prereq'])->first();

            if ($subject && $prereq) {
                $subject->update(['prerequisite_id' => $prereq->id]);
            }
        }
    }

    /**
     * Stamps every row in a catalog array with the required_room_group
     * it belongs to (unless a row already sets one explicitly — used by
     * NON-ABM1-5 below to opt into an array of programs instead of the
     * catalog's default single group).
     */
    private function tag(array $rows, ?string $group): array
    {
        foreach ($rows as &$row) {
            if (!isset($row['group'])) {
                if ($group === null) {
                    throw new \RuntimeException(
                        "Subject '{$row['code']}' has no explicit 'group' and no fallback was given to tag()."
                    );
                }
                $row['group'] = $group;
            }
        }

        return $rows;
    }

    /**
     * Structural Practicum/OJT/Internship detection: every practicum
     * subject_code in this catalog starts with "PRAC" (PRACTICUM,
     * PRACTICUM1-BSTM, PRACTICUM2-BSHM, PRAC101, PRACTICUM1-BSCRIM, ...).
     * FS1/FS2 (BSED classroom-observation subjects) are intentionally
     * NOT practicum — they're lecture-room subjects, not OJT.
     */
    private function isPracticum(array $s): bool
    {
        return str_starts_with($s['code'], 'PRAC');
    }

    /**
     * Maps the legacy `room` value + lecture/lab hours onto the current
     * required_room_type enum (Lecture / Laboratory / None). See schema
     * note #4 above for the full derivation rules.
     */
    private function mapRoomType(array $s): string
    {
        if ($this->isPracticum($s)) {
            return 'None';
        }

        if (in_array($s['room'], ['Computer Laboratory', 'Science Laboratory', 'Speech Laboratory', 'PE Area'], true)) {
            return 'Laboratory';
        }

        if ($s['room'] === 'Any') {
            return $s['lab'] > 0 ? 'Laboratory' : 'Lecture';
        }

        return 'Lecture';
    }

    /**
     * Shared General Education, PATHFIT, and NSTP subjects.
     * Appear identically across BSED, BSIT, BSTM, BSHM, and all BSCRIM
     * majors — seeded once per the dedup rule.
     */
    private function generalEducationAndMinors(): array
    {
        return [
            ['code' => 'UTS',      'title' => 'Understanding the Self',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            // Normalized from "MATH" (BSCRIM PDFs) to MMW — same subject as every other program's MMW.
            ['code' => 'MMW',      'title' => 'Mathematics in the Modern World',            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'GENSOC',   'title' => 'Gender and Society',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'READINGS', 'title' => 'Readings in Philippine History',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PCOM',     'title' => 'Purposive Communication',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'ITE',      'title' => 'Living in the IT Era',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'TCW',      'title' => 'The Contemporary World',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'ART',      'title' => 'Art Appreciation',                            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PPC',      'title' => 'Philippine Popular Culture',                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'STS',      'title' => 'Science, Technology and Society',             'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'ETHICS',   'title' => 'Ethics',                                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PIC',      'title' => 'Philippine Indigenous Communities',           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'RIZAL',    'title' => 'Life, Works and Writings of Rizal',           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],

            ['code' => 'NSTP1',    'title' => 'National Service Training Program 1',         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'NSTP2',    'title' => 'National Service Training Program 2',         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => 'NSTP1'],

            // PATHFIT: canonical titles below are the majority titles used by BSIT/BSTM/BSHM.
            // BSED and BSCRIM use different descriptive titles for the same code/hours/units slot:
            //   PATHFIT1 -> BSED: "Movement Enhancement Training" | BSCRIM: "Fundamentals of Martial Arts"
            //   PATHFIT2 -> BSED: "Fitness Exercise for Specific Sports" | BSCRIM: "Arms and Disarming Techniques"
            //   PATHFIT3 -> BSED: "Physical Activities Towards Health and Fitness in Dance" | BSCRIM: "First Aid and Water Survival" (no prereq in BSCRIM)
            //   PATHFIT4 -> BSED: "Physical Activities Towards Health and Fitness in Sports" | BSCRIM: "Fundamentals of Marksmanship" (no prereq in BSCRIM)
            // Confirm with your adviser whether these should be split into per-program subjects instead.
            ['code' => 'PATHFIT1', 'title' => 'Movement Competency Training',                'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => false, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PATHFIT2', 'title' => 'Exercise-based Fitness Activities',            'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => false, 'room' => 'Lecture', 'prereq' => 'PATHFIT1'],
            ['code' => 'PATHFIT3', 'title' => 'Individual and Dual Sports',                   'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => false, 'room' => 'Lecture', 'prereq' => 'PATHFIT2'],
            ['code' => 'PATHFIT4', 'title' => 'Team Sports',                                  'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => false, 'room' => 'Lecture', 'prereq' => 'PATHFIT3'],
        ];
    }

    /**
     * Genuinely identical across BSTM and BSHM (verified line-for-line
     * against both PDFs: same titles, hours, and units).
     */
    private function tourismHospitalityShared(): array
    {
        return [
            ['code' => 'BC1',  'title' => 'Operation Management',                                            'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'BC2',  'title' => 'Strategic Management',                                             'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => 'BC1'],

            ['code' => 'THC1', 'title' => 'Micro Perspective of Tourism and Hospitality',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'THC2', 'title' => 'Risk Management as Applied to Safety, Security and Sanitation',    'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'THC3', 'title' => 'Philippine Culture and Tourism Geography',                         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'THC4', 'title' => 'Quality Service Management in Tourism',                            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'THC5', 'title' => 'Professional Development and Applied Ethics',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'THC6', 'title' => 'Entrepreneurship in Tourism and Hospitality',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'THC7', 'title' => 'Legal Aspects in Tourism and Hospitality',                         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'THC8', 'title' => 'Multi-cultural Diversity in Workplace for Tourism Professionals',  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'THC9', 'title' => 'Macro Perspective of Tourism and Hospitality',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => 'THC1'],
            ['code' => 'THC10','title' => 'Tourism and Hospitality Marketing',                                'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],

            ['code' => 'FLT1', 'title' => 'Aircraft Familiarization',                                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'FLT2', 'title' => 'Airport and Airline Operation Practices',                           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => 'FLT1'],
            ['code' => 'FLT3', 'title' => 'Emergency Procedures and Equipment',                                'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'group' => ['BSTM', 'BSHM'], 'prereq' => 'FLT2'],
            ['code' => 'FLT4', 'title' => 'Air Laws and Regulations',                                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => 'FLT2'],
            // FLT5 prerequisite is "FLT 3 & 4" (multiple) — left null, see comment rule.
            ['code' => 'FLT5', 'title' => 'In-flight Food and Beverage Services / Food Menu Theory',           'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'group' => ['BSTM', 'BSHM'], 'prereq' => null],

            // Bridging courses for non-ABM (Accountancy/Business/Management) SHS graduates.
            // Applicable to BSTM and BSHM only, same as the rest of this method — Pass 1
            // in run() inserts one pivot row per program from the explicit 'group' array.
            // lec bumped to 3 (matching every other plain 3-unit Lecture subject in this
            // catalog) so total_hours lines up with the unit count.
            ['code' => 'NON-ABM1', 'title' => 'Business Marketing',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'NON-ABM2', 'title' => 'Organization and Management',     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'NON-ABM3', 'title' => 'Fundamentals of Accounting',      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'NON-ABM4', 'title' => 'Business Finance',                'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
            ['code' => 'NON-ABM5', 'title' => 'Applied Economics',               'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],

            // BSTM's "RESEARCH" and BSHM's "RESEARCH" are the same subject; BSED uses "RES" and
            // BSCRIM uses "RESEARCH1"/"RESEARCH2" — different codes, so no collision.
            ['code' => 'RESEARCH', 'title' => 'Methods of Research',             'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'group' => ['BSTM', 'BSHM'], 'prereq' => null],
        ];
    }

    /** BSTM-only major subjects. */
    private function bstmOnly(): array
    {
        return [
            ['code' => 'TPC1',  'title' => 'Tour and Travel Management',                                     'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => null],
            ['code' => 'TPC2',  'title' => 'Foreign Language 1 (Japanese 1 / Korean 1)',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'TPC3',  'title' => 'Global Culture and Tourism Geography',                            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'TPC1'],
            ['code' => 'TPC4',  'title' => 'Tourism Policy, Planning and Development',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'TPC1'],
            ['code' => 'TPC5',  'title' => 'Sustainable Tourism',                                              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "THC 1 & 6" (multiple) — left null.
            ['code' => 'TPC6',  'title' => 'Introduction to Meetings, Incentives, Conferences and Events Management', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'TPC7',  'title' => 'Foreign Language 2 (Japanese 2 / Korean 2)',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'TPC2'],
            ['code' => 'TPC8',  'title' => 'Applied Business Tools and Technologies',                         'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => 'BC1'],
            ['code' => 'TPC9',  'title' => 'Transportation Management',                                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'TPC10', 'title' => 'Research in Tourism',                                              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'RESEARCH'],

            // Prereq "THC 3; TPC 3" (multiple) — left null.
            ['code' => 'PROF-ELECT1-BSTM', 'title' => 'Tour Guiding',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'PROF-ELECT2-BSTM', 'title' => 'Medical and Wellness Tourism',          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "BC 1; TPC 8" (multiple) — left null.
            ['code' => 'PROF-ELECT3-BSTM', 'title' => 'Tourism Information Management',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "TPC 1 & 4; THC 10" (multiple) — left null.
            ['code' => 'PROF-ELECT4-BSTM', 'title' => 'Destination Management and Marketing',  'lec' => 3, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => null],
            // Prereq "TPC 1, 3 & 5" (multiple) — left null.
            ['code' => 'PROF-ELECT5-BSTM', 'title' => 'Eco-Tourism Management',                'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // PRACTICUM/OJT: Tourism practicum phases. Prereqs are textual ("All 2nd Year TPC & FLT
            // Subjects" / "All TPC Subjects") — left null. is_practicum intentionally omitted (see header note).
            ['code' => 'PRACTICUM1-BSTM', 'title' => 'Phase I - Tourism Practicum in Travel Agency / Passenger Handling and Assisting 1 (300 hours)', 'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Any', 'prereq' => null],
            ['code' => 'PRACTICUM2-BSTM', 'title' => 'Phase II - Airline/Shipping Line Phase (600 hours)',                                            'lec' => 6, 'lab' => 0, 'units' => 6, 'major' => true, 'room' => 'Any', 'prereq' => null],
        ];
    }

    /** BSHM-only major subjects. */
    private function bshmOnly(): array
    {
        return [
            ['code' => 'HPC1', 'title' => 'Kitchen Essentials and Basic Foods Preparation',              'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => null],
            ['code' => 'HPC2', 'title' => 'Foreign Language 1 (Japanese 1 / Korean 1)',                   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "HPC 1; THC 2" (multiple) — left null.
            ['code' => 'HPC3', 'title' => 'Fundamentals of Food Service Operation',                       'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => null],
            ['code' => 'HPC4', 'title' => 'Fundamentals of Lodging Operations',                           'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'HPC5', 'title' => 'Ergonomics and Facilities Planning for the Hospitality Industry', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'HPC6', 'title' => 'Introduction to Meetings, Incentives, Conferences and Events Management', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'HPC7', 'title' => 'Foreign Language 2 (Japanese 2 / Korean 2)',                   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'HPC2'],
            ['code' => 'HPC8', 'title' => 'Applied Business Tools and Technologies',                      'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any',     'prereq' => 'BC1'],
            ['code' => 'HPC9', 'title' => 'Supply Chain Management in Hospitality Industry',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'BC2'],
            ['code' => 'HPC10','title' => 'Research in Hospitality',                                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'RESEARCH'],

            // Prereq "HPC 1; THC 2" (multiple) — left null.
            ['code' => 'PROF-ELECT1-BSHM', 'title' => 'Fundamentals of Food Science and Technology', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => null],
            ['code' => 'PROF-ELECT2-BSHM', 'title' => 'Housekeeping Operations',                      'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => 'HPC4'],
            ['code' => 'PROF-ELECT3-BSHM', 'title' => 'Front Office',                                  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => 'THC9'],
            ['code' => 'PROF-ELECT4-BSHM', 'title' => 'Food and Beverage Service Management',          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => 'HPC3'],
            // Prereq "HPC 1 & 3; PROF ELECT 1 & 7" (multiple / source typo — "PROF ELECT 7" doesn't exist in this program) — left null.
            ['code' => 'PROF-ELECT5-BSHM', 'title' => 'Catering Management',                           'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => null],

            // PRACTICUM/OJT: Hospitality practicum phases. Prereqs are textual ("All 2nd Year HPC
            // Subjects" / "All HPC Subjects") — left null. is_practicum intentionally omitted (see header note).
            ['code' => 'PRACTICUM1-BSHM', 'title' => 'Phase I - Housekeeping/Food and Beverage Operations/Passenger Handling and Assisting 1 (300 hours)', 'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Any', 'prereq' => null],
            ['code' => 'PRACTICUM2-BSHM', 'title' => 'Phase II - Hotel Phase (600 hours)',                                                                  'lec' => 6, 'lab' => 0, 'units' => 6, 'major' => true, 'room' => 'Any', 'prereq' => null],
        ];
    }

    /** BSIT-only major subjects. */
    private function bsitOnly(): array
    {
        return [
            ['code' => 'CC101',  'title' => 'Introduction to Computing',                          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'CC102',  'title' => 'Computer Programming 1',                              'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'CC103',  'title' => 'Computer Programming 2',                              'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC102'],
            ['code' => 'HCI101', 'title' => 'Introduction to Human Computer Interaction',          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC102'],

            // Bridging courses for non-ICT SHS graduates. Units bumped to 3 (matching
            // every other lec=2/lab=3 subject in this catalog, e.g. CC101/CC102).
            ['code' => 'NON-ICT1', 'title' => 'Introduction to Computer Systems Servicing',  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'NON-ICT2', 'title' => 'Installing and Configuring Computer Systems', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'NON-ICT1'],

            ['code' => 'CC104', 'title' => 'Data Structures and Algorithms',        'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC103'],
            ['code' => 'CC105', 'title' => 'Information Management',                'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC103'],
            ['code' => 'PF101', 'title' => 'Object-Oriented Programming',           'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC103'],
            ['code' => 'PT101', 'title' => 'Platform Technologies',                 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC103'],
            ['code' => 'MS101', 'title' => 'Discrete Mathematics',                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => 'MMW'],

            ['code' => 'IM101', 'title' => 'Advanced Database Systems',             'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC105'],
            ['code' => 'WS101', 'title' => 'Web Systems and Technologies',          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'HCI101'],
            ['code' => 'NET101','title' => 'Networking 1',                          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'PT101'],
            // Prereq "PF 101; PT 101" (multiple) — left null.
            ['code' => 'IPT101','title' => 'Integrative Programming and Technologies', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'MS102', 'title' => 'Quantitative Methods',                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => 'MS101'],

            ['code' => 'CC106', 'title' => 'Application Development and Emerging Technologies', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'IM101'],
            ['code' => 'NET102','title' => 'Networking 2',                          'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'NET101'],
            ['code' => 'SIA101','title' => 'Systems Integration and Architecture',  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'IPT101'],
            ['code' => 'IAS101','title' => 'Information Assurance and Security 1',  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'SIA101'],
            // Prereq "IPT 101; CC 106" (multiple) — left null.
            ['code' => 'CAP101','title' => 'Capstone Project and Research 1',       'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],

            ['code' => 'IAS102','title' => 'Information Assurance and Security 2',  'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'IAS101'],
            ['code' => 'SA101', 'title' => 'Systems Administration and Maintenance','lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'IAS101'],
            ['code' => 'CAP102','title' => 'Capstone Project and Research 2',       'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CAP101'],
            // Prereq "4th Year Standing" (textual) — left null.
            ['code' => 'SP101', 'title' => 'Social and Professional Issues',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],

            // PRACTICUM/OJT. Prereq "IAS 101; CC 106" (multiple) — left null. is_practicum
            // intentionally omitted (see header note).
            ['code' => 'PRAC101', 'title' => 'Practicum', 'lec' => 6, 'lab' => 0, 'units' => 6, 'major' => true, 'room' => 'Any', 'prereq' => null],

            // Recommended electives: Computer-Aided Design, Graphic Design, Film/Video Production,
            // Digital Animation — the PDF lists these as generic "Elective 1-4" slots.
            ['code' => 'IT-ELECT1', 'title' => 'Elective 1', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'IT-ELECT2', 'title' => 'Elective 2', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => 'CC102'],
            ['code' => 'IT-ELECT3', 'title' => 'Elective 3', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
            ['code' => 'IT-ELECT4', 'title' => 'Elective 4', 'lec' => 2, 'lab' => 3, 'units' => 3, 'major' => true, 'room' => 'Computer Laboratory', 'prereq' => null],
        ];
    }

    /** BSED (English major)-only subjects. */
    private function bsedOnly(): array
    {
        return [
            ['code' => 'EDUC1', 'title' => 'The Child and Adolescent Learners and Learning Principles', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM1',   'title' => 'Introduction to Linguistics',                'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM2',   'title' => 'Language, Culture and Society',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM1'],
            ['code' => 'EM3',   'title' => 'Structure of English',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM1'],
            // Cognate/Elective course, not a core Major or GE subject per the unit summary.
            ['code' => 'ELECT', 'title' => 'Stylistics and Discourse Analysis',          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],

            // Prereq "EM 2 & 3" (multiple) — left null.
            ['code' => 'EM4',   'title' => 'Principles and Theories of Language Acquisition and Learning', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM5',   'title' => 'Language Programs and Policies in Multilingual Societies', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM2'],
            // Prereq "EM 1 & 3" (multiple) — left null.
            ['code' => 'EM6',   'title' => 'Technical Writing',                           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC2', 'title' => 'The Teaching Profession',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // Prereq "EM 2 & 4" (multiple) — left null.
            ['code' => 'EM7',   'title' => 'Children and Adolescent Literature',          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM8',   'title' => 'Mythology and Folklore',                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM2'],
            ['code' => 'EM9',   'title' => 'Contemporary and Popular Literature',          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM2'],
            ['code' => 'EDUC3', 'title' => 'Facilitating Learner-Centered Teaching',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC4', 'title' => 'Technology for Teaching and Learning 1',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            ['code' => 'EM10',  'title' => 'Survey of Afro-Asian Literature',              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM7'],
            ['code' => 'EM11',  'title' => 'Survey of English and American Literature',    'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM7'],
            ['code' => 'EM12',  'title' => 'Survey of Philippine Literature in English',   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM7'],
            ['code' => 'EM13',  'title' => 'Language Learning Materials Development',      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM4'],
            ['code' => 'EDUC5', 'title' => 'Foundation of Special and Inclusive Education','lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC6', 'title' => 'Assessment of Learning 1',                     'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // BSED's own "Methods of Research" code — distinct from BSTM/BSHM's "RESEARCH".
            ['code' => 'RES',   'title' => 'Methods of Research',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM14',  'title' => 'Literary Criticism',                           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM7'],
            ['code' => 'EM15',  'title' => 'Speech and Theater Arts',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM3'],
            ['code' => 'EM16',  'title' => 'Campus Journalism',                            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM6'],
            ['code' => 'EM17',  'title' => 'Technology for Teaching and Learning 2 (Technology in Language Education)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM13'],
            ['code' => 'EDUC7', 'title' => 'The Teachers and the School Curriculum',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC8', 'title' => 'The Teacher and the Community, School Culture and Organization Leadership', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'COGNATE','title' => 'Remedial Instruction and Early Intervention for Language Difficulties', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => false, 'room' => 'Lecture', 'prereq' => null],

            ['code' => 'EM18',  'title' => 'Teaching and Assessment of Literature Studies', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM14'],
            ['code' => 'EM19',  'title' => 'Teaching and Assessment of Macro Skills',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM4'],
            // Prereq "EM 1 & 3" (multiple) — left null.
            ['code' => 'EM20',  'title' => 'Teaching and Assessment of Grammar',            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EM21',  'title' => 'Language Education Research',                   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'EM4'],
            ['code' => 'EDUC9', 'title' => 'Building and Enhancing New Literacies Across the Curriculum', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'EDUC10','title' => 'Assessment of Learning 2',                      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // Field Study / Practicum. Prereqs are textual ("All Professional & Major Subjects") —
            // left null. FS1/FS2 are classroom-adjacent observation subjects, not OJT proper.
            ['code' => 'FS1', 'title' => 'Observations of Teaching-Learning in Actual School Environment', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'FS2', 'title' => 'Participation and Teaching Assistantship',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // PRACTICUM/OJT: Teaching Internship. is_practicum intentionally omitted (see header note).
            ['code' => 'PRACTICUM', 'title' => 'Teaching Internship', 'lec' => 6, 'lab' => 0, 'units' => 6, 'major' => true, 'room' => 'Any', 'prereq' => null], // prereq FS1 & FS2 (multiple)
        ];
    }

    /**
     * Shared core across all four BSCRIM majors (Questioned Documents,
     * Fingerprint Identification, Firearms Identification, Lie
     * Detection). Verified identical titles/hours/units across all four
     * PDFs. Ignoring the FB/LD/QD/FI specialization labels per the
     * instructions — every BSCRIM lab subject below is conceptually a
     * BSCRIM specialized room; there is no `required_room_group` column
     * to encode that, so it's noted only in comments.
     */
    private function bscrimShared(): array
    {
        return [
            ['code' => 'LAW1',  'title' => 'Introduction to Philippine Criminal Justice System', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CRIM1', 'title' => 'Introduction to Criminology',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CFLM1', 'title' => 'Character Formation, Nationalism and Patriotism',      'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CRIM2', 'title' => 'Theories of Crime Causation',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'LEA1',  'title' => 'Law Enforcement Organization and Administration',      'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CDI1',  'title' => 'Fundamentals of Investigation and Intelligence',       'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            ['code' => 'LEA2',  'title' => 'Comparative Models in Policing',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "CRIM 1 & 2" (multiple) — left null.
            ['code' => 'CRIM3', 'title' => 'Human Behavior and Victimology',                        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CFLM2', 'title' => 'Character Formation with Leadership, Decision Making, Management and Administration', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'CFLM1'],
            // Prereq "CRIM 1 & 2" (multiple) — left null.
            ['code' => 'CRIM4', 'title' => 'Professional Conduct and Ethical Standards',            'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // BSCRIM specialized lab subject (Science Laboratory; conceptually a BSCRIM-only room group).
            ['code' => 'FORENSIC1', 'title' => 'Forensic Photography',                              'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],
            ['code' => 'CDI2',  'title' => 'Specialized Crime Investigation with Legal Medicine',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'CDI1'],
            ['code' => 'CHEM',  'title' => 'General Chemistry (Organic)',                            'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],

            ['code' => 'CORR1', 'title' => 'Institutional Corrections',                              'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'LAW2',  'title' => 'Human Rights Education',                                 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            ['code' => 'LAW3',  'title' => 'Criminal Law (Book 1)',                                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            // Prereq "CDI 1 & 2" (multiple) — left null.
            ['code' => 'CDI3',  'title' => 'Specialized Crime Investigation with Simulation on Interrogation and Interview', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CDI4',  'title' => 'Traffic Management and Accidental Investigation with Driving', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "CRIM 1 & 2" (multiple) — left null.
            ['code' => 'CRIM5', 'title' => 'Juvenile Delinquency and Juvenile Justice System',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'FORENSIC2', 'title' => 'Personal Identification Techniques',                  'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],

            ['code' => 'CORR2', 'title' => 'Non-Institutional Corrections',                           'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'CORR1'],
            // Prereq "LEA 1 & 2" (multiple) — left null.
            ['code' => 'LEA3',  'title' => 'Introduction to Industrial Security and Concepts',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'LAW4',  'title' => 'Criminal Law (Book 2)',                                    'lec' => 4, 'lab' => 0, 'units' => 4, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            ['code' => 'LAW5',  'title' => 'Evidence',                                                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            // Prereq "CDI 1 & 2" (multiple) — left null.
            ['code' => 'CDI5',  'title' => 'Technical English 1 (Technical Report Writing and Presentation)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            // Prereq "CRIM 1 & 2" (multiple) — left null.
            ['code' => 'RESEARCH1', 'title' => 'Criminological Research 1 (Research Methods with Applied Statistics)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],
            ['code' => 'CDI6',  'title' => 'Fire Protection and Arson Investigation',                  'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CDI 1 & 2 (multiple)
            ['code' => 'FORENSIC5', 'title' => 'Forensic Ballistics',                                  'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],
            // Used by QD and FI majors; skipped by FAI (own ballistics track) and LD.
            ['code' => 'FORENSIC6', 'title' => 'Lie Detection Techniques',                             'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],
            // Used by FI, FAI, and LD majors; skipped by QD (own questioned-documents track).
            ['code' => 'FORENSIC4', 'title' => 'Questioned Documents Examination',                     'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null],

            ['code' => 'CORR3', 'title' => 'Therapeutic Modalities',                                    'lec' => 2, 'lab' => 0, 'units' => 2, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CORR 1 & 2 (multiple)
            ['code' => 'LEA4',  'title' => 'Law Enforcement Operation and Planning with Crime Mapping', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq LEA 1 & 2 (multiple)
            ['code' => 'CDI7',  'title' => 'Vice and Drug Education and Control',                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CDI 1 & 2 (multiple)
            ['code' => 'CRIM6', 'title' => 'Dispute Resolution and Crisis/Incident Management',         'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CRIM 1 & 2 (multiple)
            ['code' => 'RESEARCH2', 'title' => 'Criminological Research 2 (Thesis Writing and Presentation)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CRIM 1 & 2 (multiple)
            // Prereq "4th Year Standing" (textual) — left null.
            ['code' => 'ENHANCE1', 'title' => 'Enhancement on CRIM, CORR and CDI', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null],

            // PRACTICUM/OJT: Criminology internship phases. Prereq for phase 1 is "4th Year Standing"
            // (textual, left null); phase 2 correctly chains to phase 1. is_practicum intentionally
            // omitted (see header note). Original PDF marks lab column as "FIELD" (off-campus hours),
            // preserved here as the literal lecture/unit figures from the table.
            ['code' => 'PRACTICUM1-BSCRIM', 'title' => 'Internship (On-the-Job Training 1)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => null],
            ['code' => 'PRACTICUM2-BSCRIM', 'title' => 'Internship (On-the-Job Training 2)', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Any', 'prereq' => 'PRACTICUM1-BSCRIM'],

            ['code' => 'LAW6',  'title' => 'Criminal Procedure and Court Testimony',                    'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'LAW1'],
            ['code' => 'CDI8',  'title' => 'Technical English 2 (Legal Forms)',                          'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => null], // prereq CDI 1 & 2 (multiple)
            ['code' => 'CDI9',  'title' => 'Introduction to Cybercrime and Environmental Laws Protection', 'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => null], // prereq CDI 1 & 2 (multiple)
            ['code' => 'ENHANCE2', 'title' => 'Enhancement on FORENSICS, LEA and CLJ', 'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture', 'prereq' => 'ENHANCE1'],
        ];
    }

    /** BSCRIM — Major in Questioned Documents Examination. */
    private function bscrimQuestionedDocuments(): array
    {
        return [
            ['code' => 'QD1', 'title' => 'Introduction to Questioned Documents Examination',       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => null],
            ['code' => 'QD2', 'title' => 'Basic Principles of Questioned Documents Examination',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD1'],
            ['code' => 'QD3', 'title' => 'Determine Factors in Questioned Documents Examination',  'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD2'],
            ['code' => 'QD4', 'title' => 'Tools and Instrument in Questioned Documents Examination','lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD3'],
            ['code' => 'QD5', 'title' => 'Typewriting, Counterfeiting and Alteration Detection',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD4'],
            ['code' => 'QD6', 'title' => 'Moot Court in Relation to Questioned Documents Cases',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'QD5'],
        ];
    }

    /** BSCRIM — Major in Fingerprint Identification. */
    private function bscrimFingerprint(): array
    {
        return [
            ['code' => 'FI1', 'title' => 'Introduction to Fingerprint Identification',        'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => null],
            ['code' => 'FI2', 'title' => 'Mechanics of Taking Legible Inked Fingerprint',      'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI1'],
            ['code' => 'FI3', 'title' => 'The Fingerprint Pattern and Their Interpretation',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI2'],
            ['code' => 'FI4', 'title' => 'The Latent Impression and Their Development Process','lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI3'],
            ['code' => 'FI5', 'title' => 'The Fingerprint Classification',                     'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI4'],
            ['code' => 'FI6', 'title' => 'Preparation for Court Testimony',                    'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FI5'],
        ];
    }

    /** BSCRIM — Major in Firearms Identification (Forensic Ballistics). */
    private function bscrimFirearms(): array
    {
        return [
            ['code' => 'FAI1', 'title' => 'Introduction to Firearms Identification',   'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => null],
            ['code' => 'FAI2', 'title' => 'Study of Small Firearms Identification',     'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI1'],
            ['code' => 'FAI3', 'title' => 'Techniques in Handling Physical Evidence',   'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI2'],
            ['code' => 'FAI4', 'title' => 'Principles in Firearms Identification',      'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI3'],
            ['code' => 'FAI5', 'title' => 'Theory on Ballistics Examination',           'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI4'],
            ['code' => 'FAI6', 'title' => 'Practical Exams on Bullet and Shells with the Bullet Comparison Microscope', 'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'FAI5'],
        ];
    }

    /** BSCRIM — Major in Lie Detection (Polygraph). */
    private function bscrimLieDetection(): array
    {
        return [
            ['code' => 'LD1', 'title' => 'Introduction to Lie Detection',                       'lec' => 3, 'lab' => 0, 'units' => 3, 'major' => true, 'room' => 'Lecture',            'prereq' => null],
            ['code' => 'LD2', 'title' => 'The Polygraph of Lying',                              'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD1'],
            ['code' => 'LD3', 'title' => 'The Polygraph and Its Operation (Hands-on)',          'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD2'],
            ['code' => 'LD4', 'title' => 'The Test Question Formulation and Construction',      'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD3'],
            ['code' => 'LD5', 'title' => 'The Legal Status of the Polygraph Technique',         'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD4'],
            ['code' => 'LD6', 'title' => 'Interrogation Tactics and Techniques and Other Considerations', 'lec' => 2, 'lab' => 1, 'units' => 3, 'major' => true, 'room' => 'Science Laboratory', 'prereq' => 'LD5'],
        ];
    }
}