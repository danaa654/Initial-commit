/**
 * Client-side mirror of the generation rules in
 * app/Http/Controllers/SectionController.php and
 * app/Services/SectionCodeService.php.
 *
 * This ONLY drives the live "Generated Section Code" / "Section Name"
 * previews and frontend UX (disabling taken letters, etc.) on the
 * Create/Edit forms — the server always re-generates and validates the
 * real values on submit, so this never needs to be perfectly
 * authoritative, just consistent with the backend. Keep both in sync if
 * the generation rules ever change.
 */

// Five sections max per Program + Year Level (+ Specialization). See
// SectionController::ALLOWED_LETTERS.
export const SECTION_LETTERS = ['A', 'B', 'C', 'D', 'E']

/**
 * Whether a Program requires a Specialization to be selected before a
 * Section Code/Name can be generated.
 *
 * Data-driven, not hardcoded to any particular Program code — mirrors
 * SectionCodeService::requiresSpecialization() on the backend exactly:
 * a Program "requires" a Specialization simply by having one or more
 * active Specializations of its own (BSCRIM, BSED, or any future
 * Program set up the same way, e.g. a BSIT specialization added later).
 * A Program with no active Specializations is treated as
 * specialization-less for Section purposes.
 *
 * `program.specializations` is expected to already be scoped to active
 * ones only — see SectionController::programOptions(), which eager
 * loads `specializations` filtered to `active = true`. If a caller ever
 * passes a Program whose `specializations` includes inactive rows too,
 * this still filters defensively via `spec.active` when that field is
 * present.
 */
export function requiresSpecialization(program) {
    if (!program) {
        return false
    }

    const specializations = program.specializations ?? []

    return specializations.some(spec => spec.active ?? true)
}

/**
 * Build the Section Code preview string.
 *
 * Returns null when there isn't enough information yet (missing
 * Program, Year Level, Letter, or — for a Program with active
 * Specializations — Specialization).
 */
export function generateSectionCode({ program, specialization, yearLevel, letter }) {
    if (!program || !yearLevel || !letter) {
        return null
    }

    const upperLetter = letter.toString().toUpperCase()

    if (requiresSpecialization(program)) {
        if (!specialization || !specialization.code) {
            return null
        }

        return `${program.code.toUpperCase()}-${specialization.code.toUpperCase()}-${yearLevel}${upperLetter}`
    }

    return `${program.code.toUpperCase()}-${yearLevel}${upperLetter}`
}

/**
 * "Bachelor of Science in Information Technology" -> "BS Information Technology"
 * "Bachelor of Secondary Education" -> "BS Secondary Education"
 * "Bachelor of Science in Criminology" -> "BS Criminology"
 *
 * Generic on purpose — every program name in the system follows one of
 * these "Bachelor of ..." shapes, so a single regex strip covers all of
 * them without a per-program lookup table.
 */
function abbreviateProgramName(program) {
    if (!program?.name) {
        return program?.code ?? ''
    }

    return 'BS ' + program.name.replace(/^Bachelor of (Science in )?/i, '').trim()
}

/**
 * Clean, short display names for known specializations, used only for
 * the Section Name preview (e.g. "BS Criminology (Lie Detection) -
 * 4A"). Deliberately not the same string as specialization.name, which
 * may carry a longer qualifier (e.g. "Lie Detection (Polygraph)") — the
 * Section Name examples call for the short form specifically. Falls
 * back to specialization.name for any specialization (on any Program)
 * that isn't in this list yet — this list is a display nicety, not a
 * gate on which Programs support specializations.
 */
const SPECIALIZATION_SHORT_NAMES = {
    FI: 'Fingerprint Identification',
    FB: 'Firearms Identification',
    LD: 'Lie Detection',
    QD: 'Questioned Documents Examination',
}

function specializationDisplayName(specialization) {
    const short = SPECIALIZATION_SHORT_NAMES[specialization?.code?.toUpperCase()]
    return short ?? specialization?.name ?? ''
}

/**
 * Build the Section Name preview string, e.g.:
 *   "BS Information Technology - 1A"
 *   "BS Criminology (Lie Detection) - 4A"
 *   "BS Secondary Education (English) - 1A"
 *
 * Returns '' when there isn't enough information yet — callers should
 * treat that the same as generateSectionCode()'s null.
 */
export function generateSectionName({ program, specialization, yearLevel, letter }) {
    if (!program || !yearLevel || !letter) {
        return ''
    }

    const suffix = `${yearLevel}${letter.toString().toUpperCase()}`
    const base = abbreviateProgramName(program)

    if (requiresSpecialization(program)) {
        if (!specialization) {
            return ''
        }

        return `${base} (${specializationDisplayName(specialization)}) - ${suffix}`
    }

    return `${base} - ${suffix}`
}