<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import { ClipboardDocumentListIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline'

defineOptions({
    layout: DashboardLayout,
})

const props = defineProps({
    curriculumItem: Object,
    curricula: Array,
    subjects: Array,
    practicumSubjects: Array,
    assignedSubjectIds: Array,
})

const form = useForm({
    curriculum_id: props.curriculumItem.curriculum_id,
    item_type: props.curriculumItem.item_type,
    subject_id: props.curriculumItem.subject_id ?? '',
    ojt_hours: props.curriculumItem.ojt_hours ?? '',
    year_level: props.curriculumItem.year_level,
    semester: props.curriculumItem.semester,
    sort_order: props.curriculumItem.sort_order,
    active: props.curriculumItem.active,
})

const isSubject = computed(() => form.item_type === 'Subject')
const isOjt = computed(() => form.item_type === 'OJT')

// Only clear fields when the type is actually changed away from what
// was originally loaded — otherwise this would wipe out the existing
// subject_id / hours the moment the form mounts.
watch(() => form.item_type, (newType, oldType) => {
    if (newType === oldType) return

    form.subject_id = ''
    form.ojt_hours = ''

    // 5th Year isn't a valid placement for Practicum/OJT items — see the
    // Year Level select below.
    if (form.item_type === 'OJT' && form.year_level === 5) {
        form.year_level = 4
    }
})

const assignedSet = computed(() => new Set(props.assignedSubjectIds ?? []))

/*
|--------------------------------------------------------------------------
| Practicum / OJT — Practicum Subject Filtering
|--------------------------------------------------------------------------
|
| The `practicumSubjects` prop is already scoped to is_practicum = true
| at the controller level, so the only filtering left to do here is
| program-matching (same rule as the Create page) plus excluding
| subjects already assigned elsewhere in this curriculum.
|
| Subjects now carry a many-to-many set of applicable programs
| (room_group_codes) instead of a single required_room_group value — a
| subject is a match if it's tagged "General" or tagged with the
| curriculum's own program code specifically.
|
*/

const selectedCurriculum = computed(() => {
    return props.curricula.find((curriculum) => curriculum.id === form.curriculum_id) ?? null
})

const curriculumProgramCode = computed(() => {
    return selectedCurriculum.value?.program?.code ?? null
})

function isApplicableToProgram(subject, programCode) {
    const groups = subject.room_group_codes ?? []
    return groups.includes('General') || groups.includes(programCode)
}

const filteredPracticumSubjects = computed(() => {
    return props.practicumSubjects.filter((subject) => {
        if (!isApplicableToProgram(subject, curriculumProgramCode.value)) return false

        // Keep this item's own current practicum subject selectable even
        // though it's technically "assigned" (to this very item).
        return !assignedSet.value.has(subject.id) || subject.id === form.subject_id
    })
})

const selectedPracticumSubject = computed(() => {
    return filteredPracticumSubjects.value.find((subject) => subject.id === form.subject_id) ?? null
})

const backHref = computed(() => {
    return route('curriculums.items.manage', form.curriculum_id)
})

function curriculumLabel(curriculum) {
    return `${curriculum.code} — ${curriculum.name}`
}

function subjectLabel(subject) {
    return `${subject.subject_code} - ${subject.descriptive_title}`
}

function submit() {
    form.put(route('curriculum-items.update', props.curriculumItem.id))
}
</script>

<template>

<Head title="Edit Curriculum Item" />

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
                    Edit Curriculum Item
                </h1>
                <p class="text-sm text-[var(--text-muted)]">
                    Update {{ curriculumItem.display_title ?? curriculumItem.title ?? curriculumItem.subject?.subject_code }}
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
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
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
                    <!-- Practicum/OJT never runs in a 5th year — see Goal 6. -->
                    
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

        <!-- Subject Fields -->

        <div v-if="isSubject">

            <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                Subject
            </label>

            <select
                v-model="form.subject_id"
                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            >
                <option value="" disabled>Select subject</option>

                <option
                    v-for="subject in subjects"
                    :key="subject.id"
                    :value="subject.id"
                    :disabled="assignedSet.has(subject.id)"
                >
                    {{ subjectLabel(subject) }}
                    {{ assignedSet.has(subject.id) ? ' (already assigned)' : '' }}
                </option>
            </select>

            <p v-if="form.errors.subject_id" class="text-red-600 text-sm mt-1">
                {{ form.errors.subject_id }}
            </p>

        </div>

        <!-- Practicum / OJT Fields -->

        <div v-else-if="isOjt">

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
                            {{ subjectLabel(subject) }}
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

        </div>

        <!-- Sort Order / Toggles -->

        <div class="grid grid-cols-3 gap-4 items-end">

            <div>

                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                    Sort Order
                </label>

                <input
                    v-model.number="form.sort_order"
                    type="number"
                    min="0"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />

                <p v-if="form.errors.sort_order" class="text-red-600 text-sm mt-1">
                    {{ form.errors.sort_order }}
                </p>

            </div>

            <label class="flex items-center gap-2 pb-2.5">
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
                    || (isOjt && (!form.subject_id || !form.ojt_hours || form.ojt_hours < 1))"
                class="btn-save"
            >
                Update Item
            </button>

        </div>

    </form>

</div>

</template>