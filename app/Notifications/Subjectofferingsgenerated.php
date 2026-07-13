<?php

namespace App\Notifications;

use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to a college's stakeholders the moment Admin/Registrar
 * generates one or more new Subject Offerings for that college's
 * curriculum — see SubjectOfferingController::store(), fired only
 * after SubjectOfferingGeneratorService::generate() actually created
 * at least one offering ($summary['created'] > 0). A generate() call
 * that only re-confirmed already-existing offerings (skipped_existing
 * === created's total, nothing new) never fires this — there's
 * nothing new for the college to hear about.
 *
 * Unlike MasterGridScheduleSaved, this never needs to be grouped by
 * department — one generate() call always targets exactly one
 * Curriculum, and a Curriculum belongs to exactly one Program, which
 * belongs to exactly one Department. So this is always exactly one
 * notification per store() call, never batched across colleges.
 *
 * Recipients: Admin, Registrar, Assistant Dean, and that college's own
 * Dean/OIC — EXCEPT whoever actually performed the generation. Same
 * recipient rule as ScheduleFinalized/ScheduleUnfinalized/
 * MasterGridScheduleSaved — see
 * SubjectOfferingController::resolveStakeholders().
 *
 * Skipped entirely when the curriculum's program has no department_id
 * (there is no single Dean/OIC to address it to) — same reasoning as
 * every other department-scoped notification in this app.
 */
class SubjectOfferingsGenerated extends Notification
{
    public function __construct(
        private readonly Department $department,
        private readonly AcademicTerm $term,
        private readonly Curriculum $curriculum,
        private readonly User $performedBy,
        private readonly int $createdCount
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $offeringWord = $this->createdCount === 1 ? 'offering' : 'offerings';

        return [
            'department_id' => $this->department->id,
            'department_name' => $this->department->name,
            'academic_term_id' => $this->term->id,
            'academic_term_name' => $this->term->display_name,
            'curriculum_id' => $this->curriculum->id,
            'curriculum_name' => $this->curriculum->display_name,
            'performed_by_name' => $this->performedBy->name,
            'created_count' => $this->createdCount,
            'message' => "{$this->performedBy->name} generated {$this->createdCount} Subject {$offeringWord} for {$this->curriculum->display_name} — {$this->department->name}, {$this->term->display_name}.",
        ];
    }
}