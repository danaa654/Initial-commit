<script setup>
import { ref, computed } from 'vue'
import { accentColor } from '@/Utils/roomAccentColor'

const props = defineProps({
    collapsed: { type: Boolean, default: false },
    offerings: { type: Array, default: () => [] },
    // Offerings already Scheduled/Completed/Archived for this term —
    // excluded from the tray by default since there's nothing left to
    // drag for them. Only shown when showScheduled is toggled on. See
    // MasterGridDataService::scheduledOfferings().
    scheduledOfferings: { type: Array, default: () => [] },
    collegeColors: { type: Object, default: () => ({}) },
    // Admin/Registrar only (see Index.vue's canManage) — gates BOTH
    // the draggable attribute and the dragstart handler. Dean/
    // Assistant Dean/OIC still see the same cards (read-only context
    // about what's left to schedule), just without a grab cursor or
    // any drag behavior — dropping one on the grid would only ever
    // 403 at the backend anyway (see MasterGridController's
    // Admin/Registrar-only write middleware), so there is no reason
    // to invite the gesture for them at all.
    canManage: { type: Boolean, default: false },
})

const emit = defineEmits(['update:collapsed', 'drag-start', 'drag-end', 'card-click'])

function toggle() {
    emit('update:collapsed', !props.collapsed)
}

// Off by default — the sidebar's normal job is an unscheduled "drag-in"
// tray, and most of the time a Registrar doesn't want that cluttered
// with things that are already done. This is purely a local view
// preference, not persisted anywhere.
const showScheduled = ref(false)

// Unscheduled offerings first (these are draggable and are what the
// Registrar actually still needs to act on), then — only when the
// toggle is on — the already-Scheduled/Completed/Archived ones
// appended after, so they read as a distinct, secondary group rather
// than being shuffled in among the actionable ones.
const visibleOfferings = computed(() =>
    showScheduled.value
        ? [...props.offerings, ...props.scheduledOfferings]
        : props.offerings
)

/* ── Filters ──────────────────────────────────────────────────────
   Same pattern as RoomSidebar's Program/Room Type dropdowns — three
   independent filters, derived from whatever's actually present in
   the offerings on the page, each narrowing the list further when
   more than one is set. Empty selection = "All" (that filter is
   skipped). */
const programFilter = ref('')
const classificationFilter = ref('')
const roomTypeFilter = ref('')

const programOptions = computed(() => {
    const codes = new Set()
    visibleOfferings.value.forEach((o) => o.program_code && codes.add(o.program_code))
    return Array.from(codes).sort()
})

const classificationOptions = computed(() => {
    const values = new Set()
    visibleOfferings.value.forEach((o) => o.classification && values.add(o.classification))
    return Array.from(values).sort()
})

const roomTypeOptions = computed(() => {
    const values = new Set()
    visibleOfferings.value.forEach((o) => o.room_type && values.add(o.room_type))
    return Array.from(values).sort()
})

const filteredOfferings = computed(() =>
    visibleOfferings.value
        .filter((o) => !programFilter.value || o.program_code === programFilter.value)
        .filter((o) => !classificationFilter.value || o.classification === classificationFilter.value)
        .filter((o) => !roomTypeFilter.value || o.room_type === roomTypeFilter.value)
)

const hasActiveFilters = computed(() => !!programFilter.value || !!classificationFilter.value || !!roomTypeFilter.value)

function clearFilters() {
    programFilter.value = ''
    classificationFilter.value = ''
    roomTypeFilter.value = ''
}

const count = computed(() => filteredOfferings.value.length)
const scheduledCount = computed(() => props.scheduledOfferings.length)

/**
 * Stashes the offering's id on the native drag event as JSON — read
 * back by Timetable.vue's drop handler (see its own dragstart/drop
 * docblock) to know exactly which Subject Offering was dropped onto
 * which grid cell. Only ever wired up when canManage is true and the
 * offering isn't already scheduled — draggable="false" on every other
 * card already stops the browser from firing dragstart at all, this
 * is just belt-and-suspenders against a stray call.
 */
