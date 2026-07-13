<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;


class DepartmentController extends Controller implements HasMiddleware
{

    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {

                abort_unless(
                    auth()->user()->hasAnyRole(['Admin', 'Registrar']),
                    403,
                    'Unauthorized.'
                );

                return $next($request);
            }),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Departments/Index', [
            'departments' => Department::orderBy('abbreviation')->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Departments/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',

            'abbreviation' => 'required|string|max:20|unique:departments,abbreviation',

            'description' => 'nullable|string|max:500',

            'active' => 'required|boolean',
        ]);

        $department = Department::create([
            'name' => $validated['name'],

            'abbreviation' => strtoupper($validated['abbreviation']),

            'description' => $validated['description'],

            'active' => $validated['active'],
        ]);

        // Audit Log — master data setup, same tier as
        // Sections/Curriculums/Specializations, not a scheduling
        // milestone, so this belongs in Audit Logs, not Activity
        // History. 'College' is already a valid module in
        // AuditLogService::MODULES.
        AuditLogService::log(
            action: 'created',
            module: 'College',
            model: $department,
            description: "Created college {$department->name} ({$department->abbreviation})",
            newValues: [
                'name' => $department->name,
                'abbreviation' => $department->abbreviation,
                'description' => $department->description,
                'active' => $department->active,
            ],
        );

        return redirect()
            ->route('academic-structure.index')
            ->with('success', 'College created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Department $department)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Department $department)
    {
        return Inertia::render('Departments/Edit', [
            'department' => $department,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',

            'abbreviation' => 'required|string|max:20|unique:departments,abbreviation,' . $department->id,

            'description' => 'nullable|string|max:500',

            'active' => 'required|boolean',
        ]);

        // Captured BEFORE any changes are applied, same convention as
        // UserController::update() / SectionController::update() —
        // this is what makes old_values possible below.
        $oldValues = [
            'name' => $department->name,
            'abbreviation' => $department->abbreviation,
            'description' => $department->description,
            'active' => $department->active,
        ];

        $department->update([
            'name' => $validated['name'],

            'abbreviation' => strtoupper($validated['abbreviation']),

            'description' => $validated['description'],

            'active' => $validated['active'],
        ]);

        AuditLogService::log(
            action: 'updated',
            module: 'College',
            model: $department,
            description: "Updated college {$department->name}",
            oldValues: $oldValues,
            newValues: [
                'name' => $department->name,
                'abbreviation' => $department->abbreviation,
                'description' => $department->description,
                'active' => $department->active,
            ],
        );

        return redirect()
            ->route('academic-structure.index')
            ->with('success', 'College updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Department $department)
    {
        // Block deletion if this college still has programs attached.
        // Curricula and Specializations both hang off Program (not
        // Department directly), so checking programs() is sufficient —
        // if there are no programs left, there can't be any curricula
        // or specializations tied to this college either. Same pattern
        // as CurriculumController::destroy()'s sections/curriculumItems
        // check.
        if ($department->programs()->exists()) {
            return redirect()
                ->route('academic-structure.index')
                ->with('error', 'Unable to delete this college. It has associated programs, curriculums, or specializations. Please remove or reassign them first.');
        }

        // Captured before delete() — nothing left in the database to
        // read back afterward, same reasoning as
        // SectionController::destroy()'s Audit Log call.
        $name = $department->name;
        $abbreviation = $department->abbreviation;

        try {
            $department->delete();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('academic-structure.index')
                ->with('error', 'Unable to delete the selected college.');
        }

        AuditLogService::log(
            action: 'deleted',
            module: 'College',
            description: "Deleted college {$name} ({$abbreviation})",
            oldValues: ['name' => $name, 'abbreviation' => $abbreviation],
            recordName: $name,
        );

        return redirect()
            ->route('academic-structure.index')
            ->with('success', 'College deleted successfully.');
    }
}