<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboards)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $term = $this->dashboards->workingTerm();

        // Admin
        if ($user->hasRole('Admin')) {
            return Inertia::render('Dashboard/Admin', [
                'stats' => $this->dashboards->adminStats($term),
                'charts' => $this->dashboards->adminCharts($term),
                'widgets' => $this->dashboards->adminWidgets($term),
            ]);
        }

        // Registrar — same operational visibility as Admin (unscheduled
        // subjects, recent activity), just without user-account
        // management, so it reuses adminWidgets() rather than a
        // separate registrar-only widget set.
        if ($user->hasRole('Registrar')) {
            return Inertia::render('Dashboard/Registrar', [
                'stats' => $this->dashboards->registrarStats($term),
                'conflicts' => $this->dashboards->registrarConflicts($term),
                'charts' => $this->dashboards->adminCharts($term),
                'widgets' => $this->dashboards->adminWidgets($term),
            ]);
        }

        // Dean / OIC — OIC is an acting-Dean role, not an Assistant Dean.
        // They share the exact same dashboard view and data; only the
        // heading label differs so an OIC user sees "OIC Dashboard" while
        // a Dean user sees "Dean Dashboard".
        if ($user->hasRole('Dean') || $user->hasRole('OIC')) {
            return Inertia::render('Dashboard/Dean', [
                'roleLabel' => $user->hasRole('OIC') ? 'OIC' : 'Dean',
                'stats' => $this->dashboards->deanStats($user, $term),
                'charts' => $this->dashboards->deanCharts($user, $term),
                'tables' => $this->dashboards->deanTables($user, $term),
            ]);
        }

        // Assistant Dean — its own, lighter operational view.
        if ($user->hasRole('Assistant Dean')) {
            return Inertia::render('Dashboard/AssistantDean', [
                'stats' => $this->dashboards->assistantDeanStats($user, $term),
            ]);
        }

        // Default
        return Inertia::render('Dashboard/Index');
    }
}