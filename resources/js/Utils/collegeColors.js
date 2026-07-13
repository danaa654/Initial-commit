/**
 * Centralized College => color mapping.
 *
 * This MUST stay the single source every component reads from —
 * SubjectSidebar, RoomSidebar, and Timetable event blocks all call
 * into this file rather than picking their own colors. It mirrors
 * MasterGridDataService::collegeColorMap() on the backend; update
 * BOTH places if this ever changes.
 *
 * The backend already resolves each Subject Offering / Room down to
 * a single `college_code` (e.g. "CCS", "General", "Shared") — this
 * file only maps that code to a Tailwind color family.
 */

/**
 * Program code => the College it belongs to. Only used to color a
 * Room's "Allowed: BSHM, BSTM" programs list to match that program's
 * College — e.g. BSHM/BSTM (SHTM) render orange, BSCRIM (CRIM)
 * renders violet, the same colors already used for their Rooms/
 * Subjects/Timetable blocks elsewhere. Extend this map as more
 * programs need color-coding; anything not listed here just falls
 * back to the caller's own default (see programTextClass below).
 *
 * BSCRIM's specializations (FI/LD/QD/FB) are listed both bare and
 * prefixed ("BSCRIM-FI") since it's not confirmed which form Rooms'
 * room_group_codes actually store them in — harmless to map both.
 */
const PROGRAM_COLLEGE_MAP = {
    BSHM: 'SHTM',
    BSTM: 'SHTM',
    BSCRIM: 'CRIM',
    FI: 'CRIM',
    LD: 'CRIM',
    QD: 'CRIM',
    FB: 'CRIM',
    'BSCRIM-FI': 'CRIM',
    'BSCRIM-LD': 'CRIM',
    'BSCRIM-QD': 'CRIM',
    'BSCRIM-FB': 'CRIM',
}

export const COLLEGE_COLORS = {
    CCS: 'yellow',
    CRIM: 'purple',
    CTE: 'blue',
    SHTM: 'orange',
    CBA: 'green',
    General: 'gray',
    Shared: 'gray',
}

/**
 * Tailwind utility classes for each color family, precomputed so
 * components never string-template class names (which Tailwind's
 * JIT purge can't detect).
 */
const COLOR_CLASSES = {
    yellow: {
        bg: 'bg-amber-50',
        border: 'border-amber-300',
        hoverBorder: 'hover:border-amber-400 dark:hover:border-amber-500',
        ring: 'ring-amber-300',
        text: 'text-amber-800',
        textAdaptive: 'text-amber-800 dark:text-amber-300',
        badge: 'bg-amber-100 text-amber-800 border-amber-300',
        dot: 'bg-amber-400',
        gradient: 'from-amber-400 to-amber-500',
        block: 'bg-amber-100 border-amber-400 text-amber-900',
    },
    purple: {
        bg: 'bg-purple-50',
        border: 'border-purple-300',
        hoverBorder: 'hover:border-purple-400 dark:hover:border-purple-500',
        ring: 'ring-purple-300',
        text: 'text-purple-800',
        textAdaptive: 'text-purple-800 dark:text-purple-300',
        badge: 'bg-purple-200 text-purple-900 border-purple-400',
        dot: 'bg-purple-400',
        gradient: 'from-purple-400 to-purple-500',
        block: 'bg-purple-100 border-purple-400 text-purple-900',
    },
    blue: {
        bg: 'bg-blue-50',
        border: 'border-blue-300',
        hoverBorder: 'hover:border-blue-400 dark:hover:border-blue-500',
        ring: 'ring-blue-300',
        text: 'text-blue-800',
        textAdaptive: 'text-blue-800 dark:text-blue-300',
        badge: 'bg-blue-200 text-blue-900 border-blue-400',
        dot: 'bg-blue-400',
        gradient: 'from-blue-400 to-blue-500',
        block: 'bg-blue-100 border-blue-400 text-blue-900',
    },
    orange: {
        bg: 'bg-orange-50',
        border: 'border-orange-300',
        hoverBorder: 'hover:border-orange-400 dark:hover:border-orange-500',
        ring: 'ring-orange-300',
        text: 'text-orange-800',
        textAdaptive: 'text-orange-800 dark:text-orange-300',
        badge: 'bg-orange-200 text-orange-900 border-orange-400',
        dot: 'bg-orange-400',
        gradient: 'from-orange-400 to-orange-500',
        block: 'bg-orange-100 border-orange-400 text-orange-900',
    },
    green: {
        bg: 'bg-emerald-50',
        border: 'border-emerald-300',
        hoverBorder: 'hover:border-emerald-400 dark:hover:border-emerald-500',
        ring: 'ring-emerald-300',
        text: 'text-emerald-800',
        textAdaptive: 'text-emerald-800 dark:text-emerald-300',
        badge: 'bg-emerald-100 text-emerald-800 border-emerald-300',
        dot: 'bg-emerald-400',
        gradient: 'from-emerald-400 to-emerald-500',
        block: 'bg-emerald-100 border-emerald-400 text-emerald-900',
    },
    gray: {
        bg: 'bg-slate-50',
        border: 'border-slate-300',
        hoverBorder: 'hover:border-slate-400 dark:hover:border-slate-500',
        ring: 'ring-slate-300',
        text: 'text-slate-700',
        textAdaptive: 'text-slate-700 dark:text-slate-300',
        badge: 'bg-slate-100 text-slate-700 border-slate-300',
        dot: 'bg-slate-400',
        gradient: 'from-slate-400 to-slate-500',
        block: 'bg-slate-100 border-slate-400 text-slate-800',
    },
}

