<script setup>
import { computed } from 'vue'
import { useTimetableGrid } from '@/Composables/useTimetableGrid'
import { collegeClasses } from '@/Utils/collegeColors'
import RoomUtilizationOverview from '@/Components/RoomUtilizationOverview.vue'

const props = defineProps({
    academicTerm: { type: Object, default: null },
    selectedRoom: { type: Object, default: null },
    // All rooms for this term, with the same utilization fields
    // RoomSidebar already renders per-card — used only to populate the
    // "no room selected yet" overview below, never the grid itself.
    rooms: { type: Array, default: () => [] },
    // Future Greedy output: [{ subject_offering_id, day, start_minutes,
    //   end_minutes, room_id, subject_code, section_code, faculty_name,
    //   college_code }]. Empty today — nothing generates schedules yet.
    scheduledEvents: { type: Array, default: () => [] },
    collegeColors: { type: Object, default: () => ({}) },
    // Phase 2 — Interactive Schedule Review: subject_offering_ids that
    // currently have a conflict (set right before Save Schedule when a
    // block still fails validation), so they can be highlighted red
    // without the caller having to touch each block's own data.
    conflictingIds: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    // Admin/Registrar only — gates whether empty grid cells accept a
    // drop at all. Mirrors SubjectSidebar's canManage: Dean/Assistant
    // Dean/OIC can still open a block read-only by clicking it
    // (editable, above), they just can't drag a new one in.
    canManage: { type: Boolean, default: false },
    // The Subject Offering currently being dragged from SubjectSidebar
    // (see Index.vue's drag-start/drag-end wiring), or null when
    // nothing is being dragged. Native DataTransfer payloads can't be
    // read during dragover for security reasons — this is how the
    // grid knows WHICH subject is hovering over it while the drag is
    // still in progress, not only after the drop. Used by
    // dropIncompatibilityReason below for both the Room Type and
    // Allowed Programs checks.
    draggedOffering: { type: Object, default: null },
})

const emit = defineEmits(['edit-block', 'select-room', 'drop-subject', 'drop-rejected'])

/**
 * Whether the Room currently in view can't host the subject being
 * dragged, and why — checked in the same order, and against the same
 * two independent facts, as ScheduleValidationService's server-side
 * rule:
 *
 *   1. Room Type (Lecture/Laboratory) — a subject with no room_type
 *      set has no requirement and is compatible with any room.
 *   2. Allowed Programs (room_group_codes) — a room can be General
 *      (every program), Shared (a few programs), or Exclusive (one
 *      program), completely independent of its Room Type. Two rooms
 *      can both be "Laboratory" while only one of them actually
 *      allows a given program — e.g. Ground Zero is Laboratory AND
 *      Exclusive to BSCRIM, so a BSIT Laboratory subject fails this
 *      check even though it would pass the Room Type check above.
 *
 * Returns null when compatible (or when there's nothing being dragged/
 * no room selected yet), otherwise a ready-to-display reason string.
 */
const dropIncompatibilityReason = computed(() => {
    if (!props.draggedOffering || !props.selectedRoom) return null

    const requiredType = props.draggedOffering.room_type
    if (requiredType && requiredType !== props.selectedRoom.room_type) {
        return `${props.draggedOffering.subject_code} requires a ${requiredType} room — `
            + `${props.selectedRoom.room_code} is a ${props.selectedRoom.room_type} room.`
    }

    const programCode = props.draggedOffering.program_code
    const allowedCodes = props.selectedRoom.room_group_codes ?? []
    const isAllowed = allowedCodes.includes('General') || (!!programCode && allowedCodes.includes(programCode))

    if (programCode && !isAllowed) {
        const reservedFor = allowedCodes.length ? allowedCodes.join(', ') : 'a different program'
        return `${props.selectedRoom.room_code} is not allowed for ${programCode} — it's reserved for ${reservedFor}.`
    }

    return null
})

const dropIncompatible = computed(() => dropIncompatibilityReason.value !== null)

