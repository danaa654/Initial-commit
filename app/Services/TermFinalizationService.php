<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\Schedule;
use App\Models\SubjectOffering;
use App\Models\TeachingAssignment;
use App\Models\TermDepartmentFinalization;
use App\Models\User;
use App\Notifications\ScheduleFinalized;
use App\Notifications\ScheduleUnfinalized;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * The single write path for per-College (Department) Finalize/
 * Unfinalize — mirrors AuditLogService/ActivityHistoryService's "one
 * service owns this table" convention.
 *
 * IMPORTANT SCHEMA NOTE: this codebase's snapshot does not expose the
 * full subject_offerings/TeachingAssignment column list, so
 * readiness-checking below is written against the columns referenced
 * elsewhere in the app (MasterGridController, Schedule model,
 * BlockScheduleController): `subject_offerings.section_id`,
 * `subject_offerings.meetings_per_week`, `subject_offerings.is_offered`
 * (added by the accompanying migration), and the committed `schedules`
 * table (day/start_minutes/end_minutes/room_id/faculty_id per
 * subject_offering_id, one row per meeting — see Schedule model and
 * MasterGridController's multi-meeting handling). Adjust the column
 * names in departmentOfferingsQuery()/collectMissing() if the real
 * subject_offerings schema differs.
 */
class TermFinalizationService
{
    /**
     * Every Subject Offering for this Department+Term that is NOT
     * fully scheduled, each annotated with a `missing` array
     * describing exactly what's absent. Offerings flagged
     * `is_offered = false` are skipped entirely (Rule 2).
     */
    public function getUnscheduledSubjects(int $departmentId, int $termId): \Illuminate\Support\Collection
    {
        $offerings = $this->departmentOfferingsQuery($departmentId, $termId)->get();

        return $offerings
            ->map(function (SubjectOffering $offering) {
                $missing = $this->collectMissing($offering);

                return [
                    'subject_offering_id' => $offering->id,
                    'subject_code' => $offering->subject?->code ?? $offering->subject_code ?? null,
                    'section' => $offering->section?->section_code ?? $offering->section_code ?? null,
                    'missing' => $missing,
                ];
            })
            ->filter(fn (array $row) => ! empty($row['missing']))
            ->values();
    }

    public function isReadyToFinalize(int $departmentId, int $termId): bool
    {
        $total = $this->departmentOfferingsQuery($departmentId, $termId)->count();

        // A college with zero Subject Offerings has nothing to
        // schedule yet — that's "not started", not "fully scheduled".
        // Without this guard, 0 incomplete out of 0 total reads as
        // vacuously ready and lets an empty college get finalized.
        if ($total === 0) {
            return false;
        }

        return $this->getUnscheduledSubjects($departmentId, $termId)->isEmpty();
    }

    /**
     * One row per Department for the Settings dashboard: finalized
     * state + a readiness percentage, so the Vue table can render
     * "42/45 subjects scheduled" without a second round trip.
     */
    public function getFinalizationStatus(int $termId): \Illuminate\Support\Collection
    {
        $existing = TermDepartmentFinalization::forTerm($termId)
            ->get()
            ->keyBy('department_id');

        return Department::orderBy('name')->get()->map(function (Department $department) use ($termId, $existing) {
            $total = $this->departmentOfferingsQuery($department->id, $termId)->count();
            $unscheduled = $this->getUnscheduledSubjects($department->id, $termId);
            $incomplete = $unscheduled->count();
            $scheduled = $total - $incomplete;

            $record = $existing->get($department->id);

            // Same "zero offerings isn't ready" rule as
            // isReadyToFinalize() — kept in sync here explicitly
            // rather than calling that method again, since $unscheduled
            // is already computed above and we don't want a third
            // query just to re-derive $total === 0.
            $ready = $total > 0 && $incomplete === 0;

            return [
                'department_id' => $department->id,
                'department_name' => $department->name,
                'finalized' => (bool) ($record?->finalized ?? false),
                'total_subjects' => $total,
                'scheduled_subjects' => $scheduled,
                'incomplete_count' => $incomplete,
                'incomplete_subjects' => $unscheduled,
                'ready' => $ready,
                'finalized_at' => $record?->finalized_at,
            ];
        })->values();
    }

    /**
     * Locks a Department's scheduling data for $term. Guards:
     *  - $term must be the Active term (Rule 1 — Planning status is
     *    irrelevant to this feature).
     *  - every offering must be fully scheduled (Rule 2).
     * Throws RuntimeException with a clear message on failure so the
     * controller can flash it straight to the user.
     */
    public function finalize(Department $department, AcademicTerm $term, User $actor): void
    {
        if (! $term->active) {
            throw new RuntimeException(
                "{$term->display_name} is not the Active Academic Term. Only the Active term's colleges can be finalized."
            );
        }

        $unscheduled = $this->getUnscheduledSubjects($department->id, $term->id);
        $total = $this->departmentOfferingsQuery($department->id, $term->id)->count();

        if ($total === 0) {
            throw new RuntimeException(
                "{$department->name} has no Subject Offerings for {$term->display_name} yet — generate offerings before finalizing."
            );
        }

        if ($unscheduled->isNotEmpty()) {
            throw new RuntimeException(
                "{$department->name} still has {$unscheduled->count()} incomplete subject offering(s) and cannot be finalized yet."
            );
        }

        DB::transaction(function () use ($department, $term, $actor) {
            TermDepartmentFinalization::updateOrCreate(
                [
                    'academic_term_id' => $term->id,
                    'department_id' => $department->id,
                ],
                [
                    'finalized' => true,
                    'finalized_by' => $actor->id,
                    'finalized_at' => now(),
                ]
            );
        });

        ActivityHistoryService::recordCollegeFinalized($department, $term);

        $this->notifyDepartmentOfFinalization($department, $term, $actor);
    }

    /**
     * Reverses finalize(). Same Active-term guard as finalize() —
     * unfinalizing a college in a term that is no longer Active would
     * silently reopen historical data.
     */
    public function unfinalize(Department $department, AcademicTerm $term, User $actor): void
    {
        if (! $term->active) {
            throw new RuntimeException(
                "{$term->display_name} is not the Active Academic Term. Only the Active term's colleges can be unfinalized."
            );
        }

        $record = TermDepartmentFinalization::where('academic_term_id', $term->id)
            ->where('department_id', $department->id)
            ->where('finalized', true)
            ->first();

        if (! $record) {
            throw new RuntimeException("{$department->name} is not currently finalized for {$term->display_name}.");
        }

        DB::transaction(function () use ($record, $actor) {
            $record->update([
                'finalized' => false,
                'unfinalized_by' => $actor->id,
                'unfinalized_at' => now(),
            ]);
        });

        ActivityHistoryService::recordCollegeUnfinalized($department, $term);

        $this->notifyDepartmentOfUnfinalization($department, $term, $actor);
    }

    /**
     * Guard clause for the four scheduling controllers (Master Grid,
     * Faculty Loading, Subject Offerings, Teaching Assignments) — call
     * at the top of every store/update/delete action and let it 403
     * before any write happens. Deliberately static + cheap (single
     * indexed lookup) so it's safe to call on every mutating request.
     */
    public static function abortIfDepartmentFinalized(int $departmentId, int $termId): void
    {
        $isFinalized = TermDepartmentFinalization::forTerm($termId)
            ->where('department_id', $departmentId)
            ->finalized()
            ->exists();

        abort_if(
            $isFinalized,
            403,
            'This college\'s schedule is finalized and read-only. An Admin/Registrar can unfinalize it in Settings > Scheduling Workspace.'
        );
    }

    /**
     * Tell everyone with a stake in this college's schedule going
     * read-only, EXCEPT whoever actually clicked Finalize:
     *
     *   - Admin & Registrar — the two roles who can finalize/
     *     unfinalize at all (TermFinalizationController's
     *     middleware). If Admin finalized, Registrar still needs to
     *     know, and vice versa — "I did it" and "someone on my tier
     *     did it" are different things.
     *   - Assistant Dean — unlike FacultyLoadOverloadAppliedByAdmin's
     *     department-scoped notice, this one DOES include Assistant
     *     Dean. They oversee every college, and finalizing is a much
     *     bigger, rarer, higher-stakes event (locks a whole college
     *     read-only) than a single overload bump, so a global-scope
     *     role should hear about it too.
     *   - That college's own Dean/OIC — the ones who actually feel
     *     the read-only lock day to day.
     *
     * The actor is excluded via resolveRecipients()'s
     * ->reject(...$performedBy) — notifying someone about their own
     * click is just noise, same principle as
     * FacultyLoadOverloadService::notifyRequester()'s
     * requested_by === reviewed_by guard.
     */
    private function notifyDepartmentOfFinalization(Department $department, AcademicTerm $term, User $performedBy): void
    {
        $recipients = $this->resolveStakeholders($department, $performedBy);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new ScheduleFinalized($department, $term, $performedBy));
    }

    /**
     * Mirror image of notifyDepartmentOfFinalization() above — same
     * recipient set, same actor exclusion — for the schedule being
     * reopened for editing instead of locked.
     */
    private function notifyDepartmentOfUnfinalization(Department $department, AcademicTerm $term, User $performedBy): void
    {
        $recipients = $this->resolveStakeholders($department, $performedBy);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new ScheduleUnfinalized($department, $term, $performedBy));
    }

    /**
     * Admin + Registrar + Assistant Dean (global tiers) merged with
     * this specific college's Dean/OIC (department-scoped tier),
     * deduplicated by id, with whoever performed the action removed
     * from the list. Shared by both notify methods above since the
     * recipient rules are identical for finalize and unfinalize.
     */
    private function resolveStakeholders(Department $department, User $performedBy): \Illuminate\Support\Collection
    {
        $global = User::role(['Admin', 'Registrar', 'Assistant Dean'])->get();

        $departmentScoped = User::role(['Dean', 'OIC'])
            ->where('department_id', $department->id)
            ->get();

        return $global->merge($departmentScoped)
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $performedBy->id)
            ->values();
    }

    /**
     * Base query for every non-skipped Subject Offering belonging to
     * $departmentId in $termId, via section -> curriculum -> program
     * -> department_id (the same relation chain BlockScheduleController
     * uses for its own department-scoped queries).
     */
    private function departmentOfferingsQuery(int $departmentId, int $termId)
    {
        return SubjectOffering::query()
            ->where('academic_term_id', $termId)
            ->where(fn ($q) => $q->whereNull('is_offered')->orWhere('is_offered', true))
            ->whereHas('program', fn ($q) => $q->where('department_id', $departmentId))
            ->with(['section', 'subject']);
    }

    /**
     * Everything missing for one offering: faculty, and one
     * fully-populated Schedule row (day/start/end/room) per required
     * meeting. A subject with meetings_per_week = 2 (e.g. lab +
     * lecture) needs ALL of its meeting rows complete, not just one
     * (Rule 2, multi-meeting clause).
     */
    private function collectMissing(SubjectOffering $offering): array
    {
        $missing = [];

        $assignment = TeachingAssignment::where('subject_offering_id', $offering->id)->first();

        $hasRealFaculty = $assignment
            && $assignment->faculty_id
            && ! ($assignment->faculty_locked ?? false);

        if (! $hasRealFaculty) {
            $missing[] = 'faculty';
        }

        $requiredMeetings = max(1, (int) ($offering->meetings_per_week ?? 1));

        // Deliberately NOT using $offering->schedule here — that
        // relation returns only a single row (it's a hasOne / capped
        // relation upstream), so a subject with 2+ meetings per week
        // would always look "1/2 meetings set" even after every
        // meeting was actually scheduled. Querying the schedules
        // table directly by subject_offering_id gets every row.
        $schedules = Schedule::where('subject_offering_id', $offering->id)->get();

        $completeMeetings = $schedules->filter(fn (Schedule $s) => $s->day
            && $s->start_minutes !== null
            && $s->end_minutes !== null
            && $s->room_id
        )->count();

        if ($completeMeetings < $requiredMeetings) {
            $missing[] = "schedule ({$completeMeetings}/{$requiredMeetings} meetings set)";
        }

        return $missing;
    }
}