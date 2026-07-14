<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Specialization;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CurriculumController extends Controller implements HasMiddleware
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
     * Display a listing of the resource with filtering support.
     * 
     * Supports optional Search / Program / Status filtering via query
     * params (?search=&program_id=&status=), all combinable. Filters are
     * echoed back in the `filters` prop so the Index page can preload
     * its inputs from the URL.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $programId = $request->input('program_id');
        $status = $request->input('status');

        $curricula = Curriculum::with([
                'program.department',
                'specialization',
            ])
            ->withCount('curriculumItems')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($programId, function ($query) use ($programId) {
                $query->where('program_id', $programId);
            })
            ->when(in_array($status, ['Active', 'Inactive'], true), function ($query) use ($status) {
                $query->where('active', $status === 'Active');
            })
            ->orderBy('effective_year', 'desc')
            ->get();

        return Inertia::render('Curriculums/Index', [
            'curricula' => $curricula,
            'programs' => Program::where('active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'filters' => [
                'search' => $search,
                'program_id' => $programId ? (int) $programId : null,
                'status' => in_array($status, ['Active', 'Inactive'], true) ? $status : null,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Curriculums/Create', [

            'programs' => Program::with('department')
                ->where('active', true)
                ->orderBy('name')
                ->get(),

            'specializations' => Specialization::where('active', true)
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

            'specialization_id' => [
                'nullable',
                'exists:specializations,id',
            ],

            'code' => [
                'required',
                'string',
                'max:50',
                'unique:curricula,code',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'academic_year' => [
                'required',
                'string',
                'max:20',
            ],

            'effective_year' => [
                'required',
                'digits:4',
                Rule::unique('curricula')->where(function ($query) use ($request) {
                    return $query
                        ->where('program_id', $request->program_id)
                        ->where('specialization_id', $request->specialization_id);
                }),
            ],

            'active' => [
                'required',
                'boolean',
            ],

        ]);

        $validated['code'] = strtoupper($validated['code']);

        $curriculum = Curriculum::create($validated);

        // Audit Log — master data setup, same tier as
        // Program/College/Sections/Specialization, not a scheduling
        // milestone, so this belongs in Audit Logs, not Activity
        // History. 'Curriculum' is already a valid module in
        // AuditLogService::MODULES.
        AuditLogService::log(
            action: 'created',
            module: 'Curriculum',
            model: $curriculum,
            description: "Created curriculum {$curriculum->code} - {$curriculum->name}",
            newValues: [
                'code' => $curriculum->code,
                'name' => $curriculum->name,
                'program_id' => $curriculum->program_id,
                'specialization_id' => $curriculum->specialization_id,
                'academic_year' => $curriculum->academic_year,
                'effective_year' => $curriculum->effective_year,
                'active' => $curriculum->active,
            ],
        );

        return redirect()
            ->route('curriculums.index')
            ->with('success', 'Curriculum created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Curriculum $curriculum)
    {
        $curriculum->load(['program.department', 'specialization']);

        return Inertia::render('Curriculums/Edit', [

            'curriculum' => $curriculum,

            'programs' => Program::with('department')
                ->where('active', true)
                ->orderBy('name')
                ->get(),

            'specializations' => Specialization::where('active', true)
                ->orderBy('name')
                ->get(),

        ]);
    }

    /**
     * Update the specified resource.
     */
    public function update(Request $request, Curriculum $curriculum)
    {
        $validated = $request->validate([

            'program_id' => [
                'required',
                'exists:programs,id',
            ],

            'specialization_id' => [
                'nullable',
                'exists:specializations,id',
            ],

            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('curricula')
                    ->ignore($curriculum->id),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'academic_year' => [
                'required',
                'string',
                'max:20',
            ],

            'effective_year' => [
                'required',
                'digits:4',
                Rule::unique('curricula')
                    ->ignore($curriculum->id)
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('program_id', $request->program_id)
                            ->where('specialization_id', $request->specialization_id);
                    }),
            ],

            'active' => [
                'required',
                'boolean',
            ],

        ]);

        $validated['code'] = strtoupper($validated['code']);

        // Captured BEFORE any changes are applied, same convention as
        // every other controller's update() in this codebase — this
        // is what makes old_values possible below.
        $oldValues = [
            'code' => $curriculum->code,
            'name' => $curriculum->name,
            'program_id' => $curriculum->program_id,
            'specialization_id' => $curriculum->specialization_id,
            'academic_year' => $curriculum->academic_year,
            'effective_year' => $curriculum->effective_year,
            'active' => $curriculum->active,
        ];

        $curriculum->update($validated);

        AuditLogService::log(
            action: 'updated',
            module: 'Curriculum',
            model: $curriculum,
            description: "Updated curriculum {$curriculum->code}",
            oldValues: $oldValues,
            newValues: [
                'code' => $curriculum->code,
                'name' => $curriculum->name,
                'program_id' => $curriculum->program_id,
                'specialization_id' => $curriculum->specialization_id,
                'academic_year' => $curriculum->academic_year,
                'effective_year' => $curriculum->effective_year,
                'active' => $curriculum->active,
            ],
        );

        return redirect()
            ->route('curriculums.index')
            ->with('success', 'Curriculum updated successfully.');
    }

    /**
     * Printable Prospectus.
     *
     * Renders this curriculum's full item list (Subject + OJT), grouped
     * by Year Level + Semester, as a plain Blade view meant to be sent
     * to the browser's print dialog / saved as PDF — same pattern as
     * SubjectOfferingController::print() and
     * BlockScheduleController::printSections().
     *
     * Deliberately a Blade view, not Inertia — this is a print target,
     * not an interactive page.
     *
     * NOTE: assumes Subject has a `units` column (float/int) for the
     * running total at the bottom of the prospectus. Adjust the
     * accessor below if your column is named differently.
     */
    public function print(Curriculum $curriculum)
    {
        $curriculum->load(['program.department', 'specialization']);

        $items = $curriculum->curriculumItems()
            ->with('subject.prerequisite')
            ->where('active', true)
            ->orderBy('year_level')
            ->orderBy('semester')
            ->orderBy('sort_order')
            ->get();

        // Grouped as "{year_level}-{semester}" => Collection<CurriculumItem>
        // so the view can pull "1-1" (Year 1, 1st Sem) and "1-2" (Year 1,
        // 2nd Sem) side by side for the two-column layout.
        $grouped = $items->groupBy(fn ($item) => "{$item->year_level}-{$item->semester}");

        // Distinct year levels present, sorted — drives how many
        // "First Year", "Second Year"... row-pairs the view renders.
        $yearLevels = $items->pluck('year_level')->unique()->sort()->values();

        return view('curriculum.print', [
            'curriculum' => $curriculum,
            'grouped' => $grouped,
            'yearLevels' => $yearLevels,
            'totalUnits' => $items->sum(fn ($item) => (float) ($item->subject->units ?? 0)),
        ]);
    }

    /**
     * Remove the specified resource.
     * 
     * Prevents deletion if curriculum has sections or curriculum items.
     */
    public function destroy(Curriculum $curriculum)
    {
        // Check if curriculum is in use
        if ($curriculum->sections()->exists() || $curriculum->curriculumItems()->exists()) {
            return redirect()
                ->route('curriculums.index')
                ->with('error', 'Unable to delete this curriculum. It has associated sections or subjects.');
        }

        $code = $curriculum->code;
        $name = $curriculum->name;

        try {
            $curriculum->delete();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('curriculums.index')
                ->with('error', 'Unable to delete the selected curriculum.');
        }

        // Captured before delete() — nothing left in the database to
        // read back afterward, same reasoning as
        // SectionController::destroy()'s Audit Log call.
        AuditLogService::log(
            action: 'deleted',
            module: 'Curriculum',
            description: "Deleted curriculum {$code} - {$name}",
            oldValues: ['code' => $code, 'name' => $name],
            recordName: $code,
        );

        return redirect()
            ->route('curriculums.index')
            ->with('success', "Curriculum {$code} deleted successfully.");
    }
}