<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single "this Room is available to this Program" row.
 *
 * A Room can carry any number of these — none/"General" means available to
 * every program, a single row means Exclusive to one department, and
 * several rows means Shared across departments (e.g. a laboratory used by
 * both BSHM and BSTM). Same shape as the room_group_subject pivot already
 * used for Subject::required_room_group — reused here rather than
 * inventing a second way to model the same "one or more programs" idea.
 */
class RoomGroupRoom extends Model
{
    protected $table = 'room_group_room';

    protected $fillable = [
        'room_id',
        'room_group',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}