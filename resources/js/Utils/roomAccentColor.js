/**
 * Solid, high-contrast College/Program accent colors — deliberately
 * separate from collegeColors.js's pastel-bg + dark-text combos.
 * Those pastel combos rely on Tailwind's dark: variants resolving
 * correctly and kept rendering as washed-out gray-on-gray in
 * practice. A solid fill + white label is contrast-proof regardless
 * of theme, so this is what any NEW room-color UI (RoomSidebar,
 * RoomUtilizationOverview) should use going forward.
 *
 * Mirrors the same 6 color families as COLLEGE_COLORS in
 * collegeColors.js — update both if a new College is added.
 */
const SOLID_HEX = {
    yellow: '#ca8a04',
    purple: '#7c3aed',
    blue: '#2563eb',
    orange: '#ea580c',
    green: '#059669',
    gray: '#475569',
}

const COLLEGE_FAMILY = {
    CCS: 'yellow',
    CRIM: 'purple',
    CTE: 'blue',
    SHTM: 'orange',
    CBA: 'green',
    General: 'gray',
    Shared: 'gray',
}

const PROGRAM_FAMILY = {
    BSHM: 'orange',
    BSTM: 'orange',
    BSCRIM: 'purple',
    FI: 'purple',
    LD: 'purple',
    QD: 'purple',
    FB: 'purple',
    'BSCRIM-FI': 'purple',
    'BSCRIM-LD': 'purple',
    'BSCRIM-QD': 'purple',
    'BSCRIM-FB': 'purple',
}

/**
 * Resolves a college_code (+ optional program/room-group codes for
 * Shared/blank rooms) down to one of the 6 solid color families.
 * Falls back to 'gray' when nothing resolves or programs span more
 * than one College — same "true cross-college" fallback logic as
 * collegeColors.js's singleResolvedCollege(), just returning gray
 * instead of green here for a calmer default.
 */
export function accentFamily(collegeCode, programCodes = []) {
    if (collegeCode && collegeCode !== 'Shared' && COLLEGE_FAMILY[collegeCode]) {
        return COLLEGE_FAMILY[collegeCode]
    }

    const families = new Set(programCodes.map((code) => PROGRAM_FAMILY[code]).filter(Boolean))

    return families.size === 1 ? [...families][0] : 'gray'
}

/**
 * The actual hex value for a college_code (+ optional program codes)
 * — use directly in :style bindings for badges, left-border stripes,
 * dots, etc.
 */
export function accentColor(collegeCode, programCodes = []) {
    return SOLID_HEX[accentFamily(collegeCode, programCodes)]
}

export default { accentFamily, accentColor }