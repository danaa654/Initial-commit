<?php

namespace App\Services;

use App\Models\AcademicTerm;

/**
 * Extracted out of AcademicTermRequest so the overlap rule is a single,
 * reusable, independently-testable piece of logic rather than inline
 * validator code — per the "extract into a reusable service/helper"
 * requirement.
 *
 * Two Academic Terms are considered overlapping purely by their
 * Class Start / Class End date ranges — regardless of Academic Year or
 * Semester. A school cannot be running two terms' classes on the same
 * calendar day, even if those terms belong to different "SY" labels.
 *
 * Draft terms are excluded from the "other side" of this check (see
 * AcademicTerm::scopeNotDraft()) — a Draft is just a tentative sketch
 * that hasn't been committed to yet, so two Drafts (or a Draft and the
 * term currently being edited) are allowed to tentatively share dates
 * while someone is still planning. The moment either one is Published,
 * it becomes a real commitment and nothing may overlap it anymore. The
 * record being saved is always checked against that non-Draft set,
 * regardless of what status it itself is being saved as — this stops a
 * Draft from quietly being saved on top of dates that are already
 * spoken for, only to hit the wall later at Publish time.
 */
class AcademicTermOverlapService
{
    /**
     * Whether the given [start, end] range overlaps any other Academic
     * Term's Class Start / Class End range.
     */
    public function overlaps(string $start, string $end, ?int $ignoreId = null): bool
    {
        return $this->conflicting($start, $end, $ignoreId) !== null;
    }

    /**
     * Returns the first non-Draft Academic Term whose Class Start / Class
     * End range conflicts with the given range, or null if there's no
     * conflict.
     *
     * Standard interval-overlap test: two ranges [aStart, aEnd] and
     * [bStart, bEnd] overlap when aStart <= bEnd AND aEnd >= bStart.
     */
    public function conflicting(string $start, string $end, ?int $ignoreId = null): ?AcademicTerm
    {
        return AcademicTerm::query()
            ->notDraft()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('class_start_date', '<=', $end)
            ->where('class_end_date', '>=', $start)
            ->first();
    }
}