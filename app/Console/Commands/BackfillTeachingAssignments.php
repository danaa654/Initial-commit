<?php

namespace App\Console\Commands;

use App\Models\Schedule;
use App\Models\TeachingAssignment;
use Illuminate\Console\Command;

/**
 * One-time backfill for schedules that were committed BEFORE
 * MasterGridController::save() started syncing teaching_assignments.
 *
 * Going forward, save() keeps the two tables in sync automatically —
 * see MasterGridController::syncTeachingAssignment(). This command
 * exists purely to catch up any Schedule rows that predate that fix
 * and therefore have no matching Teaching Assignment yet. Safe to run
 * more than once: it only creates/updates a row when the offering has
 * no assignment yet, or has one pointing at a different faculty member
 * than the schedule actually carries.
 *
 * Usage: php artisan teaching-assignments:backfill
 *        php artisan teaching-assignments:backfill --dry-run
 */
class BackfillTeachingAssignments extends Command
{
    protected $signature = 'teaching-assignments:backfill {--dry-run : Show what would change without writing anything}';

    protected $description = 'Sync teaching_assignments from any schedules rows that predate the Master Grid sync fix';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $schedules = Schedule::whereNotNull('faculty_id')->get();

        $this->info("Found {$schedules->count()} schedule row(s) with a faculty assigned.");

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($schedules as $schedule) {
            $existing = TeachingAssignment::where('subject_offering_id', $schedule->subject_offering_id)->first();

            if ($existing && (int) $existing->faculty_id === (int) $schedule->faculty_id) {
                $skipped++;
                continue;
            }

            $action = $existing ? 'update' : 'create';

            $this->line(sprintf(
                '[%s] offering #%d -> faculty #%d%s',
                $action,
                $schedule->subject_offering_id,
                $schedule->faculty_id,
                $existing ? " (was faculty #{$existing->faculty_id})" : ''
            ));

            if ($dryRun) {
                $action === 'create' ? $created++ : $updated++;
                continue;
            }

            TeachingAssignment::updateOrCreate(
                ['subject_offering_id' => $schedule->subject_offering_id],
                ['faculty_id' => $schedule->faculty_id, 'active' => true]
            );

            $action === 'create' ? $created++ : $updated++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Created: {$created}, Updated: {$updated}, Already in sync: {$skipped}");

        return self::SUCCESS;
    }
}