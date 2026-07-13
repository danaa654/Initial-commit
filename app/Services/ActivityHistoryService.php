<?php

namespace App\Services;

use App\Models\ActivityHistory;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The single write path for every Activity History row in CLASSLY.
 *
 * No controller or service should ever create an ActivityHistory row
 * directly (`ActivityHistory::create(...)`) — always call
 * ActivityHistoryService::record(...) instead, mirroring
 * AuditLogService's own docblock and reasoning:
 *
 *   - user/term capture happens exactly once, the same way, everywhere.
 *   - icon/color are resolved from the event ONCE here, so a new event
 *     type only ever needs an entry added to EVENT_PALETTE — no
 *     frontend deploy required (see the migration's docblock on the
 *     icon/color columns).
 *   - every future module can start recording activity by adding ONE
 *     line (ActivityHistoryService::record(...)) at the point of
 *     action, without any other file ever needing to change.
 *
 * Deliberately swallows its own failures, same as AuditLogService::log()
 * — recording a Timeline card must never be the reason a real
 * scheduling action fails.
 */
class ActivityHistoryService
{
    /**
     * Canonical module names — populates the Activity History page's
     * "Module" filter dropdown. Not a DB enum — a new module can log
     * under a name not in this list and it will still work; this is a
     * UI convenience list only.
     */
    public const MODULES = [
        'Academic Term',
        'Subject Offering',
        'Faculty Loading',
        'Master Grid',
        'Publishing',
        'System',
    ];

    /**
     * Canonical event keys — populates the "Event" filter dropdown and
     * drives icon/color via EVENT_PALETTE below. Format: "module.verb"
     * so two different modules can each have their own "generated"
     * event without colliding.
     */
    public const EVENTS = [
        'academic_term.created',
        'academic_term.activated',
        'academic_term.working_term_changed',
        'academic_term.planning_term_selected',
        'academic_term.archived',
        'academic_term.college_finalized',
        'academic_term.college_unfinalized',

        'subject_offering.generated',
        'subject_offering.regenerated',
        'subject_offering.deleted',
        'subject_offering.weekly_hours_bulk_updated',

        'faculty_loading.assigned',
        'faculty_loading.removed',
        'faculty_loading.overridden',
        'faculty_loading.completed',

        'master_grid.generated',
        'master_grid.regenerated',
        'master_grid.conflict_resolved',
        'master_grid.room_overridden',
        'master_grid.faculty_overridden',
        'master_grid.manual_adjustment',
        'master_grid.block_moved',

        'publishing.draft_saved',
        'publishing.published',
        'publishing.unpublished',
        'publishing.archived',

        'system.import_completed',
        'system.export_completed',
        'system.database_restore',
    ];

    /**
     * event => [icon, color]. `color` is one of the six named in the
     * spec (blue=Generation, green=Completed, yellow=Manual Change,
     * red=Conflict, purple=Publishing, gray=Archive) — the Vue side
     * maps each of these six names to its own badge/border classes,
     * so adding a new event here never requires touching CSS.
     *
     * Unknown events fall back to a neutral gray "activity" card
     * (see paletteFor()) rather than failing to record — a module
     * that forgets to register its event here still shows up on the
     * Timeline, just undecorated.
     */
    private const EVENT_PALETTE = [
        'academic_term.created' => ['calendar', 'blue'],
        'academic_term.activated' => ['calendar', 'green'],
        'academic_term.working_term_changed' => ['calendar', 'blue'],
        'academic_term.planning_term_selected' => ['calendar', 'blue'],
        'academic_term.archived' => ['archive', 'gray'],
        // Purple = "Publishing"/lock-related per the six-color spec —
        // finalize/unfinalize is a lock action on committed data, the
        // same family as publishing.published/unpublished below.
        'academic_term.college_finalized' => ['lock', 'purple'],
        'academic_term.college_unfinalized' => ['lock', 'purple'],

        'subject_offering.generated' => ['subject-offering', 'blue'],
        'subject_offering.regenerated' => ['subject-offering', 'blue'],
        'subject_offering.deleted' => ['subject-offering', 'gray'],
        // Yellow = "Manual Change" per the spec's six-color palette —
        // this is a Registrar hand-editing a value, not a generation
        // or a deletion.
        'subject_offering.weekly_hours_bulk_updated' => ['subject-offering', 'yellow'],

        'faculty_loading.assigned' => ['faculty', 'green'],
        'faculty_loading.removed' => ['faculty', 'yellow'],
        'faculty_loading.overridden' => ['faculty', 'yellow'],
        'faculty_loading.completed' => ['faculty-loading', 'green'],

        'master_grid.generated' => ['master-grid', 'blue'],
        'master_grid.regenerated' => ['master-grid', 'blue'],
        'master_grid.conflict_resolved' => ['conflict', 'red'],
        'master_grid.room_overridden' => ['room', 'yellow'],
        'master_grid.faculty_overridden' => ['faculty', 'yellow'],
        'master_grid.manual_adjustment' => ['schedule', 'yellow'],
        'master_grid.block_moved' => ['schedule', 'yellow'],

        'publishing.draft_saved' => ['publish', 'purple'],
        'publishing.published' => ['publish', 'purple'],
        'publishing.unpublished' => ['publish', 'purple'],
        'publishing.archived' => ['archive', 'gray'],

        'system.import_completed' => ['system', 'green'],
        'system.export_completed' => ['system', 'green'],
        'system.database_restore' => ['system', 'gray'],
    ];

