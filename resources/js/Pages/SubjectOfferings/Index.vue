<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import BulkUpdateWeeklyHoursModal from './BulkUpdateWeeklyHoursModal.vue'
import AssignmentDropdown from './AssignmentDropdown.vue'
import {
    ClipboardDocumentListIcon,
    PrinterIcon,
    TrashIcon,
    ClockIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
    offerings: Object,
    fulfilledOfferings: Array,
    academicTerms: Array,
    programs: Array,
    specializations: Array,
    sections: Array,
    statuses: Array,
    faculties: Array,
    rooms: Array,
    filters: Object,
    can: Object,
})

const form = reactive({
    academic_term_id: props.filters.academic_term_id ?? '',
    program_id: props.filters.program_id ?? '',
    specialization_id: props.filters.specialization_id ?? '',
    year_level: props.filters.year_level ?? '',
    section_id: props.filters.section_id ?? '',
    status: props.filters.status ?? '',
    search: props.filters.search ?? '',
})

let searchTimeout = null

function applyFilters() {
    router.get(route('subject-offerings.index'), { ...form }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

watch(() => form.search, () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(applyFilters, 350)
})

watch(
    () => [form.academic_term_id, form.program_id, form.specialization_id, form.year_level, form.section_id, form.status],
    applyFilters
)

// Specializations that belong to the selected Program (e.g. BSCRIM's
// FB/LD/QD/FI). Empty for single-track programs like BSIT, which is
// what hides the Specialization filter for them below.
const specializationsForProgram = computed(() => {
    if (! form.program_id) return []

    return props.specializations.filter(s => s.program_id === form.program_id)
})

// Sections narrowed to the selected Program and (if applicable)
// Specialization — this is what actually fixes "picked BSIT, still
// see every Section." Each Section carries program_id/specialization_id
// denormalized off its Curriculum (see SubjectOfferingController::index()).
const filteredSections = computed(() => {
    return props.sections.filter(section => {
        if (form.program_id && section.program_id !== form.program_id) return false

        if (form.specialization_id && section.specialization_id !== form.specialization_id) return false

        if (form.year_level && section.year_level !== form.year_level) return false

        return true
    })
})

// Changing Program invalidates any previously chosen Specialization/
// Section that no longer applies — otherwise a stale section_id from
// e.g. BSIT could keep silently filtering a BSCRIM query into an empty
// table with no visible reason why.
watch(() => form.program_id, () => {
    form.specialization_id = ''
    form.section_id = ''
})

watch(() => form.specialization_id, () => {
    form.section_id = ''
})

const activeTermLabel = computed(() => {
    const term = props.academicTerms.find(t => t.id === form.academic_term_id)
    return term ? term.display_name : null
})

// Opens the printable Class List in a new tab, carrying over every
// filter currently applied on this page (minus `status`, which the
// print view doesn't use — see SubjectOfferingController::print()).
function openPrintView() {
    const params = new URLSearchParams()

    if (form.academic_term_id) params.set('academic_term_id', form.academic_term_id)
    if (form.program_id) params.set('program_id', form.program_id)
    if (form.specialization_id) params.set('specialization_id', form.specialization_id)
    if (form.year_level) params.set('year_level', form.year_level)
    if (form.section_id) params.set('section_id', form.section_id)
    if (form.search) params.set('search', form.search)

    window.open(`${route('subject-offerings.print')}?${params.toString()}`, '_blank')
}

function statusBadgeClass(status) {
    return {
        Draft: 'bg-gray-100 text-gray-700 border-gray-200',
        Generated: 'bg-sky-100 text-sky-800 border-sky-200',
        'Faculty Assigned': 'bg-indigo-100 text-indigo-800 border-indigo-200',
        'Room Assigned': 'bg-cyan-100 text-cyan-800 border-cyan-200',
        'Ready for Scheduling': 'bg-violet-100 text-violet-800 border-violet-200',
        Scheduled: 'bg-emerald-100 text-emerald-800 border-emerald-200',
        Completed: 'bg-teal-100 text-teal-800 border-teal-200',
        Archived: 'bg-rose-100 text-rose-800 border-rose-200',
    }[status] ?? 'bg-gray-100 text-gray-700 border-gray-200'
}

// Overall Status is fully derived — this tooltip is just a reminder of
// *why*, since there's no dropdown here to click through anymore.
function statusHint(status) {
    return {
        Generated: 'No Faculty or Room assigned yet.',
        'Faculty Assigned': 'Faculty is assigned; Room is not.',
        'Room Assigned': 'Room is assigned; Faculty is not.',
        'Ready for Scheduling': 'Faculty and Room are both assigned.',
        Scheduled: 'A day/time has been assigned by the Scheduler.',
        Completed: "The Academic Term's class end date has passed.",
        Archived: 'The Academic Term has been Archived.',
    }[status] ?? ''
}

function assignmentBadgeClass(value) {
    return value === 'Assigned'
        ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
        : 'bg-amber-100 text-amber-800 border-amber-200'
}

function destroy(offering) {
    if (! confirm(`Delete Subject Offering ${offering.edp_code}?`)) return

    router.delete(route('subject-offerings.destroy', offering.id), { preserveScroll: true })
}

/*
|--------------------------------------------------------------------------
| Faculty Scope Eligibility (mirrors TeachingAssignments/Index.vue's
| checkEligibility(), which mirrors TeachingAssignmentService's
| assertFacultyScopeAllowsSubject on the server). This is only a
| client-side filter so the dropdown only ever OFFERS faculty who could
| actually be assigned — the server remains the authoritative check via
| assertBusinessRules() in assignFaculty().
|
| Deliberately does NOT check max units here (that needs each faculty's
| current load across every offering in the term, which this page
| doesn't otherwise load) — an over-cap pick still gets caught by the
| server and surfaces as the usual error toast.
--------------------------------------------------------------------------
*/

function isFacultyEligible(faculty, offering) {
    const isMajor = offering.classification === 'Major'
    const offeringDepartmentId = offering.program?.department_id ?? null

    if (faculty.faculty_scope === 'general' && isMajor) return false
    if (faculty.faculty_scope === 'departmental' && ! isMajor) return false
    if (faculty.faculty_scope === 'departmental' && offeringDepartmentId !== faculty.department_id) return false
    if (faculty.faculty_scope === 'cross_department' && isMajor && offeringDepartmentId !== faculty.department_id) return false

    return true
}

// Eligible faculty for this offering, PLUS whoever is already assigned
// (even if a scope/department change since would now make them
// ineligible) — so the dropdown never silently hides the current
// selection.
function facultyOptionsFor(offering) {
    const currentId = offering.teaching_assignment?.faculty?.id

    return props.faculties.filter(f => f.id === currentId || isFacultyEligible(f, offering))
}

// Room compatibility — mirrors Room::manageSubjects()/
// syncPreferredSubjects() server-side: a Lecture offering can only be
// paired with a Lecture room, a Laboratory offering only a Laboratory
// room. On top of that, a room must also be available to the
// offering's Program — General rooms are open to everyone, but a
// room scoped to specific Programs (e.g. only 'BSIT', or only
// 'BSCRIM') is otherwise excluded. This mirrors Room::scopeAvailableFor()
// on the server. Same "keep the current pick visible even if now
// mismatched" safety as facultyOptionsFor() above.
function isRoomEligible(room, offering) {
    if (room.room_type !== offering.room_type) return false

    const groupCodes = room.room_group_codes ?? []
    const programCode = offering.program?.code ?? null

    return groupCodes.includes('General') || (programCode !== null && groupCodes.includes(programCode))
}

function roomOptionsFor(offering) {
    const currentId = offering.preferred_by_rooms?.[0]?.id

    return props.rooms.filter(r => r.id === currentId || isRoomEligible(r, offering))
}

// AssignmentDropdown wants a flat { id, label } shape rather than
// raw Faculty/Room objects — these just adapt the two filtered lists
// above without changing the eligibility logic itself.
//
// Faculty additionally gets a `disabled` flag + a load-aware label:
// assigning this offering would need `offering.units` more capacity
// than the faculty has left (current_load vs effective_max_units,
// both computed server-side in SubjectOfferingController::index()).
// The faculty CURRENTLY assigned to this offering is never disabled
// for it — their existing load already includes this offering, so
// re-picking them doesn't add anything new. This is purely a UI
// hint; assertWithinMaxUnits() on the server is still what actually
// enforces the cap (see updateFaculty()'s error handling below).
function facultyDropdownOptionsFor(offering) {
    const currentId = offering.teaching_assignment?.faculty?.id
    const neededUnits = offering.units ?? 0

    return facultyOptionsFor(offering).map(f => {
        const isCurrent = f.id === currentId
        const wouldExceed = ! isCurrent && neededUnits > (f.remaining_units ?? Infinity)

        return {
            id: f.id,
            label: wouldExceed ? `${f.full_name} — Full Load` : f.full_name,
            disabled: wouldExceed,
        }
    })
}

function roomDropdownOptionsFor(offering) {
    return roomOptionsFor(offering).map(r => ({ id: r.id, label: r.room_code }))
}

/*
|--------------------------------------------------------------------------
| Override Eligibility (Faculty / Room)
|--------------------------------------------------------------------------
|
| Mirrors Master Grid's per-edit "Override Eligibility" checkbox in
| EditScheduleModal.vue — normal Scope/Department (Faculty) and
| Lecture/Laboratory (Room) rules are the right default almost always,
| but real scheduling occasionally needs a deliberate exception (a
| Major section covered by a Cross-Department faculty member, or an
| SHTM section borrowing a CCS computer lab). Checking the box here
| widens that ONE row's dropdown to every active Faculty / every Room,
| full stop — the server re-checks the same override flag before
| actually writing anything (see updateFaculty()/updatePreferredRoom()
| below and assignFaculty()/setPreferredRoom() in the controller), so
| this client-side widening is never the only thing standing between a
| person and an ineligible pick.
|
| Tracked per-offering-id (a Set, same pattern as selectedIds above)
| rather than one global flag, since many rows are visible on the page
| at once. Always resets after a successful save — see
| updateFaculty()/updatePreferredRoom() — so an override is a
| deliberate choice made fresh each time, never an accidentally-sticky
| setting left on for the next row someone edits.
*/

const facultyOverrideIds = ref(new Set())
const roomOverrideIds = ref(new Set())

function isFacultyOverridden(id) {
    return facultyOverrideIds.value.has(id)
}

function setFacultyOverride(id, value) {
    const next = new Set(facultyOverrideIds.value)
    value ? next.add(id) : next.delete(id)
    facultyOverrideIds.value = next
}

function isRoomOverridden(id) {
    return roomOverrideIds.value.has(id)
}

function setRoomOverride(id, value) {
    const next = new Set(roomOverrideIds.value)
    value ? next.add(id) : next.delete(id)
    roomOverrideIds.value = next
}

// Every active faculty / every room, unfiltered — what the dropdown
// swaps to while Override Eligibility is checked for that row. Same
// load-aware disabled/label treatment as facultyDropdownOptionsFor()
// above, since Max Units is a real constraint the server still
// enforces even during an override (only Scope/Department is
// skippable — see TeachingAssignmentService::assertBusinessRules()).
function facultyOverrideOptionsFor(offering) {
    const currentId = offering.teaching_assignment?.faculty?.id
    const neededUnits = offering.units ?? 0

    // props.faculties is already scoped to active faculty only (see
    // SubjectOfferingController::index()'s 'faculties' ->
    // where('status', true)) — no extra filtering needed here.
    return props.faculties.map(f => {
        const isCurrent = f.id === currentId
        const wouldExceed = ! isCurrent && neededUnits > (f.remaining_units ?? Infinity)

        return {
            id: f.id,
            label: wouldExceed ? `${f.full_name} — Full Load` : f.full_name,
            disabled: wouldExceed,
        }
    })
}

function roomOverrideOptionsFor() {
    return props.rooms.map(r => ({ id: r.id, label: r.room_code }))
}

/*
|--------------------------------------------------------------------------
| Inline Faculty / Preferred Room
|--------------------------------------------------------------------------
|
| Both dropdowns write immediately on change (no separate "Save"
| button) and reload only the `offerings` prop afterward, same
| pattern as applyBulkUpdate() below — filters/pagination survive.
| Faculty is a real Teaching Assignment; Room is a preference only
| (see SubjectOfferingController::assignFaculty()/setPreferredRoom()).
*/

const savingFacultyId = ref(null)
const savingRoomId = ref(null)

async function updateFaculty(offering, facultyId) {
    savingFacultyId.value = offering.id
    const override = isFacultyOverridden(offering.id)

    try {
        const { data } = await axios.post(route('subject-offerings.assign-faculty', offering.id), {
            faculty_id: facultyId || null,
            override,
        })

        flash.value = { type: 'success', message: data.message }
        router.reload({ only: ['offerings'], preserveScroll: true, preserveState: true })
    } catch (error) {
        flash.value = {
            type: 'error',
            message: error.response?.data?.message ?? 'Failed to assign Faculty. Please try again.',
        }
        // Reload anyway so the dropdown snaps back to the actual saved
        // value instead of sitting on the rejected selection.
        router.reload({ only: ['offerings'], preserveScroll: true, preserveState: true })
    } finally {
        savingFacultyId.value = null
        // Always resets, success or failure — a deliberate choice made
        // fresh each time, never left on for the next row.
        setFacultyOverride(offering.id, false)
        setTimeout(() => { flash.value = null }, 6000)
    }
}

async function updatePreferredRoom(offering, roomId) {
    savingRoomId.value = offering.id
    const override = isRoomOverridden(offering.id)

    try {
        const { data } = await axios.put(route('subject-offerings.set-preferred-room', offering.id), {
            room_id: roomId || null,
            override,
        })

        flash.value = { type: 'success', message: data.message }
        router.reload({ only: ['offerings'], preserveScroll: true, preserveState: true })
    } catch (error) {
        flash.value = {
            type: 'error',
            message: error.response?.data?.message ?? 'Failed to set Preferred Room. Please try again.',
        }
        router.reload({ only: ['offerings'], preserveScroll: true, preserveState: true })
    } finally {
        savingRoomId.value = null
        setRoomOverride(offering.id, false)
        setTimeout(() => { flash.value = null }, 6000)
    }
}

/*
|--------------------------------------------------------------------------
| Bulk Update Weekly Hours
|--------------------------------------------------------------------------
|
| Selection now persists across pagination — checking rows on page 1,
| then paging to page 2 and checking more, keeps everything checked
| until Apply, Cancel, or the filters themselves change (a different
| Academic Term/Program/etc. makes the previous selection meaningless,
| so that still resets it — see applyFilters()). selectedOfferingsCache
| holds the display fields (edp_code, subject_code, hours, ...) for
| every selected row regardless of which page it came from, since
| props.offerings.data only ever holds the CURRENT page and can't be
| relied on once the person has paged away.
*/

const selectedIds = ref(new Set())
const selectedOfferingsCache = reactive(new Map())
const showBulkModal = ref(false)
const bulkSubmitting = ref(false)
const flash = ref(null)

const currentPageIds = computed(() => [...new Set(props.offerings.data.map(o => o.id))])

const selectedCount = computed(() => selectedIds.value.size)

const allOnPageSelected = computed(() =>
    currentPageIds.value.length > 0 && currentPageIds.value.every(id => selectedIds.value.has(id))
)

const someOnPageSelected = computed(() =>
    currentPageIds.value.some(id => selectedIds.value.has(id)) && ! allOnPageSelected.value
)

function isSelected(id) {
    return selectedIds.value.has(id)
}

function cacheOffering(offering) {
    selectedOfferingsCache.set(offering.id, {
        id: offering.id,
        edp_code: offering.edp_code,
        subject_code: offering.subject?.subject_code ?? offering.edp_code,
        descriptive_title: offering.subject?.descriptive_title ?? '',
        hours: offering.hours,
    })
}

function toggleRow(offering) {
    const next = new Set(selectedIds.value)
    if (next.has(offering.id)) {
        next.delete(offering.id)
        selectedOfferingsCache.delete(offering.id)
    } else {
        next.add(offering.id)
        cacheOffering(offering)
    }
    selectedIds.value = next
}

function toggleSelectAllOnPage() {
    const next = new Set(selectedIds.value)

    if (allOnPageSelected.value) {
        currentPageIds.value.forEach(id => {
            next.delete(id)
            selectedOfferingsCache.delete(id)
        })
    } else {
        props.offerings.data.forEach(offering => {
            next.add(offering.id)
            cacheOffering(offering)
        })
    }

    selectedIds.value = next
}

const selectedOfferings = computed(() => {
    // Sourced from selectedOfferingsCache (populated as rows are
    // checked, across every page visited) rather than
    // props.offerings.data, which only ever holds the current page —
    // reading from offerings.data here would silently drop anything
    // selected on a page the person has since navigated away from.
    return Array.from(selectedIds.value)
        .map(id => selectedOfferingsCache.get(id))
        .filter(Boolean)
})

function openBulkModal() {
    if (selectedCount.value === 0) return
    showBulkModal.value = true
}

function closeBulkModal() {
    if (bulkSubmitting.value) return
    showBulkModal.value = false
}

async function applyBulkUpdate(newHours) {
    bulkSubmitting.value = true

    try {
        const { data } = await axios.post(route('subject-offerings.bulk-update-weekly-hours'), {
            subject_offering_ids: Array.from(selectedIds.value),
            hours: newHours,
        })

        flash.value = { type: 'success', message: data.message }
        showBulkModal.value = false
        selectedIds.value = new Set()
        selectedOfferingsCache.clear()

        // Reload only the `offerings` prop — filters, sort order, and
        // the current page all stay exactly as they were, per spec.
        router.reload({ only: ['offerings'], preserveScroll: true, preserveState: true })
    } catch (error) {
        flash.value = {
            type: 'error',
            message: error.response?.data?.message ?? 'Failed to update Weekly Hours. Please try again.',
        }
    } finally {
        bulkSubmitting.value = false
        setTimeout(() => { flash.value = null }, 6000)
    }
}

// Only an actual filter change (Academic Term/Program/Specialization/
// Year/Section/Status/Search) invalidates the current selection —
// paging through offerings.data via the Link pagination below must
// NOT clear it, that's the whole point of persisting selection
// across pages. See applyFilters() above, the sole place these
// filters are submitted.
watch(
    () => [form.academic_term_id, form.program_id, form.specialization_id, form.year_level, form.section_id, form.status, form.search],
    () => {
        selectedIds.value = new Set()
        selectedOfferingsCache.clear()
    }
)
</script>

<template>
    <Head title="Subject Offerings" />

    <AppLayout>
        <div class="relative">

            <!-- Subtle brand texture: faint grid + one soft gold glow, static (no animation) -->
            <div class="pointer-events-none absolute -inset-x-6 -inset-y-6 -z-10 overflow-hidden">
                <div
                    class="absolute inset-0 opacity-[0.03] dark:opacity-[0.05]"
                    style="background-image: linear-gradient(#1e3a5f 1px, transparent 1px), linear-gradient(90deg, #1e3a5f 1px, transparent 1px); background-size: 42px 42px;"
                ></div>
                <div class="absolute -top-16 right-0 h-64 w-64 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
            </div>

            <div class="flex flex-col gap-6">

            <!-- Bulk action flash -->
            <div
                v-if="flash"
                class="rounded-xl border px-4 py-3 text-sm font-medium"
                :class="flash.type === 'success'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : 'border-rose-200 bg-rose-50 text-rose-800'"
            >
                {{ flash.message }}
            </div>

            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                        <ClipboardDocumentListIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Subject Offerings
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            Classes imported from a Curriculum into an Academic Term. No
                            Faculty, Room, or schedule is assigned here.
                            <span v-if="activeTermLabel"> Showing: <strong>{{ activeTermLabel }}</strong></span>
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        @click="openPrintView"
                        type="button"
                        class="btn-neutral inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold"
                    >
                        <PrinterIcon class="h-4 w-4" />
                        Print Class List
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow p-4">
                <div class="flex flex-wrap gap-3">
                    <div class="min-w-[160px] flex-1 basis-40">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            Academic Term
                        </label>
                        <select
                            v-model="form.academic_term_id"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="">All Terms</option>
                            <option v-for="term in academicTerms" :key="term.id" :value="term.id">
                                {{ term.display_name }}
                            </option>
                        </select>
                    </div>

                    <div class="min-w-[160px] flex-1 basis-40">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            Program
                        </label>
                        <select
                            v-model="form.program_id"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="">All Programs</option>
                            <option v-for="program in programs" :key="program.id" :value="program.id">
                                {{ program.code }}
                            </option>
                        </select>
                    </div>

                    <!-- Only shows once a Program with multiple tracks (e.g.
                         BSCRIM's FB/LD/QD/FI) is selected — single-track
                         Programs like BSIT never trigger this. When it's
                         absent, the flex-wrap layout above lets every other
                         filter (especially Search) stretch to fill the gap
                         instead of leaving empty grid space. -->
                    <div v-if="specializationsForProgram.length > 0" class="min-w-[160px] flex-1 basis-40">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            Specialization
                        </label>
                        <select
                            v-model="form.specialization_id"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="">All Specializations</option>
                            <option v-for="spec in specializationsForProgram" :key="spec.id" :value="spec.id">
                                {{ spec.code ?? spec.name }}
                            </option>
                        </select>
                    </div>

                    <div class="min-w-[160px] flex-1 basis-40">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            Year Level
                        </label>
                        <select
                            v-model="form.year_level"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="">All Years</option>
                            <option v-for="y in [1, 2, 3, 4]" :key="y" :value="y">Year {{ y }}</option>
                        </select>
                    </div>

                    <div class="min-w-[160px] flex-1 basis-40">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            Section
                        </label>
                        <select
                            v-model="form.section_id"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="">All Sections</option>
                            <option v-for="section in filteredSections" :key="section.id" :value="section.id">
                                {{ section.section_code }}{{ section.is_irregular ? ' (Irregular)' : '' }}
                            </option>
                        </select>
                    </div>

                    <div class="min-w-[160px] flex-1 basis-40">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            Status
                        </label>
                        <select
                            v-model="form.status"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="">All Statuses</option>
                            <option v-for="status in statuses" :key="status" :value="status">
                                {{ status }}
                            </option>
                        </select>
                    </div>

                    <div class="min-w-[200px] flex-1 basis-40">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            Search
                        </label>
                        <input
                            v-model="form.search"
                            type="text"
                            placeholder="EDP Code, Section, Subject..."
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        />
                    </div>
                </div>
            </div>

            <!-- Bulk Update Weekly Hours bar -->
            <div
                v-if="can.bulkUpdateWeeklyHours"
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] px-4 py-3 shadow"
            >
                <div class="text-sm text-[var(--text-muted)]">
                    <template v-if="selectedCount > 0">
                        <span class="font-semibold text-[var(--text-primary)]">Selected</span>
                        — {{ selectedCount }} Subject Offering{{ selectedCount === 1 ? '' : 's' }}
                    </template>
                    <template v-else>
                        Select rows below to bulk update their Weekly Hours.
                    </template>
                </div>

                <button
                    type="button"
                    :disabled="selectedCount === 0"
                    @click="openBulkModal"
                    class="btn-info inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40"
                >
                    <ClockIcon class="h-4 w-4" />
                    Bulk Update Weekly Hours
                </button>
            </div>

            <!-- Table -->
            <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-lg transition-colors duration-300">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--page-bg)] border-b border-[var(--card-border)]">
                        <tr>
                            <th v-if="can.bulkUpdateWeeklyHours" class="w-10 px-4 py-3">
                                <input
                                    type="checkbox"
                                    class="h-4.5 w-4.5 cursor-pointer rounded border-2 border-[#8A94A6] bg-white text-[#D4A62A] accent-[#D4A62A] focus:ring-2 focus:ring-[#D4A62A]/40"
                                    :checked="allOnPageSelected"
                                    :indeterminate="someOnPageSelected"
                                    @change="toggleSelectAllOnPage"
                                />
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">EDP Code</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Program</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Year</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Section</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Subject</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Units</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Hours</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Classification</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Faculty</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Room</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Overall Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="offering in offerings.data"
                            :key="offering.id"
                            class="border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                            :class="{ 'bg-[#D4A62A]/5': can.bulkUpdateWeeklyHours && isSelected(offering.id) }"
                        >
                            <td v-if="can.bulkUpdateWeeklyHours" class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    class="h-4.5 w-4.5 cursor-pointer rounded border-2 border-[#8A94A6] bg-white text-[#D4A62A] accent-[#D4A62A] focus:ring-2 focus:ring-[#D4A62A]/40"
                                    :checked="isSelected(offering.id)"
                                    @change="toggleRow(offering)"
                                />
                            </td>
                            <td class="px-4 py-3 font-mono font-medium text-[var(--text-primary)]">
                                {{ offering.edp_code }}
                            </td>
                            <td class="px-4 py-3 text-[var(--text-primary)]">
                                {{ offering.program?.code }}
                            </td>
                            <td class="px-4 py-3 text-center text-[var(--text-primary)]">
                                {{ offering.year_level }}
                            </td>
                            <td class="px-4 py-3 text-[var(--text-primary)]">
                                {{ offering.section?.section_code }}
                                <span
                                    v-if="offering.section?.is_irregular"
                                    class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded-full bg-[#D4A62A]/10 text-[#D4A62A] text-[10px] font-medium border border-[#D4A62A]/30"
                                    title="Irregular Section"
                                >
                                    Irr
                                </span>
                            </td>
                            <td class="px-4 py-3 text-[var(--text-primary)]">
                                <div class="font-medium">{{ offering.subject?.subject_code }}</div>
                                <div class="text-xs text-[var(--text-muted)]">{{ offering.subject?.descriptive_title }}</div>
                            </td>
                            <td class="px-4 py-3 text-center text-[var(--text-primary)]">
                                {{ offering.units ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-center text-[var(--text-primary)]">
                                {{ offering.hours ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-center text-[var(--text-primary)]">
                                {{ offering.classification ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <AssignmentDropdown
                                    v-if="can.assignFaculty"
                                    :model-value="offering.teaching_assignment?.faculty?.id ?? ''"
                                    :options="facultyDropdownOptionsFor(offering)"
                                    :disabled="savingFacultyId === offering.id"
                                    :badge-class="assignmentBadgeClass(offering.faculty_status)"
                                    max-width-class="max-w-[180px]"
                                    overridable
                                    :override="isFacultyOverridden(offering.id)"
                                    :override-options="facultyOverrideOptionsFor(offering)"
                                    @update:model-value="value => updateFaculty(offering, value)"
                                    @update:override="value => setFacultyOverride(offering.id, value)"
                                />
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold"
                                    :class="assignmentBadgeClass(offering.faculty_status)"
                                >
                                    {{ offering.teaching_assignment?.faculty?.full_name ?? offering.faculty_status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <AssignmentDropdown
                                    v-if="can.setPreferredRoom"
                                    :model-value="offering.preferred_by_rooms?.[0]?.id ?? ''"
                                    :options="roomDropdownOptionsFor(offering)"
                                    :disabled="savingRoomId === offering.id"
                                    :badge-class="assignmentBadgeClass(offering.room_status)"
                                    max-width-class="max-w-[160px]"
                                    overridable
                                    :override="isRoomOverridden(offering.id)"
                                    :override-options="roomOverrideOptionsFor()"
                                    @update:model-value="value => updatePreferredRoom(offering, value)"
                                    @update:override="value => setRoomOverride(offering.id, value)"
                                />
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold"
                                    :class="assignmentBadgeClass(offering.room_status)"
                                >
                                    {{ offering.preferred_by_rooms?.[0]?.room_code ?? offering.room_status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold"
                                    :class="statusBadgeClass(offering.overall_status)"
                                    :title="statusHint(offering.overall_status)"
                                >
                                    {{ offering.overall_status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button
                                    v-if="can.delete"
                                    @click="destroy(offering)"
                                    title="Delete"
                                    aria-label="Delete Subject Offering"
                                    class="btn-delete inline-flex items-center justify-center p-2"
                                >
                                    <TrashIcon class="h-3.5 w-3.5" />
                                </button>
                            </td>
                        </tr>

                        <tr v-if="offerings.data.length === 0">
                            <td :colspan="can.bulkUpdateWeeklyHours ? 13 : 12" class="text-center py-8 text-[var(--text-muted)]">
                                {{ fulfilledOfferings?.length
                                    ? 'No Subject Offerings of its own for this Section — see Reused Subjects below.'
                                    : 'No Subject Offerings found. Try adjusting your filters.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Reused Subjects: these were attached to an Irregular
                 Section by fulfillment rather than a new Subject
                 Offering (see storeIrregular()) — no EDP Code of their
                 own was minted, so they'd otherwise never show up in
                 the table above when filtered to this Section. -->
            <div
                v-if="fulfilledOfferings?.length"
                class="mt-4 rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/5 overflow-hidden"
            >
                <div class="px-4 py-3 border-b border-[#D4A62A]/20">
                    <p class="text-sm font-semibold text-[var(--text-primary)]">
                        Reused Subjects ({{ fulfilledOfferings.length }})
                    </p>
                    <p class="text-xs text-[var(--text-muted)] mt-0.5">
                        Covered by an existing Regular Section's Subject Offering — no separate EDP Code was created for these.
                    </p>
                </div>

                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--page-bg)]/60 border-b border-[#D4A62A]/20">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">EDP Code</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Program</th>
                            <th class="px-4 py-2 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Year</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Subject</th>
                            <th class="px-4 py-2 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Units</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Reused From</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="fulfillment in fulfilledOfferings"
                            :key="fulfillment.id"
                            class="border-t border-[#D4A62A]/10"
                        >
                            <td class="px-4 py-2.5 font-mono font-medium text-[var(--text-primary)]">
                                {{ fulfillment.edp_code }}
                            </td>
                            <td class="px-4 py-2.5 text-[var(--text-primary)]">
                                {{ fulfillment.program?.code }}
                            </td>
                            <td class="px-4 py-2.5 text-center text-[var(--text-primary)]">
                                {{ fulfillment.year_level }}
                            </td>
                            <td class="px-4 py-2.5 text-[var(--text-primary)]">
                                <div class="font-medium">{{ fulfillment.subject?.subject_code }}</div>
                                <div class="text-xs text-[var(--text-muted)]">{{ fulfillment.subject?.descriptive_title }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-center text-[var(--text-primary)]">
                                {{ fulfillment.units ?? '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-[var(--text-primary)]">
                                <span class="inline-flex items-center rounded-full border border-[#D4A62A]/30 bg-white px-2.5 py-0.5 text-xs font-semibold text-[#D4A62A]">
                                    {{ fulfillment.reused_from_section }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="offerings.links?.length > 3" class="flex flex-wrap items-center justify-center gap-1">
                <Link
                    v-for="(link, index) in offerings.links"
                    :key="index"
                    :href="link.url ?? '#'"
                    v-html="link.label"
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="link.active
                        ? 'btn-info text-white'
                        : link.url
                            ? 'btn-neutral'
                            : 'pointer-events-none opacity-40'"
                    preserve-scroll
                    preserve-state
                />
            </div>
            </div>
        </div>

        <BulkUpdateWeeklyHoursModal
            :open="showBulkModal"
            :offerings="selectedOfferings"
            :submitting="bulkSubmitting"
            @close="closeBulkModal"
            @apply="applyBulkUpdate"
        />
    </AppLayout>
</template>