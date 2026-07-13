<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * The single source of truth for "which Academic Term is the
 * Scheduling Workspace currently pointed at?" — the Planning Academic
 * Term.
 *
 * This is deliberately a DIFFERENT concept from the Active Academic
 * Term (AcademicTerm::active() / the `active` column):
 *
 *   - Active Academic Term  -> the semester actually running right
 *     now. Used by every operational module (Dashboard, Reports,
 *     Student Records, Enrollment, Grades, Attendance) and changed
 *     only when the Administrator officially activates a new term.
 *
 *   - Planning Academic Term -> the semester the scheduling team is
 *     currently PREPARING. Used exclusively by the scheduling modules
 *     (Subject Offerings, Faculty Loading, Teaching Assignments,
 *     Room Management, Master Grid, Greedy Scheduler, Schedule
 *     Preview, Conflict Detection, Room/Faculty/Section Availability).
 *     Set from Settings > Scheduling Workspace by Admin/Registrar
 *     only, and can point at a future term months before it starts.
 *
 * The Planning Academic Term is stored in `system_settings` (a
 * shared, database-backed key/value store — see SystemSetting) rather
 * than session storage, so every authorized user sees the exact same
 * term: Admin, Registrar, Dean, Assistant Dean, and OIC never see
 * different values from one another.
 *
 * Every scheduling controller should inject this service and call
 * getPlanningTerm() instead of querying AcademicTerm::active()
 * directly — that's the whole point of centralizing this logic here
 * instead of duplicating "resolve the term to schedule against" in
 * every controller.
 */
class SchedulingWorkspaceService
{
    /**
     * The system_settings key the Planning Academic Term's
     * academic_term_id is stored under.
     */
    private const SETTING_KEY = 'planning_academic_term_id';

    /**
     * The Academic Term all scheduling modules should currently read
     * from and write to.
     *
     * Falls back to the Active Academic Term if no Planning Academic
     * Term has ever been explicitly set (e.g. right after this
     * feature ships, before anyone has visited Settings > Scheduling
     * Workspace yet) so scheduling doesn't simply break on upgrade.
     */
    public function getPlanningTerm(): ?AcademicTerm
    {
        $id = SystemSetting::get(self::SETTING_KEY);

        $term = $id ? AcademicTerm::find($id) : null;

        return $term ?? $this->getActiveTerm();
    }

    /**
     * The Academic Term a given user should see/manage IN THE
     * SCHEDULING MODULES specifically (Subject Offerings, Faculty
     * Loading, Teaching Assignments, Room Management, Master Grid).
     *
     *   - Admin / Registrar -> the Planning Academic Term. They're the
     *     only roles who can move it (see setPlanningTerm()), and the
     *     whole point of a Planning Term is letting them prepare a
     *     future semester's schedule ahead of time.
     *
     *   - Dean / Assistant Dean / OIC / anyone else -> the Active
     *     Academic Term, always. They review and work within whatever
     *     semester is officially running; they never see or edit a
     *     future Planning draft, even read-only, because a schedule
     *     that isn't official yet could still change out from under
     *     them mid-review.
     *
     * This is the method every scheduling controller should call —
     * NOT getPlanningTerm() directly — so the Admin/Registrar-vs-
     * everyone-else split lives in exactly one place instead of being
     * re-implemented (and potentially gotten wrong) in every
     * controller.
     */
    public function getTermForUser(?User $user): ?AcademicTerm
    {
        if ($user && $user->hasAnyRole(['Admin', 'Registrar'])) {
            return $this->getPlanningTerm();
        }

        return $this->getActiveTerm();
    }

    /**
     * Convenience accessor for the Active Academic Term, kept here so
     * callers that need to display "Active" alongside "Planning" (per
     * the Settings page and the on-page Planning Academic Term
     * indicator) have one place to get both.
     */
    public function getActiveTerm(): ?AcademicTerm
    {
        return AcademicTerm::active()->first();
    }

    /**
     * Point the Scheduling Workspace at a different Academic Term.
     *
     * This ONLY changes which term the scheduling modules read/write
     * against. It must never be used to touch the `active` flag, move
     * data between terms, or duplicate/delete schedules — callers
     * needing to change the Active Academic Term should go through
     * AcademicTermController's activate flow (the `active` column),
     * which is a completely separate operation from this one.
     *
     * Authorization (Admin/Registrar only) is intentionally NOT
     * enforced here — it's an HTTP-layer concern checked by the
     * controller/route middleware, not a data-layer concern. This
     * service has no knowledge of the current request or user.
     */
    public function setPlanningTerm(AcademicTerm $term): void
    {
        SystemSetting::set(self::SETTING_KEY, (string) $term->id);
    }

    /**
     * Whether the Planning/Working Academic Term currently differs from
     * the Active Academic Term. Used by the on-page banner/indicator so
     * users always know when they're scheduling for a term other than
     * the one currently running.
     */
    public function isPlanningAheadOfActive(): bool
    {
        $planning = $this->getPlanningTerm();
        $active = $this->getActiveTerm();

        if (! $planning || ! $active) {
            return false;
        }

        return $planning->id !== $active->id;
    }

    /**
     * Guard every scheduling write action (Subject Offerings generate/
     * destroy, Teaching Assignments store/destroy, Master Grid generate/
     * validateBlock/save, Room store/update/destroy/syncPreferredSubjects)
     * against being run against an Archived term.
     *
     * Admin/Registrar can deliberately switch the Working Term to an
     * Archived semester to review its committed schedule — that's a
     * legitimate, intentional read-only view, NOT an error. What must
     * never happen is actually writing to it: a schedule that already
     * happened is historical record, same as the Academic Term record
     * itself (see AcademicTermController::destroy()'s Archived-can-never-
     * be-deleted rule). This is the scheduling-module equivalent of that
     * same principle, enforced at the point of write rather than only
     * relying on the frontend graying out buttons.
     *
     * Throws a 403 (not a soft validation error) — same reasoning as
     * TeachingAssignmentController::assertManagesFaculty(): this is a
     * genuine permission violation given the CURRENT Working Term, not
     * something the user can correct by re-submitting the same form.
     */
    public function assertWritable(?AcademicTerm $term): void
    {
        abort_if(
            $term && $term->status === 'Archived',
            403,
            'This Academic Term is Archived and read-only. Switch the Working Term to make changes.'
        );
    }
}