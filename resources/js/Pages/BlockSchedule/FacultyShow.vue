<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useTimetableGrid } from '@/Composables/useTimetableGrid'

const props = defineProps({
    department: Object,
    faculty: Object,
    assignments: Array,
    academicTerm: Object,
})

const viewMode = ref('list') // 'list' | 'overview'

function formatMinutes(minutes) {
    const h = Math.floor(minutes / 60)
    const m = minutes % 60
    const period = h >= 12 ? 'PM' : 'AM'
    const h12 = h % 12 === 0 ? 12 : h % 12
    return `${h12}:${String(m).padStart(2, '0')} ${period}`
}

function formatTime(minutes) {
    if (minutes === null || minutes === undefined) return null
    return formatMinutes(minutes)
}

function capitalize(day) {
    return day.charAt(0).toUpperCase() + day.slice(1)
}

function timeRange(row) {
    if (!row.days?.length) return 'Unscheduled'
    const dayList = row.days.map(capitalize).join(', ')
    return `${dayList} · ${formatTime(row.start_minutes)} – ${formatTime(row.end_minutes)}`
}

/*
|--------------------------------------------------------------------------
| Schedule Overview — same grid engine as Master Grid's Timetable.vue
|--------------------------------------------------------------------------
|
| Uses useTimetableGrid()'s full row model (workingDays + timeRows —
| one row per time_interval slot, exactly as configured on the Academic
| Term, plus a single collapsed Lunch Break row) instead of pixel math,
| so this Overview is built from the SAME time grid Master Grid itself
| generates a schedule against, not an approximation of it. A subject
| spanning several slots renders as one CSS-grid-spanning block (via
| grid-row: start / end), the same technique Timetable.vue uses to
| merge consecutive slots into a single visual block rather than
| repeating it once per slot.
*/
const { workingDays, timeRows } = useTimetableGrid(computed(() => props.academicTerm))

// "8:00 AM - 8:30 AM" -> "8:00–8:30 AM" (shared AM/PM dropped from the
// start side when both ends match), or "11:30 AM–12:00 PM" when they
// don't — keeps the FULL range visible on one compact line, instead of
// only showing the start time (which made the school day's last slot
// look like it stopped early when it actually ran to school_end_time).
function compactRange(label) {
    const [start, end] = label.split(' - ')
    const startPeriod = start.slice(-2)
    const endPeriod = end.slice(-2)

    if (startPeriod === endPeriod) {
        return `${start.slice(0, -3)}–${end}`
    }

    return `${start}–${end}`
}

// Scheduled rows only — the grid has no way to place a subject with no
// day/time yet (see the note rendered below the grid for those).
const scheduledAssignments = computed(() => props.assignments.filter((a) => a.days?.length))
const unscheduledCount = computed(() => props.assignments.length - scheduledAssignments.value.length)

/*
| Every row in timeRows (regular slots AND the lunch row) occupies
| exactly one CSS grid row line, in order, starting right after the
| header row. Row at array index i therefore starts at grid line
| (i + 2) — +1 because grid lines are 1-indexed, +1 more because the
| header itself sits on line 1. Identical mapping to Timetable.vue, so
| an assignment lines up on this grid exactly the way it would on
| Master Grid's.
*/
const rowLineByStartMinutes = computed(() => {
    const map = new Map()
    timeRows.value.forEach((row, index) => {
        map.set(row.startMinutes, index + 2)
    })
    return map
})

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

const positionedAssignments = computed(() =>
    scheduledAssignments.value
        // A 2x/3x-meeting subject has more than one entry in `days` —
        // it needs to appear in every one of those day columns, not
        // just the first, or the weekly grid would silently make it
        // look like a once-a-week class. `${assignment.id}-${day}`
        // keeps the :key unique per box now that one assignment can
        // render more than one.
        .flatMap((a) => a.days.map((day) => ({ assignment: a, day })))
        .map(({ assignment, day }) => {
            const dayIndex = dayColumnIndex(day?.toLowerCase())
            if (dayIndex === -1) return null

            return {
                key: `${assignment.id}-${day}`,
                assignment,
                gridColumn: `${dayIndex + 2} / span 1`,
                gridRow: `${lineForStart(assignment.start_minutes)} / ${lineForEnd(assignment.end_minutes)}`,
            }
        })
        .filter(Boolean)
)

