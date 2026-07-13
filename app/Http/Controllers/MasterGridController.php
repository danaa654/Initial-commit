<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Department;
use App\Models\Schedule;
use App\Models\SubjectOffering;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Notifications\MasterGridScheduleSaved;
use App\Services\GreedyScheduleService;
use App\Services\MasterGridDataService;
use App\Services\ScheduleRecommendationService;
use App\Services\ScheduleValidationService;
use App\Services\SchedulingWorkspaceService;
use App\Services\SessionSettingsService;
use App\Services\AuditLogService;
use App\Services\ActivityHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Throwable;

/**
 * The Master Grid Scheduling Workspace.
 *
 * index() is a READ-ONLY visual workspace. generate() runs the Greedy
 * Scheduling Algorithm and returns an in-memory preview. Phase 2 adds
 * the Interactive Schedule Review loop on top of that same preview:
 *
 *   validateBlock() — real-time conflict check for a single edited
 *                     block, called by the Edit Schedule modal on
 *                     every field change.
 *   save()          — re-validates the WHOLE preview and, only if it
 *                     is 100% conflict-free, writes every block to the
 *                     `schedules` table in one transaction. It also
 *                     keeps `teaching_assignments` in sync (see save()
 *                     below) so the Faculty Loading workspace reflects
 *                     whatever faculty the Greedy Scheduler (or a
 *                     manual edit) ultimately settled on for each
 *                     offering — Faculty Loading and Master Grid share
 *                     the same subject_offering_id, and neither table
 *                     should be able to drift from the other.
 *
 * None of these three actions contain business logic themselves — see
 * GreedyScheduleService (generation), ScheduleValidationService
 * (conflict rules), and ScheduleRecommendationService (suggested
 * alternatives). This controller only translates HTTP <-> services.
 */
