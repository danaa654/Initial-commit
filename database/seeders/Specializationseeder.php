<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\Specialization;
use Illuminate\Database\Seeder;

class SpecializationSeeder extends Seeder
{
    public function run(): void
    {
        $bsed = Program::where('code', 'BSED')->firstOrFail();
        $bscrim = Program::where('code', 'BSCRIM')->firstOrFail();

        // BSIT, BSHM, and BSTM have no majors, so they get no specialization rows.
        // Their curricula will use specialization_id = null.

        $specializations = [
            [
                'program_id' => $bsed->id,
                'code' => 'ENG',
                'name' => 'English',
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'code' => 'QD',
                'name' => 'Questioned Documents Examination',
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'code' => 'FI',
                'name' => 'Fingerprint Identification',
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'code' => 'FB',
                'name' => 'Firearms Identification (Forensic Ballistics)',
                'active' => true,
            ],
            [
                'program_id' => $bscrim->id,
                'code' => 'LD',
                'name' => 'Lie Detection (Polygraph)',
                'active' => true,
            ],
        ];

        foreach ($specializations as $specialization) {
            Specialization::updateOrCreate(
                [
                    'program_id' => $specialization['program_id'],
                    'code' => $specialization['code'],
                ],
                $specialization
            );
        }
    }
}