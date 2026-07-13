<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Who
            |--------------------------------------------------------------------------
            |
            | user_id is nullOnDelete (not cascade) — a log row is a
            | permanent security record and must survive even if the
            | user account performing the action is later deleted.
            | user_name/role are SNAPSHOT columns captured at write
            | time for the exact same reason FacultyLoadActivity
            | snapshots faculty_name_snapshot/subject_snapshot: so the
            | log keeps reading correctly ("Registrar Juan Dela Cruz
            | did X") even after the user is renamed, has their role
            | changed, or is deleted outright.
            |
            */

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users', indexName: 'audit_logs_user_fk')
                ->nullOnDelete();

            $table->string('user_name')->nullable();

            $table->string('role')->nullable();

            /*
            |--------------------------------------------------------------------------
            | What
            |--------------------------------------------------------------------------
            |
            | action is a short verb ('created', 'updated', 'deleted',
            | 'assigned', 'login', 'generated', ...) — free-form string
            | rather than an enum, since new modules will keep
            | introducing new verbs (see AuditLogService::ACTIONS for
            | the canonical list used by the UI's filter dropdown; the
            | column itself stays a plain string so a not-yet-listed
            | action never fails to log).
            |
            | module is the feature area ('Faculty Loading', 'Master
            | Grid', 'User Management', 'Authentication', ...) — same
            | free-form-but-cataloged approach as action, via
            | AuditLogService::MODULES.
            |
            */

            $table->string('action');

            $table->string('module');

            /*
            |--------------------------------------------------------------------------
            | Which Record
            |--------------------------------------------------------------------------
            |
            | record_type/record_id are deliberately NOT a real foreign
            | key — this table logs actions across dozens of unrelated
            | models (User, TeachingAssignment, Schedule, Curriculum,
            | AcademicTerm, ...), so a polymorphic-style pair of plain
            | columns is used instead of a real relation. record_name
            | is a human-readable snapshot ("BSIT 1-A - CC103", "Regil
            | Kent M. Seville") so the Audit Log page never has to
            | join out to a possibly-deleted row just to show what was
            | affected.
            |
            */

            $table->string('record_type')->nullable();

            $table->unsignedBigInteger('record_id')->nullable();

            $table->string('record_name')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Description & Diff
            |--------------------------------------------------------------------------
            */

            $table->text('description')->nullable();

            $table->json('old_values')->nullable();

            $table->json('new_values')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Where / How
            |--------------------------------------------------------------------------
            */

            $table->string('ip_address', 45)->nullable();

            $table->string('user_agent')->nullable();

            $table->timestamp('created_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            |
            | Every column the Audit Logs page filters by gets its own
            | index — module/action/user_id/created_at are the four
            | filters expected to be used heavily and in combination,
            | and this table is expected to grow large and be read far
            | more often than written.
            |
            */

            $table->index('module');
            $table->index('action');
            $table->index('created_at');
            $table->index(['record_type', 'record_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};