<?php

namespace App\Notifications;

use App\Models\FacultyLoadOverload;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever REQUESTED a Faculty Load Overload the moment
 * Admin/Registrar approves or declines it — see
 * FacultyLoadOverloadService::approve()/decline(), which fire this
 * right after the status change. Never sent for a request that
 * auto-approved on submission (Admin/Registrar requesting for
 * themselves) — see the `requested_by !== reviewed_by` guard in the
 * service, since notifying someone about their own instantaneous
 * action is just noise.
 *
 * Database-only for now (no mail/broadcast channel) — stored via the
 * standard `notifications` table and surfaced as a shared Inertia prop
 * in HandleInertiaRequests so it can be shown from anywhere in the app,
 * not just the Faculty Loading page.
 */
class FacultyLoadOverloadReviewed extends Notification
{
    public function __construct(
        private readonly FacultyLoadOverload $overload
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $faculty = $this->overload->faculty;
        $reviewer = $this->overload->reviewedBy;

        return [
            'overload_id' => $this->overload->id,
            'faculty_id' => $faculty?->id,
            'faculty_name' => $faculty?->full_name,
            'units' => $this->overload->units,
            'status' => $this->overload->status,
            'decline_reason' => $this->overload->decline_reason,
            'reviewed_by_name' => $reviewer?->name,
            'message' => $this->overload->status === FacultyLoadOverload::STATUS_APPROVED
                ? "Your request to add {$this->overload->units} units for {$faculty?->full_name} was approved."
                : "Your request to add {$this->overload->units} units for {$faculty?->full_name} was declined.",
        ];
    }
}