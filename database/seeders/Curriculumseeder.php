<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Specialization;
use Illuminate\Database\Seeder;

class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        // Effective Year 2023 so Curriculum::curriculum_range computes
        // "2023-2027" for every 4-year Program here — matching the
        // A.Y. 2023-2027 span printed on PAP's actual prospectus
        // covers (see the BSED-English sample). academic_year is the
        // school year the curriculum was authorized/took effect in —
        // the first year of that span, not the whole range — same
        // convention Curriculums/Create.vue's generateFields() uses
        // (effective_year + "-" + (effective_year + 1)).
        $academicYear = '2023-2024';
        $effectiveYear = 2023;

        // Every Program here runs 4 years, so this matches
        // Curriculum::getCurriculumRangeAttribute()'s
        // "effective_year + duration" math: 2023 + 4 = 2027. Baked into
        // the code itself (e.g. "BSIT-2023-2027") per the "should the
        // code show the full span" conversation — kept as its own
        // variable rather than hardcoding "2023-2027" per line so it
        // stays correct if $effectiveYear ever changes here.
        $curriculumRange = $effectiveYear . '-' . ($effectiveYear + 4);

        $bsit = Program::where('code', 'BSIT')->firstOrFail();
        $bsed = Program::where('code', 'BSED')->firstOrFail();
        $bscrim = Program::where('code', 'BSCRIM')->firstOrFail();
        $bshm = Program::where('code', 'BSHM')->firstOrFail();
        $bstm = Program::where('code', 'BSTM')->firstOrFail();

        $english = Specialization::where('program_id', $bsed->id)->where('code', 'ENG')->firstOrFail();

        $qd = Specialization::where('program_id', $bscrim->id)->where('code', 'QD')->firstOrFail();
        $fi = Specialization::where('program_id', $bscrim->id)->where('code', 'FI')->firstOrFail();
        $fb = Specialization::where('program_id', $bscrim->id)->where('code', 'FB')->firstOrFail();
        $ld = Specialization::where('program_id', $bscrim->id)->where('code', 'LD')->firstOrFail();

        $curricula = [
            [
                'program_id' => $bsit->id,
                'specialization_id' => null,
                'code' => 'BSIT-' . $curriculumRange,
                'name' => 'BS Information Technology Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bsed->id,
                'specialization_id' => $english->id,
                'code' => 'BSED-ENG-' . $curriculumRange,
                'name' => 'BSED English Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'specialization_id' => $qd->id,
                'code' => 'BSCRIM-QD-' . $curriculumRange,
                'name' => 'BS Criminology (Questioned Documents Examination) Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'specialization_id' => $fi->id,
                'code' => 'BSCRIM-FI-' . $curriculumRange,
                'name' => 'BS Criminology (Fingerprint Identification) Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'specialization_id' => $fb->id,
                'code' => 'BSCRIM-FB-' . $curriculumRange,
                'name' => 'BS Criminology (Firearms Identification) Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'specialization_id' => $ld->id,
                'code' => 'BSCRIM-LD-' . $curriculumRange,
                'name' => 'BS Criminology (Lie Detection) Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bshm->id,
                'specialization_id' => null,
                'code' => 'BSHM-' . $curriculumRange,
                'name' => 'BS Hospitality Management Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bstm->id,
                'specialization_id' => null,
                'code' => 'BSTM-' . $curriculumRange,
                'name' => 'BS Tourism Management Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
        ];

        foreach ($curricula as $curriculum) {
            Curriculum::updateOrCreate(
                ['code' => $curriculum['code']],
                $curriculum
            );
        }
    }
}