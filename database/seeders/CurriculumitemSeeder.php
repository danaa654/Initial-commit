<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * CurriculumItemSeeder
 * ---------------------------------------------------------------------
 * Populates `curriculum_items` (the per-curriculum prospectus) from the
 * official PAP prospectus PDFs, matched against the subject_codes
 * already created by SubjectSeeder and the curriculum codes already
 * created by CurriculumSeeder.
 *
 * All 8 curricula seeded:
 *   1. BSIT
 *   2. BSHM
 *   3. BSTM
 *   4. BSED English
 *   5. BSCRIM - Firearms Identification (curriculum code: BSCRIM-FB-2023-2027)
 *   6. BSCRIM - Fingerprint Identification
 *   7. BSCRIM - Lie Detection
 *   8. BSCRIM - Questioned Documents Examination
 *
 * Every subject_code referenced below was verified against SubjectSeeder
 * before being added — nothing here was invented. No subjects were found
 * missing for any of the 8 curricula.
 *
 * SORT ORDER: spaced in steps of 10 within each (year_level, semester)
 * group, in the exact left-to-right / top-to-bottom order the subject
 * appears in its prospectus column — matching the spacing convention
 * already used by CurriculumItemController::storeSubjects().
 *
 * OJT ITEMS: item_type is CurriculumItem::TYPE_OJT and subject_id points
 * at the Practicum entry in the Subjects master list (Practicum/OJT
 * items carry a real subject_id, not free text — see CurriculumItem.php).
 * `ojt_hours` is filled in ONLY where the prospectus PDF explicitly
 * prints an hour figure (BSTM/BSHM's "(300 hours)" / "(600 hours)"
 * phase labels). Where the PDF only prints a unit count with no hour
 * figure — BSIT's PRAC101, BSED's PRACTICUM, and all four BSCRIM majors'
 * PRACTICUM1-BSCRIM / PRACTICUM2-BSCRIM — ojt_hours is left null rather
 * than guessed, per instruction. Update these once the department
 * confirms the official required hours.
 *
 * Run with: php artisan db:seed --class=CurriculumItemSeeder
 * Safe to re-run: uses CurriculumItem::updateOrCreate() keyed on
 * (curriculum_id, subject_id), so it won't create duplicate rows.
 */
class CurriculumItemSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBsit();
        $this->seedBshm();
        $this->seedBstm();
        $this->seedBsedEnglish();
        $this->seedBscrimFirearms();
        $this->seedBscrimFingerprint();
        $this->seedBscrimLieDetection();
        $this->seedBscrimQuestionedDocuments();
    }

    /**
     * Attaches every [subject_code, year_level, semester, sort_order]
     * tuple in $items to $curriculum as a Subject-type curriculum item.
     * Shared by every seedXxx() method below.
     */
    private function attachSubjects(Curriculum $curriculum, array $items): void
    {
        foreach ($items as [$code, $year, $semester, $sortOrder]) {
            $subject = Subject::where('subject_code', $code)->firstOrFail();

            CurriculumItem::updateOrCreate(
                [
                    'curriculum_id' => $curriculum->id,
                    'subject_id' => $subject->id,
                ],
                [
                    'item_type' => CurriculumItem::TYPE_SUBJECT,
                    'year_level' => $year,
                    'semester' => $semester,
                    'sort_order' => $sortOrder,
                    'active' => true,
                ]
            );
        }
    }

    /**
     * Attaches a single Practicum/OJT curriculum item to $curriculum.
     */
    private function attachOjt(
        Curriculum $curriculum,
        string $subjectCode,
        int $year,
        int $semester,
        int $sortOrder,
        ?int $ojtHours
    ): void {
        $subject = Subject::where('subject_code', $subjectCode)->firstOrFail();

        CurriculumItem::updateOrCreate(
            [
                'curriculum_id' => $curriculum->id,
                'subject_id' => $subject->id,
            ],
            [
                'item_type' => CurriculumItem::TYPE_OJT,
                'ojt_hours' => $ojtHours,
                'year_level' => $year,
                'semester' => $semester,
                'sort_order' => $sortOrder,
                'active' => true,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 1. BSIT — BS Information Technology
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)"
    | CMO No. 25 S. 2015, A.Y. 2023-2027 prospectus PDF.
    | Curriculum code: BSIT-2023-2027 (per CurriculumSeeder).
    |
    */
    private function seedBsit(): void
    {
        $curriculum = Curriculum::where('code', 'BSIT-2023-2027')->firstOrFail();

        $this->attachSubjects($curriculum, [
            // First Year - First Semester
            ['UTS', 1, 1, 10],
            ['MMW', 1, 1, 20],
            ['GENSOC', 1, 1, 30],
            ['CC101', 1, 1, 40],
            ['CC102', 1, 1, 50],
            ['IT-ELECT1', 1, 1, 60],
            ['NSTP1', 1, 1, 70],
            ['PATHFIT1', 1, 1, 80],
            ['NON-ICT1', 1, 1, 90],

            // First Year - Second Semester
            ['READINGS', 1, 2, 10],
            ['PCOM', 1, 2, 20],
            ['ITE', 1, 2, 30],
            ['CC103', 1, 2, 40],
            ['HCI101', 1, 2, 50],
            ['IT-ELECT2', 1, 2, 60],
            ['NSTP2', 1, 2, 70],
            ['PATHFIT2', 1, 2, 80],
            ['NON-ICT2', 1, 2, 90],

            // Second Year - First Semester
            ['TCW', 2, 1, 10],
            ['ART', 2, 1, 20],
            ['PPC', 2, 1, 30],
            ['CC104', 2, 1, 40],
            ['CC105', 2, 1, 50],
            ['PF101', 2, 1, 60],
            ['PT101', 2, 1, 70],
            ['MS101', 2, 1, 80],
            ['PATHFIT3', 2, 1, 90],

            // Second Year - Second Semester
            ['STS', 2, 2, 10],
            ['ETHICS', 2, 2, 20],
            ['PIC', 2, 2, 30],
            ['IM101', 2, 2, 40],
            ['WS101', 2, 2, 50],
            ['NET101', 2, 2, 60],
            ['IPT101', 2, 2, 70],
            ['MS102', 2, 2, 80],
            ['PATHFIT4', 2, 2, 90],

            // Third Year - First Semester
            ['CC106', 3, 1, 10],
            ['NET102', 3, 1, 20],
            ['SIA101', 3, 1, 30],
            ['IT-ELECT3', 3, 1, 40],

            // Third Year - Second Semester
            ['IAS101', 3, 2, 10],
            ['CAP101', 3, 2, 20],
            ['IT-ELECT4', 3, 2, 30],
            ['RIZAL', 3, 2, 40],

            // Fourth Year - First Semester
            ['IAS102', 4, 1, 10],
            ['SA101', 4, 1, 20],
            ['CAP102', 4, 1, 30],
            ['SP101', 4, 1, 40],
        ]);

        // Fourth Year - Second Semester: Practicum (OJT)
        // No explicit hour figure printed in the prospectus (just "6" units) —
        // ojt_hours left null. See class docblock.
        $this->attachOjt($curriculum, 'PRAC101', 4, 2, 10, null);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. BSHM — BS Hospitality Management
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)"
    | CMO No. 62 S. 2017, A.Y. 2023-2027 prospectus PDF.
    | Curriculum code: BSHM-2023-2027 (per CurriculumSeeder).
    |
    | Note: the prospectus places GENSOC and ITE in Third Year - First
    | Semester (not First/Second Year) — preserved exactly as printed.
    | Second Year - Summer uses CurriculumItem::SEMESTER_SUMMER (3).
    |
    */
    private function seedBshm(): void
    {
        $curriculum = Curriculum::where('code', 'BSHM-2023-2027')->firstOrFail();

        $this->attachSubjects($curriculum, [
            // First Year - First Semester
            ['UTS', 1, 1, 10],
            ['MMW', 1, 1, 20],
            ['THC1', 1, 1, 30],
            ['HPC1', 1, 1, 40],
            ['HPC2', 1, 1, 50],
            ['FLT1', 1, 1, 60],
            ['PATHFIT1', 1, 1, 70],
            ['NSTP1', 1, 1, 80],

            // First Year - Second Semester
            ['READINGS', 1, 2, 10],
            ['PCOM', 1, 2, 20],
            ['BC1', 1, 2, 30],
            ['THC3', 1, 2, 40],
            ['THC4', 1, 2, 50],
            ['HPC3', 1, 2, 60],
            ['FLT2', 1, 2, 70],
            ['PATHFIT2', 1, 2, 80],
            ['NSTP2', 1, 2, 90],
            ['NON-ABM1', 1, 2, 100],

            // Second Year - First Semester
            ['TCW', 2, 1, 10],
            ['ART', 2, 1, 20],
            ['THC5', 2, 1, 30],
            ['PROF-ELECT1-BSHM', 2, 1, 40],
            ['HPC4', 2, 1, 50],
            ['HPC5', 2, 1, 60],
            ['FLT3', 2, 1, 70],
            ['FLT4', 2, 1, 80],
            ['PATHFIT3', 2, 1, 90],
            ['NON-ABM2', 2, 1, 100],

            // Second Year - Second Semester
            ['STS', 2, 2, 10],
            ['ETHICS', 2, 2, 20],
            ['BC2', 2, 2, 30],
            ['PROF-ELECT2-BSHM', 2, 2, 40],
            ['FLT5', 2, 2, 50],
            ['THC6', 2, 2, 60],
            ['THC7', 2, 2, 70],
            ['HPC6', 2, 2, 80],
            ['PATHFIT4', 2, 2, 90],
            ['NON-ABM3', 2, 2, 100],

            // Third Year - First Semester
            ['GENSOC', 3, 1, 10],
            ['ITE', 3, 1, 20],
            ['THC8', 3, 1, 30],
            ['THC9', 3, 1, 40],
            ['HPC7', 3, 1, 50],
            ['HPC8', 3, 1, 60],
            ['NON-ABM4', 3, 1, 70],

            // Third Year - Second Semester
            ['PPC', 3, 2, 10],
            ['THC10', 3, 2, 20],
            ['HPC9', 3, 2, 30],
            ['PROF-ELECT3-BSHM', 3, 2, 40],
            ['RESEARCH', 3, 2, 50],
            ['NON-ABM5', 3, 2, 60],

            // Fourth Year - First Semester
            ['PIC', 4, 1, 10],
            ['HPC10', 4, 1, 20],
            ['PROF-ELECT4-BSHM', 4, 1, 30],
            ['PROF-ELECT5-BSHM', 4, 1, 40],
            ['RIZAL', 4, 1, 50],
        ]);

        // Second Year - Summer: Practicum Phase I. Prospectus explicitly
        // prints "(300 hours)".
        $this->attachOjt($curriculum, 'PRACTICUM1-BSHM', 2, CurriculumItem::SEMESTER_SUMMER, 10, 300);

        // Fourth Year - Second Semester: Practicum Phase II. Prospectus
        // explicitly prints "(600 hours)".
        $this->attachOjt($curriculum, 'PRACTICUM2-BSHM', 4, 2, 10, 600);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. BSTM — BS Tourism Management
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)"
    | CMO No. 62 S. 2017, A.Y. 2023-2027 prospectus PDF.
    | Curriculum code: BSTM-2023-2027 (per CurriculumSeeder).
    |
    | Same GENSOC/ITE-in-Third-Year pattern as BSHM — preserved as printed.
    | Second Year - Summer uses CurriculumItem::SEMESTER_SUMMER (3).
    |
    */
    private function seedBstm(): void
    {
        $curriculum = Curriculum::where('code', 'BSTM-2023-2027')->firstOrFail();

        $this->attachSubjects($curriculum, [
            // First Year - First Semester
            ['UTS', 1, 1, 10],
            ['MMW', 1, 1, 20],
            ['THC1', 1, 1, 30],
            ['THC2', 1, 1, 40],
            ['TPC1', 1, 1, 50],
            ['FLT1', 1, 1, 60],
            ['PATHFIT1', 1, 1, 70],
            ['NSTP1', 1, 1, 80],

            // First Year - Second Semester
            ['READINGS', 1, 2, 10],
            ['PCOM', 1, 2, 20],
            ['BC1', 1, 2, 30],
            ['THC3', 1, 2, 40],
            ['TPC2', 1, 2, 50],
            ['FLT2', 1, 2, 60],
            ['PATHFIT2', 1, 2, 70],
            ['NSTP2', 1, 2, 80],
            ['NON-ABM1', 1, 2, 90],

            // Second Year - First Semester
            ['TCW', 2, 1, 10],
            ['ART', 2, 1, 20],
            ['THC5', 2, 1, 30],
            ['PROF-ELECT1-BSTM', 2, 1, 40],
            ['TPC4', 2, 1, 50],
            ['TPC5', 2, 1, 60],
            ['FLT3', 2, 1, 70],
            ['FLT4', 2, 1, 80],
            ['PATHFIT3', 2, 1, 90],
            ['NON-ABM2', 2, 1, 100],

            // Second Year - Second Semester
            ['STS', 2, 2, 10],
            ['ETHICS', 2, 2, 20],
            ['BC2', 2, 2, 30],
            ['PROF-ELECT2-BSTM', 2, 2, 40],
            ['FLT5', 2, 2, 50],
            ['THC6', 2, 2, 60],
            ['TPC6', 2, 2, 70],
            ['PATHFIT4', 2, 2, 80],
            ['NON-ABM3', 2, 2, 90],

            // Third Year - First Semester
            ['GENSOC', 3, 1, 10],
            ['ITE', 3, 1, 20],
            ['THC8', 3, 1, 30],
            ['THC9', 3, 1, 40],
            ['TPC7', 3, 1, 50],
            ['TPC8', 3, 1, 60],
            ['NON-ABM4', 3, 1, 70],

            // Third Year - Second Semester
            ['PPC', 3, 2, 10],
            ['THC10', 3, 2, 20],
            ['TPC9', 3, 2, 30],
            ['PROF-ELECT3-BSTM', 3, 2, 40],
            ['RESEARCH', 3, 2, 50],
            ['NON-ABM5', 3, 2, 60],

            // Fourth Year - First Semester
            ['PIC', 4, 1, 10],
            ['TPC10', 4, 1, 20],
            ['PROF-ELECT4-BSTM', 4, 1, 30],
            ['PROF-ELECT5-BSTM', 4, 1, 40],
            ['RIZAL', 4, 1, 50],
        ]);

        // Second Year - Summer: Practicum Phase I. Prospectus explicitly
        // prints "(300 hours)".
        $this->attachOjt($curriculum, 'PRACTICUM1-BSTM', 2, CurriculumItem::SEMESTER_SUMMER, 10, 300);

        // Fourth Year - Second Semester: Practicum Phase II. Prospectus
        // explicitly prints "(600 hours)".
        $this->attachOjt($curriculum, 'PRACTICUM2-BSTM', 4, 2, 10, 600);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. BSED — Major in English
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SECONDARY EDUCATION (BSED), Major in English"
    | CMO No. 75 S. 2017, A.Y. 2023-2027 prospectus PDF.
    | Curriculum code: BSED-ENG-2023-2027 (per CurriculumSeeder).
    |
    */
    private function seedBsedEnglish(): void
    {
        $curriculum = Curriculum::where('code', 'BSED-ENG-2023-2027')->firstOrFail();

        $this->attachSubjects($curriculum, [
            // First Year - First Semester
            ['UTS', 1, 1, 10],
            ['MMW', 1, 1, 20],
            ['GENSOC', 1, 1, 30],
            ['EDUC1', 1, 1, 40],
            ['EM1', 1, 1, 50],
            ['EM2', 1, 1, 60],
            ['EM3', 1, 1, 70],
            ['PATHFIT1', 1, 1, 80],
            ['ELECT', 1, 1, 90],
            ['NSTP1', 1, 1, 100],

            // First Year - Second Semester
            ['READINGS', 1, 2, 10],
            ['PCOM', 1, 2, 20],
            ['ITE', 1, 2, 30],
            ['EM4', 1, 2, 40],
            ['EM5', 1, 2, 50],
            ['EM6', 1, 2, 60],
            ['EDUC2', 1, 2, 70],
            ['PATHFIT2', 1, 2, 80],
            ['NSTP2', 1, 2, 90],

            // Second Year - First Semester
            ['TCW', 2, 1, 10],
            ['ART', 2, 1, 20],
            ['PPC', 2, 1, 30],
            ['EM7', 2, 1, 40],
            ['EM8', 2, 1, 50],
            ['EM9', 2, 1, 60],
            ['EDUC3', 2, 1, 70],
            ['EDUC4', 2, 1, 80],
            ['PATHFIT3', 2, 1, 90],

            // Second Year - Second Semester
            ['STS', 2, 2, 10],
            ['ETHICS', 2, 2, 20],
            ['PIC', 2, 2, 30],
            ['EM10', 2, 2, 40],
            ['EM11', 2, 2, 50],
            ['EM12', 2, 2, 60],
            ['EM13', 2, 2, 70],
            ['EDUC5', 2, 2, 80],
            ['EDUC6', 2, 2, 90],
            ['PATHFIT4', 2, 2, 100],

            // Third Year - First Semester
            ['RES', 3, 1, 10],
            ['EM14', 3, 1, 20],
            ['EM15', 3, 1, 30],
            ['EM16', 3, 1, 40],
            ['EM17', 3, 1, 50],
            ['EDUC7', 3, 1, 60],
            ['EDUC8', 3, 1, 70],
            ['COGNATE', 3, 1, 80],

            // Third Year - Second Semester
            ['RIZAL', 3, 2, 10],
            ['EM18', 3, 2, 20],
            ['EM19', 3, 2, 30],
            ['EM20', 3, 2, 40],
            ['EM21', 3, 2, 50],
            ['EDUC9', 3, 2, 60],
            ['EDUC10', 3, 2, 70],

            // Fourth Year - First Semester
            ['FS1', 4, 1, 10],
            ['FS2', 4, 1, 20],
        ]);

        // Fourth Year - Second Semester: Teaching Internship (OJT)
        // No explicit hour figure printed in the prospectus (just "6" units) —
        // ojt_hours left null. See class docblock.
        $this->attachOjt($curriculum, 'PRACTICUM', 4, 2, 10, null);
    }

    /*
    |--------------------------------------------------------------------------
    | BSCRIM — shared item list builder
    |--------------------------------------------------------------------------
    |
    | All four BSCRIM majors (Firearms, Fingerprint, Lie Detection,
    | Questioned Documents) share an identical GE/Professional/Major-core
    | skeleton and only differ in:
    |   - the six major-specific subject codes dropped into Year 1-2, and
    |   - which two of {FORENSIC2, FORENSIC4, FORENSIC5, FORENSIC6} round
    |     out Third Year (each major skips whichever forensic elective
    |     overlaps its own specialization track).
    | This mirrors SubjectSeeder::bscrimShared()'s own "shared core, majors
    | plug in their own codes" structure, so the four seedBscrimXxx()
    | methods below just supply those six codes plus the two Y3S1/Y3S2
    | forensic electives — verified against each program's own prospectus
    | PDF rather than assumed identical.
    |
    * @param string $major1..$major6  This major's own 6 specialization codes,
    *                                  in prospectus order (Y1S1, Y1S2, Y2S1 x2, Y2S2 x2).
    * @param string $forensicY3S1     The Forensic elective in Year 3 - Sem 1.
    * @param string $forensicY3S2a    First Forensic elective in Year 3 - Sem 2.
    * @param string $forensicY3S2b    Second Forensic elective in Year 3 - Sem 2.
    */
    private function bscrimSharedItems(
        string $major1,
        string $major2,
        string $major3,
        string $major4,
        string $major5,
        string $major6,
        string $forensicY3S1,
        string $forensicY3S2a,
        string $forensicY3S2b
    ): array {
        return [
            // First Year - First Semester
            ['READINGS', 1, 1, 10],
            ['PCOM', 1, 1, 20],
            ['ITE', 1, 1, 30],
            ['LAW1', 1, 1, 40],
            ['CRIM1', 1, 1, 50],
            [$major1, 1, 1, 60],
            ['PATHFIT1', 1, 1, 70],
            ['CFLM1', 1, 1, 80],
            ['NSTP1', 1, 1, 90],

            // First Year - Second Semester
            ['UTS', 1, 2, 10],
            ['MMW', 1, 2, 20],
            ['GENSOC', 1, 2, 30],
            ['PPC', 1, 2, 40],
            ['CRIM2', 1, 2, 50],
            ['LEA1', 1, 2, 60],
            [$major2, 1, 2, 70],
            ['PATHFIT2', 1, 2, 80],
            ['NSTP2', 1, 2, 90],

            // Second Year - First Semester
            ['STS', 2, 1, 10],
            ['ETHICS', 2, 1, 20],
            ['PIC', 2, 1, 30],
            ['CDI1', 2, 1, 40],
            ['LEA2', 2, 1, 50],
            ['CRIM3', 2, 1, 60],
            [$major3, 2, 1, 70],
            [$major4, 2, 1, 80],
            ['PATHFIT3', 2, 1, 90],

            // Second Year - Second Semester
            ['TCW', 2, 2, 10],
            ['ART', 2, 2, 20],
            ['CFLM2', 2, 2, 30],
            ['CRIM4', 2, 2, 40],
            ['FORENSIC1', 2, 2, 50],
            ['CDI2', 2, 2, 60],
            ['CHEM', 2, 2, 70],
            [$major5, 2, 2, 80],
            [$major6, 2, 2, 90],
            ['PATHFIT4', 2, 2, 100],

            // Third Year - First Semester
            ['CORR1', 3, 1, 10],
            ['LAW2', 3, 1, 20],
            ['LAW3', 3, 1, 30],
            ['CDI3', 3, 1, 40],
            ['CDI4', 3, 1, 50],
            ['CRIM5', 3, 1, 60],
            [$forensicY3S1, 3, 1, 70],

            // Third Year - Second Semester
            ['CORR2', 3, 2, 10],
            ['LEA3', 3, 2, 20],
            ['LAW4', 3, 2, 30],
            ['LAW5', 3, 2, 40],
            ['CDI5', 3, 2, 50],
            ['RESEARCH1', 3, 2, 60],
            ['CDI6', 3, 2, 70],
            [$forensicY3S2a, 3, 2, 80],
            [$forensicY3S2b, 3, 2, 90],

            // Fourth Year - First Semester
            ['CORR3', 4, 1, 20],
            ['LEA4', 4, 1, 30],
            ['CDI7', 4, 1, 40],
            ['CRIM6', 4, 1, 50],
            ['RESEARCH2', 4, 1, 60],
            ['ENHANCE1', 4, 1, 70],

            // Fourth Year - Second Semester
            ['LAW6', 4, 2, 20],
            ['RIZAL', 4, 2, 30],
            ['CDI8', 4, 2, 40],
            ['CDI9', 4, 2, 50],
            ['ENHANCE2', 4, 2, 60],
        ];
    }

    /**
     * Attaches the two BSCRIM Practicum/OJT items (Y4S1 and Y4S2), which
     * are identical in placement/sort_order across all four majors and
     * share the same underlying PRACTICUM1-BSCRIM / PRACTICUM2-BSCRIM
     * subjects. No prospectus in this set prints an explicit hour figure
     * for these (just "3 FIELD 3" units) — ojt_hours left null.
     */
    private function attachBscrimPracticum(Curriculum $curriculum): void
    {
        $this->attachOjt($curriculum, 'PRACTICUM1-BSCRIM', 4, 1, 10, null);
        $this->attachOjt($curriculum, 'PRACTICUM2-BSCRIM', 4, 2, 10, null);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. BSCRIM — Major in Firearms Identification (Forensic Ballistics)
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM), Major in
    | Firearms Identification" CMO No. 05 S. 2018, A.Y. 2023-2027 PDF.
    | Curriculum code: BSCRIM-FB-2023-2027 (per CurriculumSeeder — note the
    | curriculum uses specialization code "FB" while the subject catalog
    | uses prefix "FAI"; both are correct per their respective seeders).
    |
    | Third Year electives per this program's own prospectus:
    | Y3S1 -> FORENSIC2 (Personal Identification Techniques)
    | Y3S2 -> FORENSIC4 (Questioned Documents Examination) + FORENSIC6
    |         (Lie Detection Techniques) — FAI skips FORENSIC5 (Forensic
    |         Ballistics) since that's its own major's entire focus.
    |
    */
    private function seedBscrimFirearms(): void
    {
        $curriculum = Curriculum::where('code', 'BSCRIM-FB-2023-2027')->firstOrFail();

        $items = $this->bscrimSharedItems(
            'FAI1', 'FAI2', 'FAI3', 'FAI4', 'FAI5', 'FAI6',
            'FORENSIC2', 'FORENSIC4', 'FORENSIC6'
        );

        // Fourth Year - First/Second Semester open with Practicum in the
        // prospectus (sort_order 10); the shared list above starts its
        // Y4 rows at 20 to leave that slot free.
        $this->attachSubjects($curriculum, $items);
        $this->attachBscrimPracticum($curriculum);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. BSCRIM — Major in Fingerprint Identification
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM), Major in
    | Fingerprint Identification" CMO No. 05 S. 2018, A.Y. 2023-2027 PDF.
    | Curriculum code: BSCRIM-FI-2023-2027 (per CurriculumSeeder).
    |
    | Third Year electives per this program's own prospectus:
    | Y3S1 -> FORENSIC4 (Questioned Documents Examination)
    | Y3S2 -> FORENSIC5 (Forensic Ballistics) + FORENSIC6 (Lie Detection
    |         Techniques) — FI skips FORENSIC2 (Personal Identification
    |         Techniques) entirely; not printed in this program's PDF.
    |
    */
    private function seedBscrimFingerprint(): void
    {
        $curriculum = Curriculum::where('code', 'BSCRIM-FI-2023-2027')->firstOrFail();

        $items = $this->bscrimSharedItems(
            'FI1', 'FI2', 'FI3', 'FI4', 'FI5', 'FI6',
            'FORENSIC4', 'FORENSIC5', 'FORENSIC6'
        );

        $this->attachSubjects($curriculum, $items);
        $this->attachBscrimPracticum($curriculum);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. BSCRIM — Major in Lie Detection (Polygraph)
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM), Major in Lie
    | Detection" CMO No. 05 S. 2018, A.Y. 2023-2027 PDF.
    | Curriculum code: BSCRIM-LD-2023-2027 (per CurriculumSeeder).
    |
    | Third Year electives per this program's own prospectus:
    | Y3S1 -> FORENSIC2 (Personal Identification Techniques)
    | Y3S2 -> FORENSIC4 (Questioned Documents Examination) + FORENSIC5
    |         (Forensic Ballistics) — LD skips FORENSIC6 (Lie Detection
    |         Techniques) since that's its own major's entire focus.
    |
    */
    private function seedBscrimLieDetection(): void
    {
        $curriculum = Curriculum::where('code', 'BSCRIM-LD-2023-2027')->firstOrFail();

        $items = $this->bscrimSharedItems(
            'LD1', 'LD2', 'LD3', 'LD4', 'LD5', 'LD6',
            'FORENSIC2', 'FORENSIC4', 'FORENSIC5'
        );

        $this->attachSubjects($curriculum, $items);
        $this->attachBscrimPracticum($curriculum);
    }

    /*
    |--------------------------------------------------------------------------
    | 8. BSCRIM — Major in Questioned Documents Examination
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM), Major in
    | Questioned Documents Examination" CMO No. 05 S. 2018, A.Y. 2023-2027
    | PDF. Curriculum code: BSCRIM-QD-2023-2027 (per CurriculumSeeder).
    |
    | Third Year electives per this program's own prospectus:
    | Y3S1 -> FORENSIC2 (Personal Identification Techniques)
    | Y3S2 -> FORENSIC5 (Forensic Ballistics) + FORENSIC6 (Lie Detection
    |         Techniques) — QD skips FORENSIC4 (Questioned Documents
    |         Examination) since that's its own major's entire focus.
    |
    */
    private function seedBscrimQuestionedDocuments(): void
    {
        $curriculum = Curriculum::where('code', 'BSCRIM-QD-2023-2027')->firstOrFail();

        $items = $this->bscrimSharedItems(
            'QD1', 'QD2', 'QD3', 'QD4', 'QD5', 'QD6',
            'FORENSIC2', 'FORENSIC5', 'FORENSIC6'
        );

        $this->attachSubjects($curriculum, $items);
        $this->attachBscrimPracticum($curriculum);
    }
}