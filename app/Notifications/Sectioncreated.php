<?php

namespace App\Notifications;

use App\Models\Curriculum;
use App\Models\Department;
use App\Models\Section;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to a college's stakeholders the moment Admin/Registrar creates
 * a new Section under one of that college's curricula — see
 * SectionController::notifyDepartmentOfSectionCreated(), fired right
 * after SectionController::store()'s AuditLogService::log() call.
 *
 * Unlike SubjectOfferingsGenerated/MasterGridScheduleSaved, a Section
 * is NOT scoped to any particular Academic Term — Sections are
 * curriculum-scoped and reused across terms (see Section model) — so
 * this notification deliberately carries no academic_term_id/name.
 *
 * Also unlike the other department-scoped notifications so far, THIS
 * ONE carries an actual actionable target: clicking it in the Topbar
 * takes the recipient straight to that Section's Edit page (see
 * Topbar.vue's goToSection()), the same "dismiss + navigate" pattern
 * FacultyLoadOverloadRequested/AppliedByAdmin already use for Faculty
 * Loading. section_id is included in the payload specifically to make
 * that possible.
 *
 * Recipients: Admin, Registrar, Assistant Dean, and that college's own
 * Dean/OIC — EXCEPT whoever actually created the Section. Same
 * recipient rule as every other department-scoped notification in
 * this app — see SectionController::resolveStakeholders().
 *
 * Skipped entirely when the curriculum's program has no department_id
 * — same reasoning as SubjectOfferingsGenerated.
 */
class SectionCreated extends Notification
{
    public function __construct(
        private readonly Department $department,
        private readonly Curriculum $curriculum,
        private readonly Section $section,
        private readonly User $performedBy
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $program = $this->curriculum->program;
        $specialization = $this->curriculum->specialization;

        $programLabel = $specialization
            ? "{$program?->code} - {$specialization->name}"
            : $program?->code;

        return [
            'department_id' => $this->department->id,
            'department_name' => $this->department->name,
            'curriculum_id' => $this->curriculum->id,
            'curriculum_name' => $this->curriculum->display_name,
            'section_id' => $this->section->id,
            'section_code' => $this->section->section_code,
            'section_name' => $this->section->section_name,
            'year_level' => $this->section->year_level,
            'program_id' => $program?->id,
            'program_code' => $program?->code,
            'performed_by_name' => $this->performedBy->name,
            'message' => "{$this->performedBy->name} created Section {$this->section->section_code} ({$programLabel}, Year {$this->section->year_level}) under {$this->department->name}.",
        ];
    }
}