    /**
     * Write one Activity History card.
     *
     * @param  string  $event         One of self::EVENTS (or a new "module.verb" — see class docblock).
     * @param  string  $title         Short headline, e.g. "Subject Offerings Generated".
     * @param  string|null  $description  One-line human summary, e.g. "243 classes imported".
     * @param  array|null  $metadata  Optional extra context rendered under the description
     *                                (e.g. ['section' => 'BSIT 1-A', 'created' => 243]).
     * @param  AcademicTerm|null  $academicTerm  The term this milestone belongs to — every
     *                                card on the Timeline is grouped by this. Falls back to
     *                                null (renders under "Unassigned Term") only when truly
     *                                term-less (e.g. some System events).
     * @param  string|null  $module   Defaults to the module implied by the event's prefix.
     */
    public static function record(
        string $event,
        string $title,
        ?string $description = null,
        ?array $metadata = null,
        ?AcademicTerm $academicTerm = null,
        ?string $module = null,
    ): ?ActivityHistory {
        try {
            /** @var User|null $user */
            $user = Auth::user();

            [$icon, $color] = self::paletteFor($event);

            return ActivityHistory::create([
                'academic_term_id' => $academicTerm?->id,
                'user_id' => $user?->id,
                'user_name' => $user?->name,

                'module' => $module ?? self::moduleFromEvent($event),
                'event' => $event,

                'title' => $title,
                'description' => $description,
                'metadata' => $metadata,

                'icon' => $icon,
                'color' => $color,

                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function paletteFor(string $event): array
    {
        return self::EVENT_PALETTE[$event] ?? ['activity', 'gray'];
    }

    /**
     * Best-effort Module label derived from the "module.verb" event
     * key when the caller doesn't pass one explicitly — e.g.
     * "master_grid.generated" => "Master Grid".
     */
    private static function moduleFromEvent(string $event): string
    {
        $prefix = explode('.', $event)[0] ?? $event;

        return collect(explode('_', $prefix))
            ->map(fn ($word) => ucfirst($word))
            ->implode(' ');
    }

    /**
     * Has this event already been recorded for this term? Used to
     * guard one-time milestones (e.g. "Faculty Loading Completed")
     * from firing again on every subsequent assignment once the term
     * is already fully loaded — see recordFacultyLoadingCompleted().
     */
    public static function hasRecorded(string $event, ?int $academicTermId): bool
    {
        if (! $academicTermId) {
            return false;
        }

        return ActivityHistory::where('academic_term_id', $academicTermId)
            ->where('event', $event)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Named convenience methods — one per milestone in the spec.
    |--------------------------------------------------------------------------
    |
    | Controllers should call these instead of record() directly
    | wherever a call site exists for a milestone already named here,
    | so the title/description/metadata shape for that milestone lives
    | in exactly one place. record() itself stays public for any
    | one-off event that doesn't have (or doesn't yet deserve) its own
    | named method.
    |
    */

    public static function recordAcademicTermCreated(AcademicTerm $term): ?ActivityHistory
    {
        return self::record(
            event: 'academic_term.created',
            title: 'Academic Term Created',
            description: "{$term->display_name} was created",
            academicTerm: $term,
        );
    }

    public static function recordAcademicTermActivated(AcademicTerm $term): ?ActivityHistory
    {
        return self::record(
            event: 'academic_term.activated',
            title: 'Academic Term Activated',
            description: "{$term->display_name} is now the Active Academic Term",
            academicTerm: $term,
        );
    }

    public static function recordWorkingTermChanged(AcademicTerm $term): ?ActivityHistory
    {
        return self::record(
            event: 'academic_term.working_term_changed',
            title: 'Working Academic Term Changed',
            description: "Working Term switched to {$term->display_name}",
            academicTerm: $term,
        );
    }

    public static function recordAcademicTermArchived(AcademicTerm $term): ?ActivityHistory
    {
        return self::record(
            event: 'academic_term.archived',
            title: 'Academic Term Archived',
            description: "{$term->display_name} was archived",
            academicTerm: $term,
        );
    }

    /**
     * College (Department) Finalize/Unfinalize — see
     * TermFinalizationService, the single caller of these two.
     */
    public static function recordCollegeFinalized(\App\Models\Department $department, AcademicTerm $term): ?ActivityHistory
    {
        return self::record(
            event: 'academic_term.college_finalized',
            title: 'College Finalized',
            description: "{$department->name} was finalized for {$term->display_name}",
            metadata: ['department' => $department->name],
            academicTerm: $term,
        );
    }

    public static function recordCollegeUnfinalized(\App\Models\Department $department, AcademicTerm $term): ?ActivityHistory
    {
        return self::record(
            event: 'academic_term.college_unfinalized',
            title: 'College Unfinalized',
            description: "{$department->name} was unfinalized for {$term->display_name} and is editable again",
            metadata: ['department' => $department->name],
            academicTerm: $term,
        );
    }

    /**
     * @param  array  $metadata  e.g. ['curriculum' => 'BSIT', 'sections' => 6]
     */
    public static function recordSubjectOfferingsGenerated(AcademicTerm $term, int $created, array $metadata = [], bool $regenerated = false): ?ActivityHistory
    {
        return self::record(
            event: $regenerated ? 'subject_offering.regenerated' : 'subject_offering.generated',
            title: $regenerated ? 'Subject Offerings Regenerated' : 'Subject Offerings Generated',
            description: "{$created} classes imported",
            metadata: array_merge(['generated' => $created], $metadata),
            academicTerm: $term,
        );
    }

    public static function recordSubjectOfferingsCleared(AcademicTerm $term, int $count): ?ActivityHistory
    {
        return self::record(
            event: 'subject_offering.deleted',
            title: 'Subject Offerings Cleared',
            description: "{$count} subject offering(s) removed",
            metadata: ['removed' => $count],
            academicTerm: $term,
        );
    }

    /**
     * Bulk Update Weekly Hours — a Registrar/Admin overriding the
     * per-Term weekly hours on a hand-picked set of Subject Offerings
     * (see SubjectOfferingController::bulkUpdateWeeklyHours()). Only
     * the Subject Offering rows themselves change; the Subject
     * master, Curriculum, and Prospectus are never touched, which is
     * why this reads as a "Manual Change" (yellow) milestone rather
     * than a Generation one.
     *
     * @param  array  $metadata  e.g. ['program' => 'BSIT', 'year_level' => 1,
     *                            'section' => 'BSIT-1A', 'weekly_hours' => '5 → 4']
     */
    public static function recordBulkWeeklyHoursUpdated(AcademicTerm $term, int $count, array $metadata = []): ?ActivityHistory
    {
        return self::record(
            event: 'subject_offering.weekly_hours_bulk_updated',
            title: 'Bulk Weekly Hours Updated',
            description: "{$count} Subject Offering(s) updated",
            metadata: array_merge(['updated' => $count], $metadata),
            academicTerm: $term,
            module: 'Subject Offering',
        );
    }

    /**
     * Fires once per term — the first time every Subject Offering in
     * the term has a Teaching Assignment. Callers should check
     * hasRecorded('faculty_loading.completed', $term->id) first (see
     * TeachingAssignmentController::store()) so this never re-fires on
     * every subsequent assignment once the term is already fully
     * loaded.
     */
    public static function recordFacultyLoadingCompleted(AcademicTerm $term, int $offeringsCount, int $facultyCount): ?ActivityHistory
    {
        return self::record(
            event: 'faculty_loading.completed',
            title: 'Faculty Loading Completed',
            description: "{$offeringsCount} Subject Offerings, {$facultyCount} Faculty Assigned",
            metadata: ['offerings' => $offeringsCount, 'faculty' => $facultyCount],
            academicTerm: $term,
        );
    }

    public static function recordRoomAssignmentsCompleted(AcademicTerm $term, int $count, bool $regenerated = false): ?ActivityHistory
    {
        return self::record(
            event: $regenerated ? 'master_grid.regenerated' : 'master_grid.generated',
            title: $regenerated ? 'Room Assignment Regenerated' : 'Room Assignment Completed',
            description: "{$count} classes assigned a room",
            metadata: ['rooms_assigned' => $count],
            academicTerm: $term,
            module: 'Master Grid',
        );
    }

    /**
     * @param  array  $stats  e.g. ['scheduled' => 225, 'conflicts' => 18, 'remaining' => 5]
     */
    public static function recordMasterGridGenerated(AcademicTerm $term, array $stats, bool $regenerated = false): ?ActivityHistory
    {
        $scheduled = $stats['scheduled'] ?? 0;

        return self::record(
            event: $regenerated ? 'master_grid.regenerated' : 'master_grid.generated',
            title: $regenerated ? 'Master Grid Regenerated' : 'Master Grid Generated',
            description: "{$scheduled} classes scheduled",
            metadata: $stats,
            academicTerm: $term,
        );
    }

    public static function recordScheduleManuallyAdjusted(AcademicTerm $term, int $blocksModified, ?string $note = null): ?ActivityHistory
    {
        return self::record(
            event: 'master_grid.manual_adjustment',
            title: 'Manual Schedule Adjustment',
            description: $note ?? "{$blocksModified} schedule block(s) modified",
            metadata: ['blocks_modified' => $blocksModified],
            academicTerm: $term,
        );
    }

    public static function recordSchedulePublished(AcademicTerm $term): ?ActivityHistory
    {
        return self::record(
            event: 'publishing.published',
            title: 'Schedule Published',
            description: "{$term->display_name} schedule published",
            academicTerm: $term,
        );
    }

    public static function recordScheduleUnpublished(AcademicTerm $term): ?ActivityHistory
    {
        return self::record(
            event: 'publishing.unpublished',
            title: 'Schedule Unpublished',
            description: "{$term->display_name} schedule unpublished",
            academicTerm: $term,
        );
    }

    public static function recordCurriculumImported(string $curriculumLabel, ?AcademicTerm $term = null): ?ActivityHistory
    {
        return self::record(
            event: 'system.import_completed',
            title: 'Curriculum Imported',
            description: "{$curriculumLabel} imported",
            academicTerm: $term,
            module: 'System',
        );
    }

    public static function recordCurriculumArchived(string $curriculumLabel, ?AcademicTerm $term = null): ?ActivityHistory
    {
        return self::record(
            event: 'publishing.archived',
            title: 'Curriculum Archived',
            description: "{$curriculumLabel} archived",
            academicTerm: $term,
            module: 'System',
        );
    }

    public static function recordCurriculumRestored(string $curriculumLabel, ?AcademicTerm $term = null): ?ActivityHistory
    {
        return self::record(
            event: 'system.import_completed',
            title: 'Curriculum Restored',
            description: "{$curriculumLabel} restored",
            academicTerm: $term,
            module: 'System',
        );
    }
}