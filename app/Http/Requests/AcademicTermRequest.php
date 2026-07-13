<?php

namespace App\Http\Requests;

use App\Models\AcademicTerm;
use App\Services\AcademicTermOverlapService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AcademicTermRequest extends FormRequest
{
    /**
     * Authorization is already handled by AcademicTermController's
     * HasMiddleware role check (Admin / Registrar only), so this request
     * doesn't need to duplicate that logic.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared rules for both store and update. The unique semester rule
     * ignores the current record's id on update via route model binding.
     */
    public function rules(): array
    {
        $academicTerm = $this->route('academic_term');

        return [

            // The user only ever types the first year (e.g. 2026). The
            // full "2026-2027" academic_year string is built server-side
            // in validatedForSave() from this value — never trust a
            // client-supplied academic_year string directly.
            'start_year' => [
                'required',
                'integer',
                'min:2000',
                'max:2100',
            ],

            'semester' => [
                'required',
                'integer',
                Rule::in([1, 2, 3]),
                Rule::unique('academic_terms', 'semester')
                    ->where(fn ($query) => $query->where(
                        'academic_year',
                        $this->buildAcademicYear()
                    ))
                    ->ignore($academicTerm?->id),
            ],

            'class_start_date' => [
                'required',
                'date',
            ],

            'class_end_date' => [
                'required',
                'date',
                'after:class_start_date',
            ],

            'school_start_time' => [
                'required',
                'date_format:H:i',
            ],

            'school_end_time' => [
                'required',
                'date_format:H:i',
                'after:school_start_time',
            ],

            // Lunch is optional as a pair, but if one side is filled in
            // the other becomes required (enforced via required_with).
            'lunch_start_time' => [
                'nullable',
                'date_format:H:i',
                'required_with:lunch_end_time',
            ],

            'lunch_end_time' => [
                'nullable',
                'date_format:H:i',
                'required_with:lunch_start_time',
                'after:lunch_start_time',
            ],

            // Restricted to the granularities the scheduling engine can
            // actually slice the school day into — see
            // AcademicTerm::TIME_INTERVALS for the authoritative list.
            'time_interval' => [
                'required',
                'integer',
                Rule::in(AcademicTerm::TIME_INTERVALS),
            ],

            'monday' => ['boolean'],
            'tuesday' => ['boolean'],
            'wednesday' => ['boolean'],
            'thursday' => ['boolean'],
            'friday' => ['boolean'],
            'saturday' => ['boolean'],
            'sunday' => ['boolean'],

            'status' => [
                'required',
                Rule::in(AcademicTerm::STATUSES),
            ],

            'active' => ['boolean'],

        ];
    }

    /**
     * Cross-field checks that can't be expressed as simple rule strings.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateClassDatesWithinAcademicYear($validator);
            $this->validateLunchWithinSchoolHours($validator);
            $this->validateNoOverlap($validator);
            $this->validateActiveRequiresPublished($validator);
            $this->validateAtLeastOneWorkingDay($validator);
        });
    }

    /**
     * Class Start / Class End must fall inside the selected Academic Year
     * — nothing month- or semester-specific. See
     * AcademicTerm::academicYearDateRange() for why: schools change their
     * own calendars, so this module never assumes when a semester starts.
     */
    private function validateClassDatesWithinAcademicYear(Validator $validator): void
    {
        $startYear = $this->input('start_year');

        // Let the required/integer rules above report this first.
        if (! $startYear || ! is_numeric($startYear)) {
            return;
        }

        $range = AcademicTerm::academicYearDateRange((int) $startYear);

        $start = $this->input('class_start_date');
        $end = $this->input('class_end_date');

        if ($start && ($start < $range['min'] || $start > $range['max'])) {
            $validator->errors()->add(
                'class_start_date',
                "Class Start must fall within the Academic Year ({$range['min']} to {$range['max']})."
            );
        }

        if ($end && ($end < $range['min'] || $end > $range['max'])) {
            $validator->errors()->add(
                'class_end_date',
                "Class End must fall within the Academic Year ({$range['min']} to {$range['max']})."
            );
        }
    }

    /**
     * If a full Lunch Break is present, it must sit inside School Hours.
     */
    private function validateLunchWithinSchoolHours(Validator $validator): void
    {
        $lunchStart = $this->input('lunch_start_time');
        $lunchEnd = $this->input('lunch_end_time');
        $schoolStart = $this->input('school_start_time');
        $schoolEnd = $this->input('school_end_time');

        if (! $lunchStart || ! $lunchEnd || ! $schoolStart || ! $schoolEnd) {
            return;
        }

        if ($lunchStart < $schoolStart || $lunchEnd > $schoolEnd) {
            $validator->errors()->add(
                'lunch_start_time',
                'Lunch Break must fall within School Hours.'
            );
        }
    }

    /**
     * No Academic Term's Class Start / Class End range may overlap
     * another's — regardless of Academic Year or Semester. This is the
     * primary validation requested for this refactor. The current record
     * is ignored on Edit via route model binding.
     */
    private function validateNoOverlap(Validator $validator): void
    {
        $start = $this->input('class_start_date');
        $end = $this->input('class_end_date');

        if (! $start || ! $end) {
            return;
        }

        $academicTerm = $this->route('academic_term');

        $conflict = app(AcademicTermOverlapService::class)
            ->conflicting($start, $end, $academicTerm?->id);

        if ($conflict) {
            $message = 'This Academic Term overlaps an existing Academic Term.';

            $validator->errors()->add('class_start_date', $message);
            $validator->errors()->add('class_end_date', $message);
        }
    }

    /**
     * Only a Published term may be marked Active — Draft terms aren't
     * ready yet, and Archived terms are historical. This keeps the
     * Draft -> Published -> Active -> Archived workflow honest.
     */
    private function validateActiveRequiresPublished(Validator $validator): void
    {
        if ($this->boolean('active') && $this->input('status') !== 'Published') {
            $validator->errors()->add(
                'active',
                'Only a Published Academic Term can be set as Active.'
            );
        }
    }

    /**
     * At least one Working Day must be selected — an Academic Term with
     * every day off has no days for the scheduler to place classes on at
     * all, which would make every future Subject Offering unschedulable.
     */
    private function validateAtLeastOneWorkingDay(Validator $validator): void
    {
        $days = [
            'monday', 'tuesday', 'wednesday', 'thursday',
            'friday', 'saturday', 'sunday',
        ];

        $anySelected = collect($days)->contains(fn ($day) => $this->boolean($day));

        if (! $anySelected) {
            $validator->errors()->add(
                'monday',
                'At least one Working Day must be selected.'
            );
        }
    }

    /**
     * Builds "2026-2027" from the (still unvalidated at rule-build time)
     * start_year input, for use inside the semester uniqueness scope.
     */
    private function buildAcademicYear(): ?string
    {
        $startYear = $this->input('start_year');

        if (! is_numeric($startYear)) {
            return null;
        }

        return $startYear . '-' . ((int) $startYear + 1);
    }

    /**
     * Validated data, ready to persist: start_year is replaced with the
     * concatenated academic_year string the model actually stores.
     */
    public function validatedForSave(): array
    {
        $validated = $this->validated();

        $startYear = (int) $validated['start_year'];
        unset($validated['start_year']);

        $validated['academic_year'] = $startYear . '-' . ($startYear + 1);

        return $validated;
    }
}