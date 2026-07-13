<?php

namespace App\Notifications;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to every stakeholder of a college's schedule the moment
 * Admin/Registrar finalizes it for the Active Academic Term — see
 * TermFinalizationService::finalize() / resolveStakeholders(), fired
 * only after the TermDepartmentFinalization row is committed.
 *
 * Recipients: Admin, Registrar, Assistant Dean, and that college's own
 * Dean/OIC — EXCEPT whoever actually performed the finalize action
 * (e.g. if Admin clicked Finalize, Admin does not get this, but
 * Registrar, Assistant Dean, and the college's Dean/OIC all do).
 *
 * Finalizing locks the department's scheduling data read-only for
 * everyone under it (TermFinalizationService::abortIfDepartmentFinalized()),
 * so this needs to reach people immediately — not be discovered next
 * time someone tries to edit and gets a 403.
 */
class ScheduleFinalized extends Notification
{
    public function __construct(
        private readonly Department $department,
        private readonly AcademicTerm $term,
        private readonly User $performedBy
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'department_id' => $this->department->id,
            'department_name' => $this->department->name,
            'academic_term_id' => $this->term->id,
            'academic_term_name' => $this->term->display_name,
            'performed_by_name' => $this->performedBy->name,
            'message' => "{$this->performedBy->name} finalized {$this->department->name}'s schedule for {$this->term->display_name}. It is now read-only.",
        ];
    }
}