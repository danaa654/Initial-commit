<script setup>
import { computed } from 'vue'
import ScheduleSuccessOverlay from '@/Components/ScheduleSuccessOverlay.vue'

/**
 * Shown right after a successful Generate call — BEFORE anything
 * touches the real Master Grid. This is the "review, fix, then
 * commit" step: a Subject/Faculty/Room/Day/Time/Status table for
 * every offering the Greedy Scheduler touched (both placed and
 * failed).
 *
 * Any successfully-placed row can be clicked to open Edit Schedule
 * (see Index.vue's openEditModal(block, 'preview')) — the exact same
 * conflict-checked modal used on the live Master Grid, just scoped to
 * this in-memory preview instead of the saved schedule. Applying an
 * edit there updates the row in place, right here in this table;
 * nothing is written to the database until this modal's own Save
 * Changes is clicked.
 *
 * Nothing here is written anywhere until the user clicks "Save
 * Changes" — see Index.vue's applyGeneratedPreview(), which posts
 * straight to master-grid.save. Once that succeeds, the batch is
 * final: it is already on the Master Grid AND already in the
 * database, and each room's own timetable (see Room Sidebar -> click a
 * room) reflects it immediately.
 *
 * "Discard" throws the whole result away with zero side effects,
 * since nothing was ever written anywhere to produce this preview in
 * the first place, and closes back to the Master Grid.
 *
 * "Back" also throws this preview away (same zero-side-effects
 * reasoning — nothing here was ever saved) but returns to Session
 * Settings for the SAME section instead of closing entirely, so a
 * setting can be adjusted (Hours/Week, Meetings/Week, a preferred
 * Faculty/Room) and the batch regenerated without re-walking Target
 * Selection from scratch — see Index.vue's backToSessionSettings().
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    result: { type: Object, default: null }, // raw response from master-grid.generate
    saving: { type: Boolean, default: false },
    error: { type: String, default: null },
    // subject_offering_id => array of conflict objects (type, reason,
    // current, conflicting — see ScheduleValidationService::conflict()),
    // populated only when Save Changes itself came back 422. This is
    // what actually tells the Registrar WHICH of the "Success" rows was
    // the real problem — every row still reads Greedy-generation status
    // ('preview'/'unscheduled'/'skipped') on its own, which has nothing
    // to do with whether Save Schedule's own re-validation later
    // rejected it (e.g. it collided with something saved by someone
    // else a second ago). Without this, the generic banner above the
    // table was the only signal given, and it named zero rows.
    conflicts: { type: Object, default: null },
    // True for the ~3 seconds right after Save Changes has actually
    // succeeded — shows the confetti/success overlay on top of this
    // modal instead of closing it instantly. Index.vue owns the timer
    // (via ScheduleSuccessOverlay's 'done' event) so the modal only
    // ever closes once the celebration has actually finished playing.
    justSaved: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'discard', 'back', 'edit-block', 'saved-celebration-done'])

const blocks = computed(() => props.result?.blocks ?? [])

const placedBlocks = computed(() => blocks.value.filter((b) => b.status === 'preview'))

const scheduledCount = computed(() => props.result?.scheduled_count ?? 0)
const unscheduledCount = computed(() => props.result?.unscheduled_count ?? 0)

const DAY_ORDER = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']

function capitalize(day) {
    return day ? day[0].toUpperCase() + day.slice(1) : day
}

/**
 * Collapses a subject's multiple meeting rows (2x/3x per week — see
 * GreedyScheduleService's "Multi-meeting subjects" docblock) into ONE
 * display row per subject, combining their days into a single label
 * ("Monday - Wednesday") since they always share the same faculty,
 * room, and time by construction. `unscheduled`/`skipped` rows never
 * group (there's only ever one such row per offering) and pass through
 * unchanged. The underlying per-day rows are kept on `.blocks` so
 * saveConflictsFor()/editBlock() can still reach the real data —
 * grouping only ever changes what's DISPLAYED, never what's saved.
 */