/**
 * Drag-and-drop placement — a subject dragged in from SubjectSidebar
 * lands on one specific empty grid cell, which already knows exactly
 * which `day` and `row.startMinutes` it represents (see the v-for
 * below). onDrop reads back the subject_offering_id SubjectSidebar's
 * dragstart handler stashed in the native DataTransfer, and hands
 * Index.vue everything it needs to build a synthetic "fresh
 * placement" block — the actual faculty/room/day/time FORM still
 * happens in EditScheduleModal afterward, this is only the "which
 * subject, which cell" handoff.
 */
function onDragOver(event) {
    if (!props.canManage || !props.selectedRoom) return

    // Deliberately do NOT call preventDefault() when incompatible —
    // leaving dragover's default action in place is what makes the
    // browser itself refuse the drop (cursor shows "not-allowed", and
    // no 'drop' event ever fires on this cell). This is real
    // prevention, not just an error shown afterward.
    if (dropIncompatible.value) {
        event.dataTransfer.dropEffect = 'none'
        return
    }

    event.preventDefault()
    event.dataTransfer.dropEffect = 'copy'
}

function onDrop(event, day, row) {
    if (!props.canManage || !props.selectedRoom) return
    event.preventDefault()

    let payload
    try {
        payload = JSON.parse(event.dataTransfer.getData('application/json') || '{}')
    } catch {
        return
    }

    if (!payload?.subjectOfferingId) return

    // Defense in depth — onDragOver already stops this in every
    // standards-compliant browser, but re-check here in case
    // dropEffect was ever ignored, so an incompatible room can never
    // actually be dropped into, only reported as rejected.
    if (dropIncompatible.value) {
        emit('drop-rejected', {
            subjectOfferingId: payload.subjectOfferingId,
            reason: dropIncompatibilityReason.value,
        })
        return
    }

    emit('drop-subject', {
        subjectOfferingId: payload.subjectOfferingId,
        roomId: props.selectedRoom.id,
        day: day.field,
        startMinutes: row.startMinutes,
    })
}

function isConflicting(event) {
    return props.conflictingIds.includes(event.subject_offering_id)
}

const { workingDays, timeRows } = useTimetableGrid(computed(() => props.academicTerm))

/**
 * "Room View" — when a room is selected, only events assigned to
 * that room are shown. With no Greedy Scheduler yet, scheduledEvents
 * is always empty, but the filtering logic is ready for when it
 * isn't.
 */
const visibleEvents = computed(() => {
    // No room selected -> show nothing, rather than every room's
    // events overlaid into the same day/time cells. The grid has no
    // per-room column of its own; overlaying everything by default
    // made two totally unrelated classes in two different rooms LOOK
    // like a double-booking at a glance, when in reality only clicking
    // a specific Room in the sidebar (see Index.vue's selectRoom())
    // narrows this to a single room's real timetable. "Nothing
    // selected" and "empty grid" now mean the same thing, instead of
    // "nothing selected" secretly meaning "show literally everything."
    if (!props.selectedRoom) return []
    return props.scheduledEvents.filter((event) => event.room_id === props.selectedRoom.id)
})

// "8:00 AM - 8:30 AM" -> ["8:00 AM", "8:30 AM"] — stacked on two lines in
// the Time column instead of one long line, so it can't run wide enough
// to creep into the Monday column next to it.
function splitLabel(label) {
    return label.split(' - ')
}

// 480 -> "8:00 AM" — same 12-hour formatting used elsewhere in the
// workspace (see EditScheduleModal's time pickers), so an event
// block's own time range reads the same way as everything around it.
function formatMinutes(minutes) {
    const h = Math.floor(minutes / 60)
    const m = minutes % 60
    const period = h >= 12 ? 'PM' : 'AM'
    const h12 = h % 12 === 0 ? 12 : h % 12
    return `${h12}:${String(m).padStart(2, '0')} ${period}`
}

function timeRangeLabel(event) {
    return `${formatMinutes(event.start_minutes)} – ${formatMinutes(event.end_minutes)}`
}

