<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when an Irregular Section's need for a Subject is FULFILLED
 * by an already-existing Regular Section's Subject Offering, instead
 * of a brand new Subject Offering (and EDP Code) being generated for
 * it — the "irregular student just sits in BSIT-3A's existing Systems
 * Analysis class" path described in the capstone's irregular-section
 * workflow.
 *
 * Why a separate table instead of just pointing the Irregular
 * Section's own subject_offerings.section_id at the Regular one, or
 * literally re-using the same edp_code on a second row: a Subject
 * Offering already means "one class taught at one time slot" —
 * subject_offerings.edp_code is UNIQUE and a row can only belong to
 * ONE section (section_id is a single non-nullable FK). Duplicating
 * the row would violate that unique constraint; repointing it would
 * make it vanish from whichever section didn't win. A fulfillment
 * row lets BOTH the Regular Section (which still owns the one real
 * Subject Offering/EDP Code) and the Irregular Section (which needs
 * the same Subject covered, without a second class ever being put on
 * the schedule) show the same EDP Code without a second one ever
 * being minted.
 *
 * unique(section_id, subject_offering_id): the same Irregular Section
 * can't be marked as fulfilled by the same Subject Offering twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irregular_subject_fulfillments', function (Blueprint $table) {

            $table->id();

            // The Irregular Section whose need is being covered.
            $table->foreignId('section_id')
                ->constrained('sections')
                ->cascadeOnDelete();

            // The Regular Section's existing Subject Offering that
            // covers it — this is where the shared EDP Code lives.
            $table->foreignId('subject_offering_id')
                ->constrained('subject_offerings')
                ->cascadeOnDelete();

            // Which Curriculum Item (on the Irregular Section's own
            // track) this fulfills — mirrors subject_offerings'
            // curriculum_item_id, nullable/cascades the same way.
            $table->foreignId('curriculum_item_id')
                ->nullable()
                ->constrained('curriculum_items')
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['section_id', 'subject_offering_id'], 'irr_subj_fulfillment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('irregular_subject_fulfillments');
    }
};