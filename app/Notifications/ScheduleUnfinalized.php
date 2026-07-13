<?php

namespace App\Notifications;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to every stakeholder of a college's schedule the moment
 * Admin/Registrar unfinalizes it for the Active Academic Term — see
 * TermFinalizationService::unfinalize() / resolveStakeholders(), fired
 * only after the TermDepartmentFinalization row is committed.
 *
 * Same recipient set and same actor-exclusion rule as
 * ScheduleFinalized (see that class's docblock): Admin, Registrar,
 * Assistant Dean, and that college's own Dean/OIC, minus whoever
 * actually clicked Unfinalize.
 *
 * The mirror image of ScheduleFinalized: unfinalizing reopens the
 * department's scheduling data for editing, which everyone above
 * should know about since it means a schedule they may have treated
 * as settled can change again.
 */
class ScheduleUnfinalized extends Notification
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
            'message' => "{$this->performedBy->name} unfinalized {$this->department->name}'s schedule for {$this->term->display_name}. It is editable again.",
        ];
    }
}