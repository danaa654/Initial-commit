<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single live (or very-recently-live) authenticated session — the
 * row behind one card on the System Monitor > Active Users page.
 *
 * This is NOT an audit trail (see AuditLog/ActivityHistory for that).
 * It only represents current state: created on login, updated on
 * every request while the session is alive, and deleted on logout or
 * once it goes stale. See ActiveSessionService for the single write
 * path — nothing should ever touch this table directly.
 */
class ActiveSession extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'login_at',
        'last_activity_at',
        'current_page',
        'browser',
        'operating_system',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected $appends = [
        'status',
        'status_label',
    ];

    /*
    |--------------------------------------------------------------------------
    | Status thresholds (minutes)
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the Online/Idle/Offline rules, shared
    | by the status accessors below and ActiveSessionService::pruneStale()
    | / the scopeLive() query scope, so the "10 minutes" and "2 minutes"
    | figures only ever live in one place.
    |
    */

    public const IDLE_AFTER_MINUTES = 2;

    public const STALE_AFTER_MINUTES = 10;

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Sessions that still count as "active" for display purposes —
     * i.e. not yet stale. A row surviving past STALE_AFTER_MINUTES
     * without a fresh touch means the user closed the tab / lost
     * connectivity without ever hitting the logout route, so it's
     * treated as gone even before ActiveSessionService::pruneStale()
     * physically deletes it.
     */
    public function scopeLive($query)
    {
        return $query->where('last_activity_at', '>=', now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function scopeForStatus($query, ?string $status)
    {
        return $query->when($status, function ($q) use ($status) {
            $onlineCutoff = now()->subMinutes(self::IDLE_AFTER_MINUTES);

            if ($status === 'online') {
                $q->where('last_activity_at', '>=', $onlineCutoff);
            } elseif ($status === 'idle') {
                $q->where('last_activity_at', '<', $onlineCutoff);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Status accessors
    |--------------------------------------------------------------------------
    */

    public function getStatusAttribute(): string
    {
        $minutesIdle = $this->last_activity_at ? (int) floor($this->last_activity_at->diffInMinutes(now())) : 0;

        if ($minutesIdle >= self::STALE_AFTER_MINUTES) {
            return 'offline';
        }

        return $minutesIdle >= self::IDLE_AFTER_MINUTES ? 'idle' : 'online';
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->status !== 'idle') {
            return ucfirst($this->status);
        }

        $minutes = $this->last_activity_at ? (int) floor($this->last_activity_at->diffInMinutes(now())) : 0;

        return "Idle ({$minutes} min" . ($minutes === 1 ? '' : 's') . ')';
    }
}