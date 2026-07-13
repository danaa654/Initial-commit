<script setup>
import { computed, reactive, watch } from 'vue'

/**
 * Generate Schedule — Step 2 (Session Settings).
 *
 * Fed by GET master-grid/session-settings (one row per Subject
 * Offering still needing to be scheduled for the section chosen in
 * Step 1). Edits (meetings/week, preferred faculty/room) live only in
 * local `rows` until "Generate" is clicked, at which point they're
 * persisted via PUT master-grid/session-settings and the parent is
 * asked to run the actual Greedy Scheduling Algorithm — this modal
 * never calls master-grid/generate itself.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    error: { type: String, default: null },
    // Raw response from GET master-grid/session-settings:
    // { section_id, subjects: [...], has_offerings }
    data: { type: Object, default: null },
})

const emit = defineEmits(['close', 'back', 'generate'])

const MEETING_OPTIONS = [1, 2, 3]

// Session Settings' Hrs/Wk is meant for small corrections to actual
// classroom time (e.g. curriculum says 5, real duration is 4) — not
// an open-ended number field. 2–5 covers every real subject duration
// this school schedules; clamped both in the input's own min/max AND
// in code below, since HTML min/max alone doesn't stop someone from
// typing "99" and tabbing away.
const HOURS_MIN = 2
const HOURS_MAX = 5

/**
 * Local, editable copy of every subject row — keyed by
 * subject_offering_id so re-renders (e.g. after the parent refetches
 * data on reopen) don't clobber in-progress edits mid-session.
 */
const rows = reactive({})

watch(
    () => props.data,
    (data) => {
        Object.keys(rows).forEach((key) => delete rows[key])

        for (const subject of data?.subjects ?? []) {
            rows[subject.subject_offering_id] = {
                ...subject,
                meetings_per_week: subject.meetings_per_week ?? 1,
                curriculum_hours: subject.total_hours_per_week,
            }
        }
    },
    { immediate: true }
)

const subjectList = computed(() => Object.values(rows))

/**
 * hours_per_meeting recomputed live as the Registrar changes the
 * meetings-per-week dropdown — the server-computed value from the
 * initial fetch is only the STARTING point, not something re-fetched
 * on every edit.
 */
function hoursPerMeeting(row) {
    if (!row.meetings_per_week || !row.total_hours_per_week) return 0
    return Math.round((row.total_hours_per_week / row.meetings_per_week) * 100) / 100
}

/**
 * Keeps Hrs/Wk inside [HOURS_MIN, HOURS_MAX] no matter how the value
 * got there — typed directly, pasted, or nudged past the edge with
 * the spinner arrows. Called on @input so the field corrects itself
 * immediately rather than waiting for blur/submit.
 */
function clampHours(row) {
    const value = row.total_hours_per_week

    if (value === null || value === '' || Number.isNaN(value)) return

    if (value < HOURS_MIN) row.total_hours_per_week = HOURS_MIN
    if (value > HOURS_MAX) row.total_hours_per_week = HOURS_MAX
}

function dividesEvenly(row) {
    return row.total_hours_per_week > 0 && row.total_hours_per_week % row.meetings_per_week === 0
}

/**
 * Suggested meetings/week, based on total weekly hours alone:
 * 1–2 hrs -> 1x, 3–4 hrs -> 2x, 5+ hrs -> 3x. This is a HINT only — it
 * never overwrites row.meetings_per_week on its own; the Registrar
 * still picks the actual value via the dropdown (see applyRecommended()
 * below for the one-click "take this suggestion" action).
 */
function recommendedMeetings(row) {
    const hours = row.total_hours_per_week

    if (!hours) return null
    if (hours <= 2) return 1
    if (hours <= 4) return 2
    return 3
}

/** Applies the suggested value from recommendedMeetings() to this row. */
function applyRecommended(row) {
    const suggestion = recommendedMeetings(row)
    if (suggestion) row.meetings_per_week = suggestion
}

/** Subjects that need a warning banner before Generate is allowed to run. */
const unresolvedWarnings = computed(() =>
    subjectList.value.filter((row) => !row.has_qualified_faculty)
)

