<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for SubjectOfferingController::bulkUpdateWeeklyHours().
 *
 * Role gating (Admin/Registrar only) happens in the controller —
 * this only validates shape: which Subject Offerings were selected,
 * and what the new weekly hours value should be.
 */
class BulkUpdateWeeklyHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'subject_offering_ids' => ['required', 'array', 'min:1'],

            'subject_offering_ids.*' => ['integer', 'distinct', 'exists:subject_offerings,id'],

            // Weekly hours, not "hours per meeting" — meetings_per_week
            // stays untouched by this action. 2–5 matches the range
            // every real CLASSLY subject's weekly hours falls into.
            'hours' => ['required', 'integer', 'min:2', 'max:5'],

        ];
    }

    public function messages(): array
    {
        return [
            'subject_offering_ids.required' => 'Select at least one Subject Offering to update.',
            'subject_offering_ids.min' => 'Select at least one Subject Offering to update.',
            'hours.required' => 'Enter the new Weekly Hours.',
            'hours.min' => 'Weekly Hours must be at least 2.',
            'hours.max' => 'Weekly Hours cannot exceed 5.',
        ];
    }
}