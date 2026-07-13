<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class CurriculumItem extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Item Type Constants
    |--------------------------------------------------------------------------
    |
    | The full set of item types curriculum_items can hold. Adding a new
    | type later (e.g. "Elective Block") means: add the constant here, add
    | it to ITEM_TYPES, add it to the enum in a new migration, then teach
    | the form/request/controller about its fields. Nothing else in the
    | schema changes.
    |
    */

    public const TYPE_SUBJECT = 'Subject';
    public const TYPE_OJT = 'OJT';

    public const ITEM_TYPES = [
        self::TYPE_SUBJECT,
        self::TYPE_OJT,
    ];

    public const ITEM_TYPE_LABELS = [
        self::TYPE_SUBJECT => 'Subject',
        self::TYPE_OJT => 'Practicum / OJT',
    ];

    /*
    |--------------------------------------------------------------------------
    | Semester Constants
    |--------------------------------------------------------------------------
    */

    public const SEMESTER_FIRST = 1;
    public const SEMESTER_SECOND = 2;
    public const SEMESTER_SUMMER = 3;

    public const SEMESTERS = [
        self::SEMESTER_FIRST => 'First Semester',
        self::SEMESTER_SECOND => 'Second Semester',
        self::SEMESTER_SUMMER => 'Summer',
    ];

    protected $fillable = [
        'curriculum_id',
        'item_type',
        'subject_id',
        'title',
        'ojt_hours',
        'year_level',
        'semester',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'ojt_hours' => 'integer',
            'year_level' => 'integer',
            'semester' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Business Rule Guard
    |--------------------------------------------------------------------------
    |
    | Belt-and-suspenders alongside the Form Request rules: no matter how a
    | CurriculumItem gets saved (controller, tinker, a future importer,
    | etc.), the fields that don't belong to its item_type are force-nulled
    | here so the two types can never bleed into each other in the DB.
    |
    | Practicum/OJT items now reuse subject_id (pointing at a Practicum
    | entry in the Subjects master list) instead of a free-text title —
    | so subject_id stays populated for both types, and the legacy
    | free-text `title` column is nulled for both.
    |
    */

    protected static function booted(): void
    {
        static::saving(function (CurriculumItem $item) {
            $item->title = null;

            if ($item->item_type === self::TYPE_SUBJECT) {
                $item->ojt_hours = null;
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teachingAssignments()
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    // Every Subject Offering generated FROM this curriculum item
    // (subject_offerings.curriculum_item_id). Needed by
    // CurriculumItemController::destroy() to block removal once
    // scheduling work has already been generated for it — an
    // offering is a snapshot taken at generation time, so deleting
    // the item underneath it would orphan real scheduling data.
    public function subjectOfferings()
    {
        return $this->hasMany(SubjectOffering::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | The scheduler should only ever touch subject-type items — ->subjects()
    | is the one place that filter lives, so it never has to be re-written
    | by hand at every call site.
    |
    */

    public function scopeSubjects(Builder $query): Builder
    {
        return $query->where('item_type', self::TYPE_SUBJECT);
    }

    public function scopeOjt(Builder $query): Builder
    {
        return $query->where('item_type', self::TYPE_OJT);
    }

    /*
    |--------------------------------------------------------------------------
    | Type Helpers
    |--------------------------------------------------------------------------
    */

    public function isSubject(): bool
    {
        return $this->item_type === self::TYPE_SUBJECT;
    }

    public function isOjt(): bool
    {
        return $this->item_type === self::TYPE_OJT;
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getSemesterLabelAttribute(): string
    {
        return self::SEMESTERS[$this->semester] ?? 'Unknown';
    }

    public function getItemTypeLabelAttribute(): string
    {
        return self::ITEM_TYPE_LABELS[$this->item_type] ?? $this->item_type;
    }

    // What the Index/Manage tables show in the "Title" column, regardless
    // of item type — both Subject and Practicum/OJT items resolve to the
    // linked subject's descriptive title now. `title` is kept only as a
    // fallback for any legacy free-text OJT rows saved before Practicum/
    // OJT items were tied to the Subjects master list.
    public function getDisplayTitleAttribute(): ?string
    {
        return $this->subject?->descriptive_title ?? $this->title;
    }

    // What the Index/Manage tables show in the "Subject Code" column —
    // populated for both item types now that Practicum/OJT items also
    // carry a subject_id.
    public function getDisplayCodeAttribute(): ?string
    {
        return $this->subject?->subject_code;
    }
}