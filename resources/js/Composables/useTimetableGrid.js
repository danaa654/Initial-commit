import { computed } from 'vue'

/**
 * Builds the Master Grid timetable structure entirely from the active
 * Academic Term — school hours, lunch window, interval, and working
 * days. Nothing here is hardcoded; if the Academic Term changes, the
 * grid changes with it automatically.
 *
 * academicTerm fields consumed (see AcademicTerm.php casts):
 *   school_start_time, school_end_time  -> "H:i" strings, e.g. "08:00"
 *   lunch_start_time, lunch_end_time    -> "H:i" strings
 *   time_interval                        -> minutes (15|30|60)
 *   monday..sunday                       -> booleans
 */
export function useTimetableGrid(academicTerm) {
    const DAY_ORDER = [
        ['monday', 'Mon'],
        ['tuesday', 'Tue'],
        ['wednesday', 'Wed'],
        ['thursday', 'Thu'],
        ['friday', 'Fri'],
        ['saturday', 'Sat'],
        ['sunday', 'Sun'],
    ]

    function toMinutes(hhmm) {
        if (!hhmm) return null
        const [h, m] = hhmm.split(':').map(Number)
        return h * 60 + m
    }

    function toLabel(totalMinutes) {
        const h24 = Math.floor(totalMinutes / 60) % 24
        const m = totalMinutes % 60
        const period = h24 >= 12 ? 'PM' : 'AM'
        const h12 = h24 % 12 === 0 ? 12 : h24 % 12
        return `${h12}:${String(m).padStart(2, '0')} ${period}`
    }

    /**
     * Working day columns, in Mon->Sun order, filtered to whichever
     * days the Academic Term has enabled. Order and presence come
     * entirely from the term — e.g. Saturday only appears if
     * academicTerm.saturday is true.
     */
    const workingDays = computed(() => {
        const term = academicTerm.value ?? academicTerm
        if (!term) return []

        return DAY_ORDER
            .filter(([field]) => !!term[field])
            .map(([field, label]) => ({ field, label }))
    })

    /**
     * Time rows for the grid — one row per interval between School
     * Start and School End, with a single collapsed "Lunch Break" row
     * standing in for the whole lunch window (no scheduling allowed
     * inside it).
     */
    const timeRows = computed(() => {
        const term = academicTerm.value ?? academicTerm
        if (!term) return []

        const start = toMinutes(term.school_start_time)
        const end = toMinutes(term.school_end_time)
        const lunchStart = toMinutes(term.lunch_start_time)
        const lunchEnd = toMinutes(term.lunch_end_time)
        const interval = term.time_interval ?? 30

        if (start === null || end === null || !interval) return []

        const rows = []
        let cursor = start
        let lunchInserted = false

        while (cursor < end) {
            const isLunchStart = lunchStart !== null && cursor === lunchStart

            if (isLunchStart && !lunchInserted) {
                rows.push({
                    key: `lunch-${lunchStart}`,
                    type: 'lunch',
                    label: `${toLabel(lunchStart)} - ${toLabel(lunchEnd)}`,
                    startMinutes: lunchStart,
                    endMinutes: lunchEnd,
                })
                lunchInserted = true
                cursor = lunchEnd
                continue
            }

            const slotEnd = Math.min(cursor + interval, end)

            rows.push({
                key: `slot-${cursor}`,
                type: 'slot',
                label: `${toLabel(cursor)} - ${toLabel(slotEnd)}`,
                startMinutes: cursor,
                endMinutes: slotEnd,
            })

            cursor = slotEnd
        }

        return rows
    })

    return {
        workingDays,
        timeRows,
    }
}