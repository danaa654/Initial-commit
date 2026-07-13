<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single (subject, program) assignment row.
 *
 * A subject can have any number of these — this is what makes Program/Room
 * Group assignment a many-to-many relationship instead of the old single
 * required_room_group column.
 */
class SubjectRoomGroup extends Model
{
    protected $table = 'room_group_subject';

    protected $fillable = [
        'subject_id',
        'room_group',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Allowed Programs
    |--------------------------------------------------------------------------
    |
    | Every value 'room_groups' is allowed to hold: the literal "General"
    | (Lecture-only, Minor-only, means "applicable to every program") plus
    | every active Program's code, e.g. ['General', 'BSIT', 'BSED', 'BSIE',
    | 'BSCRIM'].
    |
    | This used to be a hardcoded GROUPS constant listing a fixed set of
    | program codes by hand. That meant adding a new College/Program on the
    | Programs page did NOT make it usable here — Subjects (and Rooms, via
    | the identical room_group_room pivot) still only recognized the old
    | fixed list until someone edited this file and redeployed.
    |
    | options() instead reads live from the programs table, so a newly
    | added Program is immediately selectable here, and immediately
    | eligible for the scheduler's room-matching (GreedyScheduleService /
    | ScheduleValidationService / ScheduleRecommendationService), with zero
    | code changes required.
    |
    */
    public static function options(): array
    {
        return array_merge(
            ['General'],
            Program::where('active', true)
                ->orderBy('code')
                ->pluck('code')
                ->all()
        );
    }
}