const displayRows = computed(() => {
    const groups = new Map()
    const order = []

    blocks.value.forEach((block, index) => {
        const key = block.status === 'preview'
            ? ['preview', block.subject_offering_id, block.faculty_id, block.room_id, block.start_minutes, block.end_minutes].join(':')
            : `solo-${index}`

        if (!groups.has(key)) {
            groups.set(key, { ...block, days: block.day ? [block.day] : [], blocks: [block] })
            order.push(key)
        } else {
            const group = groups.get(key)
            if (block.day && !group.days.includes(block.day)) group.days.push(block.day)
            group.blocks.push(block)
        }
    })

    return order.map((key) => {
        const group = groups.get(key)
        group.days.sort((a, b) => DAY_ORDER.indexOf(a) - DAY_ORDER.indexOf(b))
        return group
    })
})

function dayLabel(row) {
    if (!row.days.length) return '—'
    return row.days.map(capitalize).join(' - ')
}

/**
 * The specific conflict reason(s) Save Schedule rejected THIS block
 * for, or null if this block wasn't part of the rejected batch (or
 * nothing has failed yet). Keys of props.conflicts come back from
 * Laravel as strings even though subject_offering_id is numeric, so
 * this coerces both sides before comparing. Works unchanged for a
 * grouped multi-meeting row too — conflicts are already merged across
 * a subject's meeting rows onto the same subject_offering_id key (see
 * ScheduleValidationService::validateAll()).
 */
function saveConflictsFor(block) {
    if (!props.conflicts) return null
    const entry = props.conflicts[block.subject_offering_id] ?? props.conflicts[String(block.subject_offering_id)]
    return entry && entry.length ? entry : null
}

/**
 * Conflicts recorded LIVE, right when the person applied an edit from
 * inside this still-unsaved preview (see Index.vue's applyEdit — a
 * 'preview' context edit is allowed through even with a conflict,
 * tagging the affected meeting-day block with its own `conflicts`
 * array instead of trapping the person in the Edit Schedule modal).
 * Distinct from saveConflictsFor() above, which only ever exists AFTER
 * a real Save Changes attempt came back 422 — this one shows up the
 * moment the edit is applied, before Save Changes is ever clicked.
 */
function liveConflictsFor(row) {
    const reasons = row.blocks.flatMap((b) => b.conflicts ?? [])
    return reasons.length ? reasons : null
}

/** Any row still carrying an unresolved live conflict — Save Changes
 * stays disabled until every row is clean, since attempting to save a
 * batch that's already known to conflict would just fail anyway. */
const hasUnresolvedConflicts = computed(() =>
    displayRows.value.some((row) => row.status === 'preview' && liveConflictsFor(row))
)

function timeLabel(minutes) {
    if (minutes === null || minutes === undefined) return '—'
    const h24 = Math.floor(minutes / 60) % 24
    const m = minutes % 60
    const period = h24 >= 12 ? 'PM' : 'AM'
    const h12 = h24 % 12 === 0 ? 12 : h24 % 12
    return `${h12}:${String(m).padStart(2, '0')} ${period}`
}

function close() {
    if (props.saving) return
    emit('discard')
}

/**
 * "Back" — unlike Discard (which throws this preview away and closes
 * back to the Master Grid, done), this throws the preview away but
 * returns to Session Settings for the SAME section, so a setting can
 * be adjusted (Hours/Week, Meetings/Week, a preferred Faculty/Room)
 * and the batch regenerated, instead of starting the whole Target
 * Selection → Session Settings walk over from scratch. The preview
 * itself is still discarded either way — nothing here was ever saved
 * to begin with (see the class docblock), so there's nothing to
 * preserve, just where the person lands next.
 */
function goBack() {
    if (props.saving) return
    emit('back')
}

function save() {
    if (props.saving) return
    emit('save')
}

