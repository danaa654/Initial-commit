/**
 * useAcademicTermDates.js
 * -----------------------
 * Semester-aware date helpers for the Academic Term Create/Edit forms.
 *
 * IMPORTANT: This mirrors App\Models\AcademicTerm::semesterDateRange() on
 * the backend. The backend copy is the authoritative source of truth for
 * validation (it always re-checks on save), this copy exists purely so the
 * <input type="date"> pickers can restrict themselves client-side for a
 * better UX. If the semester calendar ever changes, update both places.
 *
 * Assumption (adjust here + backend if PAP's actual calendar differs):
 *   1st Semester -> Jun 1 - Dec 31 of the Start Year
 *   2nd Semester -> Jan 1 - May 31 of the following year
 *   Summer       -> Jun 1 - Jul 31 of the following year
 */

const SEMESTER_DATE_RULES = {
    1: { startMonthDay: '06-01', endMonthDay: '12-31', startYearOffset: 0, endYearOffset: 0 },
    2: { startMonthDay: '01-01', endMonthDay: '05-31', startYearOffset: 1, endYearOffset: 1 },
    3: { startMonthDay: '06-01', endMonthDay: '07-31', startYearOffset: 1, endYearOffset: 1 },
}

/**
 * Returns { min, max } ISO date strings (YYYY-MM-DD) allowed for the given
 * Start Year + Semester combination, or { min: null, max: null } if either
 * is missing/unrecognized.
 */
export function getSemesterDateRange(startYear, semester) {
    const rule = SEMESTER_DATE_RULES[Number(semester)]

    if (!startYear || !rule) {
        return { min: null, max: null }
    }

    const year = Number(startYear)

    return {
        min: `${year + rule.startYearOffset}-${rule.startMonthDay}`,
        max: `${year + rule.endYearOffset}-${rule.endMonthDay}`,
    }
}

/**
 * Builds the "2026-2027" Academic Year string from a Start Year.
 * Returns an empty string if Start Year is empty/invalid.
 */
export function buildAcademicYear(startYear) {
    if (!startYear && startYear !== 0) return ''

    const year = Number(startYear)

    if (!Number.isInteger(year)) return ''

    return `${year}-${year + 1}`
}

/**
 * True if `date` (an ISO "YYYY-MM-DD" string) falls inside [min, max]
 * inclusive. Returns true (i.e. "don't block it") if any bound is missing,
 * since that means we don't have enough info yet to judge validity.
 */
export function isDateWithinRange(date, min, max) {
    if (!date || !min || !max) return true

    return date >= min && date <= max
}