<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\FacultySubject;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class FacultySubjectController extends Controller implements HasMiddleware
{

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
     * Display a listing of the resource.
     *
     * Joins in faculties/subjects purely for ordering (faculty name, then
     * subject code) — the actual data returned still comes from the eager
     * loaded relationships below, so this stays a single query with no
     * N+1 risk.
     */
    public function index()
    {
        return Inertia::render('FacultySubjects/Index', [

            'facultySubjects' => FacultySubject::with(['faculty.department', 'subject'])
                ->join('faculties', 'faculty_subjects.faculty_id', '=', 'faculties.id')
                ->join('subjects', 'faculty_subjects.subject_id', '=', 'subjects.id')
                ->orderBy('faculties.last_name')
                ->orderBy('faculties.first_name')
                ->orderBy('subjects.subject_code')
                ->select('faculty_subjects.*')
                ->get(),

        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('FacultySubjects/Create', [

            'faculties' => Faculty::where('status', true)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),

            'subjects' => Subject::where('active', true)
                ->orderBy('subject_code')
                ->get(),

        ]);
    }

    /**
     * Store a newly created resource.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'faculty_id' => [
                'required',
                'exists:faculties,id',
            ],

            'subject_id' => [
                'required',
                'exists:subjects,id',
                Rule::unique('faculty_subjects')->where(function ($query) use ($request) {
                    return $query->where('faculty_id', $request->faculty_id);
                }),
            ],

            'preferred' => [
                'required',
                'boolean',
            ],

            'active' => [
                'required',
                'boolean',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:255',
            ],

        ], [
            'subject_id.unique' => 'This subject is already assigned to the selected faculty member.',
        ]);

        FacultySubject::create($validated);

        return redirect()
            ->route('faculty-subjects.index')
            ->with('success', 'Subject assigned to faculty successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(FacultySubject $facultySubject)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(FacultySubject $facultySubject)
    {
        return Inertia::render('FacultySubjects/Edit', [

            'facultySubject' => $facultySubject,

            'faculties' => Faculty::where('status', true)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),

            'subjects' => Subject::where('active', true)
                ->orderBy('subject_code')
                ->get(),

        ]);
    }

    /**
     * Update the specified resource.
     */
    public function update(Request $request, FacultySubject $facultySubject)
    {
        $validated = $request->validate([

            'faculty_id' => [
                'required',
                'exists:faculties,id',
            ],

            'subject_id' => [
                'required',
                'exists:subjects,id',
                Rule::unique('faculty_subjects')
                    ->ignore($facultySubject->id)
                    ->where(function ($query) use ($request) {
                        return $query->where('faculty_id', $request->faculty_id);
                    }),
            ],

            'preferred' => [
                'required',
                'boolean',
            ],

            'active' => [
                'required',
                'boolean',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:255',
            ],

        ], [
            'subject_id.unique' => 'This subject is already assigned to the selected faculty member.',
        ]);

        $facultySubject->update($validated);

        return redirect()
            ->route('faculty-subjects.index')
            ->with('success', 'Faculty subject assignment updated successfully.');
    }

    /**
     * Remove the specified resource.
     */
    public function destroy(FacultySubject $facultySubject)
    {
        $facultySubject->delete();

        return redirect()
            ->route('faculty-subjects.index')
            ->with('success', 'Faculty subject assignment removed successfully.');
    }
}