const divisionWarnings = computed(() =>
    subjectList.value.filter((row) => !dividesEvenly(row))
)

const canGenerate = computed(() => {
    if (!props.data?.has_offerings) return false
    // A subject with literally no qualified faculty can still be sent
    // to the algorithm (it will come back "Unscheduled — No Faculty
    // Found", per spec) — Session Settings warns but doesn't block,
    // since blocking here would mean the Registrar can never generate
    // the OTHER subjects in the same section until Faculty Loading is
    // fixed first.
    return true
})

function close() {
    emit('close')
}

function back() {
    emit('back')
}

function generate() {
    if (!canGenerate.value || props.saving) return

    const subjects = subjectList.value.map((row) => ({
        subject_offering_id: row.subject_offering_id,
        hours: row.total_hours_per_week,
        meetings_per_week: row.meetings_per_week,
    }))

    emit('generate', { section_id: props.data?.section_id, subjects })
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-3xl max-h-[85vh] flex flex-col p-5">
            <div class="flex items-center justify-between mb-2">
                <h3 class="font-black text-slate-800 dark:text-slate-100">Session Settings</h3>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="close">✕</button>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                Review each subject's actual weekly duration and how many times it meets — Hrs/Wk
                defaults to the curriculum's value but can be corrected here if actual classroom
                time differs. Hours per meeting are computed automatically; faculty and room are
                still assigned automatically by the scheduler.
            </p>

            <!-- Loading -->
            <div v-if="loading" class="flex-1 flex items-center justify-center text-sm text-slate-500 py-10">
                Loading subjects…
            </div>

            <!-- Empty state: "Section has zero subjects configured -> disable Generate, show empty state" -->
            <div v-else-if="data && !data.has_offerings" class="flex-1 flex flex-col items-center justify-center text-center py-10 gap-2">
                <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">No Subject Offerings to schedule.</p>
                <p class="text-xs text-slate-400 max-w-sm">
                    This section has no unscheduled Subject Offerings for the current Planning
                    Academic Term. Generate Subject Offerings first, or pick a different section.
                </p>
            </div>

            <template v-else>
                <div v-if="unresolvedWarnings.length" class="mb-3 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 px-3 py-2">
                    <p class="text-xs font-bold text-red-700 dark:text-red-300">
                        {{ unresolvedWarnings.length }} subject(s) have no qualified faculty at all:
                    </p>
                    <p class="text-[11px] text-red-600 dark:text-red-400">
                        {{ unresolvedWarnings.map((r) => r.subject_code).join(', ') }} — these will
                        come back "Unscheduled — No Faculty Found" if you generate now. Assign a
                        qualified faculty in Faculty Loading first, or generate anyway and fix them
                        after review.
                    </p>
                </div>

                <div v-if="divisionWarnings.length" class="mb-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 px-3 py-2">
                    <p class="text-xs font-bold text-amber-700 dark:text-amber-300">
                        {{ divisionWarnings.length }} subject(s) don't divide evenly across their meetings:
                    </p>
                    <p class="text-[11px] text-amber-600 dark:text-amber-400">
                        {{ divisionWarnings.map((r) => `${r.subject_code} (${r.total_hours_per_week}h ÷ ${r.meetings_per_week})`).join(', ') }}
                        — hours per meeting will be fractional (e.g. 1.5 hrs). Adjust meetings per
                        week if that's not intended.
                    </p>
                </div>

                <div class="thin-scroll flex-1 overflow-y-auto border border-slate-200 dark:border-slate-700 rounded-lg">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200">
                            <tr>
                                <th class="text-left font-bold uppercase tracking-wide px-3 py-2">Subject</th>
                                <th class="text-left font-bold uppercase tracking-wide px-3 py-2">Hrs/Wk</th>
                                <th class="text-left font-bold uppercase tracking-wide px-3 py-2">Meetings</th>
                                <th class="text-left font-bold uppercase tracking-wide px-3 py-2">Hrs/Meeting</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in subjectList"
                                :key="row.subject_offering_id"
                                class="border-t border-slate-100 dark:border-slate-700"
                                :class="!row.has_qualified_faculty ? 'bg-red-50/60 dark:bg-red-950/20' : ''"
                            >
                                <td class="px-3 py-2">
                                    <div class="font-semibold text-slate-700 dark:text-slate-200">{{ row.subject_code }}</div>
                                    <div class="text-[10px] text-slate-400">{{ row.descriptive_title }}</div>
                                    <div class="text-[10px] text-slate-400">{{ row.room_type }} · {{ row.classification }}</div>
                                    <div v-if="!row.has_qualified_faculty" class="text-[10px] font-bold text-red-600 mt-0.5">
                                        No qualified faculty
                                    </div>
                                </td>

                                <td class="px-3 py-2 align-top">
                                    <input
                                        v-model.number="row.total_hours_per_week"
                                        type="number"
                                        :min="HOURS_MIN"
                                        :max="HOURS_MAX"
                                        step="1"
                                        class="w-16 rounded border-slate-300 text-xs text-black dark:text-white dark:bg-slate-900 dark:border-slate-600"
                                        @input="clampHours(row)"
                                    />
                                    <div
                                        v-if="row.curriculum_hours && row.total_hours_per_week !== row.curriculum_hours"
                                        class="text-[10px] text-slate-400 mt-0.5"
                                    >
                                        Curriculum: {{ row.curriculum_hours }}h
                                    </div>
                                </td>

                                <td class="px-3 py-2 align-top">
                                    <select
                                        v-model.number="row.meetings_per_week"
                                        class="rounded border-slate-300 text-xs text-black dark:text-white dark:bg-slate-900 dark:border-slate-600"
                                    >
                                        <option v-for="n in MEETING_OPTIONS" :key="n" :value="n">{{ n }}x</option>
                                    </select>
                                    <button
                                        v-if="recommendedMeetings(row) && recommendedMeetings(row) !== row.meetings_per_week"
                                        type="button"
                                        class="block mt-0.5 text-[10px] font-semibold text-blue-600 dark:text-blue-400 hover:underline"
                                        :title="`Based on ${row.total_hours_per_week} hrs/week`"
                                        @click="applyRecommended(row)"
                                    >
                                        Suggested: {{ recommendedMeetings(row) }}x
                                    </button>
                                </td>

                                <td class="px-3 py-2 align-top" :class="!dividesEvenly(row) ? 'text-amber-600 font-semibold' : ''">
                                    {{ hoursPerMeeting(row) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <p v-if="error" class="text-xs font-semibold text-red-600 mt-3">
                {{ error }}
            </p>

            <div class="flex justify-between items-center gap-2 mt-4">
                <button type="button" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700" @click="back">
                    ← Back
                </button>

                <div class="flex gap-2">
                    <button type="button" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700" @click="close">
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="!canGenerate || saving || loading"
                        class="px-4 py-1.5 rounded-lg text-sm font-bold text-white transition"
                        :class="canGenerate && !saving && !loading ? 'bg-blue-600 hover:bg-blue-700' : 'bg-blue-300 cursor-not-allowed'"
                        @click="generate"
                    >
                        {{ saving ? 'Generating…' : 'Generate' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Thin scrollbar for the subject table */
.thin-scroll {
    scrollbar-width: thin; /* Firefox */
    scrollbar-color: rgb(203 213 225) transparent; /* Firefox: thumb / track */
}
.thin-scroll::-webkit-scrollbar {
    width: 6px;
}
.thin-scroll::-webkit-scrollbar-track {
    background: transparent;
}
.thin-scroll::-webkit-scrollbar-thumb {
    background-color: rgb(203 213 225); /* slate-300 */
    border-radius: 9999px;
}
.thin-scroll::-webkit-scrollbar-thumb:hover {
    background-color: rgb(148 163 184); /* slate-400 */
}
.dark .thin-scroll::-webkit-scrollbar-thumb {
    background-color: rgb(71 85 105); /* slate-600 */
}
.dark .thin-scroll::-webkit-scrollbar-thumb:hover {
    background-color: rgb(100 116 139); /* slate-500 */
}
</style>