function onDragStart(event, offering) {
    if (!props.canManage || offering.is_scheduled) return

    event.dataTransfer.effectAllowed = 'copy'
    event.dataTransfer.setData('application/json', JSON.stringify({ subjectOfferingId: offering.id }))
    emit('drag-start', offering)
}

/**
 * Fires whenever the drag gesture ends, no matter how — a successful
 * drop, a drop rejected by Timetable (room-type mismatch), or the
 * card just being released outside any valid drop target entirely.
 * Index.vue uses this to clear draggedOffering so the "no-drop"
 * cursor/banner in Timetable don't linger once the drag is over.
 */
function onDragEnd() {
    emit('drag-end')
}

/**
 * Click-to-schedule — an alternative to drag-and-drop for placing an
 * unscheduled offering, useful when the "right" room isn't one drag
 * would naturally land on anyway (e.g. it needs Room Eligibility
 * Override — see EditScheduleModal — because the actual target room
 * isn't Allowed for this program/type, so dragging onto that exact
 * cell either isn't obvious or gets rejected outright). Opens the same
 * Edit Schedule modal a drop would, just with Room/Day/Start left for
 * the person to fill in themselves rather than pre-set by wherever
 * they dropped it. Same guard as dragstart — no-op for an
 * already-scheduled card or when the viewer can't manage the grid.
 */
function onCardClick(offering) {
    if (!props.canManage || offering.is_scheduled) return

    emit('card-click', offering)
}
</script>

