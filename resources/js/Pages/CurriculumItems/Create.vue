<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { ClipboardDocumentListIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline'

defineOptions({
    layout: DashboardLayout,
})

const props = defineProps({
    curricula: Array,
    subjects: Array,
    practicumSubjects: Array,
    selectedCurriculumId: Number,
    assignedSubjectIds: Array,
})

const form = useForm({
    curriculum_id: props.selectedCurriculumId ?? '',
    item_type: 'Subject',
    subject_ids: [],
    subject_id: '',
    ojt_hours: '',
    year_level: 1,
    semester: 1,
    active: true,
})

// When arriving from a curriculum's Manage Items page, the curriculum
// is already known — lock the dropdown so the user can't accidentally
// attach the item somewhere else.
const curriculumLocked = computed(() => !!props.selectedCurriculumId)

const isSubject = computed(() => form.item_type === 'Subject')
const isOjt = computed(() => form.item_type === 'OJT')

// Switching item type clears whichever fields belonged to the previous
// type, so a half-filled Practicum/OJT form can't accidentally submit
// alongside chosen subjects (or vice versa).
watch(() => form.item_type, () => {
    form.subject_ids = []
    form.subject_id = ''
    form.ojt_hours = ''

    // 5th Year isn't a valid placement for Practicum/OJT items — see the
    // Year Level select below.
    if (form.item_type === 'OJT' && form.year_level === 5) {
        form.year_level = 4
    }
})

const assignedSet = computed(() => new Set(props.assignedSubjectIds ?? []))

const alreadyAssignedSubjects = computed(() => {
    return props.subjects.filter((subject) => assignedSet.value.has(subject.id))
})

/*
|--------------------------------------------------------------------------
| Subject Checklist — Search / Classification Filter (local UI state)
|--------------------------------------------------------------------------
|
| These live outside the Inertia form on purpose: they only control what
| the checklist *displays*, never what gets submitted, and they must
| survive checkbox clicks untouched (Goal 7 — search/filter state is
| never reset just because the user selected something).
|
*/

const subjectSearch = ref('')
const classificationFilter = ref('All') // 'All' | 'Minor' | 'Major'

/*
|--------------------------------------------------------------------------
| Goal 1 — Automatic Program Filtering
|--------------------------------------------------------------------------
|
| A curriculum belongs to a Program (e.g. BSIT). Subjects now carry a
| many-to-many set of applicable programs (room_group_codes — see
| Subject::roomGroups() / Subject::getRoomGroupCodesAttribute()) instead
| of a single required_room_group value, and that assignment is
| independent of Classification (Major/Minor).
|
| The checklist should only ever offer a subject if it's applicable to
| the curriculum's program at all:
|   - it's tagged "General" (General Education — shared across every
|     program), or
|   - it's tagged with the curriculum's own program code specifically
|     (a subject can carry several program tags at once, e.g. a shared
|     BSHM + BSTM subject — it's included for either).
|
| This no longer special-cases Minor subjects the way the old
| required_room_group logic did — a Minor subject that's only tagged to
| one specific program (e.g. SP101 -> Minor -> BSIT) now correctly only
| shows up for that program, not for every curriculum.
|
| The mapping is derived entirely from data already sent to this page
| (curricula[].program.code and subjects[].room_group_codes), so this
| runs instantly on the client with no extra request.
|
*/

const selectedCurriculum = computed(() => {
    return props.curricula.find((curriculum) => curriculum.id === form.curriculum_id) ?? null
})

const curriculumProgramCode = computed(() => {
    return selectedCurriculum.value?.program?.code ?? null
})

// A subject is applicable to the curriculum's program if it's tagged
// "General" (applies everywhere) or tagged with that program code
// specifically — regardless of how many other programs it's also
// tagged with, and regardless of Classification.
function isApplicableToProgram(subject, programCode) {
    const groups = subject.room_group_codes ?? []
    return groups.includes('General') || groups.includes(programCode)
}

const availableSubjects = computed(() => {
    return props.subjects.filter((subject) => !assignedSet.value.has(subject.id))
})

const programFilteredSubjects = computed(() => {
    if (!form.curriculum_id) {
        return []
    }

    return availableSubjects.value.filter((subject) => {
        return isApplicableToProgram(subject, curriculumProgramCode.value)
    })
})

// Dropping a selected subject the moment it stops being valid for the
// chosen curriculum (e.g. the user switches curriculum after picking
// some subjects) keeps the form from ever submitting a mismatched pair.
watch(() => form.curriculum_id, () => {
    const allowedIds = new Set(programFilteredSubjects.value.map((subject) => subject.id))
    form.subject_ids = form.subject_ids.filter((id) => allowedIds.has(id))

    const allowedPracticumIds = new Set(filteredPracticumSubjects.value.map((subject) => subject.id))
    if (form.subject_id && !allowedPracticumIds.has(form.subject_id)) {
        form.subject_id = ''
    }
})

/*
|--------------------------------------------------------------------------
| Practicum / OJT — Practicum Subject Filtering
|--------------------------------------------------------------------------
|
| The `practicumSubjects` prop is already scoped to is_practicum = true
| at the controller level (a Practicum subject is never mixed into the
| `subjects` prop used above), so the only filtering left to do here is
| the same program-matching rule used above: only offer subjects tagged
| "General" or tagged with the curriculum's own program code.
|
*/

const availablePracticumSubjects = computed(() => {
    return props.practicumSubjects.filter((subject) => !assignedSet.value.has(subject.id))
})

const filteredPracticumSubjects = computed(() => {
    if (!form.curriculum_id) {
        return []
    }

    return availablePracticumSubjects.value.filter((subject) => {
        return isApplicableToProgram(subject, curriculumProgramCode.value)
    })
})

const selectedPracticumSubject = computed(() => {
    return filteredPracticumSubjects.value.find((subject) => subject.id === form.subject_id) ?? null
})

/*
|--------------------------------------------------------------------------
| Goal 2 & 3 — Search + Classification Filter
|--------------------------------------------------------------------------
*/

const searchedSubjects = computed(() => {
    const term = subjectSearch.value.trim().toLowerCase()

    if (!term) {
        return programFilteredSubjects.value
    }

    return programFilteredSubjects.value.filter((subject) => {
        return subject.subject_code.toLowerCase().includes(term)
            || subject.descriptive_title.toLowerCase().includes(term)
    })
})

const visibleSubjects = computed(() => {
    if (classificationFilter.value === 'Minor') {
        return searchedSubjects.value.filter((subject) => !subject.is_major)
    }

    if (classificationFilter.value === 'Major') {
        return searchedSubjects.value.filter((subject) => subject.is_major)
    }

    return searchedSubjects.value
})

/*
|--------------------------------------------------------------------------
| Goal 9 (enhancement) — Group + Sort
|--------------------------------------------------------------------------
|
| Minors ("General Education") first, then Majors — each group sorted
| alphabetically by subject code, so a long prospectus is easy to scan.
|
*/

function sortByCode(subjects) {
    return [...subjects].sort((a, b) => a.subject_code.localeCompare(b.subject_code))
}

const visibleMinorSubjects = computed(() => {
    return sortByCode(visibleSubjects.value.filter((subject) => !subject.is_major))
})

const visibleMajorSubjects = computed(() => {
    return sortByCode(visibleSubjects.value.filter((subject) => subject.is_major))
})

/*
|--------------------------------------------------------------------------
| Goal 5 — Selection Counter
|--------------------------------------------------------------------------
|
| "shown" reflects the current search/filter view; "selected" reflects
| the full selection made so far (not just what's currently visible), so
| narrowing the list never makes it look like earlier picks were lost.
|
*/

const shownCount = computed(() => visibleSubjects.value.length)
const selectedCount = computed(() => form.subject_ids.length)

/*
|--------------------------------------------------------------------------
| Goal 6 — Select All (visible subjects only)
|--------------------------------------------------------------------------
*/

const allVisibleSelected = computed(() => {
    return visibleSubjects.value.length > 0
        && visibleSubjects.value.every((subject) => form.subject_ids.includes(subject.id))
})

function toggleSelectAll() {
    const visibleIds = visibleSubjects.value.map((subject) => subject.id)

    if (allVisibleSelected.value) {
        // Deselect only what's currently visible — selections made
        // under a different search/filter view are left untouched.
        form.subject_ids = form.subject_ids.filter((id) => !visibleIds.includes(id))
        return
    }

    const merged = new Set(form.subject_ids)
    visibleIds.forEach((id) => merged.add(id))
    form.subject_ids = Array.from(merged)
}

function resetSubjectFilters() {
    subjectSearch.value = ''
    classificationFilter.value = 'All'
}

const backHref = computed(() => {
    return curriculumLocked.value
        ? route('curriculums.items.manage', props.selectedCurriculumId)
        : route('curriculum-items.index')
})

function curriculumLabel(curriculum) {
    return `${curriculum.code} — ${curriculum.name}`
}

function submit() {
    form.post(route('curriculum-items.store'))
}
</script>

<template>

<Head title="Add Curriculum Item" />

<div class="relative">

    <!-- Subtle brand texture: faint grid + one soft gold glow, static (no animation) -->
    <div class="pointer-events-none absolute -inset-x-6 -inset-y-6 -z-10 overflow-hidden">
        <div
            class="absolute inset-0 opacity-[0.03] dark:opacity-[0.05]"
            style="background-image: linear-gradient(#1e3a5f 1px, transparent 1px), linear-gradient(90deg, #1e3a5f 1px, transparent 1px); background-size: 42px 42px;"
        ></div>
        <div class="absolute -top-16 right-0 h-64 w-64 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
    </div>

    <!-- Header -->

    <div class="flex justify-between items-center mb-6">

        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                <ClipboardDocumentListIcon class="h-5.5 w-5.5" />
            </div>
            <div>
                <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                    Add Curriculum Item
                </h1>
                <p class="text-sm text-[var(--text-muted)]">
                    Attach one or more Subjects, or a single Practicum/OJT item, to a curriculum's prospectus.
                </p>
            </div>
        </div>

        <Link
            :href="backHref"
            class="inline-flex items-center gap-1 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:underline transition-colors duration-150"
        >
            <ArrowLeftIcon class="h-3.5 w-3.5" />
            Back
        </Link>

    </div>

    <!-- Form -->

    <form
        @submit.prevent="submit"
        class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg p-6 space-y-6 transition-colors duration-300"
    >

        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

        <!-- Item Type -->

        <div>

            <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                Item Type
            </label>

            <select
                v-model="form.item_type"
                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] md:w-1/3 transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            >
                <option value="Subject">Subject</option>
                <option value="OJT">Practicum / OJT</option>
            </select>

            <p v-if="form.errors.item_type" class="text-red-600 text-sm mt-1">
                {{ form.errors.item_type }}
            </p>

        </div>

        <!-- Curriculum / Year Level / Semester -->

        <div class="grid grid-cols-3 gap-4">

            <div>

                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                    Curriculum
                </label>

                <select
                    v-model="form.curriculum_id"
                    :disabled="curriculumLocked"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] disabled:bg-[var(--page-bg)] disabled:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="" disabled>Select curriculum</option>

                    <option
                        v-for="curriculum in curricula"
                        :key="curriculum.id"
                        :value="curriculum.id"
                    >
                        {{ curriculumLabel(curriculum) }}
                    </option>
                </select>

                <p v-if="form.errors.curriculum_id" class="text-red-600 text-sm mt-1">
                    {{ form.errors.curriculum_id }}
                </p>

            </div>

            <div>

                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                    Year Level
                </label>

                <select
                    v-model.number="form.year_level"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option :value="1">1st Year</option>
                    <option :value="2">2nd Year</option>
                    <option :value="3">3rd Year</option>
                    <option :value="4">4th Year</option>
                    
                </select>

                <p v-if="form.errors.year_level" class="text-red-600 text-sm mt-1">
                    {{ form.errors.year_level }}
                </p>

            </div>

            <div>

                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                    Semester
                </label>

                <select
                    v-model.number="form.semester"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option :value="1">First Semester</option>
                    <option :value="2">Second Semester</option>
                    <option :value="3">Summer</option>
                </select>

                <p v-if="form.errors.semester" class="text-red-600 text-sm mt-1">
                    {{ form.errors.semester }}
                </p>

            </div>

        </div>

        <!-- Subject Checklist -->

        <div v-if="isSubject">

            <p class="text-xs text-[var(--text-muted)] mb-2">
                All subjects you check below get placed into this same Year Level / Semester.
                Need different subjects in a different term? Submit this batch first, then run
                Add Item again for the next term.
            </p>

            <!-- No curriculum chosen yet -->

            <div
                v-if="!form.curriculum_id"
                class="border border-dashed border-[var(--card-border)] bg-[var(--page-bg)] text-[var(--text-primary)] rounded-xl px-4 py-6 text-center text-sm text-[var(--text-muted)]"
            >
                Select a curriculum above to see the subjects available for it.
            </div>

            <template v-else>

                <!-- Search / Classification Filter -->

                <div class="flex flex-col sm:flex-row gap-3 mb-3">

                    <input
                        v-model="subjectSearch"
                        type="text"
                        placeholder="Search by subject code or title..."
                        class="w-full sm:flex-1 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    />

                    <select
                        v-model="classificationFilter"
                        class="w-full sm:w-44 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    >
                        <option value="All">All Classifications</option>
                        <option value="Minor">Minor</option>
                        <option value="Major">Major</option>
                    </select>

                    <button
                        v-if="subjectSearch || classificationFilter !== 'All'"
                        type="button"
                        @click="resetSubjectFilters"
                        class="text-sm text-[var(--text-muted)] hover:underline whitespace-nowrap sm:px-2"
                    >
                        Clear filters
                    </button>

                </div>

                <!-- Counter / Select All -->

                <div class="flex justify-between items-center mb-2">

                    <label class="block text-sm font-medium text-[var(--text-primary)]">
                        Subjects
                        <span class="text-[var(--text-muted)] font-normal">
                            ({{ shownCount }} shown &bull; {{ selectedCount }} selected)
                        </span>
                    </label>

                    <button
                        type="button"
                        @click="toggleSelectAll"
                        :disabled="visibleSubjects.length === 0"
                        class="text-sm text-blue-500 hover:underline disabled:text-[var(--text-muted)] disabled:no-underline"
                    >
                        {{ allVisibleSelected ? 'Clear shown' : 'Select all shown' }}
                    </button>

                </div>

                <div class="border border-[var(--card-border)] rounded-xl max-h-96 overflow-y-auto">

                    <!-- General Education (Minors) -->

                    <div v-if="visibleMinorSubjects.length > 0">

                        <p class="sticky top-0 bg-[var(--page-bg)] text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] px-4 py-1.5 border-b border-[var(--card-border)]">
                            General Education
                        </p>

                        <div class="divide-y divide-[var(--card-border)]">

                            <label
                                v-for="subject in visibleMinorSubjects"
                                :key="subject.id"
                                class="flex items-center gap-3 px-4 py-2.5 hover:bg-[var(--page-bg)] cursor-pointer transition-colors duration-150"
                            >
                                <input
                                    type="checkbox"
                                    :value="subject.id"
                                    v-model="form.subject_ids"
                                    class="rounded accent-[#D4A62A]"
                                />
                                <span class="text-sm text-[var(--text-primary)]">
                                    {{ subject.subject_code }} &mdash; {{ subject.descriptive_title }}
                                </span>
                                <span class="ml-auto shrink-0 inline-flex px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-xs font-medium">
                                    Minor
                                </span>
                            </label>

                        </div>

                    </div>

                    <!-- Major Subjects -->

                    <div v-if="visibleMajorSubjects.length > 0">

                        <p class="sticky top-0 bg-[var(--page-bg)] text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] px-4 py-1.5 border-b border-t border-[var(--card-border)]">
                            Major Subjects
                        </p>

                        <div class="divide-y divide-[var(--card-border)]">

                            <label
                                v-for="subject in visibleMajorSubjects"
                                :key="subject.id"
                                class="flex items-center gap-3 px-4 py-2.5 hover:bg-[var(--page-bg)] cursor-pointer transition-colors duration-150"
                            >
                                <input
                                    type="checkbox"
                                    :value="subject.id"
                                    v-model="form.subject_ids"
                                    class="rounded accent-[#D4A62A]"
                                />
                                <span class="text-sm text-[var(--text-primary)]">
                                    {{ subject.subject_code }} &mdash; {{ subject.descriptive_title }}
                                </span>
                                <span class="ml-auto shrink-0 inline-flex px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-600 dark:text-blue-400 text-xs font-medium">
                                    Major
                                </span>
                            </label>

                        </div>

                    </div>

                    <!-- No results for the current search/filter -->

                    <p
                        v-if="visibleSubjects.length === 0"
                        class="px-4 py-6 text-center text-sm text-[var(--text-muted)]"
                    >
                        No subjects match your search/filter.
                    </p>

                    <!-- Already assigned to this curriculum -->

                    <div
                        v-if="alreadyAssignedSubjects.length > 0"
                        class="bg-[var(--page-bg)] border-t border-[var(--card-border)]"
                    >

                        <p class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] px-4 py-1.5">
                            Already Assigned
                        </p>

                        <div
                            v-for="subject in alreadyAssignedSubjects"
                            :key="subject.id"
                            class="flex items-center gap-3 px-4 py-2.5 text-[var(--text-muted)]"
                        >
                            <span class="text-sm">
                                {{ subject.subject_code }} &mdash; {{ subject.descriptive_title }}
                            </span>
                            <span class="ml-auto shrink-0 text-xs bg-[var(--card-border)] text-[var(--text-muted)] px-2 py-0.5 rounded-full">
                                Already assigned
                            </span>
                        </div>

                    </div>

                </div>

            </template>

            <p v-if="form.errors.subject_ids" class="text-red-600 text-sm mt-1">
                {{ form.errors.subject_ids }}
            </p>

        </div>

        <!-- Practicum / OJT Fields -->

        <div v-else-if="isOjt">

            <!-- No curriculum chosen yet -->

            <div
                v-if="!form.curriculum_id"
                class="border border-dashed border-[var(--card-border)] bg-[var(--page-bg)] text-[var(--text-primary)] rounded-xl px-4 py-6 text-center text-sm text-[var(--text-muted)]"
            >
                Select a curriculum above to see the practicum subjects available for its program.
            </div>

            <template v-else>

                <div class="grid grid-cols-2 gap-4">

                    <div>

                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                            Practicum Subject
                        </label>

                        <select
                            v-model="form.subject_id"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="" disabled>Select practicum subject</option>

                            <option
                                v-for="subject in filteredPracticumSubjects"
                                :key="subject.id"
                                :value="subject.id"
                            >
                                {{ subject.subject_code }} &mdash; {{ subject.descriptive_title }}
                            </option>
                        </select>

                        <p
                            v-if="filteredPracticumSubjects.length === 0"
                            class="text-[var(--text-muted)] text-xs mt-1"
                        >
                            No practicum subjects are set up yet for this curriculum's program.
                        </p>

                        <p v-if="form.errors.subject_id" class="text-red-600 text-sm mt-1">
                            {{ form.errors.subject_id }}
                        </p>

                    </div>

                    <div>

                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                            Hours
                        </label>

                        <input
                            v-model.number="form.ojt_hours"
                            type="number"
                            min="1"
                            placeholder="e.g. 486"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        />

                        <p v-if="form.errors.ojt_hours" class="text-red-600 text-sm mt-1">
                            {{ form.errors.ojt_hours }}
                        </p>

                    </div>

                </div>

                <!-- Auto-filled Subject Code / Title (Goal 4) -->

                <div
                    v-if="selectedPracticumSubject"
                    class="mt-4 flex justify-between items-center rounded-xl bg-[var(--page-bg)] border border-[var(--card-border)] px-4 py-3 text-sm"
                >
                    <span class="font-semibold text-[var(--text-primary)]">
                        {{ selectedPracticumSubject.subject_code }}
                    </span>
                    <span class="text-[var(--text-muted)]">
                        {{ selectedPracticumSubject.descriptive_title }}
                    </span>
                </div>

            </template>

        </div>

        <!-- Toggles -->

        <div class="flex items-center gap-8">

            <label class="flex items-center gap-2">
                <input type="checkbox" v-model="form.active" class="rounded accent-[#D4A62A]" />
                <span class="text-sm text-[var(--text-primary)]">Active</span>
            </label>

        </div>

        <!-- Actions -->

        <div class="flex justify-end gap-3 pt-4 border-t border-[var(--card-border)]">

            <Link
                :href="backHref"
                class="btn-neutral"
            >
                Cancel
            </Link>

            <button
                type="submit"
                :disabled="form.processing
                    || (isSubject && form.subject_ids.length === 0)
                    || (isOjt && (!form.subject_id || !form.ojt_hours || form.ojt_hours < 1))"
                class="btn-save"
            >
                <template v-if="isSubject">
                    Add
                    {{ form.subject_ids.length > 0 ? form.subject_ids.length : '' }}
                    Subject{{ form.subject_ids.length === 1 ? '' : 's' }}
                </template>
                <template v-else>
                    Add Item
                </template>
            </button>

        </div>

    </form>

</div>

</template>