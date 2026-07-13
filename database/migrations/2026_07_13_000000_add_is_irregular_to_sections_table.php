<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Irregular Sections skip the normal curriculum + year-level
     * auto-population (see SubjectOfferingGeneratorService::generate())
     * entirely. Their Subject Offerings are instead hand-picked one at
     * a time via SubjectOfferingController::irregularSubjects() /
     * storeIrregular(), each still going through EdpCodeService for
     * its EDP Code so the format/uniqueness rules never diverge
     * between Regular and Irregular Sections.
     *
     * Defaults to false so every existing Section keeps behaving
     * exactly as it does today.
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->boolean('is_irregular')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('is_irregular');
        });
    }
};