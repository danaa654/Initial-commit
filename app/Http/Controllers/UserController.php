<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Services\AuditLogService;

class UserController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {

                abort_unless(
                    auth()->user()->hasRole('Admin'),
                    403,
                    'Unauthorized.'
                );

                return $next($request);
            }),
        ];
    }

    /**
     * Display a listing of users.
     */
    public function index()
    {
        $users = User::with(['roles', 'department'])
            ->orderBy('name')
            ->get();

        // The system must always keep at least one Admin able to log in,
        // so once there's only one left, that account can't be deleted —
        // regardless of which Admin it happens to be (not hardcoded to
        // the seeded admin@classly.test specifically, so this still holds
        // even if that account gets renamed or a second Admin is added
        // and later removed).
        $adminCount = User::role('Admin')->count();

        $users->each(function ($user) use ($adminCount) {

            $user->department_name = $user->hasRole([
                'Admin',
                'Registrar',
                'Assistant Dean',
            ])
                ? 'All Departments'
                : optional($user->department)->abbreviation;

            $isLastAdmin = $user->hasRole('Admin') && $adminCount <= 1;

            $user->is_protected = auth()->id() === $user->id || $isLastAdmin;

            $user->protected_reason = match (true) {
                auth()->id() === $user->id => 'You cannot delete your own account.',
                $isLastAdmin => 'This is the last remaining Admin account and cannot be deleted.',
                default => null,
            };

        });

        return Inertia::render('Users/Index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create()
    {
        return Inertia::render('Users/Create', [

            'roles' => Role::orderBy('name')->get(),

            'departments' => Department::where('active', true)
                ->orderBy('abbreviation')
                ->get(),

        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'unique:users,email'],
            'password'      => ['required', 'min:6', 'confirmed'],
            'role'          => ['required', 'exists:roles,name'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $rolesWithoutDepartment = [
            'Admin',
            'Registrar',
            'Assistant Dean',
        ];

        if (in_array($validated['role'], $rolesWithoutDepartment)) {
            $validated['department_id'] = null;
        }

        $user = User::create([
            'name'          => $validated['name'],
            'email'         => $validated['email'],
            'password'      => bcrypt($validated['password']),
            'department_id' => $validated['department_id'],
        ]);

        $user->assignRole($validated['role']);

        AuditLogService::log(
            action: 'created',
            module: 'User Management',
            model: $user,
            description: "Created user {$user->name} ({$validated['role']})",
            newValues: [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $validated['role'],
                'department_id' => $validated['department_id'],
            ],
        );

        return redirect()
            ->route('users.index')
            ->with('success', 'User created successfully.');
    }

    /**
     * Show the edit form.
     */
    public function edit(User $user)
    {
        $user->load('roles');

        return Inertia::render('Users/Edit', [

            'user' => $user,

            'roles' => Role::orderBy('name')->get(),

            'departments' => Department::where('active', true)
                ->orderBy('abbreviation')
                ->get(),

        ]);
    }

    /**
     * Update the user.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:users,email,' . $user->id,
            'password'      => 'nullable|min:6|confirmed',
            'role'          => 'required|exists:roles,name',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        if (in_array($validated['role'], [
            'Admin',
            'Registrar',
            'Assistant Dean',
        ])) {
            $validated['department_id'] = null;
        }

        // Captured BEFORE any changes are applied — this is what makes
        // old_values possible. sanitizeValues() in AuditLogService
        // strips 'password' regardless, but it's never included here
        // in the first place since we only snapshot name/email/role/
        // department, matching the spec's "do not store passwords or
        // sensitive authentication data" rule at the source, not just
        // as a defensive filter.
        $previousRole = $user->getRoleNames()->first();

        $oldValues = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $previousRole,
            'department_id' => $user->department_id,
        ];

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->department_id = $validated['department_id'];

        if (!empty($validated['password'])) {
            $user->password = bcrypt($validated['password']);
        }

        $user->save();

        $user->syncRoles([$validated['role']]);

        $newValues = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $validated['role'],
            'department_id' => $user->department_id,
        ];

        AuditLogService::log(
            action: 'updated',
            module: 'User Management',
            model: $user,
            description: "Updated user {$user->name}",
            oldValues: $oldValues,
            newValues: $newValues,
        );

        // A role change is significant enough to also get its own
        // distinct, easy-to-filter-for log entry (action = 'role_changed')
        // on top of the general 'updated' one above — an Admin scanning
        // the Audit Log for "who changed permissions" shouldn't have to
        // open every 'updated' row on Users to find out.
        if ($previousRole !== $validated['role']) {
            AuditLogService::log(
                action: 'role_changed',
                module: 'User Management',
                model: $user,
                description: "Changed {$user->name}'s role from {$previousRole} to {$validated['role']}",
                oldValues: ['role' => $previousRole],
                newValues: ['role' => $validated['role']],
            );
        }

        return redirect()
            ->route('users.index')
            ->with('success', 'User updated successfully.');
    }

    /**
     * Delete the user.
     */
    public function destroy(User $user)
    {
        if (auth()->id() === $user->id) {
            return back()->with(
                'error',
                'You cannot delete your own account.'
            );
        }

        if ($user->hasRole('Admin') && User::role('Admin')->count() <= 1) {
            return back()->with(
                'error',
                'You cannot delete the last remaining Admin account.'
            );
        }

        $userName = $user->name;
        $userRole = $user->getRoleNames()->first();

        $user->delete();

        AuditLogService::log(
            action: 'deleted',
            module: 'User Management',
            description: "Deleted user {$userName} ({$userRole})",
            oldValues: ['name' => $userName, 'role' => $userRole],
            recordName: $userName,
        );

        return redirect()
            ->route('users.index')
            ->with('success', 'User deleted successfully.');
    }
}