// Both a successfully-placed row ('preview') AND a failed row
// ('unscheduled') can be edited — a failed row has no faculty/room/
// day/time yet, but Edit Schedule handles that fine: every field
// starts empty, and picking any of them triggers the exact same live
// conflict-check as a normal edit, which is what actually populates
// the Suggested Faculty/Room/Time panel. This is what lets someone
// manually place a subject the Greedy Scheduler couldn't — e.g.
// because the (at most) two other subjects processed just ahead of
// it had already claimed every faculty/room/slot it could have used.
//
// A 'skipped' row (duplicate subject within this same run) is
// deliberately excluded — that's not a resource-exhaustion failure to
// work around, it's a real duplicate that shouldn't be scheduled
// twice for the same section.
function rowClickable(row) {
    return row.status === 'preview' || row.status === 'unscheduled'
}

// Editing a grouped multi-meeting row opens the modal with EVERY
// underlying meeting instance (e.g. both the Monday and Wednesday rows
// for a 2x/week subject) — not just the first one. This is what lets
// Edit Schedule apply a faculty/room/time change to every meeting day
// at once instead of only the day that happened to be first in the
// group, which used to leave sibling days silently pointing at the
// OLD faculty/room after Apply Changes.
function editBlock(row) {
    if (props.saving || !rowClickable(row)) return
    emit('edit-block', row.blocks)
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
        <div class="relative bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-4xl max-h-[88vh] flex flex-col">

            <!-- Header -->
            <div class="flex items-start justify-between px-5 py-4 border-b border-slate-200 dark:border-slate-700 shrink-0">
                <div>
                    <h3 class="font-black text-slate-800 dark:text-slate-100">Schedule Preview</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Nothing has been saved yet. Click any row to adjust faculty, room, day, or time, then
                        Save Changes to commit it to the Master Grid, or Discard to throw it away.
                    </p>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-600" :disabled="saving" @click="close">✕</button>
            </div>

            <!-- Summary strip -->
            <div class="flex items-center gap-4 px-5 py-2.5 border-b border-slate-200 dark:border-slate-700 shrink-0 text-xs">
                <span class="font-black text-emerald-600 dark:text-emerald-400">{{ scheduledCount }} scheduled</span>
                <span v-if="unscheduledCount > 0" class="font-black text-red-600 dark:text-red-400">{{ unscheduledCount }} failed</span>
                <span v-else class="font-semibold text-slate-400">0 failed</span>
            </div>

            <!-- Error banner (e.g. a conflict surfaced only at save time) -->
            <div
                v-if="error"
                class="px-5 py-2 border-b border-red-200 dark:border-red-700 bg-red-50 dark:bg-red-900/20 text-xs text-red-700 dark:text-red-300 shrink-0"
            >
                {{ error }}
            </div>

            <!-- Body: overview table. Each room already has its own timetable
                 on the Master Grid itself, so there is no separate per-room
                 drill-down here anymore — this table is the whole review. -->
            <div class="flex-1 min-h-0 overflow-auto custom-scrollbar-theme">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-slate-50 dark:bg-slate-900 text-[10px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="text-left px-4 py-2 border-b border-slate-200 dark:border-slate-700">Subject</th>
                            <th class="text-left px-4 py-2 border-b border-slate-200 dark:border-slate-700">Faculty</th>
                            <th class="text-left px-4 py-2 border-b border-slate-200 dark:border-slate-700">Room</th>
                            <th class="text-left px-4 py-2 border-b border-slate-200 dark:border-slate-700">Day</th>
                            <th class="text-left px-4 py-2 border-b border-slate-200 dark:border-slate-700">Time</th>
                            <th class="text-left px-4 py-2 border-b border-slate-200 dark:border-slate-700">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(block, index) in displayRows"
                            :key="block.subject_offering_id + '-' + (block.days.join(',') || index)"
                            class="border-b border-slate-100 dark:border-slate-700/60"
                            :class="[
                                block.status !== 'preview' || saveConflictsFor(block) || liveConflictsFor(block) ? 'bg-red-50/60 dark:bg-red-900/10' : '',
                                rowClickable(block) ? 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700/40' : '',
                            ]"
                            :title="rowClickable(block) ? 'Click to edit faculty, room, day, or time' : ''"
                            @click="editBlock(block)"
                        >
                            <td class="px-4 py-2 font-bold text-slate-800 dark:text-slate-100">
                                {{ block.subject_code }}
                                <span class="block text-[10px] font-medium text-slate-400">{{ block.section_code }}</span>
                            </td>
                            <td class="px-4 py-2 text-slate-600 dark:text-slate-300">
                                <span v-if="block.faculty_name">
                                    {{ block.faculty_name }}
                                    <span v-if="block.faculty_source === 'auto'" class="ml-1 px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 text-[9px] font-black uppercase tracking-wide dark:bg-blue-500/10 dark:text-blue-300">
                                        Auto Assigned
                                    </span>
                                </span>
                                <span v-else class="text-slate-400 italic">Unassigned</span>
                            </td>
                            <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ block.room_code ?? '—' }}</td>
                            <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ dayLabel(block) }}</td>
                            <td class="px-4 py-2 text-slate-600 dark:text-slate-300">
                                <template v-if="block.start_minutes !== null">
                                    {{ timeLabel(block.start_minutes) }} – {{ timeLabel(block.end_minutes) }}
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="px-4 py-2">
                                <span
                                    v-if="block.status === 'preview' && !saveConflictsFor(block) && !liveConflictsFor(block)"
                                    class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
                                >
                                    Success
                                </span>
                                <span v-else-if="block.status === 'preview'" class="inline-flex flex-col">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300 self-start">
                                        Conflict
                                    </span>
                                    <span
                                        v-for="(conflict, i) in (saveConflictsFor(block) ?? liveConflictsFor(block))"
                                        :key="i"
                                        class="text-[10px] text-red-500 dark:text-red-400 mt-0.5"
                                    >
                                        {{ conflict.reason }}
                                    </span>
                                </span>
                                <span v-else class="inline-flex flex-col">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300 self-start">
                                        Failed
                                    </span>
                                    <span class="text-[10px] text-red-500 dark:text-red-400 mt-0.5">{{ block.reason }}</span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-between px-5 py-3 border-t border-slate-200 dark:border-slate-700 shrink-0">
                <p class="text-[11px] text-slate-400">
                    <template v-if="hasUnresolvedConflicts">
                        <span class="text-red-500 dark:text-red-400 font-semibold">Resolve the conflict(s) highlighted above</span>
                        before Save Changes — click the row to fix its faculty, room, day, or time.
                    </template>
                    <template v-else>
                        Click any row above — including a failed one — to set its faculty, room, day, or time.
                        Once saved, the same edit is still available anytime by clicking the block on the Master Grid.
                    </template>
                </p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-50"
                        :disabled="saving"
                        @click="goBack"
                    >
                        ← Back to Session Settings
                    </button>
                    <button
                        type="button"
                        class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-50"
                        :disabled="saving"
                        @click="close"
                    >
                        Discard
                    </button>
                    <button
                        type="button"
                        class="px-4 py-1.5 rounded-lg text-sm font-bold text-white transition bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-300 disabled:cursor-not-allowed"
                        :disabled="saving || placedBlocks.length === 0 || hasUnresolvedConflicts"
                        @click="save"
                    >
                        {{ saving ? 'Saving…' : `Save Changes (${placedBlocks.length})` }}
                    </button>
                </div>
            </div>

            <ScheduleSuccessOverlay
                :show="justSaved"
                message="Schedule Saved Successfully!"
                @done="emit('saved-celebration-done')"
            />

        </div>
    </div>
</template>