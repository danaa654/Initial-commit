<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\SubjectOffering;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 of Generate Schedule — "Session Settings". Once a Section has
 * been picked (Step 1), this fetches every Subject Offering waiting to
 * be scheduled for that section and lets the Registrar/Admin edit,
 * per subject:
 *
 *   - Meetings per week (1x/2x/3x)         -> subject_offerings.meetings_per_week
 *   - Preferred faculty (optional override) -> teaching_assignments
 *   - Preferred room (optional override)    -> room_subject_offering
 *
 * Nothing here runs the Greedy Scheduling Algorithm — that's still
 * GreedyScheduleService, unchanged, and still only runs when the
 * Registrar clicks "Generate" in Step 2. This service exists purely so
 * that step has real data (and real warnings) to show before the
 * algorithm ever sees it, per the spec's edge cases: a subject with no
 * qualified faculty at all, or a duration that doesn't cleanly divide
 * by the chosen meeting count, must be visible HERE, not discovered
 * only after Generate silently fails or mis-splits the hours.
 *
 * Faculty eligibility mirrors GreedyScheduleService::
 * resolveAutoFacultyCandidates() and ScheduleRecommendationService::
 * suggestFaculty() exactly (same Departmental / Cross-Department /
 * General scope rules) — if that rule ever changes, mirror it in all
 * three places so "who could teach this" never disagrees between the
 * Session Settings dropdown, the Greedy Scheduler, and the Interactive
 * Review's suggestion panel.
 */
class SessionSettingsService
{
    /** Allowed values for the Meetings per Week dropdown. */
    public const ALLOWED_MEETINGS_PER_WEEK = [1, 2, 3];

    /**
     * @param  array{department_id:int,program_id:int,specialization_id:?int,year_level:int,section_id:int}  $filters
     * @return array{section_id:int,subjects:array<int,array>,has_offerings:bool}
     */
    public function build(AcademicTerm $term, array $filters): array
    {
        $offerings = $this->loadOfferings($term, $filters);

        $preferredRooms = $this->preferredRoomByOffering($offerings->pluck('id')->all());

        $subjects = $offerings
            ->map(fn (SubjectOffering $offering) => $this->presentOffering($offering, $preferredRooms))
            ->values()
            ->all();

        return [
            'section_id' => $filters['section_id'],
            'subjects' => $subjects,
            'has_offerings' => count($subjects) > 0,
        ];
    }

