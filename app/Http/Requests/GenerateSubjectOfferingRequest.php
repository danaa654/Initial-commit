<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerateSubjectOfferingRequest extends FormRequest
{
    /**
     * Role gating happens in SubjectOfferingController's middleware +
     * the "generate" ability check — this only validates shape.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],

            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],

            'section_ids' => ['required', 'array', 'min:1'],

            'section_ids.*' => [
                'integer',
                Rule::exists('sections', 'id')
                    ->where('curriculum_id', $this->input('curriculum_id'))
                    ->where('status', 'Active'),
            ],

        ];
    }

    /**
     * The exists() rule above already confirms every section_id
     * belongs to curriculum_id and is Active — this is just a clearer
     * error than the default "invalid" message when that fails.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('section_ids.*')) {
                $validator->errors()->add(
                    'section_ids',
                    'One or more selected Sections do not belong to the selected Curriculum, or are not Active.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'section_ids.required' => 'Select at least one Section to open.',
            'section_ids.min' => 'Select at least one Section to open.',
        ];
    }
}