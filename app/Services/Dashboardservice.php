<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for every number shown on any role dashboard.
 *
 * IMPORTANT ASSUMPTION — please confirm/adjust:
 * "Working Academic Term" is read via AcademicTerm::query()->active()->first()
 * (the `active` boolean column), the same term already shared globally through
 * HandleInertiaRequests. If your WorkingAcademicTermController resolves the
 * working term differently (e.g. a separate settings row), swap the body of
 * workingTerm() below to match — every other method here just takes the
 * resolved AcademicTerm in, so nothing else needs to change.
 *
 * All scheduling-related counts (offerings, faculty/room assignment,
 * conflicts, completion %) are scoped to a single term on purpose — mixing
 * terms would make "Scheduling Completion" meaningless.
 */
class DashboardService
{
    public function workingTerm(): ?AcademicTerm
    {
        return AcademicTerm::query()->active()->first();
    }

    /*
    |--------------------------------------------------------------------------
    | ADMIN
    |--------------------------------------------------------------------------
    */

    public function adminStats(?AcademicTerm $term): array
    {
        $activeTerm = AcademicTerm::where('status', 'Published')
            ->whereDate('class_start_date', '<=', now())
            ->whereDate('class_end_date', '>=', now())
            ->first();

        return [
            'active_term' => $activeTerm?->display_name,
            'working_term' => $term?->display_name,
            'departments' => Department::where('active', true)->count(),
            'programs' => Program::where('active', true)->count(),
            'faculty_members' => Faculty::count(),
            'active_rooms' => Room::where('active', true)->count(),
            'subject_offerings' => $term ? SubjectOffering::forTerm($term->id)->count() : 0,
            'published_schedules' => $term ? Schedule::forTerm($term->id)->count() : 0,
        ];
    }

    public function adminCharts(?AcademicTerm $term): array
    {
        return [
            'faculty_load' => $this->facultyLoadDistribution($term),
            'room_utilization' => $this->roomUtilization($term),
            'schedule_completion' => $this->scheduleCompletion($term),
            'subjects_by_department' => $this->subjectsByDepartment($term),
        ];
    }

