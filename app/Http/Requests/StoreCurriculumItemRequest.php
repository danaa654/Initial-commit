<?php

namespace App\Http\Requests;

use App\Models\CurriculumItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurriculumItemRequest extends FormRequest
{
    /**
     * Role/permission checks for this resource are handled by the
     * controller's route middleware (Admin|Registrar), same as every
     * other Curriculum-related controller — this just validates shape.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isSubject = $this->input('item_type') === CurriculumItem::TYPE_SUBJECT;
        $isOjt = $this->input('item_type') === CurriculumItem::TYPE_OJT;

        return [

            'curriculum_id' => [
                'required',
                'exists:curricula,id',
            ],

            'item_type' => [
                'required',
                Rule::in(CurriculumItem::ITEM_TYPES),
            ],

            /*
            |--------------------------------------------------------------------------
            | Subject fields (item_type = Subject only)
            |--------------------------------------------------------------------------
            |
            | An array, not a single subject_id — the Create form lets the
            | user check off several subjects at once to place them all into
            | the same year_level/semester in one submit. Duplicate-vs-
            | already-assigned checking happens in the controller (so it can
            | silently skip already-assigned subjects instead of failing the
            | whole batch), not here.
            |
            */

            'subject_ids' => [
                Rule::requiredIf($isSubject),
                'array',
                'min:' . ($isSubject ? 1 : 0),
            ],

            'subject_ids.*' => [
                'integer',
                'exists:subjects,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Practicum / OJT fields (item_type = OJT only)
            |--------------------------------------------------------------------------
            |
            | Practicum/OJT items reuse the Subjects master list instead of
            | a free-text title — the Registrar picks from Subjects flagged
            | is_practicum = true (scoped to the curriculum's program on
            | the frontend). The uniqueness check mirrors the DB's
            | (curriculum_id, subject_id) constraint, which doesn't care
            | about item_type, so it's checked the same way regardless of
            | which type this subject is being attached as.
            |
            */

            'subject_id' => [
                Rule::requiredIf($isOjt),
                'nullable',
                'exists:subjects,id',
                Rule::unique('curriculum_items', 'subject_id')
                    ->where(fn ($query) => $query
                        ->where('curriculum_id', $this->input('curriculum_id'))),
            ],

            'ojt_hours' => [
                Rule::requiredIf($isOjt),
                'nullable',
                'integer',
                'min:1',
                'max:2000',
            ],

            /*
            |--------------------------------------------------------------------------
            | Shared placement fields
            |--------------------------------------------------------------------------
            */

            'year_level' => [
                'required',
                'integer',
                'min:1',
                'max:4',
            ],

            'semester' => [
                'required',
                'integer',
                Rule::in([
                    CurriculumItem::SEMESTER_FIRST,
                    CurriculumItem::SEMESTER_SECOND,
                    CurriculumItem::SEMESTER_SUMMER,
                ]),
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'active' => [
                'required',
                'boolean',
            ],

        ];
    }

    public function messages(): array
    {
        return [
            'subject_ids.required' => 'Select at least one subject.',
            'subject_ids.min' => 'Select at least one subject.',
            'subject_id.required' => 'Select a practicum subject.',
            'subject_id.unique' => 'That subject is already assigned to this curriculum.',
            'ojt_hours.required' => 'Enter the number of practicum hours.',
        ];
    }
}