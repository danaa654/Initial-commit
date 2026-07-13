<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\Specialization;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SpecializationController extends Controller implements HasMiddleware
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
        return Inertia::render('Specializations/Index', [
            'specializations' => Specialization::with('program.department')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Specializations/Create', [
            'programs' => Program::with('department')
                ->where('active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Store a newly created resource.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'program_id' => [
                'required',
                'exists:programs,id',
            ],

            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('specializations')
                    ->where(fn ($query) =>
                        $query->where('program_id', $request->program_id)
                    ),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('specializations')
                    ->where(fn ($query) =>
                        $query->where('program_id', $request->program_id)
                    ),
            ],

            'active' => [
                'required',
                'boolean',
            ],

        ]);

        if (!empty($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $specialization = Specialization::create($validated);

        // Audit Log — master data setup, same tier as
        // Program/College/Sections/Curriculum, not a scheduling
        // milestone, so this belongs in Audit Logs, not Activity
        // History. 'Specialization' is already a valid module in
        // AuditLogService::MODULES.
        AuditLogService::log(
            action: 'created',
            module: 'Specialization',
            model: $specialization,
            description: "Created specialization {$specialization->name}",
            newValues: [
                'program_id' => $specialization->program_id,
                'code' => $specialization->code,
                'name' => $specialization->name,
                'active' => $specialization->active,
            ],
        );

        return redirect()
            ->route('academic-structure.index')
            ->with('success', 'Specialization created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Specialization $specialization)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Specialization $specialization)
    {
        return Inertia::render('Specializations/Edit', [

            'specialization' => $specialization,

            'programs' => Program::with('department')
                ->where('active', true)
                ->orderBy('name')
                ->get(),

        ]);
    }

    /**
     * Update the specified resource.
     */
    public function update(Request $request, Specialization $specialization)
    {
        $validated = $request->validate([

            'program_id' => [
                'required',
                'exists:programs,id',
            ],

            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('specializations')
                    ->ignore($specialization->id)
                    ->where(fn ($query) =>
                        $query->where('program_id', $request->program_id)
                    ),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('specializations')
                    ->ignore($specialization->id)
                    ->where(fn ($query) =>
                        $query->where('program_id', $request->program_id)
                    ),
            ],

            'active' => [
                'required',
                'boolean',
            ],

        ]);

        if (!empty($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        // Captured BEFORE any changes are applied, same convention as
        // every other controller's update() in this codebase — this
        // is what makes old_values possible below.
        $oldValues = [
            'program_id' => $specialization->program_id,
            'code' => $specialization->code,
            'name' => $specialization->name,
            'active' => $specialization->active,
        ];

        $specialization->update($validated);

        AuditLogService::log(
            action: 'updated',
            module: 'Specialization',
            model: $specialization,
            description: "Updated specialization {$specialization->name}",
            oldValues: $oldValues,
            newValues: [
                'program_id' => $specialization->program_id,
                'code' => $specialization->code,
                'name' => $specialization->name,
                'active' => $specialization->active,
            ],
        );

        return redirect()
            ->route('academic-structure.index')
            ->with('success', 'Specialization updated successfully.');
    }

    /**
     * Remove the specified resource.
     */
    public function destroy(Specialization $specialization)
    {
        // Block deletion if this specialization still has curricula
        // attached (curricula.specialization_id is nullable, but any
        // curriculum that was created under this specialization still
        // references it). Same pattern as DepartmentController and
        // ProgramController's destroy() methods.
        if ($specialization->curricula()->exists()) {
            return redirect()
                ->route('academic-structure.index')
                ->with('error', 'Unable to delete this specialization. It has associated curriculums. Please remove or reassign them first.');
        }

        // Captured before delete() — nothing left in the database to
        // read back afterward, same reasoning as
        // SectionController::destroy()'s Audit Log call.
        $name = $specialization->name;
        $code = $specialization->code;

        try {
            $specialization->delete();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('academic-structure.index')
                ->with('error', 'Unable to delete the selected specialization.');
        }

        AuditLogService::log(
            action: 'deleted',
            module: 'Specialization',
            description: "Deleted specialization {$name}",
            oldValues: ['name' => $name, 'code' => $code],
            recordName: $name,
        );

        return redirect()
            ->route('academic-structure.index')
            ->with('success', 'Specialization deleted successfully.');
    }
}