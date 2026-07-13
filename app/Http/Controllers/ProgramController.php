<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\Department;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProgramController extends Controller implements HasMiddleware
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
        return Inertia::render('Programs/Index', [
            'programs' => Program::with('department')
                ->orderBy('code')
                ->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Programs/Create', [
            'departments' => Department::where('active', true)
                ->orderBy('abbreviation')
                ->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'department_id' => 'required|exists:departments,id',

            'code' => 'required|string|max:20|unique:programs,code',

            'name' => 'required|string|max:255',

            'years' => 'required|integer|min:1|max:8',

            'active' => 'required|boolean',

        ]);

        $program = Program::create($validated);

        // Audit Log — master data setup, same tier as
        // College/Sections/Curriculum, not a scheduling milestone, so
        // this belongs in Audit Logs, not Activity History. 'Program'
        // isn't in AuditLogService::MODULES' static UI list, but that
        // list is a convenience only (not a DB constraint) — the
        // Audit Logs filter dropdown is populated from actual distinct
        // rows already logged, so 'Program' shows up there correctly
        // once this fires.
        AuditLogService::log(
            action: 'created',
            module: 'Program',
            model: $program,
            description: "Created program {$program->name} ({$program->code})",
            newValues: [
                'code' => $program->code,
                'name' => $program->name,
                'department_id' => $program->department_id,
                'years' => $program->years,
                'active' => $program->active,
            ],
        );

        return redirect()
            ->route('academic-structure.index')
            ->with('success', 'Program created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Program $program)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Program $program)
    {
        return Inertia::render('Programs/Edit', [

            'program' => $program,

            'departments' => Department::where('active', true)
                ->orderBy('abbreviation')
                ->get(),

        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Program $program)
    {
        $validated = $request->validate([

            'department_id' => 'required|exists:departments,id',

            'code' => 'required|string|max:20|unique:programs,code,' . $program->id,

            'name' => 'required|string|max:255',

            'years' => 'required|integer|min:1|max:8',

            'active' => 'required|boolean',

        ]);

        // Captured BEFORE any changes are applied, same convention as
        // every other controller's update() in this codebase — this
        // is what makes old_values possible below.
        $oldValues = [
            'code' => $program->code,
            'name' => $program->name,
            'department_id' => $program->department_id,
            'years' => $program->years,
            'active' => $program->active,
        ];

        $program->update($validated);

        AuditLogService::log(
            action: 'updated',
            module: 'Program',
            model: $program,
            description: "Updated program {$program->name}",
            oldValues: $oldValues,
            newValues: [
                'code' => $program->code,
                'name' => $program->name,
                'department_id' => $program->department_id,
                'years' => $program->years,
                'active' => $program->active,
            ],
        );

        return redirect()
            ->route('academic-structure.index')
            ->with('success', 'Program updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Program $program)
    {
        // Block deletion if this program still has curricula or
        // specializations attached. Same pattern as
        // DepartmentController::destroy() and
        // CurriculumController::destroy() — check for dependents
        // before attempting delete() so we can show a friendly error
        // instead of letting the DB throw a raw FK violation.
        if ($program->curricula()->exists() || $program->specializations()->exists()) {
            return redirect()
                ->route('academic-structure.index')
                ->with('error', 'Unable to delete this program. It has associated curriculums or specializations. Please remove or reassign them first.');
        }

        // Captured before delete() — nothing left in the database to
        // read back afterward, same reasoning as
        // SectionController::destroy()'s Audit Log call.
        $code = $program->code;
        $name = $program->name;

        try {
            $program->delete();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('academic-structure.index')
                ->with('error', 'Unable to delete the selected program.');
        }

        AuditLogService::log(
            action: 'deleted',
            module: 'Program',
            description: "Deleted program {$name} ({$code})",
            oldValues: ['code' => $code, 'name' => $name],
            recordName: $name,
        );

        return redirect()
            ->route('academic-structure.index')
            ->with('success', 'Program deleted successfully.');
    }
}