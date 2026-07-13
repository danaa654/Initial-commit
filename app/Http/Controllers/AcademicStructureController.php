<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AcademicStructureController extends Controller implements HasMiddleware
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
     * One-page tree view: College -> Programs -> Specializations.
     * Reuses the Department/Program/Specialization models as-is
     * (tables stay separate); this controller only exists to shape
     * the nested payload for the tree UI. All writes still go
     * through DepartmentController / ProgramController /
     * SpecializationController so validation + AuditLogService
     * logging is not duplicated.
     */
    public function index()
    {
        return Inertia::render('AcademicStructure/Index', [
            'departments' => Department::with([
                    'programs' => fn ($q) => $q->orderBy('code'),
                    'programs.specializations' => fn ($q) => $q->orderBy('name'),
                ])
                ->orderBy('abbreviation')
                ->get(),
        ]);
    }
}