<?php

namespace App\Services;

use App\Models\AcademicTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Detects when the Active Academic Term's Class End date has passed, and
 * performs the (Admin/Registrar-confirmed, never automatic) Archive &
 * Activate action that closes it out and — if the Planning Academic Term
 * is ready — promotes it to Active in the same transaction.
 *
 * Deliberately NOT a scheduled job that flips things on its own. Real
 * semesters don't end cleanly on class_end_date — grading, incompletes,
 * and attendance corrections routinely run past it — and Active/Archived
 * status touches every operational module (Dashboard, Reports, Student
 * Records, Enrollment, Grades, Attendance). This service only ever
 * SURFACES that the date has passed; a human still has to click the
 * button. See HandleInertiaRequests for how that surface reaches the UI,
 * and AcademicTermController::closeActiveTerm() for the endpoint this
 * service backs.
 */
class SemesterTransitionService
{
    public function __construct(
        private readonly SchedulingWorkspaceService $workspace
    ) {
    }

    /**
     * Whether the Active Academic Term has passed its Class End date and
     * is due to be closed out. Returns false (never true) when there is
     * no Active term at all — nothing to close.
     */
    public function isActiveTermOverdue(): bool
    {
        $active = $this->workspace->getActiveTerm();

        if (! $active || ! $active->class_end_date) {
            return false;
        }

        return Carbon::today()->greaterThan($active->class_end_date);
    }

    /**
     * Whether the Planning Academic Term is far enough along to become
     * the new Active term the moment the current one closes:
     *   - it must actually be a DIFFERENT term than the one closing,
     *   - it must already be Published (mirrors AcademicTermRequest's
     *     "only a Published term may be Active" rule — this service
     *     must never promote a Draft),
     *   - and its Class Start date must have already arrived.
     *
     * If this returns false, closeActiveTerm() still archives the
     * overdue term — it just leaves the system with no Active term
     * until an Admin activates one manually, rather than either
     * guessing or leaving a stale Active term in place.
     */
    public function isPlanningTermReadyToActivate(): bool
    {
        $active = $this->workspace->getActiveTerm();
        $planning = $this->workspace->getPlanningTerm();

        if (! $planning || ! $planning->class_start_date) {
            return false;
        }

        if ($active && $planning->id === $active->id) {
            return false;
        }

        if ($planning->status !== 'Published') {
            return false;
        }

        return Carbon::today()->greaterThanOrEqualTo($planning->class_start_date);
    }

    /**
     * Everything the "Semester Ended" banner needs to render itself and
     * decide which button/copy to show, or null if there's nothing to
     * surface right now (no Active term, or it hasn't ended yet).
     */
    public function bannerData(): ?array
    {
        if (! $this->isActiveTermOverdue()) {
            return null;
        }

        $active = $this->workspace->getActiveTerm();
        $planning = $this->workspace->getPlanningTerm();
        $planningIsDifferent = $planning && (! $active || $planning->id !== $active->id);

        return [
            'activeTerm' => [
                'id' => $active->id,
                'academic_year' => $active->academic_year,
                'semester_label' => $active->semester_label,
                'class_end_date' => $active->class_end_date?->toDateString(),
            ],
            'planningTerm' => $planningIsDifferent ? [
                'id' => $planning->id,
                'academic_year' => $planning->academic_year,
                'semester_label' => $planning->semester_label,
                'class_start_date' => $planning->class_start_date?->toDateString(),
                'status' => $planning->status,
            ] : null,
            'planningReady' => $this->isPlanningTermReadyToActivate(),
        ];
    }

    /**
     * The one button-click action itself: archive the overdue Active
     * term, and — only if isPlanningTermReadyToActivate() — promote the
     * Planning term to Active in the same transaction.
     *
     * This NEVER moves data between terms, duplicates or deletes any
     * schedule, or touches the Planning Term setting itself (system_settings
     * still points at whatever AcademicTerm the Registrar last chose —
     * that doesn't need to change just because it got activated).
     *
     * @return array{archived: AcademicTerm, activated: ?AcademicTerm}
     */
    public function closeAndActivate(): array
    {
        $active = $this->workspace->getActiveTerm();

        abort_unless($active, 422, 'There is no Active Academic Term to close.');

        $activated = null;

        DB::transaction(function () use ($active, &$activated) {
            $active->update([
                'status' => 'Archived',
                'active' => false,
            ]);

            if ($this->isPlanningTermReadyToActivate()) {
                $planning = $this->workspace->getPlanningTerm();

                // Defense in depth: only one term may ever be active,
                // even though $active above should already be the only
                // one set. Mirrors the same safety pattern
                // AcademicTermController::store()/update() already use.
                AcademicTerm::where('active', true)->update(['active' => false]);

                $planning->update(['active' => true]);

                $activated = $planning;
            }
        });

        return ['archived' => $active->fresh(), 'activated' => $activated];
    }
}