    public function adminWidgets(?AcademicTerm $term): array
    {
        return [
            'unscheduled_subjects' => $this->unscheduledSubjects($term, 10),
            'conflicts' => $this->conflictSummary($term),
            'recent_activity' => $this->recentActivity(10),
            // Raw {id, department_id} roster + department list so the
            // Faculty Members card can cycle Total -> per-department ->
            // General Education client-side, same pattern as the Total
            // Faculty card on Teaching Assignments' Index.vue.
            'faculty_roster' => Faculty::get(['id', 'department_id']),
            'departments' => Department::where('active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTRAR
    |--------------------------------------------------------------------------
    */

    public function registrarStats(?AcademicTerm $term): array
    {
        if (! $term) {
            return $this->emptyRegistrarStats();
        }

        $totalOfferings = SubjectOffering::forTerm($term->id)->count();
        $scheduled = Schedule::forTerm($term->id)->distinct('subject_offering_id')->count('subject_offering_id');
        $facultyAssigned = TeachingAssignment::forTerm($term->id)->whereNotNull('faculty_id')->count();
        $roomsAssigned = Schedule::forTerm($term->id)->distinct('room_id')->count('room_id');

        return [
            'working_term' => $term->display_name,
            'subject_offerings' => $totalOfferings,
            'scheduled_subjects' => $scheduled,
            'remaining_subjects' => max($totalOfferings - $scheduled, 0),
            'faculty_assigned' => $facultyAssigned,
            'rooms_assigned' => $roomsAssigned,
            'completion_percent' => $totalOfferings > 0
                ? round(($scheduled / $totalOfferings) * 100, 1)
                : 0.0,
        ];
    }

    private function emptyRegistrarStats(): array
    {
        return [
            'working_term' => null,
            'subject_offerings' => 0,
            'scheduled_subjects' => 0,
            'remaining_subjects' => 0,
            'faculty_assigned' => 0,
            'rooms_assigned' => 0,
            'completion_percent' => 0.0,
        ];
    }

    public function registrarConflicts(?AcademicTerm $term): array
    {
        return $this->conflictSummary($term);
    }

    /*
    |--------------------------------------------------------------------------
    | DEAN / ASSISTANT DEAN (scoped to one department)
    |--------------------------------------------------------------------------
    */

    public function deanStats(User $user, ?AcademicTerm $term): array
    {
        $departmentId = $user->department_id;

        $facultyCount = Faculty::where('department_id', $departmentId)
            ->where('status', 'Active')
            ->count();

        $programsCount = Program::where('department_id', $departmentId)
            ->where('active', true)
            ->count();

        $sectionsCount = Section::whereHas(
            'curriculum.program',
            fn ($q) => $q->where('department_id', $departmentId)
        )->count();

        $offeringsQuery = $term
            ? SubjectOffering::forTerm($term->id)->whereHas(
                'program',
                fn ($q) => $q->where('department_id', $departmentId)
            )
            : SubjectOffering::whereRaw('1 = 0');

        $totalOfferings = (clone $offeringsQuery)->count();
        $offeringIds = (clone $offeringsQuery)->pluck('id');

        $scheduled = $term
            ? Schedule::forTerm($term->id)->whereIn('subject_offering_id', $offeringIds)
                ->distinct('subject_offering_id')->count('subject_offering_id')
            : 0;

        return [
            'working_term' => $term?->display_name,
            'faculty' => $facultyCount,
            'programs' => $programsCount,
            'sections' => $sectionsCount,
            'scheduled_subjects' => $scheduled,
            'remaining_subjects' => max($totalOfferings - $scheduled, 0),
        ];
    }

    public function deanCharts(User $user, ?AcademicTerm $term): array
    {
        $departmentId = $user->department_id;

        return [
            'faculty_load' => $this->facultyLoadDistribution($term, $departmentId),
            'department_progress' => $this->departmentSchedulingProgress($term, $departmentId),
        ];
    }

    public function deanTables(User $user, ?AcademicTerm $term): array
    {
        $departmentId = $user->department_id;

        return [
            'faculty_needing_assignment' => $this->facultyNeedingAssignment($departmentId, $term),
            'faculty_overload' => $this->facultyOverload($departmentId),
            'subjects_without_faculty' => $this->subjectsWithoutFaculty($departmentId, $term),
        ];
    }

    public function assistantDeanStats(User $user, ?AcademicTerm $term): array
    {
        $departmentId = $user->department_id;

        $offeringIds = $term
            ? SubjectOffering::forTerm($term->id)->whereHas(
                'program',
                fn ($q) => $q->where('department_id', $departmentId)
            )->pluck('id')
            : collect();

        $assignments = TeachingAssignment::whereIn('subject_offering_id', $offeringIds);

        return [
            'working_term' => $term?->display_name,
            'faculty_assignments' => (clone $assignments)->whereNotNull('faculty_id')->count(),
            'pending_assignments' => (clone $assignments)->whereNull('faculty_id')->count()
                + max($offeringIds->count() - $assignments->count(), 0),
            'conflicts' => $this->conflictSummary($term, $departmentId),
            'recent_activity' => $this->recentActivity(8, $departmentId),
            // Active faculty in this department with no teaching load at all
            // for the working term yet — same helper Dean's dashboard uses,
            // just surfaced here too since Assistant Dean shares that concern.
            // Guarded against a null department_id (e.g. an Assistant Dean
            // account not yet assigned to a department) since
            // facultyNeedingAssignment() requires a real int.
            'faculty_needing_assignment' => $departmentId
                ? $this->facultyNeedingAssignment($departmentId, $term)
                : collect(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shared building blocks
    |--------------------------------------------------------------------------
    */

    /**
     * Faculty Load Distribution — total assigned units per faculty member
     * for the working term, optionally scoped to one department. Feeds a
     * bar chart: labels = faculty names, data = units.
     */
    public function facultyLoadDistribution(?AcademicTerm $term, ?int $departmentId = null): array
    {
        if (! $term) {
            return ['labels' => [], 'data' => []];
        }

        $rows = TeachingAssignment::forTerm($term->id)
            ->join('subject_offerings', 'subject_offerings.id', '=', 'teaching_assignments.subject_offering_id')
            ->join('faculties', 'faculties.id', '=', 'teaching_assignments.faculty_id')
            ->when($departmentId, fn ($q) => $q->where('faculties.department_id', $departmentId))
            ->groupBy('faculties.id', 'faculties.first_name', 'faculties.last_name')
            ->select(
                'faculties.id',
                DB::raw("CONCAT(faculties.first_name, ' ', faculties.last_name) as name"),
                DB::raw('SUM(subject_offerings.units) as total_units')
            )
            ->orderByDesc('total_units')
            ->limit(15)
            ->get();

        return [
            'labels' => $rows->pluck('name')->all(),
            'data' => $rows->pluck('total_units')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    /**
     * Room Utilization — hours actually scheduled per room for the working
     * term against Room::WEEKLY_CAPACITY_HOURS.
     */
    public function roomUtilization(?AcademicTerm $term): array
    {
        if (! $term) {
            return ['labels' => [], 'data' => []];
        }

        $rows = Schedule::forTerm($term->id)
            ->join('rooms', 'rooms.id', '=', 'schedules.room_id')
            ->groupBy('rooms.id', 'rooms.room_code')
            ->select(
                'rooms.room_code',
                DB::raw('SUM(schedules.end_minutes - schedules.start_minutes) / 60 as hours_used')
            )
            ->orderByDesc('hours_used')
            ->limit(15)
            ->get();

        return [
            'labels' => $rows->pluck('room_code')->all(),
            'data' => $rows->pluck('hours_used')->map(fn ($v) => round((float) $v, 1))->all(),
            'capacity' => Room::WEEKLY_CAPACITY_HOURS,
        ];
    }

    /**
     * Schedule Completion — doughnut of Scheduled vs Remaining subject
     * offerings for the working term.
     */
    public function scheduleCompletion(?AcademicTerm $term): array
    {
        if (! $term) {
            return ['labels' => ['Scheduled', 'Remaining'], 'data' => [0, 0]];
        }

        $total = SubjectOffering::forTerm($term->id)->count();
        $scheduled = Schedule::forTerm($term->id)->distinct('subject_offering_id')->count('subject_offering_id');

        return [
            'labels' => ['Scheduled', 'Remaining'],
            'data' => [$scheduled, max($total - $scheduled, 0)],
        ];
    }

    /**
     * Subjects by Department — count of subject offerings in the working
     * term, grouped by the owning department.
     */
    public function subjectsByDepartment(?AcademicTerm $term): array
    {
        if (! $term) {
            return ['labels' => [], 'data' => []];
        }

        $rows = SubjectOffering::forTerm($term->id)
            ->join('programs', 'programs.id', '=', 'subject_offerings.program_id')
            ->join('departments', 'departments.id', '=', 'programs.department_id')
            ->groupBy('departments.id', 'departments.abbreviation')
            ->select('departments.abbreviation', DB::raw('COUNT(*) as total'))
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $rows->pluck('abbreviation')->all(),
            'data' => $rows->pluck('total')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    /**
     * Department Scheduling Progress — Scheduled vs total offerings for
     * one department, for a simple horizontal progress chart.
     */
    public function departmentSchedulingProgress(?AcademicTerm $term, int $departmentId): array
    {
        if (! $term) {
            return ['scheduled' => 0, 'total' => 0, 'percent' => 0.0];
        }

        $offeringIds = SubjectOffering::forTerm($term->id)
            ->whereHas('program', fn ($q) => $q->where('department_id', $departmentId))
            ->pluck('id');

        $total = $offeringIds->count();
        $scheduled = Schedule::forTerm($term->id)
            ->whereIn('subject_offering_id', $offeringIds)
            ->distinct('subject_offering_id')
            ->count('subject_offering_id');

        return [
            'scheduled' => $scheduled,
            'total' => $total,
            'percent' => $total > 0 ? round(($scheduled / $total) * 100, 1) : 0.0,
        ];
    }

    public function unscheduledSubjects(?AcademicTerm $term, int $limit = 10): Collection
    {
        if (! $term) {
            return collect();
        }

        $scheduledIds = Schedule::forTerm($term->id)->pluck('subject_offering_id');

        return SubjectOffering::forTerm($term->id)
            ->whereNotIn('id', $scheduledIds)
            ->with(['subject:id,subject_code,descriptive_title', 'section:id,section_code'])
            ->limit($limit)
            ->get(['id', 'subject_id', 'section_id', 'edp_code'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'edp_code' => $o->edp_code,
                'subject' => $o->subject?->subject_code,
                'title' => $o->subject?->descriptive_title,
                'section' => $o->section?->section_code,
            ]);
    }

    /**
     * Overlap-based conflict detection directly against `schedules` for the
     * working term. If a dedicated ScheduleConflictService already exists in
     * the codebase, prefer delegating to it instead of this — this is a
     * self-contained fallback so the dashboard has real numbers either way.
     */
    public function conflictSummary(?AcademicTerm $term, ?int $departmentId = null): array
    {
        if (! $term) {
            return ['faculty' => 0, 'room' => 0, 'section' => 0, 'time' => 0];
        }

        $base = Schedule::query()
            ->where('schedules.academic_term_id', $term->id)
            ->join('subject_offerings', 'subject_offerings.id', '=', 'schedules.subject_offering_id')
            ->when($departmentId, function ($q) use ($departmentId) {
                $q->join('programs', 'programs.id', '=', 'subject_offerings.program_id')
                    ->where('programs.department_id', $departmentId);
            });

        $facultyConflicts = (clone $base)
            ->select('schedules.id', 'schedules.faculty_id', 'schedules.day', 'schedules.start_minutes', 'schedules.end_minutes')
            ->whereNotNull('schedules.faculty_id')
            ->get();

        return [
            'faculty' => $this->countOverlaps($facultyConflicts, 'faculty_id'),
            'room' => $this->countOverlaps(
                (clone $base)->select('schedules.id', 'schedules.room_id', 'schedules.day', 'schedules.start_minutes', 'schedules.end_minutes')->get(),
                'room_id'
            ),
            'section' => $this->countOverlaps(
                (clone $base)->select('schedules.id', 'subject_offerings.section_id', 'schedules.day', 'schedules.start_minutes', 'schedules.end_minutes')->get(),
                'section_id'
            ),
            // "Time" conflicts = any schedule block that falls outside the
            // term's configured school hours (school_start_time/school_end_time).
            'time' => $this->countOutOfBoundsBlocks($term, $departmentId),
        ];
    }

    /**
     * Given a flat collection of {key_field, day, start_minutes, end_minutes}
     * rows, count how many rows participate in at least one time overlap
     * with another row sharing the same key (same faculty / room / section).
     */
    private function countOverlaps(Collection $rows, string $keyField): int
    {
        $conflicting = collect();

        $rows->groupBy($keyField)->each(function (Collection $group) use (&$conflicting) {
            $group = $group->values();

            for ($i = 0; $i < $group->count(); $i++) {
                for ($j = $i + 1; $j < $group->count(); $j++) {
                    $a = $group[$i];
                    $b = $group[$j];

                    if (
                        $a->day === $b->day
                        && $a->start_minutes < $b->end_minutes
                        && $b->start_minutes < $a->end_minutes
                    ) {
                        $conflicting->put($a->id, true);
                        $conflicting->put($b->id, true);
                    }
                }
            }
        });

        return $conflicting->count();
    }

    private function countOutOfBoundsBlocks(AcademicTerm $term, ?int $departmentId = null): int
    {
        if (! $term->school_start_time || ! $term->school_end_time) {
            return 0;
        }

        $startMinutes = (int) $term->school_start_time->format('H') * 60 + (int) $term->school_start_time->format('i');
        $endMinutes = (int) $term->school_end_time->format('H') * 60 + (int) $term->school_end_time->format('i');

        return Schedule::query()
            ->where('schedules.academic_term_id', $term->id)
            ->join('subject_offerings', 'subject_offerings.id', '=', 'schedules.subject_offering_id')
            ->when($departmentId, function ($q) use ($departmentId) {
                $q->join('programs', 'programs.id', '=', 'subject_offerings.program_id')
                    ->where('programs.department_id', $departmentId);
            })
            ->where(function ($q) use ($startMinutes, $endMinutes) {
                $q->where('schedules.start_minutes', '<', $startMinutes)
                    ->orWhere('schedules.end_minutes', '>', $endMinutes);
            })
            ->count();
    }

    public function recentActivity(int $limit = 10, ?int $departmentId = null)
    {
        // Assumes a generic activity_log table (spatie/laravel-activitylog)
        // is or will be present. If it isn't installed yet, this returns an
        // empty collection rather than throwing, so the widget degrades
        // gracefully instead of breaking the dashboard.
        if (! \Illuminate\Support\Facades\Schema::hasTable('activity_log')) {
            return collect();
        }

        return DB::table('activity_log')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['description', 'causer_id', 'created_at']);
    }

    private function facultyNeedingAssignment(int $departmentId, ?AcademicTerm $term)
    {
        return Faculty::where('department_id', $departmentId)
            ->where('status', 'Active')
            ->whereDoesntHave('teachingAssignments', function ($q) use ($term) {
                if ($term) {
                    $q->forTerm($term->id);
                }
            })
            ->get(['id', 'first_name', 'last_name', 'max_units']);
    }

    private function facultyOverload(int $departmentId)
    {
        return Faculty::where('faculties.department_id', $departmentId)
            ->join('teaching_assignments', 'teaching_assignments.faculty_id', '=', 'faculties.id')
            ->join('subject_offerings', 'subject_offerings.id', '=', 'teaching_assignments.subject_offering_id')
            ->groupBy('faculties.id', 'faculties.first_name', 'faculties.last_name', 'faculties.max_units')
            ->havingRaw('SUM(subject_offerings.units) > faculties.max_units')
            ->select(
                'faculties.id',
                'faculties.first_name',
                'faculties.last_name',
                'faculties.max_units',
                DB::raw('SUM(subject_offerings.units) as assigned_units')
            )
            ->get();
    }

    private function subjectsWithoutFaculty(int $departmentId, ?AcademicTerm $term)
    {
        if (! $term) {
            return collect();
        }

        return SubjectOffering::forTerm($term->id)
            ->whereHas('program', fn ($q) => $q->where('department_id', $departmentId))
            ->whereDoesntHave('teachingAssignment', fn ($q) => $q->whereNotNull('faculty_id'))
            ->with(['subject:id,subject_code,descriptive_title', 'section:id,section_code'])
            ->get(['id', 'subject_id', 'section_id', 'edp_code']);
    }
}