/*
|--------------------------------------------------------------------------
| Grid row/column geometry
|--------------------------------------------------------------------------
|
| Every row in timeRows (regular slots AND the lunch row) occupies
| exactly one CSS grid row line, in order, starting right after the
| header row. Row at array index i therefore starts at grid line
| (i + 2) — +1 because grid lines are 1-indexed, +1 more because the
| header itself sits on line 1.
|
| This is what lets a single multi-slot event render as ONE block
| spanning several rows (via grid-row: start / end) instead of being
| repeated once per overlapping row, which is what a plain <table>
| forces — every <tr> is its own independent cell, with no concept of
| "this cell continues into the next row."
*/
const rowLineByStartMinutes = computed(() => {
    const map = new Map()
    timeRows.value.forEach((row, index) => {
        // Every row — including the lunch band — occupies a real grid
        // line (see the v-for below, which renders a cell/band for
        // EVERY row in order). Excluding lunch here used to mean any
        // event ending exactly when lunch starts (e.g. 8:00 AM –
        // 12:00 PM) couldn't resolve its end line, fell back to
        // finalLine, and got stretched all the way to the bottom of
        // the table instead of stopping at lunch.
        map.set(row.startMinutes, index + 2)
    })
    return map
})

// One past the last row's line — the fallback end-line for an event
// that runs all the way to the end of the school day (i.e. there's no
// "next row" whose startMinutes to key off of).
const finalLine = computed(() => timeRows.value.length + 2)

function lineForStart(minutes) {
    return rowLineByStartMinutes.value.get(minutes) ?? finalLine.value
}

function lineForEnd(minutes) {
    return rowLineByStartMinutes.value.get(minutes) ?? finalLine.value
}

function dayColumnIndex(dayField) {
    return workingDays.value.findIndex((d) => d.field === dayField)
}

/**
 * Every visible event, pre-computed with its grid placement. Placed
 * on the day's column (offset by 1 for the sticky Time column) and
 * spanning from its start row's line to its end row's line — this is
 * the piece that actually merges consecutive slots into one block.
 */
const positionedEvents = computed(() =>
    visibleEvents.value
        .map((event) => {
            const dayIndex = dayColumnIndex(event.day)
            if (dayIndex === -1) return null

            return {
                event,
                gridColumn: `${dayIndex + 2} / span 1`,
                gridRow: `${lineForStart(event.start_minutes)} / ${lineForEnd(event.end_minutes)}`,
            }
        })
        .filter(Boolean)
)

const gridTemplateColumns = computed(
    () => `72px repeat(${workingDays.value.length}, minmax(0, 1fr))`
)
</script>

