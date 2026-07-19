<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\TeachingAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Greedy Schedule Generator — v2 (Intelligent Scheduling Engine).
 *
 * Produces an in-memory DRAFT/PREVIEW schedule for a single Section
 * (Department + Program + [Specialization] + Year Level + Section).
 * Nothing is persisted here — this service only computes and returns
 * an array of proposed blocks. Saving is a deliberately separate
 * concern (see MasterGridController::save()).
 *
 * ── What changed from v1 ─────────────────────────────────────────────
 * v1 required a Subject Offering to already have BOTH an assigned
 * Faculty (Faculty Loading) and a preferred Room before it could be
 * placed reliably, and any single unplaceable subject could make the
 * whole run look like it produced nothing. v2 never requires either:
 *
 *   Faculty — if Faculty Loading already assigned someone, we try to
 *   honor that first and only fall back to an automatic search if that
 *   faculty genuinely cannot take the slot (busy, overloaded,
 *   inactive). If nobody is assigned at all, we search automatically
 *   from the very start — same department -> qualified for the
 *   subject's classification -> lowest current load -> no conflict.
 *
 *   Room — the preferred Room (if any) is always tried first, but a
 *   fully automatic, type/program/capacity-compatible Room search
 *   always runs immediately after, in the same pass.
 *
 * Every subject is still processed independently and greedily (first
 * conflict-free combination wins, no backtracking, no reshuffling of
 * already-accepted blocks — see findPlacement()). If one subject can't
 * be placed anywhere, we record why and move on to the next one; we
 * never abort the batch. The goal is maximum subjects scheduled, not
 * an all-or-nothing batch.
 *
 * ── Why conflict-checking is in-memory only ─────────────────────────
 * There is no need to also query the `schedules` table here — Save
 * Schedule (MasterGridController::save()) re-validates the whole
 * preview against both the in-memory set AND `schedules` via
 * ScheduleValidationService right before committing. This service's
 * only job is to produce a good first draft; every conflict check here
 * is against the blocks generated within THIS SAME preview run.
 *
 * ── Multi-meeting subjects (meetings_per_week) ──────────────────────
 * A subject configured in Session Settings to meet 2x or 3x a week
 * produces MULTIPLE result rows here — one per meeting day, all
 * sharing subject_offering_id/faculty_id/room_id/start_minutes/
 * end_minutes, differing only in `day` — never a single row spanning
 * several days. This mirrors exactly how `schedules` now stores them
 * (see the migration making schedules unique on [subject_offering_id,
 * day] instead of subject_offering_id alone) and how the Master Grid
 * already renders/edits one block per row.
 *
 * Per spec, a subject's SAME faculty, SAME room, and SAME time-of-day
 * are used across every one of its meetings — day-combinations are
 * restricted to standard pairings (1x: any single day; 2x: MW / TTh /
 * WF / MTh; 3x: MWF or TThS), and placement only accepts a combo if
 * EVERY day in it is simultaneously free for that faculty/room/section
 * at the same start/end — see resolveDayCombos() and findPlacement().
 */
class GreedyScheduleService
{
    /**
     * Standard day-combinations per spec, tried in this order. Each
     * combo is only offered as a candidate if every day in it is
     * actually a working day for the term (see resolveDayCombos()).
     */
    private const DAY_COMBOS_2X = [
        ['monday', 'wednesday'],
        ['tuesday', 'thursday'],
        ['wednesday', 'friday'],
        ['monday', 'thursday'],
    ];

    private const DAY_COMBOS_3X = [
        ['monday', 'wednesday', 'friday'],
        ['tuesday', 'thursday', 'saturday'],
    ];

