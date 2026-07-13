<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Faculty Loading action — assign/unassign a Subject
 * Offering, or Admin/Registrar directly adding overload units. Powers
 * the "Recent Activity" panel on the Faculty Loading overview
 * (Index.vue, shown only while no faculty is selected — see the
 * `v-if="!selectedFaculty"` wrapper there, which is what makes the
 * panel disappear the moment someone picks a faculty member).
 *
 * See the create_faculty_load_activities_table and
 * add_overload_columns_to_faculty_load_activities_table migrations
 * for why faculty_name_snapshot/subject_snapshot/edp_code_snapshot/
 * units exist alongside the live relations.
 */
class FacultyLoadActivity extends Model
{
    public const ACTION_ASSIGNED = 'assigned';
    public const ACTION_UNASSIGNED = 'unassigned';

    // Admin/Registrar adding overload units directly — this only ever
    // fires for the auto-approved path (see
    // FacultyLoadOverloadService::request()); a Dean/OIC/Assistant
    // Dean's *pending* request isn't logged here, since nothing has
    // actually changed about the faculty member's load yet.
    public const ACTION_OVERLOAD_ADDED = 'overload_added';

    // Only created_at is tracked — a logged action is a fact about a
    // moment in time and is never edited afterward, so there's no
    // updated_at to maintain.
    public $timestamps = false;

    protected $fillable = [
        'faculty_id',
        'subject_offering_id',
        'overload_id',
        'performed_by',
        'action',
        'units',
        'faculty_name_snapshot',
        'subject_snapshot',
        'edp_code_snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'units' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function subjectOffering()
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function overload()
    {
        return $this->belongsTo(FacultyLoadOverload::class, 'overload_id');
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}