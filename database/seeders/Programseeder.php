<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Database\Seeder;

class ProgramSeeder extends Seeder
{
    public function run(): void
    {
        $ccs = Department::where('abbreviation', 'CCS')->firstOrFail();
        $cte = Department::where('abbreviation', 'CTE')->firstOrFail();
        $crim = Department::where('abbreviation', 'COC')->firstOrFail();
        $shtm = Department::where('abbreviation', 'SHTM')->firstOrFail();

        $programs = [
            [
                'department_id' => $ccs->id,
                'code' => 'BSIT',
                'name' => 'Bachelor of Science in Information Technology',
                'years' => 4,
                'active' => true,
            ],
            [
                'department_id' => $cte->id,
                'code' => 'BSED',
                'name' => 'Bachelor of Secondary Education',
                'years' => 4,
                'active' => true,
            ],
            [
                'department_id' => $crim->id,
                'code' => 'BSCRIM',
                'name' => 'Bachelor of Science in Criminology',
                'years' => 4,
                'active' => true,
            ],
            [
                'department_id' => $shtm->id,
                'code' => 'BSHM',
                'name' => 'Bachelor of Science in Hospitality Management',
                'years' => 4,
                'active' => true,
            ],
            [
                'department_id' => $shtm->id,
                'code' => 'BSTM',
                'name' => 'Bachelor of Science in Tourism Management',
                'years' => 4,
                'active' => true,
            ],
        ];

        foreach ($programs as $program) {
            Program::updateOrCreate(
                ['code' => $program['code']],
                $program
            );
        }
    }
}