const gridTemplateColumns = computed(
    () => `80px repeat(${workingDays.value.length}, minmax(110px, 1fr))`
)

/**
 * How many scheduled classes fall on each working day — powers the
 * small summary cards above the grid ("MON · 2 classes", etc.), so at
 * a glance you can see which days are busiest without counting blocks
 * in the grid itself.
 */
const dayCounts = computed(() =>
    workingDays.value.map((day) => ({
        ...day,
        count: scheduledAssignments.value.filter((a) => a.days.map((d) => d.toLowerCase()).includes(day.field)).length,
    }))
)
</script>

<template>
    <AppLayout>
        <div class="p-8">
            <Link
                :href="department.is_general ? route('block-schedule.faculty.general') : route('block-schedule.faculty.list', department.id)"
                class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800"
            >
                &lsaquo; Back to {{ department.code }} Faculty
            </Link>

            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ faculty.full_name }}</h1>
                    <p class="text-sm font-medium text-slate-400">
                        {{ department.name }}
                        <span v-if="academicTerm"> — {{ academicTerm.display_name }}</span>
                    </p>
                </div>

                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                    <button
                        type="button"
                        @click="viewMode = 'list'"
                        class="rounded-md px-3 py-1.5 text-xs font-bold uppercase tracking-wide transition"
                        :class="viewMode === 'list' ? 'bg-slate-900 text-white' : 'text-slate-500 hover:text-slate-800'"
                    >
                        List
                    </button>
                    <button
                        type="button"
                        @click="viewMode = 'overview'"
                        class="rounded-md px-3 py-1.5 text-xs font-bold uppercase tracking-wide transition"
                        :class="viewMode === 'overview' ? 'bg-slate-900 text-white' : 'text-slate-500 hover:text-slate-800'"
                    >
                        Overview
                    </button>
                </div>
            </div>

            <!-- LIST VIEW -->
            <div v-if="viewMode === 'list'" class="overflow-hidden rounded-2xl border border-slate-200 shadow-sm">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-900 text-white">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">EDP Code</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Subject</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Block</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Units</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Day &amp; Time</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Room</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-for="row in assignments" :key="row.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3 text-sm font-semibold text-slate-700">
                                {{ row.edp_code ?? '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <p class="font-semibold text-slate-900">{{ row.subject_code }}</p>
                                <p class="text-xs text-slate-400">{{ row.descriptive_title }}</p>
                            </td>
                            <td class="px-5 py-3 text-sm font-medium text-slate-700">
                                {{ row.section_code ?? '—' }}
                            </td>
                            <td class="px-5 py-3 text-sm font-medium text-slate-700">
                                {{ row.units ?? '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    :class="row.days?.length ? 'text-slate-700' : 'italic text-slate-400'"
                                    class="text-sm font-medium"
                                >
                                    {{ timeRange(row) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-sm font-medium text-slate-700">
                                {{ row.room_code ?? 'TBA' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- OVERVIEW: same CSS-grid engine as Master Grid's Timetable.vue -->
            <div v-else>
                <div v-if="timeRows.length === 0" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
                    This Academic Term has no valid school hours configured yet.
                </div>

                <template v-else>
                    <!-- Per-day summary cards -->
                    <div class="mb-4 grid gap-3" :style="{ gridTemplateColumns: `repeat(${workingDays.length}, minmax(0, 1fr))` }">
                        <div
                            v-for="day in dayCounts"
                            :key="day.field"
                            class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-center shadow-sm"
                        >
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ day.label }}</p>
                            <p class="mt-1 text-2xl font-extrabold" :class="day.count > 0 ? 'text-indigo-600' : 'text-slate-300'">
                                {{ day.count }}
                            </p>
                            <p class="text-[11px] font-medium text-slate-400">{{ day.count === 1 ? 'class' : 'classes' }}</p>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="max-h-[560px] w-full overflow-auto rounded-lg">
                            <div
                                class="timetable-grid grid w-full border-separate select-none"
                                :style="{ gridTemplateColumns }"
                            >
                                <!-- Header row -->

                            <div
                                class="timetable-time-col sticky left-0 top-0 z-30 border border-slate-300 bg-slate-100 px-1.5 py-1 text-[9px] font-black uppercase tracking-wider text-black"
                                style="grid-column: 1; grid-row: 1;"
                            >
                                Time
                            </div>
                            <div
                                v-for="(day, dIndex) in workingDays"
                                :key="day.field"
                                class="sticky top-0 z-20 border border-slate-300 bg-slate-100 px-1 py-1 text-center text-[9px] font-black uppercase tracking-wider text-black"
                                :style="{ gridColumn: dIndex + 2, gridRow: 1 }"
                            >
                                {{ day.label }}
                            </div>

                            <!-- Row backgrounds/borders + time labels + lunch band -->
                            <template v-for="(row, rIndex) in timeRows" :key="row.key">
                                <div
                                    class="timetable-time-col sticky left-0 z-10 whitespace-nowrap border border-slate-300 px-1.5 text-[8px] font-semibold leading-[18px] text-black"
                                    :class="row.type === 'lunch' ? 'bg-slate-50' : 'bg-white'"
                                    :style="{ gridColumn: 1, gridRow: rIndex + 2 }"
                                >
                                    {{ compactRange(row.label) }}
                                </div>

                                <!-- Lunch spans every day column in one band -->
                                <div
                                    v-if="row.type === 'lunch'"
                                    class="flex items-center justify-center border border-slate-300 bg-slate-100/70"
                                    :style="{ gridColumn: `2 / span ${workingDays.length}`, gridRow: rIndex + 2 }"
                                >
                                    <span class="text-[8px] font-black uppercase tracking-[0.1em] text-black">
                                        Lunch
                                    </span>
                                </div>

                                <!-- Otherwise one empty bordered cell per day, purely for the grid lines -->
                                <div
                                    v-else
                                    v-for="(day, dIndex) in workingDays"
                                    :key="day.field + row.key"
                                    class="timetable-cell h-[18px] border border-slate-300"
                                    :style="{ gridColumn: dIndex + 2, gridRow: rIndex + 2 }"
                                ></div>
                            </template>

                            <!-- Scheduled blocks — each rendered exactly ONCE,
                                 spanning every row it actually covers. -->
                            <div
                                v-for="{ key, assignment, gridColumn, gridRow } in positionedAssignments"
                                :key="key"
                                class="z-[5] m-px flex flex-col items-center justify-center gap-0 overflow-hidden rounded border border-indigo-300 bg-indigo-100 px-1 py-0.5 text-center"
                                :style="{ gridColumn, gridRow }"
                            >
                                <p class="text-[8px] font-black leading-tight text-indigo-900">
                                    {{ assignment.subject_code }} · {{ assignment.section_code ?? '—' }}
                                </p>
                                <p class="text-[7px] font-bold leading-tight text-indigo-800">
                                    {{ formatMinutes(assignment.start_minutes) }} – {{ formatMinutes(assignment.end_minutes) }}
                                </p>
                                <p class="truncate text-[7px] font-semibold leading-tight text-indigo-700">
                                    {{ assignment.room_code ?? 'TBA' }}
                                </p>
                            </div>
                        </div>
                    </div>
                    </div>
                </template>
            </div>

            <p v-if="viewMode === 'overview' && unscheduledCount > 0" class="mt-3 text-xs italic text-slate-400">
                {{ unscheduledCount }} subject(s) don't have a day/time yet and aren't shown above — switch to List view to see them.
            </p>

            <p v-if="!assignments.length" class="mt-10 text-center text-sm text-slate-400">
                No Teaching Assignments for this faculty member yet.
            </p>
        </div>
    </AppLayout>
</template>

<style scoped>
.timetable-time-col {
    width: 80px;
}
</style>

<style>
/* Sidebar/Topbar come from AppLayout and are visible during
   normal browsing (same chrome as every other page). They're only
   suppressed here for the *printed* output, so the "Print" button above
   still produces a clean Subject/Block/Day/Room report. */
@media print {
    #app-sidebar,
    #sidebar-overlay {
        display: none !important;
    }
}
</style>