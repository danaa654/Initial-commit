<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\Department;
use App\Models\Program;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\User;
use App\Notifications\SectionCreated;
use App\Services\SectionCodeService;
use App\Services\AuditLogService;
use App\Services\ActivityHistoryService;
use App\Services\SchedulingWorkspaceService;
use App\Services\SubjectOfferingGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SectionController extends Controller implements HasMiddleware
{
    /**
     * Section Letters are capped at A-E — five sections per Program +
     * Year Level (+ Specialization, for Programs that have one) is the
     * hard ceiling.
     */
    public const ALLOWED_LETTERS = ['A', 'B', 'C', 'D', 'E'];

    public function __construct(
        private readonly SubjectOfferingGeneratorService $generator,
        private readonly SchedulingWorkspaceService $workspace
    ) {
    }

    /**
     * Controller Middleware
     */
    public static function middleware(): array
    {
        return [

            new Middleware(function ($request, $next) {

                abort_unless(
                    auth()->user()->hasAnyRole([
                        'Admin',
                        'Registrar',
                    ]),
                    403,
                    'Unauthorized.'
                );

                return $next($request);

            }),

        ];
    }

    /**
     * Display all sections.
     *
     * Supports optional Search / Program / Status filtering via query
     * params (?search=&program_id=&status=), all combinable. Filters are
     * echoed back in the `filters` prop (not just applied to the query)
     * so the Index page can preload its inputs from the URL and stay in
     * sync after an Inertia visit — same idea as the URL being the
     * source of truth for the current view, not local component state.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));

        $programId = $request->input('program_id');

        $status = $request->input('status');

        $sections = Section::with([
                'curriculum.program',
                'curriculum.specialization',
            ])
            // Powers Section::is_in_use on the frontend, so the
            // delete-confirmation modal can block deletion instantly
            // without a round trip. See Section::getIsInUseAttribute().
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('section_code', 'like', "%{$search}%")
                        ->orWhere('section_name', 'like', "%{$search}%");
                });
            })
            ->when($programId, function ($query) use ($programId) {
                $query->whereHas('curriculum', function ($inner) use ($programId) {
                    $inner->where('program_id', $programId);
                });
            })
            ->when(in_array($status, ['Active', 'Inactive'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderBy('section_code')
            ->get();

        return Inertia::render('Sections/Index', [

            'sections' => $sections,

            // For the Program filter dropdown — same source as the
            // Create/Edit forms so the option list stays consistent.
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
     * Show create form.
     */
    public function create()
    {
        return Inertia::render('Sections/Create', [

            'programs' => $this->programOptions(),

            // Lets the frontend disable already-taken letters, auto-pick
            // the next available one, and proactively warn/disable Save
            // when a Program+Year(+Specialization) scope is already full
            // — all without a round trip per keystroke.
            'usedLetters' => $this->usedLetterMap(),

        ]);
    }

    /**
     * Store section. Section Code is system-generated — see
     * processSection() / SectionCodeService.
     */
    public function store(Request $request)
    {
        [$validated, $curriculum, $sectionCode] = $this->processSection($request);

        $section = Section::create([

            'curriculum_id' => $curriculum->id,

            'section_code' => $sectionCode,

            'section_name' => $validated['section_name'],

            'year_level' => $validated['year_level'],

            'section_letter' => $validated['section_letter'],

            'capacity' => $validated['capacity'],

            'status' => $validated['status'],

            'is_irregular' => $validated['is_irregular'] ?? false,

        ]);

        // Audit Log — Sections is master data (same tier as
        // Departments/Curriculums/Specializations), not a scheduling
        // milestone, so this belongs in Audit Logs, not Activity
        // History. Matches UserController::store()'s pattern.
        AuditLogService::log(
            action: 'created',
            module: 'Sections',
            model: $section,
            description: "Created section {$section->section_code}",
            newValues: [
                'section_code' => $section->section_code,
                'section_name' => $section->section_name,
                'curriculum' => $curriculum->display_name,
                'year_level' => $section->year_level,
                'capacity' => $section->capacity,
                'status' => $section->status,
            ],
        );

        $this->notifyDepartmentOfSectionCreated($curriculum, $section, auth()->user());

        $generatedCount = $this->autoGenerateOfferings($section, $curriculum, auth()->user());

        $message = 'Section created successfully.';

        if ($generatedCount > 0) {
            $message .= " {$generatedCount} Subject Offering(s) were generated for it automatically.";
        }

        return redirect()
            ->route('sections.index')
            ->with('success', $message);
    }

    /**
     * Auto-generates this Section's Subject Offerings the moment it's
     * created, replacing the old "Generate" button on the Sections
     * list — a new Regular Section should already have its Subjects
     * lined up in Subject Offerings without a separate manual step.
     *
     * Mirrors SubjectOfferingController::generateForSection(), minus
     * the abort()s: this runs as a side effect of Section creation, so
     * it fails soft instead of blocking the Section from being saved.
     * Skipped entirely for:
     * - Irregular Sections (no single curriculum/year-level match to
     *   generate from — they use the manual Subject picker instead).
     * - Inactive Sections (SubjectOfferingGeneratorService::generate()
     *   only scans Active ones anyway).
     * - No Working Academic Term set, or that term is Archived
     *   (read-only) — the registrar can still generate manually later
     *   from the Sections list... once that Working Term is sorted.
     *
     * Returns the number of Subject Offerings created, so store() can
     * fold it into the one flash message instead of firing a second
     * one.
     */
    private function autoGenerateOfferings(Section $section, Curriculum $curriculum, User $performedBy): int
    {
        if ($section->is_irregular || $section->status !== 'Active') {
            return 0;
        }

        $academicTerm = $this->workspace->getTermForUser($performedBy);

        if (! $academicTerm || $academicTerm->status === 'Archived') {
            return 0;
        }

        $summary = $this->generator->generate($academicTerm, $curriculum, [$section->id], $performedBy);

        if ($summary['created'] > 0) {
            AuditLogService::log(
                action: 'generated',
                module: 'Subject Offering',
                model: $section,
                description: "Generated {$summary['created']} Subject Offering(s) for {$section->section_code}",
                newValues: [
                    'section_code' => $section->section_code,
                    'curriculum' => $curriculum->display_name,
                    'academic_term' => $academicTerm->display_name,
                    'created' => $summary['created'],
                ],
                recordName: $section->section_code,
            );

            ActivityHistoryService::recordSubjectOfferingsGenerated(
                $academicTerm,
                $summary['created'],
                ['curriculum' => $curriculum->display_name]
            );
        }

        return $summary['created'];
    }

    /**
     * Notifies the new Section's own college — Admin, Registrar,
     * Assistant Dean, and that college's Dean/OIC, minus whoever
     * created it (see resolveStakeholders() below). $curriculum comes
     * straight from processSection()'s already-resolved return value,
     * loaded fresh with 'program.department' / 'specialization' here
     * since processSection() doesn't eager-load either — Section
     * creation is a one-at-a-time action, so this is a single extra
     * query, not an N+1 concern.
     *
     * Skipped entirely if the curriculum's program has no
     * department_id (there's no single Dean/OIC to address it to) or
     * if there's no one to notify after excluding the actor.
     */
    private function notifyDepartmentOfSectionCreated(Curriculum $curriculum, Section $section, User $performedBy): void
    {
        $curriculum->loadMissing(['program', 'specialization']);

        $departmentId = $curriculum->program?->department_id;

        if (! $departmentId) {
            return;
        }

        $department = Department::find($departmentId);

        if (! $department) {
            return;
        }

        $recipients = $this->resolveStakeholders($department, $performedBy);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new SectionCreated($department, $curriculum, $section, $performedBy));
    }

    /**
     * Admin + Registrar + Assistant Dean (global tiers) merged with
     * one college's Dean/OIC (department-scoped tier), deduplicated,
     * with whoever performed the action removed from the list. Same
     * recipient rule as TermFinalizationService::resolveStakeholders(),
     * MasterGridController::resolveStakeholders(), and
     * SubjectOfferingController::resolveStakeholders() — duplicated
     * here for the same reason as those three: no shared owning
     * service exists yet for cross-module notification recipients. If
     * this rule needs to change, update all four places.
     */
    private function resolveStakeholders(Department $department, User $performedBy): Collection
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
     * Show edit form.
     */
    public function edit(Section $section)
    {
        $section->load(['curriculum.program', 'curriculum.specialization']);

        return Inertia::render('Sections/Edit', [

            'section' => $section,

            'programs' => $this->programOptions(),

            // Excludes this section itself, so its own letter never
            // reads as "taken" against itself while editing.
            'usedLetters' => $this->usedLetterMap(excludeSectionId: $section->id),

        ]);
    }

    /**
     * Update section. Section Code is re-generated from the submitted
     * Program/Specialization/Year Level/Letter — it is never accepted
     * directly from the client.
     */
    public function update(Request $request, Section $section)
    {
        // Captured BEFORE any changes are applied, same convention as
        // UserController::update() — this is what makes old_values
        // possible below.
        $oldValues = [
            'section_code' => $section->section_code,
            'section_name' => $section->section_name,
            'year_level' => $section->year_level,
            'capacity' => $section->capacity,
            'status' => $section->status,
        ];

        [$validated, $curriculum, $sectionCode] = $this->processSection($request, $section);

        $section->update([

            'curriculum_id' => $curriculum->id,

            'section_code' => $sectionCode,

            'section_name' => $validated['section_name'],

            'year_level' => $validated['year_level'],

            'section_letter' => $validated['section_letter'],

            'capacity' => $validated['capacity'],

            'status' => $validated['status'],

            'is_irregular' => $validated['is_irregular'] ?? false,

        ]);

        AuditLogService::log(
            action: 'updated',
            module: 'Sections',
            model: $section,
            description: "Updated section {$section->section_code}",
            oldValues: $oldValues,
            newValues: [
                'section_code' => $section->section_code,
                'section_name' => $section->section_name,
                'curriculum' => $curriculum->display_name,
                'year_level' => $section->year_level,
                'capacity' => $section->capacity,
                'status' => $section->status,
            ],
        );

        return redirect()
            ->route('sections.index')
            ->with('success', 'Section updated successfully.');
    }

    /**
     * Delete section.
     *
     * The frontend already blocks this via Section::is_in_use (see
     * index()) before the user can even reach the type-to-confirm step —
     * this check is the authoritative backstop in case that's ever
     * bypassed, not the primary UX gate.
     */
    public function destroy(Section $section)
    {
        if ($section->isInUse()) {
            return redirect()
                ->back()
                ->with('error', 'Unable to delete the selected section.');
        }

        $sectionCode = $section->section_code;

        try {

            $section->delete();

        } catch (\Throwable $e) {

            report($e);

            return redirect()
                ->back()
                ->with('error', 'Unable to delete the selected section.');

        }

        // old_values only — there's nothing left in the database to
        // read back after delete(), same reasoning as
        // TeachingAssignmentController::destroy()'s Audit Log call.
        AuditLogService::log(
            action: 'deleted',
            module: 'Sections',
            description: "Deleted section {$sectionCode}",
            oldValues: ['section_code' => $sectionCode],
            recordName: $sectionCode,
        );

        return redirect()
            ->back()
            ->with('success', "Section {$sectionCode} deleted successfully.");
    }

    /**
     * Shared validation + Section Code generation for store/update.
     *
     * Returns [validated request data, resolved Curriculum model,
     * generated section code]. Every rule the frontend also enforces
     * (letter restricted to A-E, capacity 20-45, max 5 sections per
     * scope, no duplicate codes) is re-checked here — the frontend is a
     * convenience, this is the actual gate.
     *
     * A handful of these checks flash a session 'error' message before
     * throwing, in addition to the normal field-level $errors bag. The
     * app's toast system reads that flash key directly (same as the
     * 'success' flash already used on store/update above), so this is
     * what actually produces the toast — there's no separate
     * client-only toast trigger.
     */
    private function processSection(Request $request, ?Section $section = null): array
    {
        // Normalize case up front so "a" and "A" behave identically,
        // both for the ALLOWED_LETTERS check below and for the code/name
        // generation that follows.
        $request->merge([
            'section_letter' => strtoupper((string) $request->input('section_letter')),
        ]);

        $validated = $request->validate([

            'program_id' => [
                'required',
                'integer',
                'exists:programs,id',
            ],

            'specialization_id' => [
                Rule::requiredIf(function () use ($request) {
                    return SectionCodeService::requiresSpecialization(
                        Program::find($request->input('program_id'))
                    );
                }),
                'nullable',
                'integer',
                'exists:specializations,id',
            ],

            'year_level' => [
                'required',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) use ($request) {

                    $program = Program::find($request->input('program_id'));

                    if ($program && $value > $program->years) {
                        $fail("Year Level cannot exceed {$program->years} for {$program->code}.");
                    }

                },
            ],

            'section_letter' => [
                'required',
                Rule::in(self::ALLOWED_LETTERS),
            ],

            'section_name' => [
                'required',
                'string',
                'max:255',
            ],

            'capacity' => [
                'required',
                'integer',
                'between:20,45',
            ],

            'status' => [
                'required',
                Rule::in([
                    'Active',
                    'Inactive',
                ]),
            ],

            // Irregular Sections skip curriculum/year-level
            // auto-population of Subject Offerings — see
            // EdpCodeService::generate() and
            // SubjectOfferingController::irregularSubjects()/
            // storeIrregular(). Defaults to false so the field is
            // optional on both store and update.
            'is_irregular' => [
                'nullable',
                'boolean',
            ],

        ], [

            'section_letter.in' => 'Section Letter must be one of A, B, C, D, or E.',

            'capacity.between' => 'Capacity must be between 20 and 45 students.',

        ]);

        $program = Program::findOrFail($validated['program_id']);

        $specialization = ! empty($validated['specialization_id'])
            ? Specialization::find($validated['specialization_id'])
            : null;

        // Server-side backstop: a Specialization only ever applies to a
        // program that requires one (i.e. has active Specializations of
        // its own). The frontend already hides/disables the field
        // otherwise, so this only matters if that gets bypassed.
        if ($specialization && ! SectionCodeService::requiresSpecialization($program)) {
            $specialization = null;
        }

        // Max 5 sections per Program + Year Level (+ Specialization).
        // Checked ahead of the duplicate-code check below so a maxed-out
        // scope gets its own specific message rather than a generic
        // "already exists" one.
        $lettersInScope = $this->lettersInScope(
            $program->id,
            $specialization?->id,
            $validated['year_level'],
            excludeSectionId: $section?->id
        );

        if ($lettersInScope->count() >= count(self::ALLOWED_LETTERS)) {
            session()->flash('error', 'All available sections (A–E) have already been created for this year level.');

            throw ValidationException::withMessages([
                'section_letter' => 'All available sections (A-E) have already been created for this year level.',
            ]);
        }

        $curriculum = SectionCodeService::resolveCurriculum(
            $program->id,
            $specialization?->id
        );

        if (! $curriculum) {
            throw ValidationException::withMessages([
                'program_id' => 'No curriculum exists yet for '
                    . $program->code
                    . ($specialization ? " - {$specialization->name}" : '')
                    . '. Please create one first before adding a section.',
            ]);
        }

        $sectionCode = SectionCodeService::generate(
            $program,
            $specialization,
            $validated['year_level'],
            $validated['section_letter']
        );

        $duplicate = Section::where('section_code', $sectionCode)
            ->when($section, fn ($query) => $query->where('id', '!=', $section->id))
            ->exists();

        if ($duplicate) {
            session()->flash('error', 'This section already exists. Please choose another section letter.');

            throw ValidationException::withMessages([
                'section_letter' => 'This section already exists. Please choose another section letter.',
            ]);
        }

        return [$validated, $curriculum, $sectionCode];
    }

    /**
     * Every distinct Section Letter already in use for a given
     * Program + Year Level (+ Specialization) scope. Used by both the
     * max-5 check above and usedLetterMap() below — kept as one method
     * so the "what counts as this scope" definition only lives in one
     * place.
     */
    private function lettersInScope(
        int $programId,
        ?int $specializationId,
        int $yearLevel,
        ?int $excludeSectionId = null
    ): Collection {
        return Section::whereHas('curriculum', function ($query) use ($programId, $specializationId) {
                $query->where('program_id', $programId)
                    ->where('specialization_id', $specializationId);
            })
            ->where('year_level', $yearLevel)
            ->when($excludeSectionId, fn ($query) => $query->where('id', '!=', $excludeSectionId))
            ->pluck('section_letter')
            ->filter()
            ->unique();
    }

    /**
     * Every scope (Program + Specialization + Year Level) mapped to its
     * currently-used letters, e.g. {"3_null_1": ["A","B"], "5_2_4": ["A"]}.
     * Sent to the frontend so it can disable taken letters, auto-select
     * the next free one, and know when a scope is completely full —
     * entirely client-side, no per-change request needed.
     */
    private function usedLetterMap(?int $excludeSectionId = null): array
    {
        return Section::with('curriculum:id,program_id,specialization_id')
            ->when($excludeSectionId, fn ($query) => $query->where('id', '!=', $excludeSectionId))
            ->get()
            ->filter(fn (Section $section) => $section->year_level
                && $section->section_letter
                && $section->curriculum)
            ->groupBy(fn (Section $section) => $section->curriculum->program_id
                . '_' . ($section->curriculum->specialization_id ?? 'null')
                . '_' . $section->year_level)
            ->map(fn ($group) => $group->pluck('section_letter')->unique()->values())
            ->toArray();
    }

    /**
     * Program dropdown options, with each program's active
     * Specializations eager-loaded so the Create/Edit pages can filter
     * the Specialization dropdown client-side without extra requests.
     */
    private function programOptions()
    {
        return Program::with(['specializations' => function ($query) {
                $query->where('active', true)->orderBy('name');
            }])
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }
}