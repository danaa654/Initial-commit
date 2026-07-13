<?php

namespace App\Notifications;

use App\Models\FacultyLoadOverload;
use Illuminate\Notifications\Notification;

/**
 * Sent to every Admin/Registrar the moment a Dean/Assistant Dean/OIC
 * submits a new Faculty Load Overload request — see
 * FacultyLoadOverloadService::request(), which fires this right after
 * creating a 'pending' overload. Never sent when the request
 * auto-approves on submission (Admin/Registrar requesting for
 * themselves) since there's nothing left pending to review.
 *
 * Database-only, same as FacultyLoadOverloadReviewed — surfaced in the
 * same Topbar dropdown so Admin/Registrar notice a new request without
 * needing the Faculty Loading page open.
 */
class FacultyLoadOverloadRequested extends Notification
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
        $requester = $this->overload->requestedBy;

        return [
            'overload_id' => $this->overload->id,
            'faculty_id' => $faculty?->id,
            'faculty_name' => $faculty?->full_name,
            'units' => $this->overload->units,
            'reason' => $this->overload->reason,
            'requested_by_name' => $requester?->name,
            'message' => "{$requester?->name} requested {$this->overload->units} overload units for {$faculty?->full_name}.",
        ];
    }
}