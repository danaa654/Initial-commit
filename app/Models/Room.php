<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = [

        /*
        |--------------------------------------------------------------------------
        | Room Identity
        |--------------------------------------------------------------------------
        |
        | room_code is the only display identifier now (e.g. "Room 108",
        | "Room 304 (ICT Workshop)"). room_name was dropped — the two
        | fields answered the same "what do we call this room" question.
        |
        */

        'room_code',

        /*
        |--------------------------------------------------------------------------
        | Room Type
        |--------------------------------------------------------------------------
        |
        | room_type reflects PAP's actual room inventory (Lecture /
        | Laboratory). Which program(s) the room is available to now lives
        | in the roomGroups() relationship below, not a column here — a
        | room can be General (every department), Shared (several
        | departments), or Exclusive (one department).
        |
        */

        'room_type',

        /*
        |--------------------------------------------------------------------------
        | Location
        |--------------------------------------------------------------------------
        */

        'building',
        'floor',

        /*
        |--------------------------------------------------------------------------
        | Capacity
        |--------------------------------------------------------------------------
        */

        'capacity',

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        'active',
    ];

    /**
     * room_group_codes is derived from the roomGroups relationship, not a
     * real column — appending it here means it's always present on the
     * model's array/JSON form (what Inertia sends to the Vue pages)
     * without every caller having to remember to add it manually.
     */
    protected $appends = [
        'room_group_codes',
    ];

    /*
    |--------------------------------------------------------------------------
    | Room Preference Capacity
    |--------------------------------------------------------------------------
    |
    | Provisional weekly-hours denominator used purely to display a
    | "X / Y hrs" utilization readout on the Manage Subjects workspace
    | (see RoomController::manageSubjects()). This is NOT a scheduling
    | constraint — no day/time slots exist yet, nothing here blocks a
    | preference from being saved past this number. The real capacity
    | (derived from the Academic Term's school hours/days) belongs to the
    | future Scheduling module; this constant exists only so Room
    | Preferences has a stable, sensible number to show in the meantime.
    |
    */

    public const WEEKLY_CAPACITY_HOURS = 60;

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    | Matches the column types defined in the rooms migration so values come
    | back from Eloquent as the correct native PHP types.
    */

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The programs this room is available to. Zero-or-more rows via
     * room_group_room — a single "General" row, one Exclusive row, or
     * several rows for a room Shared across departments.
     */
    public function roomGroups()
    {
        return $this->hasMany(RoomGroupRoom::class);
    }

    /**
     * Subject Offerings this room PREFERS to host, via the
     * room_subject_offering pivot (see the Manage Subjects workspace).
     *
     * This is a preference only — it carries no day/time/faculty
     * information and does not represent a schedule. Since every Subject
     * Offering already belongs to one Academic Term, filter by
     * ->where('academic_term_id', ...) wherever only the active term's
     * preferences should count (e.g. utilization totals), rather than
     * assuming every row here is current.
     */
    public function preferredSubjectOfferings()
    {
        return $this->belongsToMany(SubjectOffering::class, 'room_subject_offering')
            ->withTimestamps();
    }

    /**
     * Actual, committed schedule blocks for this room (the `schedules`
     * table) — the output of Master Grid's Save Schedule step, via
     * GreedyScheduleService + MasterGridController::save(). This is
     * NOT the same thing as preferredSubjectOfferings() above: a
     * preference has no day/time and means "this room would like to
     * host this offering"; a Schedule row means "this offering IS
     * meeting here, on this day, at this time." Rooms/Index.vue and
     * the Manage Subjects modal both need this to show real
     * utilization instead of only the pre-scheduling wishlist — see
     * RoomController::index() and RoomController::manageSubjects().
     */
    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * Flat array of program codes (e.g. ['BSHM', 'BSTM']) for the roomGroups
     * relationship above — what the Create/Edit/Index pages actually bind
     * to and display. Call ->load('roomGroups') first when fetching many
     * rooms to avoid an N+1 query per room.
     */
    public function getRoomGroupCodesAttribute(): array
    {
        return $this->roomGroups->pluck('room_group')->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Rooms eligible for the automatic scheduler
     * (i.e. active rooms only).
     */
    public function scopeSchedulable($query)
    {
        return $query->where('active', true);
    }

    /**
     * Rooms available to a given program — either flagged General
     * (available to every program) or specifically assigned to
     * $programCode (Shared or Exclusive). For the future Greedy Scheduler
     * to pick a valid room for a given subject/section.
     */
    public function scopeAvailableFor($query, string $programCode)
    {
        return $query->whereHas('roomGroups', function ($query) use ($programCode) {
            $query->whereIn('room_group', ['General', $programCode]);
        });
    }

    /**
     * Rooms matching a single program filter — used by the Index page's
     * "Available Programs" dropdown. Matches any room that has that
     * program among its (possibly several) assigned programs. Unlike
     * scopeAvailableFor(), this does NOT also match General when filtering
     * by a specific department — it's a literal "has this exact group"
     * filter, not a scheduling-eligibility check.
     */
    public function scopeForRoomGroup($query, string $roomGroup)
    {
        return $query->whereHas('roomGroups', function ($query) use ($roomGroup) {
            $query->where('room_group', $roomGroup);
        });
    }
}