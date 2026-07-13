<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanningAcademicTermRequest extends FormRequest
{
    /**
     * Authorization also happens at the route/controller-middleware
     * level (role:Admin|Registrar), but it's checked again here as a
     * defense-in-depth measure — this request should never validate
     * successfully for a Dean/Assistant Dean/OIC even if the route
     * grouping is ever rearranged later.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Admin', 'Registrar']) ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_term_id.required' => 'Please select an Academic Term for the Scheduling Workspace.',
            'academic_term_id.exists' => 'The selected Academic Term could not be found.',
        ];
    }
}