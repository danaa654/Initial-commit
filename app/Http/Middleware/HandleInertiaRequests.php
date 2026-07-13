<?php

namespace App\Http\Middleware;

use App\Models\AcademicTerm;
use App\Services\SchedulingWorkspaceService;
use App\Services\SemesterTransitionService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            // Shared on every Inertia response so any page/component can read
            // $page.props.activeAcademicTerm without the controller having to
            // fetch or pass it. Resolves to null (never throws) when no term
            // is currently marked active.
            'activeAcademicTerm' => fn () => AcademicTerm::active()->first(),

            // The Scheduling Workspace's "Working Term" — shaped for
            // the Topbar. This is role-aware, NOT simply "whatever the
            // Planning Term currently is":
            //
            //   - Admin/Registrar  -> the Planning Academic Term, so
            //     they can see (and, via the switcher below, change)
            //     which future semester they're preparing schedules
            //     for ahead of time — including switching to an
            //     Archived term to review it read-only.
            //   - Dean/Assistant Dean/OIC/anyone else -> the Active
            //     Academic Term, always. They review the semester
            //     that's actually running, never a Planning draft that
            //     could still change — see
            //     SchedulingWorkspaceService::getTermForUser().
            //
            // scheduling_status is a 3-state label — Active/Archived/
            // Planning — computed by schedulingStatusFor() below, so
            // an Archived term is never mislabeled "Planning" just
            // because it happens to be selected as the Working Term.
            // is_read_only mirrors AcademicTerm::is_locked: true
            // whenever the term's own workflow status is Archived,
            // regardless of whether it's Active/Planning/whatever —
            // scheduling controllers use this same rule server-side
            // to actually block writes, this flag is just for the UI
            // to gray itself out proactively.
            'workingAcademicTerm' => function () use ($user) {
                $workspace = app(SchedulingWorkspaceService::class);
                $working = $workspace->getTermForUser($user);

                if (! $working) {
                    return null;
                }

                $active = $workspace->getActiveTerm();

                return [
                    'id' => $working->id,
                    'academic_year' => $working->academic_year,
                    'semester_label' => $working->semester_label,
                    'scheduling_status' => $this->schedulingStatusFor($working, $active),
                    'is_read_only' => $working->status === 'Archived',
                ];
            },

            // Every Academic Term, for the Topbar's "Switch Working
            // Term" dropdown — populated ONLY for Admin/Registrar.
            // Dean/Assistant Dean/OIC (and everyone else) get an empty
            // array, which is what Topbar.vue's canSwitchWorkingTerm
            // uses to keep the dropdown from opening at all for them —
            // they only ever get the read-only Active-term pill above.
            //
            // Archived terms are intentionally included here, not
            // filtered out — Admin/Registrar can deliberately switch
            // the Working Term to an Archived semester to review its
            // committed schedule read-only (see is_read_only above and
            // the write-guards added to each scheduling controller).
            'academicTermsForSwitcher' => function () use ($user) {
                if (! $user || ! $user->hasAnyRole(['Admin', 'Registrar'])) {
                    return [];
                }

                $workspace = app(SchedulingWorkspaceService::class);
                $active = $workspace->getActiveTerm();

                return AcademicTerm::orderByDesc('academic_year')
                    ->orderBy('semester')
                    ->get()
                    ->map(fn (AcademicTerm $term) => [
                        'id' => $term->id,
                        'academic_year' => $term->academic_year,
                        'semester_label' => $term->semester_label,
                        'scheduling_status' => $this->schedulingStatusFor($term, $active),
                    ])
                    ->values();
            },

            // Drives the "Semester Ended" banner — null for everyone
            // except Admin/Registrar, and null even for them unless the
            // Active Academic Term has actually passed its Class End
            // date. See SemesterTransitionService for the full
            // "surface it, never auto-flip it" reasoning, and
            // AcademicTermController::closeActiveTerm() for the action
            // the banner's button submits to.
            'semesterTransition' => function () use ($user) {
                if (! $user || ! $user->hasAnyRole(['Admin', 'Registrar'])) {
                    return null;
                }

                return app(SemesterTransitionService::class)->bannerData();
            },

            // Relayed from session flash data set by controller redirect()
            // ->with('success'|'warning'|'error', $message) calls. Read by
            // useFlashToast.js on the frontend — without this key being
            // shared, that composable's watcher never has anything to
            // react to, and toasts never appear no matter what the
            // controller flashes.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
                'error' => fn () => $request->session()->get('error'),
                'deleted' => fn () => $request->session()->get('deleted'),
            ],

            // Unread notifications for the current user — shared on
            // EVERY page (not just Faculty Loading or Scheduling
            // Workspace), since the people involved could be off
            // working anywhere else in the app when one of these
            // fires. Five types feed into this single prop, merged
            // and sorted together so the Topbar dropdown renders one
            // unified list:
            //
            //   - FacultyLoadOverloadReviewed        — "your request
            //     was approved/declined", sent to the requester.
            //   - FacultyLoadOverloadRequested        — "a new request
            //     needs your review", sent to every Admin/Registrar.
            //   - FacultyLoadOverloadAppliedByAdmin   — "Admin/
            //     Registrar added overload units to your department's
            //     faculty member", sent to that department's Dean/OIC.
            //   - ScheduleFinalized                   — "a college's
            //     schedule was finalized (now read-only)", sent to
            //     Admin/Registrar/Assistant Dean + that college's
            //     Dean/OIC, minus whoever performed the action — see
            //     TermFinalizationService::notifyDepartmentOfFinalization().
            //   - ScheduleUnfinalized                  — same
            //     recipients as above, for the schedule being
            //     reopened — see
            //     TermFinalizationService::notifyDepartmentOfUnfinalization().
            //   - MasterGridScheduleSaved              — "N subjects
            //     changed in a Master Grid save", same recipient rule
            //     as the two above, batched per college per save — see
            //     MasterGridController::notifyDepartmentsOfSave().
            //   - SubjectOfferingsGenerated             — "N Subject
            //     Offerings generated for a curriculum", same
            //     recipient rule, one notification per generate()
            //     call — see
            //     SubjectOfferingController::notifyDepartmentOfGeneration().
            //   - SectionCreated                        — "a new
            //     Section was created", same recipient rule, one
            //     notification per store() call — see
            //     SectionController::notifyDepartmentOfSectionCreated().
            //     Unlike the others, this one carries an actionable
            //     section_id so the Topbar can navigate straight to
            //     the Section's Edit page.
            //
            // Kept as the same 'overloadNotifications' prop key (not
            // renamed) so the existing Topbar.vue/useNotifications
            // frontend wiring doesn't need to change — only the list
            // of types being merged into it grew.
            //
            // Empty array for guests/unauthenticated requests. See
            // FacultyLoadOverloadController::markNotificationRead()/
            // markAllNotificationsRead() for how the frontend
            // dismisses these (markAllNotificationsRead's own
            // whitelist must be kept in sync with this one, or "mark
            // all as read" will silently skip whichever type is
            // missing from it), and Topbar.vue for how
            // `notification.type` picks which card layout to render.
            'overloadNotifications' => fn () => $user
                ? $user->unreadNotifications()
                    ->whereIn('type', [
                        \App\Notifications\FacultyLoadOverloadReviewed::class,
                        \App\Notifications\FacultyLoadOverloadRequested::class,
                        \App\Notifications\FacultyLoadOverloadAppliedByAdmin::class,
                        \App\Notifications\ScheduleFinalized::class,
                        \App\Notifications\ScheduleUnfinalized::class,
                        \App\Notifications\MasterGridScheduleSaved::class,
                        \App\Notifications\SubjectOfferingsGenerated::class,
                        \App\Notifications\SectionCreated::class,
                    ])
                    ->orderByDesc('created_at')
                    ->get(['id', 'type', 'data', 'created_at'])
                : [],

            'auth' => [
                'user' => $user
                    ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,

                        // Array of role names
                        'roles' => $user->getRoleNames()->toArray(),

                        // Array of permission names
                        'permissions' => $user->getPermissionNames()->toArray(),
                    ]
                    : null,
            ],
        ];
    }

    /**
     * The 3-state label shown next to a term in the Topbar/switcher:
     * "Active" if it IS the Active term, "Archived" if its own
     * workflow status says so (checked BEFORE the Planning fallback,
     * since a term can be both "not the Active term" and "Archived" —
     * that combination must read Archived, never Planning), otherwise
     * "Planning".
     */
    private function schedulingStatusFor(AcademicTerm $term, ?AcademicTerm $active): string
    {
        if ($active && $active->id === $term->id) {
            return 'Active';
        }

        if ($term->status === 'Archived') {
            return 'Archived';
        }

        return 'Planning';
    }
}