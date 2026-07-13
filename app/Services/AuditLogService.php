<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * The single write path for every Audit Log row in CLASSLY.
 *
 * No controller, service, or model should ever create an AuditLog row
 * directly (`AuditLog::create(...)`) — always call
 * AuditLogService::log(...) instead, so:
 *
 *   - user/role/IP/user-agent capture happens exactly once, the same
 *     way, everywhere (no controller has to remember to fill those
 *     in itself or gets them slightly wrong).
 *   - the description-formatting convention stays consistent across
 *     every module.
 *   - new modules can start logging by adding ONE line
 *     (AuditLogService::log(...)) at the point of action, without any
 *     other file ever needing to change.
 *
 * Deliberately swallows its own failures (see log()'s try/catch) —
 * logging must never be the reason a real user action fails. A
 * faculty assignment that succeeds but fails to audit-log is a bug to
 * fix; a faculty assignment that FAILS because the audit log insert
 * failed is a much worse bug to ship.
 */
class AuditLogService
{
    /**
     * Canonical action verbs — used to populate the Audit Logs page's
     * "Action" filter dropdown. The `action` column itself is a plain
     * string, not a DB enum, so passing a string not in this list
     * still logs fine; this is a UI convenience list, not a
     * constraint.
     */
    public const ACTIONS = [
        'login', 'logout', 'password_reset', 'password_changed',
        'created', 'updated', 'deleted',
        'activated', 'deactivated', 'role_changed',
        'assigned', 'unassigned', 'overridden',
        'generated', 'published', 'unpublished', 'moved',
        'conflict_overridden',
    ];

    /**
     * Canonical module names — populates the "Module" filter dropdown.
     * Same non-constraining role as ACTIONS above.
     */
    public const MODULES = [
        'Authentication',
        'User Management',
        'Academic Term',
        'College',
        'Program',
        'Specialization',
        'Curriculum',
        'Sections',
        'Faculty',
        'Rooms',
        'Subjects',
        'Subject Offering',
        'Faculty Loading',
        'Master Grid',
        'Settings',
    ];

    /**
     * Write one Audit Log row.
     *
     * @param  string  $action        One of self::ACTIONS (or a new verb — see class docblock).
     * @param  string  $module        One of self::MODULES (or a new module).
     * @param  Model|null  $model     The record affected, if any. Its class basename becomes
     *                                record_type, its key becomes record_id — pass null for
     *                                actions with no single affected record (e.g. 'login').
     * @param  string  $description   Human-readable summary, e.g.
     *                                "Assigned Regil Kent M. Seville to BSIT 1-A CC103".
     * @param  array|null  $oldValues Previous field values, for edits — e.g. ['room' => 'Room 301'].
     * @param  array|null  $newValues New field values, for edits — e.g. ['room' => 'Room 302'].
     * @param  string|null  $recordName  Human-readable label for the record, e.g. "BSIT 1-A - CC103".
     *                                    Falls back to the model's common display accessors if omitted.
     */
    public static function log(
        string $action,
        string $module,
        ?Model $model = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $recordName = null,
    ): ?AuditLog {
        try {
            /** @var User|null $user */
            $user = Auth::user();

            return AuditLog::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'role' => $user?->getRoleNames()->first(),

                'action' => $action,
                'module' => $module,

                'record_type' => $model ? class_basename($model) : null,
                'record_id' => $model?->getKey(),
                'record_name' => $recordName ?? self::resolveRecordName($model),

                'description' => $description,

                // Passwords/tokens/remember_token must NEVER end up
                // here even by accident — sanitizeValues() strips any
                // key that looks like a credential before it's ever
                // persisted, regardless of what a caller passes in.
                'old_values' => $oldValues ? self::sanitizeValues($oldValues) : null,
                'new_values' => $newValues ? self::sanitizeValues($newValues) : null,

                'ip_address' => RequestFacade::ip(),
                'user_agent' => RequestFacade::userAgent(),

                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the real action it's attached
            // to. report() still surfaces the failure to Laravel's
            // normal error tracking so a broken audit pipeline doesn't
            // fail silently forever — it just never bubbles up to the
            // user as a 500.
            report($e);

            return null;
        }
    }

    /**
     * Best-effort human label for the affected record when the caller
     * doesn't pass one explicitly — tries the common display accessors
     * used across CLASSLY's models (full_name, display_name, name,
     * section_code, ...) before falling back to "#<id>".
     */
    private static function resolveRecordName(?Model $model): ?string
    {
        if (! $model) {
            return null;
        }

        foreach (['full_name', 'display_name', 'section_code', 'edp_code', 'name', 'code'] as $attribute) {
            if (isset($model->{$attribute}) && $model->{$attribute} !== '') {
                return (string) $model->{$attribute};
            }
        }

        return '#'.$model->getKey();
    }

    /**
     * Strips any credential-shaped key out of an old/new values array
     * before it's persisted — belt-and-suspenders alongside every
     * caller already being expected not to pass these in. Matched
     * case-insensitively and by substring, so 'password',
     * 'new_password', 'password_confirmation', 'remember_token', and
     * 'api_token' are all caught.
     */
    private static function sanitizeValues(array $values): array
    {
        $blocked = ['password', 'token', 'secret'];

        return collect($values)
            ->reject(function ($value, $key) use ($blocked) {
                $key = strtolower((string) $key);

                foreach ($blocked as $needle) {
                    if (str_contains($key, $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->all();
    }
}