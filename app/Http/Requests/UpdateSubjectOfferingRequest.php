<?php

namespace App\Http\Requests;

use App\Models\SubjectOffering;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Status is the only editable field after generation. Everything
     * else (Term/Curriculum/Program/Section/Subject/Units/Hours/
     * Classification/Room Type) is a generated fact about the
     * offering, not something to hand-edit — regenerate instead if
     * any of that needs to change.
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(SubjectOffering::STATUSES)],
        ];
    }
}