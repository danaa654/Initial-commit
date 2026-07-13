<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single row in the system-wide, shared key/value settings store.
 *
 * Deliberately NOT session/cache-backed — every authorized user
 * (Admin, Registrar, Dean, Assistant Dean, OIC) must see the exact
 * same value at the exact same time, which rules out session storage
 * entirely. Reads go straight to the database so there's a single
 * source of truth; SchedulingWorkspaceService is the only intended
 * caller for the 'planning_academic_term_id' key specifically.
 */
class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Fetch a setting's raw string value, or $default if it doesn't
     * exist yet (e.g. on a fresh install before Settings has ever
     * been saved).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    /**
     * Create or overwrite a setting. updateOrCreate on `key` (which is
     * uniquely indexed) so this is always a single row per key, never
     * a duplicate.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}