/**
 * True for any college_code this map can't confidently color on its
 * own: the literal "Shared" string, but also null/undefined/empty
 * (some Rooms come back with no college_code at all) or any code
 * COLLEGE_COLORS doesn't recognize. All of these should attempt the
 * program-based resolution below rather than silently defaulting to
 * gray.
 */
function isUnresolvedCollege(collegeCode) {
    return !collegeCode || collegeCode === 'Shared' || !(collegeCode in COLLEGE_COLORS)
}

/**
 * If every program in programCodes belongs to the same College (e.g.
 * only BSHM + BSTM, both SHTM, or only BSCRIM's FI/LD/QD/FB, all
 * CRIM), returns that College's code. Returns null when the programs
 * genuinely span more than one College, or none are recognized —
 * i.e. a true cross-college (or unresolvable) shared room, which
 * callers should treat as "no single answer" rather than guessing.
 */
function singleResolvedCollege(programCodes = []) {
    const colleges = new Set(
        programCodes
            .map((code) => PROGRAM_COLLEGE_MAP[code])
            .filter(Boolean)
    )

    return colleges.size === 1 ? [...colleges][0] : null
}

/**
 * Resolve a college_code (as sent from MasterGridDataService) to its
 * Tailwind class set.
 *
 * programCodes is optional and only matters when collegeCode is
 * Shared/blank/unrecognized — pass the Room's room_group_codes (or a
 * Subject's applicable programs) so a same-College shared room/
 * subject can be colored by that College instead of a flat,
 * meaningless gray. Falls back to green (not gray) when the programs
 * genuinely span more than one College — a true cross-college shared
 * room.
 */
export function collegeClasses(collegeCode, programCodes = []) {
    if (isUnresolvedCollege(collegeCode)) {
        const resolved = singleResolvedCollege(programCodes)
        return COLOR_CLASSES[resolved ? COLLEGE_COLORS[resolved] : 'green']
    }

    return COLOR_CLASSES[COLLEGE_COLORS[collegeCode]]
}

/**
 * The text a badge should actually DISPLAY for a college_code — same
 * resolution as collegeClasses() above, but returning the College
 * code itself (e.g. "SHTM") instead of a class set, so a Room with a
 * blank/"Shared" college_code doesn't render an empty badge just
 * because its own college_code had nothing to print. Falls back to
 * the raw collegeCode (or "Shared") when no single College resolves.
 */
export function collegeLabel(collegeCode, programCodes = []) {
    if (!isUnresolvedCollege(collegeCode)) return collegeCode

    return singleResolvedCollege(programCodes) ?? (collegeCode || 'Shared')
}

/**
 * Resolve a Program code (BSHM, BSTM, BSCRIM, etc.) to the
 * light/dark text color pair of the College it belongs to — e.g.
 * BSHM/BSTM (SHTM) => orange, BSCRIM (CRIM) => violet. Returns null
 * for any program not in PROGRAM_COLLEGE_MAP, so callers can fall
 * back to their own default (plain black/slate) instead of getting
 * an unrelated color guessed for them.
 */
export function programTextClass(programCode) {
    const collegeCode = PROGRAM_COLLEGE_MAP[programCode]
    if (!collegeCode) return null
    return collegeClasses(collegeCode).textAdaptive
}

export default {
    COLLEGE_COLORS,
    collegeClasses,
    collegeLabel,
    programTextClass,
}