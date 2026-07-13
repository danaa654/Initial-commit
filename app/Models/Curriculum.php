<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Curriculum extends Model
{
    protected $fillable = [

        'program_id',

        'specialization_id',

        'code',

        'name',

        'academic_year',

        'effective_year',

        'active',

    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    // Append these attributes when converting to array/JSON
    protected $appends = ['has_sections', 'has_items', 'display_name', 'curriculum_range'];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function specialization()
    {
        return $this->belongsTo(Specialization::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Sections
    |--------------------------------------------------------------------------
    |
    | The section groupings (e.g. BSIT-1A) that follow this curriculum.
    |
    */

    public function sections()
    {
        return $this->hasMany(Section::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Curriculum Items (Subjects, OJT, and future item types)
    |--------------------------------------------------------------------------
    |
    | This curriculum's full prospectus — every item type included. This is
    | the relationship to reach for on the Manage/Index pages, which need
    | to show Subject and OJT rows side by side.
    |
    */

    public function curriculumItems()
    {
        return $this->hasMany(CurriculumItem::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Subjects (convenience accessor)
    |--------------------------------------------------------------------------
    |
    | Subjects are a master list shared across curriculums. This exposes
    | just the Subject-type items of this curriculum's prospectus as an
    | actual collection of Subject models — this is what the scheduler
    | should use, since it only ever schedules Subject items into rooms.
    |
    */

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'curriculum_items')
            ->wherePivot('item_type', CurriculumItem::TYPE_SUBJECT)
            ->withPivot(['id', 'item_type', 'year_level', 'semester', 'sort_order', 'active'])
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Attributes
    |--------------------------------------------------------------------------
    */

    public function getDisplayNameAttribute()
    {
        if ($this->specialization) {
            return "{$this->program->code} - {$this->specialization->name} ({$this->effective_year})";
        }

        return "{$this->program->code} ({$this->effective_year})";
    }

    public function getHasSectionsAttribute()
    {
        return $this->sections()->exists();
    }

    public function getHasItemsAttribute()
    {
        return $this->curriculumItems()->exists();
    }

    /**
     * The prospectus's expected coverage span, e.g. "2023-2027" for a
     * curriculum effective in 2023 under a 4-year program — this is
     * what a printed prospectus cover typically shows.
     *
     * Deliberately DERIVED, never stored: it's computed fresh from
     * effective_year + the owning Program's `years` every time it's
     * read, rather than being a static range baked in at creation.
     * This means it always stays internally consistent even if a
     * Program's duration is edited later, and — just as importantly —
     * it does NOT represent an expiration date. A student who takes
     * longer than the normal duration can still be actively following
     * this curriculum well past its computed end year; see the
     * "Should we fix our curriculum?" conversation for the full
     * reasoning. This is a label for the cohort's *expected* span,
     * not an enforced cutoff — nothing in the app should ever block
     * usage of a curriculum because "today" falls outside this range.
     *
     * Falls back to a 4-year duration if the Program (or its `years`
     * column) isn't available, so this never throws even on a
     * partially-loaded model.
     */
    public function getCurriculumRangeAttribute(): ?string
    {
        if (! $this->effective_year) {
            return null;
        }

        $duration = $this->program?->years ?: 4;

        $endYear = (int) $this->effective_year + $duration - 1;

        return "{$this->effective_year}-{$endYear}";
    }
}