    /**
     * Entry point. Generates a draft schedule for one Section.
     *
     * @param  AcademicTerm  $term
     * @param  array{department_id:int,program_id:int,specialization_id:?int,year_level:int,section_id:int}  $filters
     * @return array{academic_term_id:int,section_id:int,generated_at:string,blocks:array<int,array>,scheduled_count:int,unscheduled_count:int}
     */
    public function generateForSection(AcademicTerm $term, array $filters): array
    {
        $section = Section::find($filters['section_id']);

        $offerings = $this->loadOfferings($term, $filters);

        $offerings = $this->applyPriorityOrder($offerings);

        $grid = $this->buildTimeGrid($term);

        // Running faculty load, seeded from whatever is already
        // committed in Faculty Loading for this term (units), then
        // incremented in-memory as this run places more subjects. This
        // is what "lowest current workload" and "faculty overload"
        // checks are measured against — always live, never stale.
        $facultyLoad = $this->initialFacultyLoad($term);

        // Running room-usage count for THIS run only (rooms have no
        // "max load" concept, so there's nothing to seed from existing
        // data — every room starts at 0 uses per run). Used purely to
        // spread subjects across every eligible General/type-matching
        // room instead of the search always finding the same smallest-
        // capacity room "available" and picking it again — see
        // candidateRooms() for why that happened.
        $roomUsage = [];

        // Seeded from every Schedule row already committed for this
        // term — across ALL sections, not just the one being
        // generated. Without this, the algorithm only knows about
        // blocks it places during THIS run and happily assigns a
        // room/faculty/section slot that's already taken by a
        // previously-saved class elsewhere in the term. That produced
        // previews the algorithm itself marked "success" but that
        // immediately failed validation the moment
        // ScheduleValidationService checked them against the real
        // `schedules` table (which it does check) — the Schedule
        // Preview would show CONFLICT rows straight out of Generate,
        // before the Registrar ever touched anything. See
        // initialCommittedBlocks() below.
        $placedBlocks = $this->initialCommittedBlocks($term);
        $scheduledSubjectIds = [];
        $results = [];

        foreach ($offerings as $offering) {
            // Duplicate subject guard — the same subject should never be
            // scheduled twice for the same section in one run.
            if (in_array($offering->subject_id, $scheduledSubjectIds, true)) {
                $results[] = $this->presentBlock($offering, null, 'skipped', 'Duplicate subject — already scheduled for this section.');
                continue;
            }

            $meetings = $offering->meetings_per_week ?: SubjectOffering::DEFAULT_MEETINGS_PER_WEEK;
            $totalMinutes = (int) $offering->hours * 60;

            if ($totalMinutes <= 0) {
                $this->debug("Processing: {$this->subjectLabel($offering)}\n\nRejected — Subject Offering has no hours configured.");

                $results[] = $this->presentBlock($offering, null, 'unscheduled', 'Subject Offering has no hours configured.');
                continue;
            }

            // Same duration on every meeting of a subject — per spec, a
            // subject is never split into uneven per-meeting durations
            // even when total hours don't divide evenly (Session
            // Settings warns about that at edit time; here we just
            // round to the nearest minute so the algorithm always has
            // a single, consistent per-meeting length to place).
            $meetingMinutes = (int) round($totalMinutes / $meetings);

            $dayCombos = $this->resolveDayCombos($meetings, $grid['working_days']);

            if (empty($dayCombos)) {
                $this->debug("Rejected: {$this->subjectLabel($offering)}\n\nReason:\nNo valid day-combination for {$meetings}x/week given this term's working days.");

                $results[] = $this->presentBlock(
                    $offering,
                    null,
                    'unscheduled',
                    "No valid day-combination for {$meetings}x/week meetings — this term doesn't have enough compatible working days enabled."
                );
                continue;
            }

            // "Spread wider" policy — prefer whichever valid day-combo
            // currently leaves this SECTION'S week most evenly loaded,
            // rather than always trying the fixed list in the same
            // order (which is what made everything default to Mon/Wed
            // and pile up there: DAY_COMBOS_2X always tries Mon/Wed
            // first, for every subject, every section, with no memory
            // of what that section already has that day). This is a
            // soft preference, not a hard rule — findPlacement() below
            // still falls through to every other combo/room/time if
            // the lightest-day choice has no actual room/faculty
            // availability, so a subject is never left unscheduled
            // just to keep the week balanced.
            $dayCombos = $this->sortDayCombosByLoad($dayCombos, $placedBlocks, $offering->section_id);

            $assignedFaculty = $this->resolveAssignedFaculty($offering);

            $this->debug(sprintf(
                "Processing: %s (%dx/week, %d min/meeting)\n\nFaculty:\n%s\n\nPreferred Room:\n%s",
                $this->subjectLabel($offering),
                $meetings,
                $meetingMinutes,
                $assignedFaculty ? "{$assignedFaculty->full_name} (Source: Faculty Loading)" : 'None assigned — will search automatically.',
                $this->preferredRoomCode($offering) ?? 'None set — will search automatically.'
            ));

            $placement = $this->findPlacement($offering, $section, $grid, $dayCombos, $meetingMinutes, $assignedFaculty, $facultyLoad, $placedBlocks, $roomUsage);

            if (! $placement) {
                $reason = $this->unscheduledReason($offering, $assignedFaculty);

                $this->debug("Rejected: {$this->subjectLabel($offering)}\n\nReason:\n{$reason}");

                $results[] = $this->presentBlock($offering, null, 'unscheduled', $reason);
                continue;
            }

            /** @var Faculty|null $usedFaculty */
            $usedFaculty = $placement['faculty'];

            foreach ($placement['days'] as $day) {
                $placedBlocks[] = [
                    'room_id' => $placement['room']->id,
                    'faculty_id' => $usedFaculty?->id,
                    'section_id' => $offering->section_id,
                    'day' => $day,
                    'start' => $placement['start'],
                    'end' => $placement['end'],
                ];

                $roomUsage[$placement['room']->id] = ($roomUsage[$placement['room']->id] ?? 0) + 1;
            }

            // Only the 'auto' path adds NEW load here — an 'assigned'
            // placement's units are already part of $facultyLoad from
            // initialFacultyLoad() (that's the whole reason
            // assignedFacultyOverCap() above doesn't add them again
            // either). Incrementing here too would push a faculty
            // member pre-assigned to two or three subjects artificially
            // over cap by the time this run reaches their second or
            // third one, for the exact same double-counting reason.
            if ($usedFaculty && $placement['faculty_source'] === 'auto') {
                $facultyLoad[$usedFaculty->id] = ($facultyLoad[$usedFaculty->id] ?? 0) + (int) $offering->units;
            }

            $scheduledSubjectIds[] = $offering->subject_id;

            $this->debug(sprintf(
                "Accepted: %s\n\nFaculty: %s (%s)\nRoom: %s\nDays: %s\nTime: %s - %s",
                $this->subjectLabel($offering),
                $usedFaculty?->full_name ?? 'Unassigned',
                $placement['faculty_source'] ?? 'none',
                $placement['room']->room_code,
                implode(', ', array_map('ucfirst', $placement['days'])),
                $this->label($placement['start']),
                $this->label($placement['end'])
            ));

            // One result row PER MEETING DAY — see the class docblock's
            // "Multi-meeting subjects" note for why this is a flat list
            // of rows rather than one row carrying several days.
            foreach ($placement['days'] as $day) {
                $results[] = $this->presentBlock($offering, [
                    'room' => $placement['room'],
                    'faculty' => $usedFaculty,
                    'faculty_source' => $placement['faculty_source'],
                    'day' => $day,
                    'start' => $placement['start'],
                    'end' => $placement['end'],
                ], 'preview', null);
            }
        }

        $scheduledCount = count($scheduledSubjectIds);
        $unscheduledCount = collect($results)->where('status', '!=', 'preview')->count();

        $this->debug("Preview Result\n\nScheduled: {$scheduledCount}\nFailed: {$unscheduledCount}");

        return [
            'academic_term_id' => $term->id,
            'section_id' => $filters['section_id'],
            'generated_at' => now()->toIso8601String(),
            'blocks' => $results,
            'scheduled_count' => $scheduledCount,
            'unscheduled_count' => $unscheduledCount,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Subject Offering Source
    |--------------------------------------------------------------------------
    */

    /**
     * Only Subject Offerings for: Active Academic Term, selected
     * Department (via Program), Program, Year Level, and Section.
     * Already-scheduled/completed/archived offerings are excluded —
     * "ignore already scheduled offerings" per spec.
     */
    private function loadOfferings(AcademicTerm $term, array $filters): Collection
    {
        $offerings = SubjectOffering::with([
                'subject.roomGroups',
                'teachingAssignment.faculty',
                'preferredByRooms',
                'program.department',
                'section',
                // Needed for the specialization filter below — a
                // Curriculum (not a Subject) is what actually carries
                // specialization_id. See the note below on why this
                // replaced the old subject->room_group_codes check.
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

        // Specialization is an optional narrowing filter (e.g.
        // BSCRIM's FB/LD/QD/FI tracks). This used to compare the
        // chosen Specialization's code against the OFFERING'S
        // SUBJECT's room_group_codes — but Subject::room_group_codes
        // (via SubjectRoomGroup) is an entirely different taxonomy:
        // PROGRAM-level tags like "General", "BSED", "BSHM", "BSCRIM".
        // A Specialization's code ("ENG", "FI", "FB", "LD", "QD") never
        // appears in that list, by construction — the two never
        // overlap except by coincidence. That's why this filter never
        // actually matched anything: BSED-ENG-1A's subjects (UTS, MMW,
        // GENSOC, EDUC1, etc.) all carry program-level tags like
        // "BSED"/"General", none of which equal "ENG", so the whole
        // offering list came back empty every time a specialization
        // was selected — 0 scheduled, 0 failed, regardless of how many
        // Subject Offerings actually existed.
        //
        // Specialization actually lives on Curriculum
        // (curriculum.specialization_id), and every Subject Offering
        // already points at the Curriculum it was generated from — see
        // SubjectOfferingGeneratorService::createOffering(). This is
        // also exactly how SubjectOfferingController::
        // filteredOfferingsQuery() already filters by specialization
        // (`whereHas('curriculum', fn ($c) => $c->where(
        // 'specialization_id', $specializationId))`), so this now
        // matches that same, already-correct pattern instead of
        // inventing a second, incompatible one here.
        if (! empty($filters['specialization_id'])) {
            $offerings = $offerings->filter(
                fn (SubjectOffering $o) => (int) $o->curriculum?->specialization_id === (int) $filters['specialization_id']
            );
        }

        return $offerings->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Greedy Priority Order
    |--------------------------------------------------------------------------
    */

    /**
     * Priority, highest first:
     *   1. Subjects with an assigned faculty (from Faculty Loading)
     *   2. Among the rest, subjects with the FEWEST scope-eligible
     *      auto-search faculty candidates ("scarcity") — see the note
     *      below on why this was added.
     *   3. Laboratory subjects
     *   4. Major subjects
     *   5. Minor subjects
     *
     * Implemented as a rank tuple per offering (0 = higher priority),
     * compared elementwise — PHP's <=> on equal-length arrays compares
     * index by index, so [0,1,0] sorts before [0,1,1], etc.
     *
     * ── Why scarcity was added ────────────────────────────────────
     * Without it, two subjects needing the SAME small pool of eligible
     * faculty (e.g. two CCS Major subjects with only 2-3 Departmental/
     * Cross-Department faculty who qualify) were ordered by nothing
     * more than lab/major/minor — purely incidental. Whichever one
     * happened to sort first could claim the only faculty member with
     * a free slot, even though it also had OTHER acceptable
     * candidates, while the second subject — which had NO other viable
     * candidate at all — was left with "No conflict-free day/time/room
     * combination found" purely because it was processed second. This
     * is exactly what happened to CAP102/SP101 after IAS102/SA101 had
     * already claimed the two available CCS faculty ahead of them.
     *
     * Scarcity-first means the subject with the narrowest pool of
     * eligible faculty is placed FIRST, while more flexible subjects
     * are deliberately pushed later — a more flexible subject has more
     * fallback options left over even if it goes last, whereas a
     * scarce subject has none. This is still greedy (no backtracking),
     * it just orders the greedy pass to fail less often.
     */
    private function applyPriorityOrder(Collection $offerings): Collection
    {
        return $offerings
            ->sort(fn (SubjectOffering $a, SubjectOffering $b) => $this->priorityRank($a) <=> $this->priorityRank($b))
            ->values();
    }

    private function priorityRank(SubjectOffering $offering): array
    {
        $hasFaculty = $this->resolveAssignedFaculty($offering) ? 0 : 1;

        // Only worth computing for offerings that will actually go
        // through the auto-search (hasFaculty === 1) — an already-
        // assigned offering's scarcity is irrelevant to its own rank,
        // so this is left at 0 for those to avoid an unnecessary query.
        // Uses an empty $facultyLoad on purpose: this is a scope-only
        // eligibility count (faculty_scope/department/classification),
        // which never depends on anyone's current load — only
        // findPlacement()'s later, load-aware pass does.
        $scarcity = $hasFaculty === 1
            ? $this->resolveAutoFacultyCandidates($offering, [])->count()
            : 0;

        $isLab = strtolower((string) $offering->room_type) === 'laboratory' ? 0 : 1;
        $isMajor = $offering->classification === SubjectOffering::CLASSIFICATION_MAJOR ? 0 : 1;

        return [$hasFaculty, $scarcity, $isLab, $isMajor];
    }

    /*
    |--------------------------------------------------------------------------
    | Faculty — Assigned (Faculty Loading)
    |--------------------------------------------------------------------------
    |
    | Faculty Loading (the "Assign Subject" workflow) writes exactly one
    | row per Subject Offering into `teaching_assignments`
    | (subject_offering_id unique, faculty_id) — see
    | TeachingAssignmentController::store(). SubjectOffering::
    | teachingAssignment() is the one relationship that reaches it, and
    | it is what MasterGridDataService::presentOffering() already reads
    | to show "Faculty Assigned" on the Subject Card. This method is the
    | ONLY place GreedyScheduleService resolves an assigned faculty, so
    | it can never drift out of sync with what the Master Grid displays.
    |
    | This only recognizes a MANUALLY assigned faculty. It never picks
    | one on its own — that's resolveAutoFacultyCandidates() below,
    | which findPlacement() falls back to whenever this returns null OR
    | the assigned faculty turns out to be unusable for every slot.
    */
    private function resolveAssignedFaculty(SubjectOffering $offering): ?Faculty
    {
        $assignment = $offering->teachingAssignment;
        $faculty = $assignment?->faculty_id ? $assignment->faculty : null;

        return ($faculty && $faculty->status) ? $faculty : null;
    }

    /**
     * Every active Faculty member eligible to automatically pick up
     * this Subject Offering, sorted best-candidate-first.
     *
     * ── Faculty Scope rules (mirrors the Faculty Loading module's
     *    three-scope system — see Faculty::scopeEligibleFor() /
     *    TeachingAssignmentService) ──────────────────────────────────
     *
     *   Departmental — belongs to exactly one college. Can ONLY teach
     *   Major subjects, and ONLY inside their own college. Never
     *   eligible for a Minor subject, anywhere, and never eligible for
     *   a Major belonging to another department.
     *
     *   Cross-Department — also belongs to one home college, but has a
     *   wider reach: can teach both Major AND Minor subjects inside
     *   their own college, AND can additionally teach Minor subjects
     *   belonging to OTHER departments. They are never handed a Major
     *   subject outside their own college.
     *
     *   General / GenEd — belongs to no department (department_id is
     *   null) and can ONLY teach Minor subjects, but across every
     *   department without restriction.
     *
     * In table form, for a Subject Offering belonging to department D:
     *
     *   | Scope            | Major (in D) | Minor (in D) | Minor (other) |
     *   |-------------------|:-----------:|:------------:|:--------------:|
     *   | Departmental (D)  |     YES     |      NO      |       NO       |
     *   | Cross-Dept (D)    |     YES     |     YES      |      YES       |
     *   | General/GenEd     |     NO      |     YES      |      YES       |
     *
     * A Major is therefore always restricted to Departmental/Cross-
     * Department faculty who belong to that exact department — never
     * General/GenEd, and never a Departmental/Cross-Department faculty
     * from a different college. A Minor is open to General/GenEd
     * faculty (any department) and Cross-Department faculty (any
     * department, including their own) — Departmental faculty are
     * never eligible for a Minor.
     *
     * Secondary ranking, once scope-eligible:
     *   1. Same department as the offering (helps a Cross-Department
     *      faculty's own-college minors edge out their outside-college
     *      minors, all else equal)
     *   2. Lowest current teaching load (live — includes everything
     *      already placed earlier in THIS run)
     *
     * Eligibility mirrors ScheduleRecommendationService::suggestFaculty()
     * so "who could teach this" never disagrees between the Greedy
     * Scheduler and the Interactive Review's suggestion panel — if you
     * change the rule here, mirror it there too.
     *
     * @return Collection<int,Faculty>
     */
    private function resolveAutoFacultyCandidates(SubjectOffering $offering, array $facultyLoad): Collection
    {
        $departmentId = $offering->program?->department_id;
        $isMajor = $offering->classification === SubjectOffering::CLASSIFICATION_MAJOR;

        $candidates = Faculty::where('status', true)
            ->where(function ($query) use ($departmentId, $isMajor) {
                if ($isMajor) {
                    // Major: Departmental or Cross-Department faculty,
                    // strictly within their own home department. Never
                    // General/GenEd, never a different department.
                    $query->whereIn('faculty_scope', ['departmental', 'cross_department'])
                        ->where('department_id', $departmentId);
                } else {
                    // Minor: General/GenEd (any department, they carry
                    // none of their own) or Cross-Department (any
                    // department — inside or outside their own home
                    // college). Departmental faculty are excluded
                    // entirely from Minor subjects.
                    $query->whereIn('faculty_scope', ['general', 'cross_department']);
                }
            })
            ->get()
            // Shuffled BEFORE the stable sort below on purpose. ->sort()
            // is stable — when several faculty tie exactly on
            // [same_department, current_load] (e.g. every Gen-Ed/Cross-
            // Department faculty still sitting at 0 load at the start of
            // a run), a stable sort leaves ties in whatever order the DB
            // happened to return them, and that SAME faculty member wins
            // every tie, every time, until their load finally climbs
            // past everyone else's. That's how one low-load faculty
            // member could absorb five straight Minor subjects in a row
            // instead of the load spreading across the whole eligible
            // pool. Shuffling first means ties resolve differently on
            // every call, while real signals (department match, actual
            // load) still fully determine the ranking whenever they
            // actually differ.
            ->shuffle();

        return $candidates
            ->map(function (Faculty $faculty) use ($facultyLoad, $departmentId) {
                $faculty->setAttribute('_current_load', $facultyLoad[$faculty->id] ?? 0);
                $faculty->setAttribute('_same_department', $faculty->department_id === $departmentId);

                return $faculty;
            })
            // Load comes first, department match is only the
            // tie-breaker. See the class-level note above this method:
            // for a Major, every candidate already shares the
            // offering's department (the query above filters on it),
            // so same_department is 0 for the whole list and this
            // tuple order changes nothing for Majors. For a Minor,
            // department_id is NOT filtered — General/GenEd faculty
            // (department_id null, same_department always 1) compete
            // directly against Cross-Department faculty who happen to
            // share the offering's department (same_department 0).
            // Sorting department-first meant that one same-department
            // Cross-Department faculty would out-rank every General/
            // GenEd faculty on EVERY Minor in that department, no
            // matter how high their own load climbed, until they
            // finally hit max_units — which is exactly how a single
            // faculty member (e.g. a CCS Cross-Department instructor)
            // ended up auto-assigned to every Minor subject for a
            // section (NSTP, PATHFIT, GENSOC, etc.) while every GenEd
            // faculty sat untouched at 0 load. Load-first restores the
            // "lowest current workload" behavior the docblock actually
            // promises, and department match only breaks a tie between
            // two otherwise-equally-loaded candidates. This also keeps
            // this method in agreement with
            // ScheduleRecommendationService::suggestFaculty(), which
            // already sorts load-first — see this method's own
            // docblock ("if you change the rule here, mirror it
            // there too").
            ->sort(function (Faculty $a, Faculty $b) {
                return [$a->_current_load, $a->_same_department ? 0 : 1]
                    <=> [$b->_current_load, $b->_same_department ? 0 : 1];
            })
            ->values();
    }

    /**
     * Seeds the running faculty-load map from what's already committed
     * in Faculty Loading for this term (units per faculty across their
     * existing Teaching Assignments) — so "lowest current workload" and
     * the max-load hard constraint reflect real load from the very
     * first subject processed, not just what this run places.
     *
     * @return array<int,int> faculty_id => units
     */
    private function initialFacultyLoad(AcademicTerm $term): array
    {
        return TeachingAssignment::forTerm($term->id)
            ->active()
            ->with('subjectOffering')
            ->get()
            ->groupBy('faculty_id')
            ->map(fn ($rows) => $rows->sum(fn (TeachingAssignment $ta) => (int) ($ta->subjectOffering?->units ?? 0)))
            ->all();
    }

    /**
     * Every Schedule row already committed for this term, in the same
     * shape $placedBlocks entries use ('room_id', 'faculty_id',
     * 'section_id', 'day', 'start', 'end') — across ALL sections, not
     * just the one currently being generated.
     *
     * This is what makes the algorithm's own room/faculty/section
     * conflict checks (hasConflict()/comboHasConflict()) actually
     * aware of everything that's already been Saved on the Master
     * Grid, the same "preview + already-saved" merge
     * ScheduleValidationService::allKnownBlocksForTerm() already does
     * for post-generation validation. Before this, generateForSection()
     * started $placedBlocks at [] every run — perfectly happy to place
     * a subject in a room/time another section already occupies, or
     * to double-book a section against its own already-scheduled
     * subjects, because nothing here ever told it those blocks
     * existed. The conflict was only ever caught afterward, when the
     * Schedule Preview modal separately re-validates against the real
     * `schedules` table — which is why "successfully generated" rows
     * could still show CONFLICT the moment the preview rendered.
     *
     * A currently-being-regenerated offering's OWN prior row is
     * naturally excluded here for free: loadOfferings() only ever
     * hands generateForSection() offerings that are NOT already
     * Scheduled, so a subject with an existing Schedule row never
     * reaches this run to begin with.
     */
    private function initialCommittedBlocks(AcademicTerm $term): array
    {
        return Schedule::forTerm($term->id)
            ->get()
            ->map(fn (Schedule $s) => [
                'room_id' => $s->room_id,
                'faculty_id' => $s->faculty_id,
                'section_id' => $s->subjectOffering?->section_id,
                'day' => $s->day,
                'start' => $s->start_minutes,
                'end' => $s->end_minutes,
            ])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Time Grid (derived from the Active Academic Term)
    |--------------------------------------------------------------------------
    */

    /**
     * Mirrors the client-side useTimetableGrid.js logic in PHP: working
     * days (filtered to whichever the term has enabled) and every valid
     * meeting start time (interval steps across school hours, with any
     * start that falls inside the lunch window excluded outright).
     * Starts are naturally ascending, so morning slots are always tried
     * first — this is what gives the greedy search its "prefer morning"
     * soft-constraint behavior without any extra scoring pass.
     */
    private function buildTimeGrid(AcademicTerm $term): array
    {
        $dayFields = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $workingDays = array_values(array_filter($dayFields, fn ($field) => (bool) $term->{$field}));

        $start = $this->toMinutes($term->school_start_time);
        $end = $this->toMinutes($term->school_end_time);
        $lunchStart = $this->toMinutes($term->lunch_start_time);
        $lunchEnd = $this->toMinutes($term->lunch_end_time);
        $interval = $term->time_interval ?: 30;

        $starts = [];

        if ($start !== null && $end !== null && $interval > 0) {
            for ($cursor = $start; $cursor < $end; $cursor += $interval) {
                $insideLunch = $lunchStart !== null && $lunchEnd !== null
                    && $cursor >= $lunchStart && $cursor < $lunchEnd;

                if ($insideLunch) {
                    continue;
                }

                $starts[] = $cursor;
            }
        }

        return [
            'working_days' => $workingDays,
            'starts' => $starts,
            'school_end' => $end,
            'lunch_start' => $lunchStart,
            'lunch_end' => $lunchEnd,
        ];
    }

    /**
     * AcademicTerm casts these as 'datetime:H:i' — but that format
     * suffix ONLY controls array/JSON serialization (which is why the
     * Master Grid header correctly shows "08:00 – 20:00"). Plain PHP
     * attribute access (`$term->school_start_time`) hands back a raw
     * Carbon instance instead, NOT the "H:i" string this used to
     * assume. Exploding a stringified Carbon ("2026-07-05 08:00:00")
     * on ':' produced garbage hour/minute values (e.g. hours=2026),
     * which silently broke every single time-slot check — the actual
     * cause of "0 scheduled" regardless of faculty/room assignment.
     *
     * Handles a Carbon/DateTime instance directly, and falls back to a
     * regex pull of the trailing H:i(:s) segment for anything that
     * arrives as a string instead, so this is correct either way.
     */
    private function toMinutes($value): ?int
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return ((int) $value->format('H')) * 60 + (int) $value->format('i');
        }

        $value = (string) $value;

        if (preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?\s*$/', $value, $matches)) {
            return ((int) $matches[1]) * 60 + (int) $matches[2];
        }

        return null;
    }

    /**
     * A candidate block is valid only if it fits inside school hours
     * and never overlaps the lunch window — it is not split around
     * lunch, it simply isn't offered as a start option if it would
     * cross into it. Also enforces allow_split_schedule = false means
     * nothing here needs to change — a block's duration is always one
     * contiguous run in v2, same as v1.
     */
    private function isValidBlock(array $grid, int $start, int $duration): bool
    {
        $end = $start + $duration;

        if ($grid['school_end'] !== null && $end > $grid['school_end']) {
            return false;
        }

        if ($grid['lunch_start'] !== null && $grid['lunch_end'] !== null) {
            $overlapsLunch = $start < $grid['lunch_end'] && $end > $grid['lunch_start'];

            if ($overlapsLunch) {
                return false;
            }
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Room Candidates
    |--------------------------------------------------------------------------
    */

    /**
     * Preferred room (from the Manage Subjects preference pivot) always
     * tried first — "use it if available" per spec, meaning we still
     * check it for conflicts, we just don't re-validate its type/program/
     * capacity fit since that preference was set deliberately. Every
     * other schedulable room that matches room type + allowed program +
     * capacity follows, ordered smallest-sufficient-capacity first so we
     * don't burn a large room on a small section.
     */
    private function candidateRooms(SubjectOffering $offering, ?Section $section, array $roomUsage = []): Collection
    {
        $preferredRoomId = DB::table('room_subject_offering')
            ->where('subject_offering_id', $offering->id)
            ->value('room_id');

        $fallback = Room::schedulable()
            ->with('roomGroups')
            ->get()
            ->filter(function (Room $room) use ($offering, $section) {
                if ($offering->room_type && $room->room_type !== $offering->room_type) {
                    return false;
                }

                $programCode = $offering->program?->code;
                $allowed = in_array('General', $room->room_group_codes, true)
                    || ($programCode && in_array($programCode, $room->room_group_codes, true));

                if (! $allowed) {
                    return false;
                }

                if ($section && $section->capacity && (int) $room->capacity < (int) $section->capacity) {
                    return false;
                }

                return true;
            })
            // Usage-this-run first, capacity as the tie-breaker —
            // NOT capacity alone. Sorting by capacity alone means
            // whichever General/type-matching room happens to have
            // the smallest sufficient capacity wins the search every
            // single time it's free — and since these subjects are
            // routinely on DIFFERENT days/times for the same section,
            // that one room is essentially always free, so it gets
            // picked for every single subject while every other
            // General Lecture room with plenty of open slots sits
            // completely unused. That's exactly how "Room 110" ended
            // up hosting five straight BSIT-1A subjects while the rest
            // of the General rooms were never even tried. Usage-first
            // spreads subjects across the whole eligible room pool the
            // same way the faculty auto-search now spreads load across
            // faculty, and capacity only breaks a tie between two
            // otherwise-equally-used rooms.
            ->sort(function (Room $a, Room $b) use ($roomUsage) {
                return [$roomUsage[$a->id] ?? 0, $a->capacity]
                    <=> [$roomUsage[$b->id] ?? 0, $b->capacity];
            })
            ->values();

        $ordered = collect();

        if ($preferredRoomId) {
            $preferredRoom = $fallback->firstWhere('id', $preferredRoomId) ?? Room::find($preferredRoomId);

            if ($preferredRoom && $preferredRoom->active) {
                $ordered->push($preferredRoom);
            }
        }

        foreach ($fallback as $room) {
            if ($room->id !== $preferredRoomId) {
                $ordered->push($room);
            }
        }

        return $ordered;
    }

    private function preferredRoomCode(SubjectOffering $offering): ?string
    {
        return DB::table('room_subject_offering')
            ->join('rooms', 'rooms.id', '=', 'room_subject_offering.room_id')
            ->where('room_subject_offering.subject_offering_id', $offering->id)
            ->value('rooms.room_code');
    }

    /*
    |--------------------------------------------------------------------------
    | Placement Search (Greedy — first fit wins, no backtracking)
    |--------------------------------------------------------------------------
    */

    /**
     * Two passes, in order:
     *
     *   Pass 1 — Assigned Faculty. If Faculty Loading already assigned
     *   someone AND that faculty isn't already over their max load,
     *   walk rooms -> days -> starts and take the first slot where that
     *   exact faculty, the room, and the section are all free. This is
     *   the "use assigned faculty, only reject on real conflict" rule.
     *
     *   Pass 2 — Automatic Faculty Search. Runs whenever Pass 1 didn't
     *   place the subject (no assigned faculty at all, assigned faculty
     *   is overloaded, or assigned faculty had no free slot anywhere).
     *   Walks rooms -> days -> starts and, for each slot, tries
     *   auto-search candidates — filtered to Faculty Scope-eligible
     *   candidates only, see resolveAutoFacultyCandidates() — in
     *   priority order until one is free.
     *
     * Either pass returns the very first working combination it finds
     * — this is the greedy choice per spec: accept immediately, never
     * reshuffle, never revisit an earlier subject.
     */
    private function findPlacement(
        SubjectOffering $offering,
        ?Section $section,
        array $grid,
        array $dayCombos,
        int $duration,
        ?Faculty $assignedFaculty,
        array $facultyLoad,
        array $placedBlocks,
        array $roomUsage = []
    ): ?array {
        $rooms = $this->candidateRooms($offering, $section, $roomUsage);

        // Deliberately NOT exceedsMaxLoad() here — that method adds
        // $offering->units on TOP of $facultyLoad's current total,
        // which is correct for an AUTO candidate (see the 'auto'
        // branch below, genuinely new load for them) but WRONG for
        // $assignedFaculty specifically: resolveAssignedFaculty() only
        // ever returns non-null because an active Teaching Assignment
        // for THIS EXACT offering already exists, and
        // initialFacultyLoad() sums every active Teaching Assignment a
        // faculty holds — meaning this offering's units are already
        // baked into $facultyLoad[$assignedFaculty->id] before we ever
        // get here. Adding them again double-counted this one
        // offering, which made ANY faculty member loaded right up to
        // (or over) their cap — i.e. exactly the normal, intended end
        // state of Faculty Loading — fail this check for literally
        // every subject they were ever pre-assigned, silently falling
        // back to an auto-picked faculty member with no indication
        // anywhere that the Registrar's own Teaching Assignment choice
        // had been overridden.
        $assignedUsable = $assignedFaculty
            && ! $this->assignedFacultyOverCap($assignedFaculty, $facultyLoad);

        if ($assignedUsable) {
            foreach ($rooms as $room) {
                foreach ($dayCombos as $combo) {
                    foreach ($grid['starts'] as $start) {
                        if (! $this->isValidBlock($grid, $start, $duration)) {
                            continue;
                        }

                        $end = $start + $duration;

                        if ($this->comboHasConflict($placedBlocks, $combo, $start, $end, $room->id, $assignedFaculty->id, $offering->section_id)) {
                            continue;
                        }

                        return [
                            'room' => $room,
                            'faculty' => $assignedFaculty,
                            'faculty_source' => 'assigned',
                            'days' => $combo,
                            'start' => $start,
                            'end' => $end,
                        ];
                    }
                }
            }

            $this->debug("{$this->subjectLabel($offering)} — assigned faculty {$assignedFaculty->full_name} has no free slot anywhere. Falling back to automatic search.");
        } elseif ($assignedFaculty) {
            $this->debug("{$this->subjectLabel($offering)} — assigned faculty {$assignedFaculty->full_name} would exceed max load. Falling back to automatic search.");
        }

        $autoCandidates = $this->resolveAutoFacultyCandidates($offering, $facultyLoad)
            ->reject(fn (Faculty $f) => $assignedFaculty && $f->id === $assignedFaculty->id)
            ->values();

        if ($autoCandidates->isEmpty() && ! $assignedFaculty) {
            // No one is even theoretically eligible — no point walking
            // the whole room/day/time grid for nothing.
            return null;
        }

        foreach ($rooms as $room) {
            foreach ($dayCombos as $combo) {
                foreach ($grid['starts'] as $start) {
                    if (! $this->isValidBlock($grid, $start, $duration)) {
                        continue;
                    }

                    $end = $start + $duration;

                    if ($this->comboHasConflict($placedBlocks, $combo, $start, $end, $room->id, null, $offering->section_id)) {
                        // Room or section already taken on at least one
                        // day of this combo — no faculty choice will
                        // fix that, skip ahead.
                        continue;
                    }

                    foreach ($autoCandidates as $candidate) {
                        if ($this->exceedsMaxLoad($candidate, $facultyLoad, (int) $offering->units)) {
                            continue;
                        }

                        if ($this->comboHasConflict($placedBlocks, $combo, $start, $end, $room->id, $candidate->id, $offering->section_id)) {
                            continue;
                        }

                        return [
                            'room' => $room,
                            'faculty' => $candidate,
                            'faculty_source' => 'auto',
                            'days' => $combo,
                            'start' => $start,
                            'end' => $end,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Every standard day-combination for $meetings/week that this
     * term's working days can actually support — 1x offers every
     * individual working day as its own candidate; 2x/3x only offer
     * the fixed pairings/triples from DAY_COMBOS_2X/DAY_COMBOS_3X, and
     * only ones where EVERY day in the pairing is enabled for the
     * term. Falls back to 1x-style single-day combos for any
     * meetings count outside 1–3 (defensive; Session Settings' dropdown
     * never actually offers anything else).
     *
     * @return array<int,array<int,string>> list of combos, each combo
     *         a list of lowercase day field names.
     */
    private function resolveDayCombos(int $meetings, array $workingDays): array
    {
        if ($meetings <= 1) {
            return array_map(fn ($day) => [$day], $workingDays);
        }

        $fixed = $meetings === 3 ? self::DAY_COMBOS_3X : self::DAY_COMBOS_2X;

        $combos = array_values(array_filter(
            $fixed,
            fn ($combo) => empty(array_diff($combo, $workingDays))
        ));

        // No standard combo fits this term's working days (e.g. 3x
        // requested but the term only runs Mon–Wed) — rather than
        // silently falling back to a WRONG meeting count, this is
        // reported as unscheduled by the caller (empty array signals
        // "no valid combo exists").
        return $combos;
    }

    /**
     * Reorders $dayCombos (from resolveDayCombos() above — either the
     * fixed 2x/3x pairings or the one-day-per-combo list used for 1x)
     * so the combo that currently leaves $sectionId's week most evenly
     * loaded is tried FIRST, instead of always trying the fixed list
     * in its original order.
     *
     * Scored per combo as [worst single day's current load, combo's
     * total current load] and sorted ascending on both — "worst single
     * day" first because the goal is avoiding any one day becoming a
     * marathon for this section, not merely minimizing a sum that a
     * single very-heavy day could still hide inside. The original list
     * order is the final tie-break (via a stable sort), so behavior
     * stays deterministic and explainable rather than shuffling
     * identically-loaded combos around run to run.
     *
     * $placedBlocks is the SAME running list findPlacement() already
     * conflict-checks against — every entry already carries
     * section_id/day/start/end, so this reads current load straight
     * off it rather than maintaining a second, parallel tally that
     * could drift out of sync.
     */
    private function sortDayCombosByLoad(array $dayCombos, array $placedBlocks, int $sectionId): array
    {
        $sectionBlocks = array_filter($placedBlocks, fn ($b) => $b['section_id'] === $sectionId);

        $loadByDay = [];
        foreach ($sectionBlocks as $b) {
            $loadByDay[$b['day']] = ($loadByDay[$b['day']] ?? 0) + ($b['end'] - $b['start']);
        }

        $scored = array_map(function ($combo, $index) use ($loadByDay) {
            $loads = array_map(fn ($day) => $loadByDay[$day] ?? 0, $combo);

            return [
                'combo' => $combo,
                'worst' => max($loads),
                'total' => array_sum($loads),
                'index' => $index, // stable tie-break, preserves original order
            ];
        }, $dayCombos, array_keys($dayCombos));

        usort($scored, fn ($a, $b) => $a['worst'] <=> $b['worst']
            ?: $a['total'] <=> $b['total']
            ?: $a['index'] <=> $b['index']);

        return array_column($scored, 'combo');
    }

    /**
     * Whether ANY day in $combo would conflict (room, faculty, or
     * section already booked, same day+overlapping time) against
     * everything placed so far. All days in a combo share the same
     * faculty/room/time by construction — this only needs to check
     * each day independently against $placedBlocks, since sibling days
     * of THIS SAME combo aren't in $placedBlocks yet (they're only
     * added once the whole combo is accepted — see the main loop).
     */
    private function comboHasConflict(
        array $placedBlocks,
        array $combo,
        int $start,
        int $end,
        int $roomId,
        ?int $facultyId,
        int $sectionId
    ): bool {
        foreach ($combo as $day) {
            if ($this->hasConflict($placedBlocks, $day, $start, $end, $roomId, $facultyId, $sectionId)) {
                return true;
            }
        }

        return false;
    }

    private function exceedsMaxLoad(Faculty $faculty, array $facultyLoad, int $additionalUnits): bool
    {
        // Must match the cap TeachingAssignmentService::assertWithinMaxUnits()
        // enforces for manual "Assign Subject" — effective_max_units (base
        // max_units PLUS any APPROVED Faculty Load Overload — see
        // Faculty::getEffectiveMaxUnitsAttribute()), not the raw max_units
        // column. Comparing against max_units alone meant a faculty member
        // with approved overload (e.g. 24 base + 9 overload = 33) who was
        // already carrying load between 24 and 33 units got treated here as
        // permanently over-capacity — excluded from every placement attempt,
        // even though they were well within their real, approved cap. That
        // silently starved the room/day/time search of otherwise-eligible
        // faculty, which is what produced "No conflict-free day/time/room
        // combination found" even when a valid faculty/slot combination did
        // in fact exist.
        if (! $faculty->effective_max_units) {
            return false;
        }

        $currentLoad = $facultyLoad[$faculty->id] ?? 0;

        return ($currentLoad + $additionalUnits) > $faculty->effective_max_units;
    }

    /**
     * Whether $faculty's CURRENT committed load already exceeds their
     * effective cap, on its own — used only for the pre-assigned-
     * faculty path in findPlacement(), where $facultyLoad already
     * includes this exact offering's units (see the long comment at
     * that call site for why). Deliberately does not take an
     * `$additionalUnits` parameter the way exceedsMaxLoad() does —
     * there's nothing additional to add here; the offering is already
     * accounted for.
     *
     * Reads from the same live $facultyLoad array exceedsMaxLoad()
     * does (not a fresh DB query), so it still reflects anything this
     * SAME generation run has already added for this faculty member
     * earlier — e.g. if they were auto-picked for a different offering
     * moments ago, that increment is already visible here too.
     */
    private function assignedFacultyOverCap(Faculty $faculty, array $facultyLoad): bool
    {
        if (! $faculty->effective_max_units) {
            return false;
        }

        $currentLoad = $facultyLoad[$faculty->id] ?? 0;

        return $currentLoad > $faculty->effective_max_units;
    }

    /**
     * A single overlap check that covers all three conflict types at
     * once: two blocks on the same day whose time ranges overlap
     * conflict if they share a Room, a Faculty (when one is being
     * checked), or a Section.
     */
    private function hasConflict(
        array $placedBlocks,
        string $day,
        int $start,
        int $end,
        int $roomId,
        ?int $facultyId,
        int $sectionId
    ): bool {
        foreach ($placedBlocks as $block) {
            if ($block['day'] !== $day) {
                continue;
            }

            $overlaps = $start < $block['end'] && $end > $block['start'];

            if (! $overlaps) {
                continue;
            }

            if ($block['room_id'] === $roomId) {
                return true;
            }

            if ($facultyId && $block['faculty_id'] === $facultyId) {
                return true;
            }

            if ($block['section_id'] === $sectionId) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Output Shaping
    |--------------------------------------------------------------------------
    */

    /**
     * Shapes one result row. When $placement is null the offering is
     * reported as unscheduled/skipped with a reason instead of a
     * day/time/room, so the frontend can distinguish a real preview
     * block from one that still needs manual attention.
     */
    private function presentBlock(SubjectOffering $offering, ?array $placement, string $status, ?string $reason): array
    {
        /** @var Faculty|null $faculty */
        $faculty = $placement['faculty'] ?? null;

        return [
            'subject_offering_id' => $offering->id,
            'subject_id' => $offering->subject_id,
            'subject_code' => $offering->subject?->subject_code,
            'descriptive_title' => $offering->subject?->descriptive_title,
            'edp_code' => $offering->edp_code,
            'section_id' => $offering->section_id,
            'section_code' => $offering->section?->section_code,
            'is_irregular' => (bool) $offering->section?->is_irregular,
            'year_level' => $offering->year_level,
            'program_id' => $offering->program_id,
            'program_code' => $offering->program?->code,
            'department_id' => $offering->program?->department_id,
            'college_code' => $offering->program?->department?->abbreviation ?? 'General',
            'academic_term_id' => $offering->academic_term_id,
            'classification' => $offering->classification,
            'room_type' => $offering->room_type,
            'units' => $offering->units,
            'hours' => $offering->hours,
            'meetings_per_week' => $offering->meetings_per_week ?: SubjectOffering::DEFAULT_MEETINGS_PER_WEEK,

            'faculty_id' => $faculty?->id,
            'faculty_name' => $faculty?->full_name,
            'faculty_source' => $placement['faculty_source'] ?? null, // 'assigned' | 'auto' | null
            // Whether the offering's CURRENT Faculty/Room preference
            // (Subject Offerings) was set via Override Eligibility —
            // same idea as MasterGridDataService::presentOffering()'s
            // faculty_override/room_override. Lets EditScheduleModal
            // seed its checkboxes correctly when editing a Generate
            // Preview row, not just a fresh drag-and-drop.
            'faculty_override' => (bool) ($offering->teachingAssignment?->is_override ?? false),
            'room_override' => (bool) ($offering->preferredByRooms?->first()?->pivot?->is_override ?? false),

            'room_id' => $placement['room']->id ?? null,
            'room_code' => $placement['room']->room_code ?? null,

            'day' => $placement['day'] ?? null,
            'start_minutes' => $placement['start'] ?? null,
            'end_minutes' => $placement['end'] ?? null,

            'status' => $status, // 'preview' | 'unscheduled' | 'skipped'
            'reason' => $reason,
        ];
    }

    /**
     * Reason text shown on the preview when a subject could not be
     * placed at all — distinguishes "nobody could ever teach this" from
     * "someone could, but every room/day/time is taken", matching the
     * spec's "No Faculty Found" vs generic conflict examples.
     */
    private function unscheduledReason(SubjectOffering $offering, ?Faculty $assignedFaculty): string
    {
        if (! $assignedFaculty && $this->resolveAutoFacultyCandidates($offering, [])->isEmpty()) {
            return 'No Faculty Found — no eligible faculty exists for this subject (check Faculty Scope vs. this subject\'s classification and department).';
        }

        return 'No conflict-free day/time/room combination found.';
    }

    private function subjectLabel(SubjectOffering $offering): string
    {
        return $offering->subject?->subject_code ?? "Offering #{$offering->id}";
    }

    private function label(int $minutes): string
    {
        $h24 = intdiv($minutes, 60) % 24;
        $m = $minutes % 60;
        $period = $h24 >= 12 ? 'PM' : 'AM';
        $h12 = $h24 % 12 === 0 ? 12 : $h24 % 12;

        return sprintf('%d:%02d %s', $h12, $m, $period);
    }

    private function debug(string $message): void
    {
        Log::debug($message);
    }
}