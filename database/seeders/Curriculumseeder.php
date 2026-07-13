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
        $academicYear = '2026-2027';
        $effectiveYear = 2026;

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
                'code' => 'BSIT-' . $effectiveYear,
                'name' => 'BS Information Technology Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bsed->id,
                'specialization_id' => $english->id,
                'code' => 'BSED-ENG-' . $effectiveYear,
                'name' => 'BSED English Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'specialization_id' => $qd->id,
                'code' => 'BSCRIM-QD-' . $effectiveYear,
                'name' => 'BS Criminology (Questioned Documents Examination) Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'specialization_id' => $fi->id,
                'code' => 'BSCRIM-FI-' . $effectiveYear,
                'name' => 'BS Criminology (Fingerprint Identification) Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'specialization_id' => $fb->id,
                'code' => 'BSCRIM-FB-' . $effectiveYear,
                'name' => 'BS Criminology (Firearms Identification) Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'specialization_id' => $ld->id,
                'code' => 'BSCRIM-LD-' . $effectiveYear,
                'name' => 'BS Criminology (Lie Detection) Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bshm->id,
                'specialization_id' => null,
                'code' => 'BSHM-' . $effectiveYear,
                'name' => 'BS Hospitality Management Curriculum',
                'academic_year' => $academicYear,
                'effective_year' => $effectiveYear,
                'active' => true,
            ],
            [
                'program_id' => $bstm->id,
                'specialization_id' => null,
                'code' => 'BSTM-' . $effectiveYear,
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