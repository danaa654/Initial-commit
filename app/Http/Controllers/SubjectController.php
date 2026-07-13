<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\SubjectRoomGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Services\AuditLogService;

class SubjectController extends Controller implements HasMiddleware
{
    /**
     * Controller Middleware
     *
     * Subjects is a shared MASTER LIST spanning every college's
     * curriculum (GenEd, NSTP, PATHFIT, etc. show up under multiple
     * programs at once) — it isn't "owned" by any one department the
     * way a Dean/OIC/Assistant Dean's other data is (their own
     * faculty, their own section of the Master Grid). So access here
     * is split into two tiers instead of one flat role check:
     *
     *   - Read (index)                    -> Admin, Registrar, Dean,
     *     Assistant Dean, OIC — they still need to see the master list
     *     (e.g. while doing Faculty-Subject assignment or reviewing
     *     curriculum), just not change it.
     *   - Write (create/store/edit/
     *     update/destroy)                 -> Admin, Registrar only —
     *     centralizes changes to the one list every college's
     *     curriculum depends on, the same way Programs/Curriculum
     *     management is centralized. Delete in particular cascades
     *     into curriculum_items/faculty_subjects/schedules, so it's
     *     deliberately not distributed across 4-5 college-level roles.
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

            }, only: ['index']),

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

            }, only: ['create', 'store', 'edit', 'update', 'destroy']),

        ];
    }

    /**
     * Display all subjects — filtered, searched, and paginated
     * server-side.
     *
     * Query string params (all optional):
     *   - search:         matches subject_code OR descriptive_title
     *                      (case-insensitive, partial match)
     *   - room_type:      Lecture | Laboratory | Practicum
     *   - classification: Major | Minor
     *   - room_group:     General | any active Program's code (BSIT,
     *                      BSED, BSHM, BSTM, BSCRIM, BSIE, etc. — see
     *                      SubjectRoomGroup::options(), matches subjects
     *                      that have this program among their
     *                      one-or-more assigned programs)
     *   - status:         Active | Inactive
     *   - page:           handled automatically by paginate()
     */
    public function index(Request $request)
    {
        $filters = $request->only([
            'search',
            'room_type',
            'classification',
            'room_group',
            'status',
        ]);

        $subjects = Subject::query()
            ->with(['prerequisite', 'roomGroups'])

            /*
            |--------------------------------------------------------------------------
            | Search — Subject Code / Descriptive Title
            |--------------------------------------------------------------------------
            |
            | LOWER() on both sides keeps this case-insensitive regardless
            | of the database's default collation.
            |
            */
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%' . strtolower($search) . '%';

                $query->where(function ($query) use ($term) {
                    $query->whereRaw('LOWER(subject_code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(descriptive_title) LIKE ?', [$term]);
                });
            })

            /*
            |--------------------------------------------------------------------------
            | Room Type Filter
            |--------------------------------------------------------------------------
            |
            | "Practicum/OJT" is not a required_room_type value — it's
            | driven by the is_practicum flag — so it's handled as its own
            | branch rather than a plain column match.
            |
            */
            ->when($filters['room_type'] ?? null, function ($query, $roomType) {
                if ($roomType === 'Practicum') {
                    $query->where('is_practicum', true);
                } elseif (in_array($roomType, ['Lecture', 'Laboratory'])) {
                    $query->where('required_room_type', $roomType);
                }
            })

            /*
            |--------------------------------------------------------------------------
            | Classification Filter (Major / Minor)
            |--------------------------------------------------------------------------
            */
            ->when($filters['classification'] ?? null, function ($query, $classification) {
                $query->where('is_major', $classification === 'Major');
            })

            /*
            |--------------------------------------------------------------------------
            | Room Group Filter
            |--------------------------------------------------------------------------
            |
            | A subject now can carry several programs, so this is a
            | whereHas against the room_group_subject pivot (via the
            | forRoomGroup scope) instead of a plain column match — a
            | subject shows up under a program filter if it's applicable
            | to that program at all, regardless of what else it's
            | assigned to.
            |
            */
            ->when($filters['room_group'] ?? null, function ($query, $roomGroup) {
                $query->forRoomGroup($roomGroup);
            })

            /*
            |--------------------------------------------------------------------------
            | Status Filter (Active / Inactive)
            |--------------------------------------------------------------------------
            */
            ->when($filters['status'] ?? null, function ($query, $status) {
                $query->where('active', $status === 'Active');
            })

            ->orderBy('subject_code')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Subjects/Index', [

            'subjects' => $subjects,

            // Sourced live from the Programs table (via
            // SubjectRoomGroup::options()) instead of a hardcoded list —
            // a newly added College/Program shows up in this filter
            // dropdown immediately, no code change required.
            'roomGroupOptions' => SubjectRoomGroup::options(),

            'filters' => [
                'search' => $filters['search'] ?? '',
                'room_type' => $filters['room_type'] ?? '',
                'classification' => $filters['classification'] ?? '',
                'room_group' => $filters['room_group'] ?? '',
                'status' => $filters['status'] ?? '',
            ],

        ]);
    }

    /**
     * Show create form.
     */
    public function create()
    {
        return Inertia::render('Subjects/Create', [

            'subjects' => Subject::orderBy('subject_code')->get(),

            // Sourced live from the Programs table — see the note on
            // SubjectRoomGroup::options().
            'roomGroupOptions' => SubjectRoomGroup::options(),

        ]);
    }

    /**
     * Store subject.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->rules($request));

        $roomGroups = $validated['room_groups'] ?? [];
        unset($validated['room_groups']);

        $validated = $this->applyRoomTypeOverrides($validated);

        // Practicum/OJT and "None" room-type subjects never get a room, so
        // they never get a program assignment either, regardless of what
        // was submitted.
        if ($validated['is_practicum'] || $validated['required_room_type'] === 'None') {
            $roomGroups = [];
        }

        /*
        |--------------------------------------------------------------------------
        | Compute Total Contact Hours
        |--------------------------------------------------------------------------
        */

        $validated['total_hours'] =
            $validated['lecture_hours']
            + $validated['laboratory_hours'];

        /*
        |--------------------------------------------------------------------------
        | Subject Code
        |--------------------------------------------------------------------------
        */

        $validated['subject_code'] = strtoupper(
            $validated['subject_code']
        );

        $subject = Subject::create($validated);

        $this->syncRoomGroups($subject, $roomGroups);

        AuditLogService::log(
            action: 'created',
            module: 'Subjects',
            model: $subject,
            description: "Created subject {$subject->subject_code}",
            newValues: [
                'subject_code' => $subject->subject_code,
                'descriptive_title' => $validated['descriptive_title'],
                'units' => $validated['units'],
                'is_major' => $validated['is_major'],
            ],
        );

        return redirect()
            ->route('subjects.index', $request->query())
            ->with('success', 'Subject created successfully.');
    }

    /**
     * Show edit form.
     */
    public function edit(Subject $subject)
    {
        $subject->load('roomGroups');

        return Inertia::render('Subjects/Edit', [

            'subject' => $subject,

            'subjects' => Subject::where('id', '!=', $subject->id)
                ->orderBy('subject_code')
                ->get(),

            // Sourced live from the Programs table — see the note on
            // SubjectRoomGroup::options(). If this subject was already
            // assigned a program that has since been deactivated, that
            // code is merged back in so it still renders (checked) on
            // the form instead of silently vanishing.
            'roomGroupOptions' => array_values(array_unique(array_merge(
                SubjectRoomGroup::options(),
                $subject->room_group_codes
            ))),

        ]);
    }

    /**
     * Update subject.
     */
    public function update(Request $request, Subject $subject)
    {
        $validated = $request->validate($this->rules($request, $subject));

        $roomGroups = $validated['room_groups'] ?? [];
        unset($validated['room_groups']);

        $validated = $this->applyRoomTypeOverrides($validated);

        if ($validated['is_practicum'] || $validated['required_room_type'] === 'None') {
            $roomGroups = [];
        }

        /*
        |--------------------------------------------------------------------------
        | Compute Total Contact Hours
        |--------------------------------------------------------------------------
        */

        $validated['total_hours'] =
            $validated['lecture_hours']
            + $validated['laboratory_hours'];

        /*
        |--------------------------------------------------------------------------
        | Subject Code
        |--------------------------------------------------------------------------
        */

        $validated['subject_code'] = strtoupper(
            $validated['subject_code']
        );

        $oldValues = [
            'subject_code' => $subject->subject_code,
            'descriptive_title' => $subject->descriptive_title,
            'units' => $subject->units,
            'is_major' => $subject->is_major,
        ];

        $subject->update($validated);

        $this->syncRoomGroups($subject, $roomGroups);

        AuditLogService::log(
            action: 'updated',
            module: 'Subjects',
            model: $subject,
            description: "Updated subject {$subject->subject_code}",
            oldValues: $oldValues,
            newValues: [
                'subject_code' => $subject->subject_code,
                'descriptive_title' => $validated['descriptive_title'],
                'units' => $validated['units'],
                'is_major' => $validated['is_major'],
            ],
        );

        // Edit.vue appends the filter query string it arrived with onto
        // this PUT request's URL, so $request->query() reflects whatever
        // search/filters were active on the index — passing it straight
        // through here lands the redirect back on that same filtered view.
        return redirect()
            ->route('subjects.index', $request->query())
            ->with('warning', 'Subject updated successfully.');
    }

    /**
     * Delete subject.
     */
    public function destroy(Request $request, Subject $subject)
    {
        // Block deletion if this subject is still attached to any
        // curriculum's prospectus, has already had Subject Offerings
        // generated from it, or is set as another subject's
        // prerequisite. Same guard pattern as
        // CurriculumController/CurriculumItemController::destroy() —
        // check dependents before attempting delete() so we can show
        // a friendly error instead of a raw FK violation.
        if ($subject->curriculumItems()->exists()) {
            return redirect()
                ->route('subjects.index', $request->query())
                ->with('error', 'Unable to delete this subject. It is still assigned to one or more curriculums.');
        }

        if ($subject->subjectOfferings()->exists()) {
            return redirect()
                ->route('subjects.index', $request->query())
                ->with('error', 'Unable to delete this subject. Subject Offerings have already been generated for it.');
        }

        if ($subject->dependents()->exists()) {
            return redirect()
                ->route('subjects.index', $request->query())
                ->with('error', 'Unable to delete this subject. It is set as a prerequisite for another subject.');
        }

        $subjectCode = $subject->subject_code;

        try {
            $subject->delete();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('subjects.index', $request->query())
                ->with('error', 'Unable to delete the selected subject.');
        }

        AuditLogService::log(
            action: 'deleted',
            module: 'Subjects',
            description: "Deleted subject {$subjectCode}",
            oldValues: ['subject_code' => $subjectCode],
            recordName: $subjectCode,
        );

        // Index.vue appends the current filter query string onto this
        // DELETE request's URL for the same reason as update() above.
        return redirect()
            ->route('subjects.index', $request->query())
            ->with('deleted', 'Subject deleted successfully.');
    }

    /**
     * Shared validation rules for store() and update().
     *
     * @param  \Illuminate\Http\Request  $request  The current request —
     *         needed so the room_groups rule can look at the sibling
     *         required_room_type / is_practicum values.
     * @param  \App\Models\Subject|null  $subject  The subject being updated,
     *         null when creating (used for the unique/notIn ignore rules).
     */
    private function rules(Request $request, ?Subject $subject = null): array
    {
        return [

            'subject_code' => [
                'required',
                'string',
                'max:20',
                $subject
                    ? Rule::unique('subjects', 'subject_code')->ignore($subject->id)
                    : Rule::unique('subjects', 'subject_code'),
            ],

            'descriptive_title' => [
                'required',
                'string',
                'max:255',
            ],

            // units / lecture_hours / laboratory_hours are stored as
            // unsignedTinyInteger columns, so they must be validated as
            // whole numbers, not arbitrary decimals.
            'units' => [
                'required',
                'integer',
                'min:1',
                'max:6',
            ],

            'lecture_hours' => [
                'required',
                'integer',
                'min:0',
                'max:10',
            ],

            'laboratory_hours' => [
                'required',
                'integer',
                'min:0',
                'max:10',
            ],

            // Classification no longer drives a *default* for room_groups,
            // but it does constrain what's allowed: Major and Minor
            // subjects both support any combination of the active
            // academic programs, but "General" is Minor-only — see the
            // room_groups rule below.
            'is_major' => [
                'required',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Room Type / Room Groups (Programs) / Practicum
            |--------------------------------------------------------------------------
            |
            | required_room_type reflects PAP's actual room inventory
            | (Lecture / Laboratory / None).
            |
            | room_groups is a plain array of one-or-more programs this
            | subject is applicable to. Allowed values are sourced live
            | from the programs table (plus "General") via
            | SubjectRoomGroup::options() — see that method's docblock.
            | Criminalistics specializations (FB / LD / QD / FI) still all
            | collapse to BSCRIM upstream of this list; the scheduler picks
            | whichever Criminalistics lab is free.
            |
            | "General" is a Lecture-only AND Minor-only program: it's
            | rejected below when required_room_type is Laboratory or when
            | is_major is true. The frontend mirrors both checks by hiding
            | the option from the checklist, but this validation is the
            | real source of truth.
            |
            | required_room_type is still validated against its full
            | allowed list even when is_practicum is true —
            | applyRoomTypeOverrides() forces the scheduler-relevant value
            | server-side afterwards, so a disabled/tampered frontend field
            | can't smuggle in a bad state.
            |
            */

            'required_room_type' => [
                'required',
                Rule::in([
                    'Lecture',
                    'Laboratory',
                    'None',
                ]),
            ],

            'room_groups' => [
                'array',
                // Cross-field business rule (needs is_practicum /
                // required_room_type), kept on the same field as the
                // 'array' rule so any failure surfaces under
                // form.errors.room_groups on the frontend.
                function ($attribute, $value, $fail) use ($request) {

                    // Practicum/OJT and "None" subjects never get a room —
                    // any selection here gets cleared server-side
                    // regardless, so there's nothing to enforce.
                    if ($request->boolean('is_practicum')) {
                        return;
                    }

                    $roomType = $request->input('required_room_type');
                    $roomGroups = (array) $value;

                    if ($roomType === 'None') {
                        return;
                    }

                    if (empty($roomGroups)) {
                        $fail('At least one program must be selected.');

                        return;
                    }

                    if ($roomType === 'Laboratory' && in_array('General', $roomGroups, true)) {
                        $fail('General is a Lecture-only program. Laboratory subjects must select one or more specific programs.');

                        return;
                    }

                    // "General" is a Minor-only program — mirrors the
                    // Laboratory check above (Lecture/Laboratory <->
                    // Minor/Major). The frontend already hides/unchecks
                    // General as soon as Classification is set to Major,
                    // so this only fires against a bypassed or tampered
                    // request.
                    if ($request->boolean('is_major') && in_array('General', $roomGroups, true)) {
                        $fail('General cannot be selected for Major subjects.');
                    }

                },
            ],

            // Sourced live from the Programs table (plus "General") via
            // SubjectRoomGroup::options() — a newly added College/Program
            // is a valid room_groups value immediately, no code change
            // required.
            'room_groups.*' => [
                Rule::in(SubjectRoomGroup::options()),
            ],

            'is_practicum' => [
                'required',
                'boolean',
            ],

            'allow_split_schedule' => [
                'required',
                'boolean',
            ],

            'prerequisite_id' => $subject
                ? [
                    'nullable',
                    'exists:subjects,id',
                    // A subject can't be its own prerequisite.
                    Rule::notIn([$subject->id]),
                ]
                : [
                    'nullable',
                    'exists:subjects,id',
                ],

            'active' => [
                'required',
                'boolean',
            ],

        ];
    }

    /**
     * Server-side source of truth for is_practicum -> required_room_type —
     * mirrors the frontend watcher but doesn't trust it, so a
     * disabled/tampered field can't smuggle in a bad state:
     *
     *   - is_practicum forces required_room_type to "None".
     *
     * Program assignment (room_groups) is handled separately in
     * store()/update(), since it isn't a plain column on this table
     * anymore.
     */
    private function applyRoomTypeOverrides(array $validated): array
    {
        if ($validated['is_practicum']) {
            $validated['required_room_type'] = 'None';
        }

        return $validated;
    }

    /**
     * Replace a subject's assigned programs with the given list. Used by
     * both store() and update() so a subject's room_group_subject rows
     * always exactly match what was submitted (order doesn't matter,
     * duplicates are collapsed).
     */
    private function syncRoomGroups(Subject $subject, array $roomGroups): void
    {
        $subject->roomGroups()->delete();

        $rows = collect($roomGroups)
            ->unique()
            ->map(fn ($roomGroup) => [
                'subject_id' => $subject->id,
                'room_group' => $roomGroup,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if (! empty($rows)) {
            SubjectRoomGroup::insert($rows);
        }
    }
}