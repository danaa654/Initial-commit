<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\FacultyLoadOverload;
use App\Models\User;
use App\Services\FacultyLoadOverloadService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Faculty Load Overload — requesting (and, for Admin/Registrar,
 * reviewing) a bump to one faculty member's effective teaching cap.
 * See FacultyLoadOverloadService for the actual business rules; this
 * controller is just validation + RBAC + the redirect, the same
 * division of responsibility as TeachingAssignmentController.
 */
class FacultyLoadOverloadController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly FacultyLoadOverloadService $service
    ) {
    }

    /**
     * Same role tier as Faculty Loading itself — anyone who can manage
     * a faculty member's load can request more room in it. approve()/
     * decline() are further restricted to Admin/Registrar only, via
     * the role:Admin|Registrar route group in web.php, not here — a
     * direct hit from a Dean's session still 403s at the route layer
     * before it ever reaches this controller.
     */
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
        ];
    }

    /**
     * Submit a new overload request. Admin/Registrar requests are
     * approved immediately by the service; everyone else's lands as
     * 'pending' for Admin/Registrar to review (who are notified via
     * FacultyLoadOverloadRequested — see the service).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'faculty_id' => ['required', 'integer', 'exists:faculties,id'],
            'units' => ['required', 'integer', 'in:3,6,9,12'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $faculty = Faculty::findOrFail($validated['faculty_id']);

        $this->assertManagesFaculty($faculty);

        $overload = $this->service->request(
            $faculty,
            auth()->user(),
            $validated['units'],
            $validated['reason'] ?? null
        );

        $message = $overload->status === FacultyLoadOverload::STATUS_APPROVED
            ? "Added {$overload->units} overload units to {$faculty->full_name}'s load."
            : "Overload request submitted for {$faculty->full_name} — awaiting Admin/Registrar review.";

        return back()->with('success', $message);
    }

    /**
     * Approve a pending request. Admin/Registrar only (enforced at the
     * route level).
     */
    public function approve(FacultyLoadOverload $facultyLoadOverload)
    {
        $this->service->approve($facultyLoadOverload, auth()->user());

        return back()->with('success', "Overload request approved for {$facultyLoadOverload->faculty->full_name}.");
    }

    /**
     * Decline a pending request, with a required explanation for the
     * requester. Admin/Registrar only (enforced at the route level).
     */
    public function decline(Request $request, FacultyLoadOverload $facultyLoadOverload)
    {
        $validated = $request->validate([
            'decline_reason' => ['required', 'string', 'max:500'],
        ]);

        $this->service->decline($facultyLoadOverload, auth()->user(), $validated['decline_reason']);

        return back()->with('success', "Overload request declined for {$facultyLoadOverload->faculty->full_name}.");
    }

    /**
     * Dismiss one notification — either a "your request was
     * approved/declined" (FacultyLoadOverloadReviewed) or a "new
     * request needs your review" (FacultyLoadOverloadRequested) — as
     * clicked/dismissed in the Topbar dropdown. Deliberately scoped to
     * auth()->user()->notifications() rather than a bare
     * DatabaseNotification::find() — a user can only ever mark their
     * OWN notifications read, never guess another user's notification
     * ID and silence it for them.
     */
    public function markNotificationRead(string $notification)
    {
        $record = auth()->user()->notifications()->whereKey($notification)->first();

        $record?->markAsRead();

        return back();
    }

    /**
     * Dismiss every unread notification for the current user at once
     * — the dropdown's "Mark all as read". Covers every type merged
     * into HandleInertiaRequests' `overloadNotifications` shared prop
     * (Faculty Load Overload's three types plus College Finalization's
     * two) so a future, unrelated notification feature sharing the
     * same `notifications` table isn't silently swept up by this
     * action too. Keep this whitelist in sync with the one in
     * HandleInertiaRequests::share() — a type present in one but not
     * the other either can't be dismissed in bulk, or (worse) never
     * shows up in the dropdown to begin with.
     */
    public function markAllNotificationsRead()
    {
        auth()->user()->unreadNotifications()
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
            ->update(['read_at' => now()]);

        return back();
    }

    /**
     * RBAC guard: can the currently logged-in manager request overload
     * for this particular faculty member at all? Exact mirror of
     * TeachingAssignmentController::assertManagesFaculty() — scoped
     * managers (Dean, OIC) may only touch faculty in their own
     * department or General Education (department_id null). See
     * managerDepartmentId() below for which roles count as scoped.
     */
    private function assertManagesFaculty(Faculty $faculty): void
    {
        $departmentId = $this->managerDepartmentId(auth()->user());

        if ($departmentId === null) {
            return;
        }

        if ($faculty->department_id !== null && (int) $faculty->department_id !== $departmentId) {
            abort(403, 'You do not have permission to request overload for this faculty member.');
        }
    }

    /**
     * The department a manager is scoped to, or null if they oversee
     * every department — identical rule to
     * TeachingAssignmentController::managerDepartmentId().
     */
    private function managerDepartmentId(User $user): ?int
    {
        if ($user->hasAnyRole(['Admin', 'Registrar', 'Assistant Dean'])) {
            return null;
        }

        return $user->department_id;
    }
}