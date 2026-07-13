<?php

namespace App\Notifications;

use App\Models\FacultyLoadOverload;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to the Dean/OIC of a faculty member's own department the
 * moment Admin/Registrar directly adds overload units to that
 * faculty member — see FacultyLoadOverloadService::request(), fired
 * only on the auto-approved (Admin/Registrar) path, and only when the
 * faculty belongs to a department (General Education faculty have no
 * department-scoped Dean/OIC to notify, so nothing is sent for them).
 *
 * This is deliberately separate from FacultyLoadOverloadReviewed:
 * that one tells the *requester* their own submitted request was
 * approved/declined. This one tells the department's Dean/OIC — who
 * never requested anything — that their faculty member's cap just
 * changed underneath them, so they aren't surprised by it later on
 * the Faculty Loading page.
 */
class FacultyLoadOverloadAppliedByAdmin extends Notification
{
    public function __construct(
        private readonly FacultyLoadOverload $overload,
        private readonly User $performedBy
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $faculty = $this->overload->faculty;

        return [
            'overload_id' => $this->overload->id,
            'faculty_id' => $faculty?->id,
            'faculty_name' => $faculty?->full_name,
            'units' => $this->overload->units,
            'performed_by_name' => $this->performedBy->name,
            'message' => "{$this->performedBy->name} added {$this->overload->units} overload units to {$faculty?->full_name}'s load.",
        ];
    }
}