    /**
     * Persists the edited Session Settings for one or more Subject
     * Offerings. Called when the Registrar clicks "Generate" in Step
     * 2 — saved BEFORE the Greedy Scheduler runs, so the algorithm
     * (and any future re-Generate) always reads back whatever was just
     * confirmed here.
     *
     * Session Settings edits meetings_per_week and, now, the actual
     * weekly duration ("hours") — since the curriculum's stated hours
     * don't always match real classroom time. Preferred faculty/room
     * stay exactly as Faculty Loading / Manage Subjects left them
     * (see teachingAssignment / room_subject_offering). This method
     * deliberately does NOT touch either of those, even though a
     * Preferred Faculty/Room CAN still exist from those other
     * workspaces and GreedyScheduleService will still honor it — this
     * step just isn't where that gets set or cleared.
     *
     * @param  array<int,array{subject_offering_id:int,hours:int,meetings_per_week:int}>  $settings
     */
    public function save(array $settings): void
    {
        DB::transaction(function () use ($settings) {
            foreach ($settings as $row) {
                $offering = SubjectOffering::find($row['subject_offering_id']);

                if (! $offering) {
                    continue;
                }

                $offering->update([
                    'hours' => $row['hours'] ?? $offering->hours,
                    'meetings_per_week' => $row['meetings_per_week'] ?? SubjectOffering::DEFAULT_MEETINGS_PER_WEEK,
                ]);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Offerings
    |--------------------------------------------------------------------------
    */

    /**
     * Same "still needs scheduling" scope as GreedyScheduleService::
     * loadOfferings() — Scheduled/Completed/Archived offerings never
     * reach Session Settings, since re-configuring meetings/faculty/
     * room for a class that already has a committed schedule belongs
     * to the Edit Schedule modal, not here.
     */
    private function loadOfferings(AcademicTerm $term, array $filters): Collection
    {
        $offerings = SubjectOffering::with([
                'subject',
                'teachingAssignment.faculty',
                'program.department',
                'section',
                'curriculum',
            ])
            ->forTerm($term->id)
            ->where('program_id', $filters['program_id'])
            ->where('year_level', $filters['year_level'])
            ->where('section_id', $filters['section_id'])
            ->whereHas('program', fn ($q) => $q->where('department_id', $filters['department_id']))
            ->get()
            ->reject(fn (SubjectOffering $o) => in_array($o->overall_status, [
                SubjectOffering::STATUS_SCHEDULED,
                SubjectOffering::STATUS_COMPLETED,
                SubjectOffering::STATUS_ARCHIVED,
            ], true));

        if (! empty($filters['specialization_id'])) {
            $offerings = $offerings->filter(
                fn (SubjectOffering $o) => (int) $o->curriculum?->specialization_id === (int) $filters['specialization_id']
            );
        }

        return $offerings->values();
    }

    private function presentOffering(SubjectOffering $offering, array $preferredRooms): array
    {
        $eligibleFaculty = $this->eligibleFaculty($offering);
        $eligibleRooms = $this->eligibleRooms($offering);

        $meetings = $offering->meetings_per_week ?: SubjectOffering::DEFAULT_MEETINGS_PER_WEEK;
        $dividesEvenly = $offering->hours > 0 && fmod((float) $offering->hours, $meetings) === 0.0;

        $currentFaculty = $offering->teachingAssignment?->faculty_id;
        $currentRoom = $preferredRooms[$offering->id] ?? null;

        return [
            'subject_offering_id' => $offering->id,
            'edp_code' => $offering->edp_code,
            'subject_code' => $offering->subject?->subject_code,
            'descriptive_title' => $offering->subject?->descriptive_title,
            'classification' => $offering->classification,
            'room_type' => $offering->room_type,
            'units' => $offering->units,

            // Read-only, from the offering itself.
            'total_hours_per_week' => $offering->hours,

            // Editable.
            'meetings_per_week' => $meetings,
            'hours_per_meeting' => $offering->hours_per_meeting,
            'divides_evenly' => $dividesEvenly,

            'preferred_faculty_id' => $currentFaculty,
            'preferred_room_id' => $currentRoom,

            'eligible_faculty' => $eligibleFaculty
                ->map(fn (Faculty $f) => [
                    'id' => $f->id,
                    'full_name' => $f->full_name,
                ])
                ->values()
                ->all(),

            'eligible_rooms' => $eligibleRooms
                ->map(fn (Room $r) => [
                    'id' => $r->id,
                    'room_code' => $r->room_code,
                    'room_type' => $r->room_type,
                ])
                ->values()
                ->all(),

            // Edge case: flag immediately, don't let this reach the
            // algorithm silently — per spec, "Subject has no qualified
            // faculty at all -> flag immediately in Session Settings".
            'has_qualified_faculty' => $eligibleFaculty->isNotEmpty(),
            'has_eligible_room' => $eligibleRooms->isNotEmpty(),
        ];
    }

    /**
     * Every active Faculty member scope-eligible to teach this
     * offering — deliberately load-agnostic (no "current load" sort,
     * no exclusion for being busy at a specific time) since Session
     * Settings has no day/time yet; that's what makes this a listing
     * of WHO COULD ever teach it, not a live availability check. Load-
     * aware ranking still only happens inside GreedyScheduleService
     * once actual placement begins.
     *
     * Mirrors GreedyScheduleService::resolveAutoFacultyCandidates()'s
     * scope table exactly — see that method's docblock for the full
     * Departmental / Cross-Department / General rules.
     */
    private function eligibleFaculty(SubjectOffering $offering): Collection
    {
        $departmentId = $offering->program?->department_id;
        $isMajor = $offering->classification === SubjectOffering::CLASSIFICATION_MAJOR;

        return Faculty::where('status', true)
            ->where(function ($query) use ($departmentId, $isMajor) {
                if ($isMajor) {
                    $query->whereIn('faculty_scope', ['departmental', 'cross_department'])
                        ->where('department_id', $departmentId);
                } else {
                    $query->whereIn('faculty_scope', ['general', 'cross_department']);
                }
            })
            ->get()
            // full_name is a derived accessor (TRIM(CONCAT(first_name,
            // ' ', last_name)) — see MasterGridDataService's identical
            // raw-SQL version of this same concat), not a real column,
            // so it can't be used in ->orderBy(). Sort the fetched
            // collection instead.
            ->sortBy(fn (Faculty $f) => $f->full_name)
            ->values();
    }

    /**
     * Rooms whose type and room_group match this offering — mirrors
     * ScheduleRecommendationService::suggestRooms()'s filter, minus
     * the availability check (again: no day/time exists yet here).
     */
    private function eligibleRooms(SubjectOffering $offering): Collection
    {
        $programCode = $offering->program?->code;

        return Room::schedulable()
            ->with('roomGroups')
            ->get()
            ->filter(function (Room $room) use ($offering, $programCode) {
                if (! empty($offering->room_type) && $room->room_type !== $offering->room_type) {
                    return false;
                }

                return in_array('General', $room->room_group_codes, true)
                    || ($programCode && in_array($programCode, $room->room_group_codes, true));
            })
            ->sortBy('room_code')
            ->values();
    }

    /**
     * subject_offering_id => preferred room_id, for every offering ID
     * given — one query instead of N, same batching pattern as
     * MasterGridDataService::preferredRoomByOffering(). Still shown
     * (read-only) in the Session Settings table so the "no eligible
     * room" edge case can be flagged, even though this step no longer
     * lets the Registrar set/change it.
     */
    private function preferredRoomByOffering(array $offeringIds): array
    {
        if (empty($offeringIds)) {
            return [];
        }

        return DB::table('room_subject_offering')
            ->whereIn('subject_offering_id', $offeringIds)
            ->pluck('room_id', 'subject_offering_id')
            ->all();
    }
}