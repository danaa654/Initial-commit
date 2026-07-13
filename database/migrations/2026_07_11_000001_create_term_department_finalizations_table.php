<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-College (Department) Finalize/Unfinalize lock for an Academic
 * Term's scheduling data. One row per (academic_term_id, department_id)
 * pair — created lazily by TermFinalizationService the first time a
 * college is finalized; a missing row simply means "never finalized,
 * still Draft" (see TermDepartmentFinalization::scopeFinalized()).
 *
 * NOTE ON NAMING: the feature spec that requested this refers to
 * "College", but this codebase's actual table/model for that concept
 * is `departments` (see DepartmentController, Faculty::department_id,
 * Section::curriculum.program.department_id in BlockScheduleController).
 * `department_id` below IS the "college" FK the spec describes — no
 * separate `colleges` table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_department_finalizations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('academic_term_id')
                ->constrained('academic_terms')
                ->cascadeOnDelete();

            $table->foreignId('department_id')
                ->constrained('departments')
                ->cascadeOnDelete();

            $table->boolean('finalized')->default(false);

            $table->foreignId('finalized_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();

            $table->foreignId('unfinalized_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('unfinalized_at')->nullable();

            $table->timestamps();

            $table->unique(['academic_term_id', 'department_id'], 'term_dept_finalizations_term_dept_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_department_finalizations');
    }
};