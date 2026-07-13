<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single, immutable security/accountability record — who did what,
 * to which record, when, from where, and (for edits) what changed.
 *
 * This is intentionally separate from FacultyLoadActivity and any
 * other module-specific "Recent Activity" feed: those are lightweight,
 * user-facing convenience feeds scoped to one module. AuditLog is the
 * system-wide, security-grade record read only by Admin/Registrar via
 * the Audit Logs page — see AuditLogController.
 *
 * Rows are written exclusively through AuditLogService::log() — never
 * created directly by a controller — and are never updated or deleted
 * through the UI (see AuditLogController, which exposes index()/show()
 * only).
 */
class AuditLog extends Model
{
    /**
     * No updated_at column — a log row is written once and never
     * modified again, so tracking "last updated" makes no sense here.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name',
        'role',
        'action',
        'module',
        'record_type',
        'record_id',
        'record_name',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The user who performed the action, if their account still
     * exists (user_id is nullOnDelete — see the migration). Always
     * prefer the user_name/role SNAPSHOT columns for display; this
     * relationship exists mainly so the Audit Logs page can filter
     * "by user" against a real users table for the dropdown, not so
     * every row display has to load it.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes — one per filter the Audit Logs page exposes
    |--------------------------------------------------------------------------
    */

    public function scopeForUser($query, ?int $userId)
    {
        return $query->when($userId, fn ($q) => $q->where('user_id', $userId));
    }

    public function scopeForRole($query, ?string $role)
    {
        return $query->when($role, fn ($q) => $q->where('role', $role));
    }

    public function scopeForModule($query, ?string $module)
    {
        return $query->when($module, fn ($q) => $q->where('module', $module));
    }

    public function scopeForAction($query, ?string $action)
    {
        return $query->when($action, fn ($q) => $q->where('action', $action));
    }

    public function scopeBetweenDates($query, ?string $from, ?string $to)
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));
    }

    /**
     * Free-text search across description/record_name/user_name — the
     * single "Search" box on the Audit Logs page.
     */
    public function scopeSearch($query, ?string $term)
    {
        return $query->when(trim((string) $term) !== '', function ($q) use ($term) {
            $like = '%'.strtolower($term).'%';

            $q->where(function ($inner) use ($like) {
                $inner->whereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(record_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(user_name) LIKE ?', [$like]);
            });
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Display Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Best-effort human label for the browser/device from the raw
     * user_agent string — deliberately simple pattern matching, not a
     * full UA-parsing library, since this is only ever shown as a
     * light "Chrome on Windows" hint in the row detail panel, not
     * relied on for any security decision.
     */
    public function getBrowserLabelAttribute(): string
    {
        $ua = (string) $this->user_agent;

        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') && ! str_contains($ua, 'Chrome/') => 'Safari',
            $ua === '' => 'Unknown',
            default => 'Other',
        };
    }
}