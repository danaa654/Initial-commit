<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per authenticated browser session — the backing table for
 * the System Monitor > Active Users page.
 *
 * Rows are written exclusively through ActiveSessionService (never
 * created directly), and are deliberately NOT append-only like
 * AuditLog/ActivityHistory: this table only ever holds the CURRENT
 * state of sessions that are (or very recently were) live. A row is
 * updated in place on every request via TrackActiveSession, and
 * removed outright on logout or once it goes stale — see
 * ActiveSessionService::endSession() / pruneStale().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Laravel's own session id — the natural key for "which
            // browser tab/device is this". Unique so a second request
            // from the same session always updates the same row
            // rather than creating a duplicate.
            $table->string('session_id')->unique();

            $table->timestamp('login_at')->useCurrent();
            $table->timestamp('last_activity_at')->useCurrent()->index();

            // Human-readable label (e.g. "Faculty Loading"), resolved
            // from the current route name — see
            // ActiveSessionService::resolvePageLabel().
            $table->string('current_page')->nullable();

            $table->string('browser')->nullable();
            $table->string('operating_system')->nullable();
            $table->string('ip_address')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_sessions');
    }
};