class MasterGridController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MasterGridDataService $data,
        private readonly GreedyScheduleService $greedy,
        private readonly ScheduleValidationService $validator,
        private readonly ScheduleRecommendationService $recommender,
        private readonly SchedulingWorkspaceService $workspace,
        private readonly SessionSettingsService $sessionSettings,
    ) {
    }

    public static function middleware(): array
    {
        return [

            new Middleware(function ($request, $next) {

                abort_unless(
                    auth()->user()->hasAnyRole([
                        'Admin',
                        'Registrar',
                        'Dean',
                        'Assistant Dean',
                        'OIC',
                    ]),
                    403,
                    'Unauthorized.'
                );

                return $next($request);

            }),

            // Generate / Validate / Save all require the same
            // Admin/Registrar-only restriction — reviewing and
            // committing a schedule is the same privileged action the
            // spec restricts to "When the Registrar or Admin clicks
            // Generate Schedule...".
            new Middleware(function ($request, $next) {

                if ($request->routeIs(
                    'master-grid.generate',
                    'master-grid.validate-block',
                    'master-grid.save',
                    'master-grid.remove-schedule',
                    'master-grid.session-settings',
                    'master-grid.session-settings.update'
                )) {
                    abort_unless(
                        auth()->user()->hasAnyRole(['Admin', 'Registrar']),
                        403,
                        'Only Admin or Registrar can generate or save a schedule.'
                    );
                }

                return $next($request);

            }),

        ];
    }

    public function index()
    {
        // Admin/Registrar see the Planning Academic Term here (so they
        // can lay out next semester's grid ahead of time); Dean/
        // Assistant Dean/OIC always see the Active Academic Term —
        // see SchedulingWorkspaceService::getTermForUser().
        $term = $this->workspace->getTermForUser(auth()->user());

        return Inertia::render(
            'MasterGrid/Index',
            [
                ...$this->data->build($term, $this->managerDepartmentId(auth()->user())),

                // Drives every editing affordance client-side (the
                // Generate Schedule button, and whether clicking a
                // block opens it read-only or editable) — Dean/
                // Assistant Dean/OIC may view the grid and open a
                // block's details, but only Admin/Registrar can
                // actually change anything, matching the same
                // Admin/Registrar-only restriction already enforced
                // server-side in middleware() above for generate/
                // validate-block/save/session-settings. This is
                // purely a UI convenience — the real enforcement
                // stays in middleware(), so even if this flag were
                // ever wrong, no write endpoint would open up because
                // of it.
                'can' => [
                    'manage' => auth()->user()->hasAnyRole(['Admin', 'Registrar']),
                ],
            ]
        );
    }

    /**
     * The department a manager is scoped to, or null if they oversee
     * every department. Admin, Registrar, and Assistant Dean always
     * see every department unscoped; Dean/OIC are limited to their own
     * department_id (plus General Education, which MasterGridDataService
     * always includes regardless of this value). Exact same rule —
     * and copied verbatim rather than shared — as
     * TeachingAssignmentController::managerDepartmentId(), since
     * Faculty Loading and Master Grid must never drift apart on who
     * gets to see whose Subject Offerings.
     */
    private function managerDepartmentId(User $user): ?int
    {
        if ($user->hasAnyRole(['Admin', 'Registrar', 'Assistant Dean'])) {
            return null;
        }

        return $user->department_id;
    }

    /**
     * Step 2 of Generate Schedule — "Session Settings". Given the
     * Target Selection from Step 1, returns every Subject Offering
     * still needing to be scheduled for that section, along with
     * enough data (eligible faculty, eligible rooms, current meetings/
     * week, computed hours/meeting) for the modal to render its
     * per-subject editing table. Read-only — nothing is written here.
     */
    public function sessionSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'specialization_id' => ['nullable', 'integer', 'exists:specializations,id'],
            'year_level' => ['required', 'integer', 'min:1', 'max:4'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ]);

        $planningTerm = $this->workspace->getTermForUser(auth()->user());

        abort_unless($planningTerm, 422, 'No Planning Academic Term is set — configure one in Settings > Scheduling Workspace before generating a schedule.');

        $result = $this->sessionSettings->build($planningTerm, $validated);

        return response()->json($result);
    }

    /**
     * Persists whatever the Registrar/Admin edited in Session
     * Settings — actual weekly duration and meetings/week per subject
     * — right before Generate actually runs. Saved even on a
     * re-Generate (Step 4's "Regenerate"), so the Greedy Scheduler
     * always reads back the most recently confirmed settings for
     * every offering in this batch.
     *
     * "hours" overrides subject_offerings.hours (which starts out
     * copied from the curriculum at generation time — see
     * SubjectOfferingGeneratorService) — real classroom time doesn't
     * always match what the curriculum states (e.g. a subject listed
     * at 5 hrs/week that's actually only taught 4), and this is the
     * one place that correction belongs, since it flows directly into
     * how the Greedy Scheduler computes each block's duration.
     * Preferred Faculty/Room are NOT edited here — those still come
     * from Faculty Loading / Manage Subjects, unchanged.
     */
    public function updateSessionSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*.subject_offering_id' => ['required', 'integer', 'exists:subject_offerings,id'],
            'subjects.*.hours' => ['required', 'integer', 'min:1'],
            'subjects.*.meetings_per_week' => ['required', 'integer', 'in:' . implode(',', SessionSettingsService::ALLOWED_MEETINGS_PER_WEEK)],
        ]);

        $planningTerm = $this->workspace->getTermForUser(auth()->user());

        abort_unless($planningTerm, 422, 'No Planning Academic Term is set. Configure one in Settings > Scheduling Workspace.');

        $this->workspace->assertWritable($planningTerm);

        $this->sessionSettings->save($validated['subjects']);

        return response()->json(['message' => 'Session settings saved.']);
    }

    /**
     * Runs the Greedy Scheduling Algorithm for one Department + Program
     * + [Specialization] + Year Level + Section and returns a draft
     * preview. Nothing is written to the database.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'specialization_id' => ['nullable', 'integer', 'exists:specializations,id'],
            'year_level' => ['required', 'integer', 'min:1', 'max:4'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ]);

        $planningTerm = $this->workspace->getTermForUser(auth()->user());

        abort_unless($planningTerm, 422, 'No Planning Academic Term is set — configure one in Settings > Scheduling Workspace before generating a schedule.');

        // Generate produces an in-memory preview only (nothing is
        // persisted here), but blocking it up front — rather than only
        // at Save — means Admin/Registrar reviewing an Archived term
        // never gets led through a full Generate flow just to be
        // stopped at the last step.
        $this->workspace->assertWritable($planningTerm);

        $result = $this->greedy->generateForSection($planningTerm, $validated);

        return response()->json($result);
    }

    /**
     * Real-time conflict check for ONE edited block, called by the
     * Edit Schedule modal on every field change (Faculty/Room/Day/
     * Start/End). $request->blocks is the FULL current in-memory
     * preview (including the edited block) so the validator can check
     * it against every sibling block, not just the one being edited.
     * If any conflict is found, also returns suggested alternatives
     * from ScheduleRecommendationService.
     */
    public function validateBlock(Request $request): JsonResponse
    {
        // 'blocks' is deliberately `present` rather than `required` —
        // Laravel's `required` rule treats an empty array as "missing"
        // (see the Laravel validation docs' definition of "empty" for
        // arrays), but an empty sibling list is completely legitimate
        // here: it's exactly what the very first block ever dropped
        // onto an otherwise-empty Room View looks like. `present`
        // still demands the key exist in the request at all — it just
        // stops rejecting the valid case of "there's nothing to
        // compare against yet."
        $validated = $request->validate([
            'block' => ['required', 'array'],
            'blocks' => ['present', 'array'],
        ]);

        $planningTerm = $this->workspace->getTermForUser(auth()->user());

        abort_unless($planningTerm, 422, 'No Planning Academic Term is set. Configure one in Settings > Scheduling Workspace.');

        $allBlocks = collect($validated['blocks']);
        $block = $validated['block'];

        $outcome = $this->validator->validateBlock($block, $allBlocks, $planningTerm);

        $recommendations = null;

        if (! empty($outcome['conflicts'])) {
            $recommendations = $this->recommender->recommend($block, $allBlocks, $planningTerm);
        }

        return response()->json([
            'conflicts' => $outcome['conflicts'],
            'warnings' => $outcome['warnings'],
            'recommendations' => $recommendations,
        ]);
    }

    /**
     * Re-validates every block in the submitted preview and, only if
     * NONE of them have a conflict, inserts them all into `schedules`
     * inside one transaction — all-or-nothing. If even one block still
     * conflicts (e.g. a race with another user's generate/save), the
     * whole request is rejected and the conflicting blocks are
     * returned so the frontend can highlight them, matching the spec's
     * "Do not save. Highlight all conflicting schedule blocks."
     *
     * Alongside every Schedule row, this also upserts the matching
     * `teaching_assignments` row for that offering (see
     * syncTeachingAssignment() below). Without this, a class the
     * Greedy Scheduler placed on the grid — faculty, room, day, and
     * time all decided — would show up on Master Grid but not on the
     * Faculty Loading workspace, since that workspace has always read
     * exclusively from `teaching_assignments`, never from `schedules`.
     */
    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blocks' => ['required', 'array', 'min:1'],
        ]);

        $planningTerm = $this->workspace->getTermForUser(auth()->user());

        abort_unless($planningTerm, 422, 'No Planning Academic Term is set. Configure one in Settings > Scheduling Workspace.');

        $this->workspace->assertWritable($planningTerm);

        $blocks = collect($validated['blocks']);

        // Rule 5/7 of College Finalization: reject the batch if it
        // would actually WRITE a change to a finalized college's
        // schedule. Deliberately NOT "any finalized-college offering
        // present in $blocks at all" — Save Schedule resubmits the
        // full grid on every save, including untouched blocks from
        // OTHER colleges that just happen to still be on screen. Only
        // offerings whose incoming day/time/room/faculty actually
        // differs from what's already committed (or that don't exist
        // in `schedules` yet at all) count as a real write; an
        // unmodified finalized block riding along in the payload is a
        // no-op and must not block someone else's unrelated edit.
        $existingSchedules = Schedule::whereIn('subject_offering_id', $blocks->pluck('subject_offering_id')->unique())
            ->get()
            ->groupBy('subject_offering_id');

        $changedOfferingIds = $blocks
            ->filter(function (array $block) use ($existingSchedules) {
                $existing = $existingSchedules->get($block['subject_offering_id'], collect())
                    ->firstWhere('day', $block['day']);

                if (! $existing) {
                    return true; // brand new meeting row -> a real write
                }

                return (int) $existing->room_id !== (int) ($block['room_id'] ?? null)
                    || (int) $existing->start_minutes !== (int) ($block['start_minutes'] ?? null)
                    || (int) $existing->end_minutes !== (int) ($block['end_minutes'] ?? null)
                    || (int) $existing->faculty_id !== (int) ($block['faculty_id'] ?? null);
            })
            ->pluck('subject_offering_id')
            ->unique();

        \App\Models\SubjectOffering::whereIn('id', $changedOfferingIds)
            ->with('program')
            ->get()
            ->pluck('program.department_id')
            ->filter()
            ->unique()
            ->each(fn ($departmentId) => \App\Services\TermFinalizationService::abortIfDepartmentFinalized($departmentId, $planningTerm->id));

        $conflictsByOffering = $this->validator->validateAll($blocks, $planningTerm);

        if (! empty($conflictsByOffering)) {
            return response()->json([
                'message' => 'One or more schedule blocks have conflicts. Nothing was saved.',
                'conflicts' => $conflictsByOffering,
            ], 422);
        }

        // Checked BEFORE the transaction below deletes/overwrites
        // anything — if any offering in this batch already had a
        // committed Schedule row, this save is a re-generation of
        // already-placed classes rather than a first pass, and the
        // Activity History card should say so.
        $isRegeneration = Schedule::whereIn('subject_offering_id', $blocks->pluck('subject_offering_id')->unique())
            ->exists();

        try {
            DB::transaction(function () use ($blocks, $planningTerm) {
                // A subject can now have multiple rows (one per meeting
                // day — see GreedyScheduleService's "Multi-meeting
                // subjects" docblock). If a subject previously saved as
                // 2x/week gets regenerated as 1x/week, its old second
                // day's row would otherwise be orphaned — never
                // touched by the updateOrCreate below, since that only
                // ever inserts/updates the days THIS batch actually
                // contains. Delete any existing day for an offering in
                // this batch that ISN'T one of the days being saved for
                // it now, before writing the current set.
                $daysByOffering = $blocks
                    ->groupBy('subject_offering_id')
                    ->map(fn ($rows) => $rows->pluck('day')->all());

                foreach ($daysByOffering as $offeringId => $days) {
                    Schedule::where('subject_offering_id', $offeringId)
                        ->whereNotIn('day', $days)
                        ->delete();
                }

                foreach ($blocks as $block) {
                    Schedule::updateOrCreate(
                        [
                            'subject_offering_id' => $block['subject_offering_id'],
                            'day' => $block['day'],
                        ],
                        [
                            'academic_term_id' => $planningTerm->id,
                            'faculty_id' => $block['faculty_id'] ?? null,
                            'room_id' => $block['room_id'],
                            'start_minutes' => $block['start_minutes'],
                            'end_minutes' => $block['end_minutes'],
                            'created_by' => auth()->id(),
                        ]
                    );

                    $this->syncTeachingAssignment($block);
                }
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to save the schedule. No changes were committed.',
            ], 500);
        }

        AuditLogService::log(
            action: 'generated',
            module: 'Master Grid',
            model: $planningTerm,
            description: "Saved {$blocks->count()} schedule block(s) to Master Grid for {$planningTerm->display_name}",
            newValues: [
                'academic_term' => $planningTerm->display_name,
                'blocks_saved' => $blocks->count(),
                'offerings_affected' => $blocks->pluck('subject_offering_id')->unique()->count(),
            ],
            recordName: $planningTerm->display_name,
        );

        // Activity History milestone — term-wide "how far along is
        // this schedule" snapshot, not just what this one section's
        // save touched. `remaining` reads whereDoesntHave('schedule')
        // rather than the inverse of `scheduled`, so it stays correct
        // even if an offering somehow has more than one Schedule row
        // (multi-meeting subjects — see the note above about
        // multiple rows per offering).
        $scheduled = Schedule::forTerm($planningTerm->id)->distinct('subject_offering_id')->count('subject_offering_id');
        $remaining = SubjectOffering::where('academic_term_id', $planningTerm->id)
            ->whereDoesntHave('schedule')
            ->count();

        ActivityHistoryService::recordMasterGridGenerated(
            $planningTerm,
            ['scheduled' => $scheduled, 'remaining' => $remaining],
            regenerated: $isRegeneration,
        );

        $this->notifyDepartmentsOfSave($changedOfferingIds, $planningTerm, auth()->user());

        return response()->json([
            'message' => 'Schedule generated successfully.',
        ]);
    }

    /**
     * Removes an already-committed Schedule block from the Master
     * Grid — every meeting-day row for the given subject_offering_id
     * (e.g. both the Monday and Wednesday rows of a 2x/week subject),
     * scoped to the Working Term. The subject goes back to showing as
     * unscheduled on the grid/Subject Sidebar; it can be re-generated
     * or manually re-placed from there like any other unscheduled
     * offering.
     *
     * Deliberately does NOT touch `teaching_assignments`. Removing a
     * day/time/room placement is a different decision from un-
     * assigning the faculty member — Faculty Loading is its own
     * workspace with its own explicit "remove assignment" action, and
     * silently clearing it here just because the grid placement was
     * pulled would surprise a Registrar who only meant to re-schedule
     * the same faculty member at a different time. If the Registrar
     * really does want the faculty un-assigned too, that's still a
     * separate, explicit action in Faculty Loading.
     */
    public function removeSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject_offering_id' => ['required', 'integer', 'exists:subject_offerings,id'],
        ]);

        $planningTerm = $this->workspace->getTermForUser(auth()->user());

        abort_unless($planningTerm, 422, 'No Planning Academic Term is set. Configure one in Settings > Scheduling Workspace.');

        $this->workspace->assertWritable($planningTerm);

        $offering = \App\Models\SubjectOffering::with('program')
            ->find($validated['subject_offering_id']);

        if ($offering && $departmentId = $offering->program?->department_id) {
            \App\Services\TermFinalizationService::abortIfDepartmentFinalized($departmentId, $planningTerm->id);
        }

        $deleted = Schedule::forTerm($planningTerm->id)
            ->where('subject_offering_id', $validated['subject_offering_id'])
            ->delete();

        abort_if($deleted === 0, 404, 'No committed schedule was found for this subject on the current Working Term.');

        AuditLogService::log(
            action: 'deleted',
            module: 'Master Grid',
            description: "Removed committed schedule for subject offering #{$validated['subject_offering_id']} from {$planningTerm->display_name}",
            oldValues: [
                'subject_offering_id' => $validated['subject_offering_id'],
                'academic_term' => $planningTerm->display_name,
            ],
            recordName: "Offering #{$validated['subject_offering_id']}",
        );

        ActivityHistoryService::recordScheduleManuallyAdjusted(
            $planningTerm,
            1,
            "Subject offering #{$validated['subject_offering_id']} removed from Master Grid"
        );

        return response()->json([
            'message' => 'Schedule removed — this subject is unscheduled again.',
            'subject_offering_id' => (int) $validated['subject_offering_id'],
        ]);
    }

    /**
     * Groups $changedOfferingIds (real writes only — see save()'s
     * docblock on why this is $changedOfferingIds and not every
     * offering in the payload) by department and sends one batched
     * MasterGridScheduleSaved notification per affected college. A
     * single save touching 12 subjects across CCS and CTE sends
     * exactly 2 notifications (one per college), never 12.
     *
     * General Education offerings (department_id null) are skipped —
     * same reasoning as every other department-scoped notification in
     * this app: there's no single Dean/OIC to address it to.
     */
    private function notifyDepartmentsOfSave($changedOfferingIds, AcademicTerm $term, User $performedBy): void
    {
        if ($changedOfferingIds->isEmpty()) {
            return;
        }

        $byDepartment = SubjectOffering::whereIn('id', $changedOfferingIds)
            ->with('program')
            ->get()
            ->groupBy(fn (SubjectOffering $offering) => $offering->program?->department_id);

        foreach ($byDepartment as $departmentId => $offerings) {
            if (! $departmentId) {
                continue;
            }

            $department = Department::find($departmentId);

            if (! $department) {
                continue;
            }

            $recipients = $this->resolveStakeholders($department, $performedBy);

            if ($recipients->isEmpty()) {
                continue;
            }

            Notification::send($recipients, new MasterGridScheduleSaved(
                $department,
                $term,
                $performedBy,
                $offerings->count()
            ));
        }
    }

    /**
     * Admin + Registrar + Assistant Dean (global tiers) merged with
     * one college's Dean/OIC (department-scoped tier), deduplicated,
     * with whoever performed the save removed from the list. Exact
     * same recipient rule as
     * TermFinalizationService::resolveStakeholders() — duplicated
     * here rather than shared, since Master Grid's save() lives in
     * this controller directly with no owning notification service of
     * its own (unlike Faculty Load Overload / College Finalization,
     * which each have one). If this rule needs to change, update both
     * places.
     */
    private function resolveStakeholders(Department $department, User $performedBy): \Illuminate\Support\Collection
    {
        $global = User::role(['Admin', 'Registrar', 'Assistant Dean'])->get();

        $departmentScoped = User::role(['Dean', 'OIC'])
            ->where('department_id', $department->id)
            ->get();

        return $global->merge($departmentScoped)
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $performedBy->id)
            ->values();
    }

    /**
     * Keeps `teaching_assignments` in step with whatever faculty a
     * saved Schedule block ends up carrying, so Faculty Loading's
     * "Assigned Subjects" table and unit totals never silently fall
     * out of sync with what Master Grid actually committed.
     *
     * Three cases, keyed off subject_offering_id (the same column
     * `teaching_assignments` uniquely indexes on):
     *
     *   1. No faculty on the block — nothing to sync. A Teaching
     *      Assignment made through the Faculty Loading workspace
     *      itself is left untouched; Master Grid never invents a
     *      faculty member that wasn't actually placed on the grid.
     *   2. A Teaching Assignment for this offering already names the
     *      SAME faculty — nothing to do, it's already correct.
     *   3. Otherwise — create or repoint the assignment to the
     *      faculty Master Grid saved. This deliberately covers both
     *      "no assignment existed yet" (the Greedy Scheduler picked a
     *      faculty member directly) and "a different faculty was
     *      assigned before" (a manual Edit Schedule change should win,
     *      since it's the more recent, more specific decision).
     *
     * updateOrCreate() targets subject_offering_id only (not
     * +faculty_id), so re-saving the same offering with a new faculty
     * updates the existing row instead of violating the unique
     * constraint on subject_offering_id by inserting a second one.
     */
    private function syncTeachingAssignment(array $block): void
    {
        $facultyId = $block['faculty_id'] ?? null;

        if (! $facultyId) {
            return;
        }

        $existing = TeachingAssignment::where('subject_offering_id', $block['subject_offering_id'])->first();

        if ($existing && (int) $existing->faculty_id === (int) $facultyId) {
            return;
        }

        TeachingAssignment::updateOrCreate(
            ['subject_offering_id' => $block['subject_offering_id']],
            [
                'faculty_id' => $facultyId,
                'active' => true,
            ]
        );
    }
}