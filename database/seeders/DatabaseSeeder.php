<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DepartmentSeeder::class,
            ProgramSeeder::class,
            SpecializationSeeder::class,
            CurriculumSeeder::class,
            AdminUserSeeder::class,
            SubjectSeeder::class,
            CurriculumItemSeeder::class,
            FacultySeeder::class,
            RoomSeeder::class,
        ]);
    }
}