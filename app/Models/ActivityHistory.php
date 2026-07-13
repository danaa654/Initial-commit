<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One card in the Activity History Timeline — a single scheduling
 * milestone (Subject Offerings Generated, Faculty Loading Completed,
 * Master Grid Generated, Manual Schedule Adjustment, Schedule
 * Published, ...), always attributed to an Academic Term.
 *
 * This is NOT AuditLog. AuditLog answers "who modified a record?" for
 * security purposes and is a flat, ungrouped, row-per-write table.
 * ActivityHistory answers "what happened while building this
 * semester's schedule?" and is deliberately grouped, timeline-shaped,
 * and story-driven — see ActivityHistoryController::index() for the
 * grouping-by-term.
 *
 * Rows are written exclusively through ActivityHistoryService::record()
 * — never created directly by a controller — and are never updated or
 * deleted through the UI (read-only, same as AuditLog).
 */
class ActivityHistory extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'academic_term_id',
        'user_id',
        'user_name',
        'module',
        'event',
        'title',
        'description',
        'metadata',
        'icon',
        'color',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function academicTerm()
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes — one per filter the Activity History page exposes
    |--------------------------------------------------------------------------
    */

    public function scopeForTerm($query, ?int $termId)
    {
        return $query->when($termId, fn ($q) => $q->where('academic_term_id', $termId));
    }

    public function scopeForModule($query, ?string $module)
    {
        return $query->when($module, fn ($q) => $q->where('module', $module));
    }

    public function scopeForEvent($query, ?string $event)
    {
        return $query->when($event, fn ($q) => $q->where('event', $event));
    }

    public function scopeForUser($query, ?int $userId)
    {
        return $query->when($userId, fn ($q) => $q->where('user_id', $userId));
    }

    public function scopeBetweenDates($query, ?string $from, ?string $to)
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));
    }

    /**
     * Free-text search across title/description/user_name — the single
     * "Search" box on the Activity History page.
     */
    public function scopeSearch($query, ?string $term)
    {
        return $query->when(trim((string) $term) !== '', function ($q) use ($term) {
            $like = '%'.strtolower($term).'%';

            $q->where(function ($inner) use ($like) {
                $inner->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(user_name) LIKE ?', [$like]);
            });
        });
    }
}