<template>
    <aside
        class="subject-sidebar shrink-0 flex flex-col transition-all duration-200"
        style="background: var(--card-bg); border-right: 1px solid var(--card-border)"
        :class="collapsed ? 'w-10' : 'w-[220px]'"
    >
        <div class="flex items-center justify-between px-3 py-2.5 shrink-0" style="border-bottom: 1px solid var(--card-border)">
            <p v-if="!collapsed" class="text-[11px] font-black uppercase tracking-widest" style="color: var(--text-secondary)">
                Subjects <span style="color: var(--text-muted)">({{ count }})</span>
            </p>
            <button
                type="button"
                class="text-xs font-bold hover:opacity-70"
                style="color: var(--text-muted)"
                @click="toggle"
            >
                {{ collapsed ? '»' : '« Hide Subjects' }}
            </button>
        </div>

        <label
            v-if="!collapsed && scheduledCount > 0"
            class="flex items-center gap-1.5 px-3 py-1.5 shrink-0 text-[10px] font-semibold cursor-pointer select-none"
            style="border-bottom: 1px solid var(--card-border); color: var(--text-secondary)"
        >
            <input type="checkbox" v-model="showScheduled" class="rounded" />
            Show scheduled too ({{ scheduledCount }})
        </label>

        <div v-if="!collapsed" class="flex-1 overflow-y-auto custom-scrollbar-theme p-2 space-y-2">
            <!-- Filters -->
            <div class="space-y-1.5 pb-1">
                <select
                    v-model="programFilter"
                    class="w-full rounded-lg border text-[11px] font-semibold px-2 py-1.5"
                    style="background: var(--card-bg); border-color: var(--card-border); color: var(--text-primary)"
                >
                    <option value="">All Programs</option>
                    <option v-for="code in programOptions" :key="code" :value="code">{{ code }}</option>
                </select>

                <select
                    v-model="classificationFilter"
                    class="w-full rounded-lg border text-[11px] font-semibold px-2 py-1.5"
                    style="background: var(--card-bg); border-color: var(--card-border); color: var(--text-primary)"
                >
                    <option value="">All Classifications</option>
                    <option v-for="value in classificationOptions" :key="value" :value="value">{{ value }}</option>
                </select>

                <select
                    v-model="roomTypeFilter"
                    class="w-full rounded-lg border text-[11px] font-semibold px-2 py-1.5"
                    style="background: var(--card-bg); border-color: var(--card-border); color: var(--text-primary)"
                >
                    <option value="">All Room Types</option>
                    <option v-for="value in roomTypeOptions" :key="value" :value="value">{{ value }}</option>
                </select>

                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="text-[10px] font-bold hover:opacity-70"
                    style="color: var(--text-muted)"
                    @click="clearFilters"
                >
                    Clear filters ✕
                </button>
            </div>

            <p v-if="visibleOfferings.length === 0" class="text-xs text-center py-8" style="color: var(--text-muted)">
                No unscheduled Subject Offerings for this term.
            </p>
            <p v-else-if="count === 0" class="text-xs text-center py-8" style="color: var(--text-muted)">
                No subjects match these filters.
            </p>

            <div
                v-for="offering in filteredOfferings"
                :key="offering.id"
                class="subject-card rounded-lg px-2.5 py-2 transition-all duration-150 ease-out"
                :class="offering.is_scheduled || !canManage
                    ? 'opacity-60 cursor-default'
                    : 'cursor-grab active:cursor-grabbing hover:-translate-y-0.5 hover:shadow-md'"
                :style="{
                    background: 'var(--card-bg)',
                    border: '1px solid var(--card-border)',
                    borderLeft: '4px solid var(--subject-accent)',
                    '--subject-accent': accentColor(offering.college_code),
                }"
                :draggable="canManage && !offering.is_scheduled"
                :title="canManage && !offering.is_scheduled ? 'Drag onto the grid, or click to open Edit Schedule and place it manually.' : null"
                @dragstart="onDragStart($event, offering)"
                @dragend="onDragEnd"
                @click="onCardClick(offering)"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="font-black text-[12px]" style="color: var(--text-primary)">
                        {{ offering.subject_code }}
                        <span class="font-bold" style="color: var(--text-muted)">· {{ offering.section_code }}</span>
                        <span
                            v-if="offering.is_irregular"
                            class="ml-1 inline-flex items-center px-1 py-0.5 rounded-full text-[8px] font-black uppercase"
                            style="background: rgba(212, 166, 42, 0.15); color: #D4A62A; border: 1px solid rgba(212, 166, 42, 0.3)"
                            title="Irregular Section"
                        >
                            Irr
                        </span>
                    </p>
                    <span
                        v-if="offering.is_scheduled"
                        class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase"
                        style="background: var(--card-border); color: var(--text-muted)"
                    >
                        {{ offering.overall_status }}
                    </span>
                    <span
                        v-else
                        class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase text-white shrink-0"
                        :style="{ background: accentColor(offering.college_code) }"
                    >
                        {{ offering.college_code }}
                    </span>
                </div>

                <p class="text-[11px] font-semibold truncate" style="color: var(--text-secondary)">
                    {{ offering.descriptive_title }}
                </p>

                <div class="grid grid-cols-2 gap-x-2 gap-y-0.5 mt-1.5 text-[10px]" style="color: var(--text-secondary)">
                    <span>{{ offering.program_code }} · Yr {{ offering.year_level }}</span>
                    <span>{{ offering.section_code }}</span>
                    <span>{{ offering.hours }} hrs</span>
                    <span>{{ offering.classification }}</span>
                    <span class="col-span-2 truncate">Faculty: <strong class="font-bold" style="color: var(--text-primary)">{{ offering.faculty_assigned ?? '—' }}</strong></span>
                    <span class="col-span-2 truncate">Pref. Room: <strong class="font-bold" style="color: var(--text-primary)">{{ offering.preferred_room_code ?? '—' }}</strong></span>
                    <span class="col-span-2">Room Type: <strong class="font-bold" style="color: var(--text-primary)">{{ offering.room_type ?? '—' }}</strong></span>
                </div>
            </div>
        </div>
    </aside>
</template>

<style scoped>
.subject-card {
    border-left-width: 4px;
}
.subject-card:not(.cursor-default):hover {
    border-left-width: 6px;
    padding-left: calc(0.625rem - 2px);
}
</style>