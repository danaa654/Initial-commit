<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\Section;
use App\Models\Specialization;
use App\Services\SectionCodeService;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    private const DEFAULT_CAPACITY = 30;

    private const DEFAULT_STATUS = 'Active';

    /**
     * Only Section A is seeded by default — B through E are created as
     * enrollment actually demands them.
     */
    private const SECTION_LETTER = 'A';

    /**
     * Seed the application's database.
     *
     * Fully data-driven — no Program or Specialization codes are
     * hardcoded here. Every active Program is walked in turn:
     *
     *   - If it has one or more active Specializations, one full set of
     *     Year 1..Program::years sections is seeded per Specialization
     *     (this is what BSCRIM and BSED both look like today).
     *   - Otherwise, a single non-specialized set of Year 1..
     *     Program::years sections is seeded for the Program itself
     *     (BSIT, BSHM, BSTM, ...).
     *
     * This mirrors SectionCodeService::requiresSpecialization(), which
     * makes the exact same per-Program-vs-per-Specialization decision
     * from the exact same data — so a newly added specialized Program
     * (or a Program that later gains/loses Specializations) is picked
     * up automatically the next time this seeder runs, with no code
     * changes here.
     */
    public function run(): void
    {
        Program::where('active', true)
            ->orderBy('code')
            ->with(['specializations' => function ($query) {
                $query->where('active', true)->orderBy('name');
            }])
            ->get()
            ->each(fn (Program $program) => $this->seedProgram($program));
    }

    /**
     * Seed one Program: per-Specialization if it has any active ones,
     * otherwise as a single plain Program.
     */
    private function seedProgram(Program $program): void
    {
        if ($program->specializations->isEmpty()) {
            $this->seedYears($program, null, $program->name);

            return;
        }

        foreach ($program->specializations as $specialization) {
            $this->seedYears(
                $program,
                $specialization,
                "{$program->name} ({$specialization->name})"
            );
        }
    }

    /**
     * Seed Year 1 through Program::years, Section A, for a single
     * Program/Specialization pair. $displayName is the part of the
     * Section Name before " - {year}{letter}", e.g. "BS Information
     * Technology" or "BS Criminology (Firearms Identification)".
     */
    private function seedYears(Program $program, ?Specialization $specialization, string $displayName): void
    {
        for ($yearLevel = 1; $yearLevel <= $program->years; $yearLevel++) {
            $this->createSection(
                $program,
                $specialization,
                $yearLevel,
                "{$displayName} - {$yearLevel}" . self::SECTION_LETTER
            );
        }
    }

    /**
     * Resolve the Curriculum, generate the Section Code via
     * SectionCodeService (the same authoritative generator the
     * controller uses — never hand-rolled here), and upsert the Section
     * by its code so re-running the seeder is always safe.
     */
    private function createSection(
        Program $program,
        ?Specialization $specialization,
        int $yearLevel,
        string $sectionName
    ): void {
        $label = $program->code . ($specialization ? "-{$specialization->code}" : '');

        $curriculum = SectionCodeService::resolveCurriculum($program->id, $specialization?->id);

        if (! $curriculum) {
            $this->command?->warn("SectionSeeder: skipping {$label} Year {$yearLevel} — no curriculum found.");

            return;
        }

        $sectionCode = SectionCodeService::generate(
            $program,
            $specialization,
            $yearLevel,
            self::SECTION_LETTER
        );

        if (! $sectionCode) {
            $this->command?->warn("SectionSeeder: skipping {$label} Year {$yearLevel} — could not generate a Section Code (missing/empty Specialization code?).");

            return;
        }

        Section::updateOrCreate(
            [
                'section_code' => $sectionCode,
            ],
            [
                'curriculum_id' => $curriculum->id,
                'section_name' => $sectionName,
                'year_level' => $yearLevel,
                'section_letter' => self::SECTION_LETTER,
                'capacity' => self::DEFAULT_CAPACITY,
                'status' => self::DEFAULT_STATUS,
            ]
        );
    }
}