<template>
    <div class="timetable-wrapper p-4">
        <div v-if="timeRows.length === 0" class="p-10 text-center text-sm text-slate-400">
            This Academic Term has no valid school hours configured yet.
        </div>

        <RoomUtilizationOverview
            v-else-if="!selectedRoom"
            :rooms="rooms"
            @select="(room) => emit('select-room', room)"
        />

        <div v-else class="min-w-[660px]">
            <div
                v-if="draggedOffering && dropIncompatible"
                class="mb-2 flex items-center gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300"
            >
                <span>🚫</span>
                <span>{{ dropIncompatibilityReason }} Drop is disabled here.</span>
            </div>

            <div
                class="timetable-grid grid border-separate select-none"
                :class="draggedOffering && dropIncompatible ? 'opacity-60 grayscale' : ''"
                :style="{ gridTemplateColumns }"
            >
                <!-- Header row -->
                <div
                    class="timetable-time-col sticky left-0 top-0 bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-600 px-3 py-2 text-[11px] font-black uppercase tracking-wider text-black dark:text-slate-300 z-20"
                    style="grid-column: 1; grid-row: 1;"
                >
                    Time
                </div>
                <div
                    v-for="(day, dIndex) in workingDays"
                    :key="day.field"
                    class="border border-slate-300 dark:border-slate-600 bg-slate-100 dark:bg-slate-800 px-2 py-2 text-[11px] font-black uppercase tracking-wider text-black dark:text-slate-300 z-10"
                    :style="{ gridColumn: dIndex + 2, gridRow: 1 }"
                >
                    {{ day.label }}
                </div>

                <!-- Row backgrounds/borders + time labels + lunch band —
                     rendered per row so grid lines/borders exist even
                     where no event covers a cell. -->
                <template v-for="(row, rIndex) in timeRows" :key="row.key">
                    <div
                        class="timetable-time-col sticky left-0 border border-slate-300 dark:border-slate-600 px-2 py-1 text-[10px] font-semibold text-black dark:text-slate-400 z-10"
                        :class="row.type === 'lunch' ? 'bg-slate-50 dark:bg-slate-800/60' : 'bg-white dark:bg-slate-900'"
                        :style="{ gridColumn: 1, gridRow: rIndex + 2 }"
                    >
                        <div class="flex flex-col leading-tight">
                            <span>{{ splitLabel(row.label)[0] }}</span>
                            <span>{{ splitLabel(row.label)[1] }}</span>
                        </div>
                    </div>

                    <!-- Lunch spans every day column in one band -->
                    <div
                        v-if="row.type === 'lunch'"
                        class="border border-slate-300 dark:border-slate-600 bg-slate-100/70 dark:bg-slate-800/40 flex items-center justify-center"
                        :style="{ gridColumn: `2 / span ${workingDays.length}`, gridRow: rIndex + 2 }"
                    >
                        <span class="text-[11px] font-black uppercase tracking-[0.2em] text-black dark:text-slate-400">
                            Lunch Break
                        </span>
                    </div>

                    <!-- Otherwise one empty bordered cell per day, purely
                         for the grid lines — events are drawn separately
                         below, on top of these. -->
                    <div
                        v-else
                        v-for="(day, dIndex) in workingDays"
                        :key="day.field + row.key"
                        class="timetable-cell border border-slate-300 dark:border-slate-600 h-[28px]"
                        :class="canManage && selectedRoom
                            ? (draggedOffering && dropIncompatible
                                ? 'cursor-not-allowed'
                                : 'hover:bg-blue-50 dark:hover:bg-blue-500/10')
                            : ''"
                        :style="{ gridColumn: dIndex + 2, gridRow: rIndex + 2 }"
                        @dragover="onDragOver"
                        @drop="onDrop($event, day, row)"
                    ></div>
                </template>

                <!-- Scheduled event blocks — each rendered exactly ONCE,
                     spanning every row it actually covers, instead of
                     being repeated per overlapping row. -->
                <div
                    v-for="{ event, gridColumn, gridRow } in positionedEvents"
                    :key="event.subject_offering_id + '-' + event.day"
                    class="rounded-md border px-2 py-1.5 m-0.5 overflow-hidden z-[5] flex flex-col items-center justify-center text-center gap-0.5 !text-black dark:!text-black"
                    :class="[
                        collegeClasses(event.college_code).block,
                        editable ? 'cursor-pointer hover:ring-2 hover:ring-blue-400' : 'cursor-default',
                        isConflicting(event) ? '!border-red-500 !bg-red-100 dark:!bg-red-900/40 ring-2 ring-red-500' : '',
                    ]"
                    :style="{ gridColumn, gridRow }"
                    @click="editable && emit('edit-block', event)"
                >
                    <p class="font-black text-[13px] leading-tight">
                        {{ event.subject_code }} · {{ event.section_code }}
                        <span
                            v-if="event.is_irregular"
                            class="ml-0.5 px-1 py-0.5 rounded-full text-[8px] font-black uppercase align-middle"
                            style="background: rgba(212, 166, 42, 0.2); color: #92700f; border: 1px solid rgba(212, 166, 42, 0.4)"
                            title="Irregular Section"
                        >Irr</span>
                    </p>

                    <span
                        v-if="event.classification"
                        class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wide border"
                        :class="event.classification === 'Major'
                            ? 'bg-amber-100 border-amber-300 text-amber-800 dark:bg-amber-900/40 dark:border-amber-700 dark:text-amber-300'
                            : 'bg-sky-100 border-sky-300 text-sky-800 dark:bg-sky-900/40 dark:border-sky-700 dark:text-sky-300'"
                    >
                        {{ event.classification }}
                    </span>

                    <p class="text-[11px] font-bold leading-tight">{{ timeRangeLabel(event) }}</p>
                    <p class="truncate text-[11px] font-semibold leading-tight">{{ event.faculty_name ?? 'Unassigned' }}</p>
                    <p class="truncate text-[11px] font-semibold leading-tight">{{ event.room_code ?? 'Unassigned' }}</p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Explicit 72px on the sticky Time column; every day column then
   splits the remaining width evenly via minmax(0, 1fr) in
   gridTemplateColumns above — so columns stretch/shrink as sidebars
   toggle, same behavior as the old table-fixed layout. */
.timetable-time-col {
    width: 72px;
}
</style>