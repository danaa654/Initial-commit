<?php

namespace App\Notifications;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to a college's stakeholders the moment Admin/Registrar commits
 * a Master Grid save that actually changed one or more of that
 * college's Subject Offerings — see
 * MasterGridController::notifyDepartmentsOfSave(), fired only after
 * the DB::transaction() in save() commits successfully, and only for
 * offerings whose day/time/room/faculty genuinely differ from what
 * was already committed ($changedOfferingIds — an unmodified block
 * riding along in the save payload does not trigger this).
 *
 * Batched per department, per save — one notification per affected
 * college summarizing how many of its offerings changed, not one
 * notification per Schedule row. A single "Save" click can touch many
 * subjects in the same college at once, and firing one notification
 * per row would flood the recipient's dropdown for what is, from
 * their perspective, one event.
 *
 * Recipients: Admin, Registrar, Assistant Dean, and that college's own
 * Dean/OIC — EXCEPT whoever actually performed the save (e.g. if
 * Admin clicked Save, Admin does not get this, but Registrar,
 * Assistant Dean, and the college's Dean/OIC all do). Same recipient
 * rule as ScheduleFinalized/ScheduleUnfinalized — see
 * TermFinalizationService::resolveStakeholders() and
 * MasterGridController::resolveStakeholders() for the two (currently
 * duplicated) implementations of this same rule.
 *
 * General Education offerings (department_id null) never reach this
 * notification at all — there is no single department-scoped
 * Dean/OIC to notify, same reasoning as every other department-scoped
 * notification in the app.
 */
class MasterGridScheduleSaved extends Notification
{
    public function __construct(
        private readonly Department $department,
        private readonly AcademicTerm $term,
        private readonly User $performedBy,
        private readonly int $offeringsCount
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $subjectWord = $this->offeringsCount === 1 ? 'subject' : 'subjects';

        return [
            'department_id' => $this->department->id,
            'department_name' => $this->department->name,
            'academic_term_id' => $this->term->id,
            'academic_term_name' => $this->term->display_name,
            'performed_by_name' => $this->performedBy->name,
            'offerings_count' => $this->offeringsCount,
            'message' => "{$this->performedBy->name} saved Master Grid changes for {$this->department->name} — {$this->offeringsCount} {$subjectWord} affected in {$this->term->display_name}.",
        ];
    }
}