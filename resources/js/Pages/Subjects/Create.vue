<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import { BookOpenIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline'

defineOptions({
    layout: DashboardLayout,
})

const props = defineProps({
    subjects: Array,
    roomGroupOptions: {
        type: Array,
        default: () => ['General', 'BSIT', 'BSED', 'BSHM', 'BSTM', 'BSCRIM'],
    },
})

const form = useForm({
    subject_code: '',
    descriptive_title: '',
    units: '',
    lecture_hours: 0,
    laboratory_hours: 0,
    is_major: true,
    required_room_type: 'Lecture',
    // One or more programs this subject is applicable to. Independent of
    // is_major — Major and Minor subjects both support any combination
    // (e.g. Minor -> General, or Major -> BSHM + BSTM).
    room_groups: [],
    is_practicum: false,
    allow_split_schedule: true,
    prerequisite_id: '',
    active: true,
})

const totalHours = computed(() => {
    return (Number(form.lecture_hours) || 0) + (Number(form.laboratory_hours) || 0)
})

// Programs only make sense when the subject actually needs a room.
const roomGroupsDisabled = computed(() => form.required_room_type === 'None')

// "General" is a Lecture-only, Minor-only program — it's hidden from the
// checklist whenever Room Type is Laboratory OR Classification is Major
// (enforced server-side too, this is just UX). Mirrors the Required Room
// Type logic: Lecture -> General available, Laboratory -> General hidden;
// likewise Minor -> General available, Major -> General hidden.
const roomGroupChoices = computed(() => {
    if (form.required_room_type === 'Laboratory' || form.is_major) {
        return props.roomGroupOptions.filter(option => option !== 'General')
    }

    return props.roomGroupOptions
})

function toggleRoomGroup(option) {
    const index = form.room_groups.indexOf(option)

    if (index === -1) {
        form.room_groups.push(option)
    } else {
        form.room_groups.splice(index, 1)
    }
}

// Checking Practicum/OJT forces Room Type to "None" and clears the
// program selection, since a Practicum subject never gets assigned a room.
watch(() => form.is_practicum, (isPracticum) => {
    if (isPracticum) {
        form.required_room_type = 'None'
    } else if (form.required_room_type === 'None') {
        form.required_room_type = 'Lecture'
    }
})

// Keeps the program selection in sync with Room Type. The backend enforces
// all of this too (a disabled/tampered field can't smuggle in a bad
// value), but mirroring it here keeps the form from ever showing/
// submitting a selection that doesn't make sense for the selected room
// type:
//   - None        -> selection cleared (Practicum/OJT gets no room)
//   - Laboratory  -> "General" isn't valid, so it's dropped from whatever
//                     was already selected
watch(() => form.required_room_type, (roomType) => {
    if (roomType === 'None') {
        form.room_groups = []
    } else if (roomType === 'Laboratory') {
        form.room_groups = form.room_groups.filter(option => option !== 'General')
    }
})

// Keeps the program selection in sync with Classification, the same way
// the watcher above keeps it in sync with Room Type. "General" only makes
// sense for Minor subjects, so switching Classification to Major drops it
// from whatever was already selected. immediate: true also runs this once
// on mount, so a Major subject seeded with a stale "General" (from before
// this rule existed) gets cleaned up as soon as the form loads.
watch(() => form.is_major, (isMajor) => {
    if (isMajor) {
        form.room_groups = form.room_groups.filter(option => option !== 'General')
    }
}, { immediate: true })

function submit() {
    form.post(route('subjects.store'))
}
</script>

<template>

<Head title="Add Subject" />

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
                <BookOpenIcon class="h-5.5 w-5.5" />
            </div>
            <div>
                <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                    Add Subject
                </h1>
                <p class="text-sm text-[var(--text-muted)]">
                    Create a new subject in the master list
                </p>
            </div>
        </div>

        <Link
            :href="route('subjects.index')"
            class="inline-flex items-center gap-1.5 text-sm text-[var(--text-secondary)] transition-colors duration-150 hover:text-[var(--text-primary)]"
        >
            <ArrowLeftIcon class="h-4 w-4" />
            Back to Subjects
        </Link>

    </div>

    <!-- Form -->

    <form
        @submit.prevent="submit"
        class="relative overflow-hidden mx-auto max-w-3xl bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-lg p-6 space-y-6 transition-colors duration-300"
    >

        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

        <!-- Subject Code / Descriptive Title -->

        <div class="grid grid-cols-2 gap-4">

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Subject Code
                </label>

                <input
                    v-model="form.subject_code"
                    type="text"
                    placeholder="e.g. IT101"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />

                <p v-if="form.errors.subject_code" class="text-red-500 text-sm mt-1">
                    {{ form.errors.subject_code }}
                </p>

            </div>

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Descriptive Title
                </label>

                <input
                    v-model="form.descriptive_title"
                    type="text"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />

                <p v-if="form.errors.descriptive_title" class="text-red-500 text-sm mt-1">
                    {{ form.errors.descriptive_title }}
                </p>

            </div>

        </div>

        <!-- Units / Hours -->

        <div class="grid grid-cols-4 gap-4">

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Units
                </label>

                <input
                    v-model.number="form.units"
                    type="number"
                    min="1"
                    max="6"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />

                <p v-if="form.errors.units" class="text-red-500 text-sm mt-1">
                    {{ form.errors.units }}
                </p>

            </div>

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Lecture Hours
                </label>

                <input
                    v-model.number="form.lecture_hours"
                    type="number"
                    min="0"
                    max="10"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />

                <p v-if="form.errors.lecture_hours" class="text-red-500 text-sm mt-1">
                    {{ form.errors.lecture_hours }}
                </p>

            </div>

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Laboratory Hours
                </label>

                <input
                    v-model.number="form.laboratory_hours"
                    type="number"
                    min="0"
                    max="10"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />

                <p v-if="form.errors.laboratory_hours" class="text-red-500 text-sm mt-1">
                    {{ form.errors.laboratory_hours }}
                </p>

            </div>

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Total Hours
                </label>

                <input
                    :value="totalHours"
                    type="number"
                    disabled
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--card-border)]/20 px-3 py-2.5 text-sm text-[var(--text-muted)]"
                />

            </div>

        </div>

        <!-- Classification / Required Room Type -->

        <div class="grid grid-cols-2 gap-4">

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Classification
                </label>

                <select
                    v-model="form.is_major"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option :value="true">Major</option>
                    <option :value="false">Minor</option>
                </select>

                <p v-if="form.errors.is_major" class="text-red-500 text-sm mt-1">
                    {{ form.errors.is_major }}
                </p>

            </div>

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Required Room Type
                </label>

                <select
                    v-model="form.required_room_type"
                    :disabled="form.is_practicum"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30 disabled:bg-[var(--card-border)]/30 disabled:text-[var(--text-muted)]"
                >
                    <option value="Lecture">Lecture</option>
                    <option value="Laboratory">Laboratory</option>
                    <option value="None">None (Practicum/OJT)</option>
                </select>

                <p v-if="form.errors.required_room_type" class="text-red-500 text-sm mt-1">
                    {{ form.errors.required_room_type }}
                </p>

            </div>

        </div>

        <!-- Programs (Room Groups) -->
        <!--
            Multi-select: a subject can belong to any number of programs,
            fully independent of Classification (Major/Minor). E.g.
            English Communication -> Minor -> General; Business Marketing
            -> Major -> BSHM + BSTM.
        -->

        <div>

            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                Programs
            </label>

            <p class="text-xs text-[var(--text-muted)] mb-2">
                Select every program this subject applies to. A subject is
                considered applicable if it belongs to any of its assigned
                programs.
            </p>

            <div
                class="flex flex-wrap gap-2 rounded-xl border p-3 transition-colors duration-200"
                :class="roomGroupsDisabled ? 'bg-[var(--page-bg)]/50 border-[var(--card-border)]' : 'bg-[var(--page-bg)] border-[var(--card-border)]'"
            >

                <label
                    v-for="option in roomGroupChoices"
                    :key="option"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-full border text-sm cursor-pointer select-none transition-colors duration-150"
                    :class="[
                        form.room_groups.includes(option)
                            ? 'bg-blue-100 border-blue-300 text-blue-700'
                            : 'bg-[var(--card-bg)] border-[var(--card-border)] text-[var(--text-secondary)]',
                        roomGroupsDisabled ? 'opacity-50 cursor-not-allowed' : 'hover:border-[#D4A62A]/40',
                    ]"
                >
                    <input
                        type="checkbox"
                        class="rounded accent-[#D4A62A]"
                        :checked="form.room_groups.includes(option)"
                        :disabled="roomGroupsDisabled"
                        @change="toggleRoomGroup(option)"
                    />
                    {{ option }}
                </label>

                <p v-if="roomGroupsDisabled" class="text-xs text-[var(--text-muted)] w-full">
                    Practicum/OJT subjects (Room Type: None) don't get a program assignment.
                </p>

            </div>

            <p v-if="form.errors.room_groups" class="text-red-500 text-sm mt-1">
                {{ form.errors.room_groups }}
            </p>

        </div>

        <!-- Prerequisite -->

        <div>

            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                Prerequisite (optional)
            </label>

            <select
                v-model="form.prerequisite_id"
                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            >
                <option value="">None</option>

                <option
                    v-for="subject in subjects"
                    :key="subject.id"
                    :value="subject.id"
                >
                    {{ subject.subject_code }} - {{ subject.descriptive_title }}
                </option>
            </select>

            <p v-if="form.errors.prerequisite_id" class="text-red-500 text-sm mt-1">
                {{ form.errors.prerequisite_id }}
            </p>

        </div>

        <!-- Toggles -->

        <div class="flex items-center gap-8">

            <label class="flex items-center gap-2">
                <input type="checkbox" v-model="form.allow_split_schedule" class="rounded accent-[#D4A62A]" />
                <span class="text-sm text-[var(--text-primary)]">Allow Split Schedule</span>
            </label>

            <label class="flex items-center gap-2">
                <input type="checkbox" v-model="form.active" class="rounded accent-[#D4A62A]" />
                <span class="text-sm text-[var(--text-primary)]">Active</span>
            </label>

            <label class="flex items-center gap-2">
                <input type="checkbox" v-model="form.is_practicum" class="rounded accent-[#D4A62A]" />
                <span class="text-sm text-[var(--text-primary)]">Practicum/OJT</span>
            </label>

        </div>

        <!-- Actions -->

        <div class="flex justify-end gap-2 pt-4 border-t border-[var(--card-border)]">

            <Link
                :href="route('subjects.index')"
                class="btn-neutral"
            >
                Cancel
            </Link>

            <button
                type="submit"
                :disabled="form.processing"
                class="btn-save"
            >
                Save Subject
            </button>

        </div>

    </form>

</div>

</template>