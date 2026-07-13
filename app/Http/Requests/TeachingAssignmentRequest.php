<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Field-level validation for assigning a faculty member to a Subject
 * Offering. Cross-model business rules (scope, department, active
 * term, active faculty, max units) live in TeachingAssignmentService,
 * since they need more than one record to evaluate and want to stay
 * reusable/testable outside of HTTP validation.
 *
 * Role/permission checks already happen in
 * TeachingAssignmentController::middleware(). Nothing extra to gate
 * here.
 */
class TeachingAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'subject_offering_id' => [
                'required',
                'exists:subject_offerings,id',
                // One faculty member per Subject Offering — mirrors
                // the DB-level unique constraint on
                // teaching_assignments.subject_offering_id.
                Rule::unique('teaching_assignments'),
            ],

            'faculty_id' => [
                'required',
                // Faculty model has no $table override, so its default
                // table name is the plural "faculties" (matches the
                // migration's foreignId('faculty_id')->constrained()
                // convention).
                'exists:faculties,id',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:255',
            ],

            'active' => ['boolean'],

        ];
    }

    public function messages(): array
    {
        return [
            'subject_offering_id.unique' => 'This Subject Offering already has a faculty member assigned.',
        ];
    }
}