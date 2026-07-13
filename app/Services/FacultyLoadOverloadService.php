<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\FacultyLoadActivity;
use App\Models\FacultyLoadOverload;
use App\Models\User;
use App\Notifications\FacultyLoadOverloadAppliedByAdmin;
use App\Notifications\FacultyLoadOverloadRequested;
use App\Notifications\FacultyLoadOverloadReviewed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Business rules for Faculty Load Overload — raising a single faculty
 * member's effective teaching cap above their normal max_units for
 * departments too short-staffed to keep everyone under it.
 *
 * Mirrors the same two-tier pattern already used everywhere else in
 * Faculty Loading (TeachingAssignmentController::assertManagesFaculty /
 * managerDepartmentId):
 *
 *   - Admin/Registrar requests are auto-approved on submission — they
 *     oversee every department already, so there's no one else who
 *     should be reviewing their own decision. The affected
 *     department's Dean/OIC is notified and a Recent Activity entry
 *     is logged instead, since the change is already live and they
 *     weren't the one who asked for it.
 *   - Dean/Assistant Dean/OIC requests start 'pending' and require a
 *     reason; only Admin/Registrar can approve or decline them.
 *
 * Each rule throws a ValidationException (never abort_if/abort_unless)
 * for the same reason TeachingAssignmentService does: Inertia needs a
 * 422 it can render as an inline form error, not a raw HTTP exception
 * page.
 */
class FacultyLoadOverloadService
{
    /**
     * Submit a new overload request for $faculty. Auto-approves
     * immediately when $user is Admin/Registrar — in which case the
     * faculty's own department (if any) is notified and the action is
     * logged to Recent Activity — otherwise leaves it pending for
     * Admin/Registrar's review, who are notified instead.
     */
    public function request(Faculty $faculty, User $user, int $units, ?string $reason): FacultyLoadOverload
    {
        $this->assertValidUnits($units);
        $this->assertWithinRequestCap($faculty, $units);

        $isUnscoped = $user->hasAnyRole(['Admin', 'Registrar']);

        if (! $isUnscoped && ! trim((string) $reason)) {
            throw ValidationException::withMessages([
                'reason' => 'Please explain why this faculty member needs additional units — Admin/Registrar will review this request.',
            ]);
        }

        $overload = FacultyLoadOverload::create([
            'faculty_id' => $faculty->id,
            'units' => $units,
            'status' => $isUnscoped ? FacultyLoadOverload::STATUS_APPROVED : FacultyLoadOverload::STATUS_PENDING,
            'requested_by' => $user->id,
            'reviewed_by' => $isUnscoped ? $user->id : null,
            'reason' => $reason,
            'reviewed_at' => $isUnscoped ? now() : null,
        ]);

        if ($isUnscoped) {
            $this->logOverloadActivity($overload, $user);
            $this->notifyDepartmentOfAppliedOverload($overload, $user);
        } else {
            $this->notifyReviewers($overload);
        }

        return $overload;
    }

    /**
     * Approve a pending request. Re-checks the cap at approval time
     * (not just at request time) — the faculty member could have
     * picked up other approved overload in the meantime.
     */
    public function approve(FacultyLoadOverload $overload, User $reviewer): void
    {
        $this->assertPending($overload);

        $faculty = $overload->faculty;
        $projected = $faculty->approved_overload_units + $overload->units;

        if ($projected > Faculty::MAX_OVERLOAD_UNITS) {
            throw ValidationException::withMessages([
                'units' => "Approving this would push {$faculty->full_name}'s overload to {$projected} units, exceeding the ".Faculty::MAX_OVERLOAD_UNITS.'-unit cap.',
            ]);
        }

        $overload->update([
            'status' => FacultyLoadOverload::STATUS_APPROVED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->notifyRequester($overload);
    }

    /**
     * Decline a pending request, with an explanation for the requester.
     */
    public function decline(FacultyLoadOverload $overload, User $reviewer, string $declineReason): void
    {
        $this->assertPending($overload);

        $overload->update([
            'status' => FacultyLoadOverload::STATUS_DECLINED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'decline_reason' => $declineReason,
        ]);

        $this->notifyRequester($overload);
    }

    /**
     * Tell whoever submitted the request that it's been reviewed —
     * skipped when the requester IS the reviewer (an Admin/Registrar
     * request auto-approves itself on submission; there's no one to
     * notify about their own instantaneous action). Loads
     * requestedBy fresh rather than trusting a possibly-stale relation
     * on $overload, since this always runs right after an update().
     */
    private function notifyRequester(FacultyLoadOverload $overload): void
    {
        $overload->loadMissing(['requestedBy', 'reviewedBy', 'faculty']);

        if (! $overload->requestedBy || $overload->requestedBy->id === $overload->reviewed_by) {
            return;
        }

        $overload->requestedBy->notify(new FacultyLoadOverloadReviewed($overload));
    }

    /**
     * Tell every Admin/Registrar that a new request is waiting on
     * them — the mirror image of notifyRequester() above, but fans
     * out to every reviewer instead of a single requester, since any
     * Admin or Registrar can act on it. Never called for auto-approved
     * requests, which never reach 'pending' in the first place.
     */
    private function notifyReviewers(FacultyLoadOverload $overload): void
    {
        $overload->loadMissing(['faculty', 'requestedBy']);

        $reviewers = User::role(['Admin', 'Registrar'])->get();

        Notification::send($reviewers, new FacultyLoadOverloadRequested($overload));
    }

    /**
     * Tell the faculty member's own department Dean/OIC that
     * Admin/Registrar just added overload units directly — they never
     * requested this and never get to approve/decline it, but it
     * changes a number (effective_max_units) they rely on day to day,
     * so they should hear about it without having to stumble onto it.
     * Skipped for General Education faculty (department_id null) —
     * there is no single department-scoped Dean/OIC for them to
     * notify, and Assistant Dean (who oversees every department) is
     * intentionally excluded here since this is meant for the one
     * department actually affected, not a global broadcast.
     */
    private function notifyDepartmentOfAppliedOverload(FacultyLoadOverload $overload, User $performedBy): void
    {
        $overload->loadMissing('faculty');
        $faculty = $overload->faculty;

        if (! $faculty || ! $faculty->department_id) {
            return;
        }

        $recipients = User::role(['Dean', 'OIC'])
            ->where('department_id', $faculty->department_id)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new FacultyLoadOverloadAppliedByAdmin($overload, $performedBy));
    }

    /**
     * Write one "overload added" row to the Faculty Loading audit
     * trail (the same feed TeachingAssignmentController's assign/
     * unassign actions write into) — only for the auto-approved path,
     * since a still-pending request hasn't actually changed anything
     * about the faculty member's load yet.
     */
    private function logOverloadActivity(FacultyLoadOverload $overload, User $performedBy): void
    {
        FacultyLoadActivity::create([
            'faculty_id' => $overload->faculty_id,
            'overload_id' => $overload->id,
            'performed_by' => $performedBy->id,
            'action' => FacultyLoadActivity::ACTION_OVERLOAD_ADDED,
            'units' => $overload->units,
            'faculty_name_snapshot' => $overload->faculty?->full_name,
            'created_at' => now(),
        ]);
    }

    private function assertPending(FacultyLoadOverload $overload): void
    {
        if ($overload->status !== FacultyLoadOverload::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'This request has already been reviewed.',
            ]);
        }
    }

    private function assertValidUnits(int $units): void
    {
        if ($units <= 0 || $units % Faculty::OVERLOAD_INCREMENT_UNITS !== 0) {
            throw ValidationException::withMessages([
                'units' => 'Units must be a multiple of '.Faculty::OVERLOAD_INCREMENT_UNITS.'.',
            ]);
        }

        if ($units > Faculty::MAX_OVERLOAD_UNITS) {
            throw ValidationException::withMessages([
                'units' => 'A single request cannot exceed '.Faculty::MAX_OVERLOAD_UNITS.' units.',
            ]);
        }
    }

    /**
     * The running total of approved + pending overload for one
     * faculty member may never exceed MAX_OVERLOAD_UNITS — counting
     * pending requests too, so a scoped manager can't stack multiple
     * pending requests that would blow past the cap the moment they
     * all happened to get approved.
     */
    private function assertWithinRequestCap(Faculty $faculty, int $units): void
    {
        $projected = $faculty->approved_overload_units + $faculty->pending_overload_units + $units;

        if ($projected > Faculty::MAX_OVERLOAD_UNITS) {
            throw ValidationException::withMessages([
                'units' => "This would bring {$faculty->full_name}'s total overload (approved + pending) to {$projected} units, exceeding the ".Faculty::MAX_OVERLOAD_UNITS.'-unit cap.',
            ]);
        }
    }
}