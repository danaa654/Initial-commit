<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import { UserGroupIcon } from '@heroicons/vue/24/outline'
import {
    requiresSpecialization,
    generateSectionCode,
    generateSectionName,
    SECTION_LETTERS,
} from '@/Composables/useSectionCodeGenerator'

const props = defineProps({
    programs: Array,
    // { "programId_specializationId_yearLevel": ["A","B",...] }
    usedLetters: Object,
})

const form = useForm({
    program_id: '',
    specialization_id: '',
    year_level: '',
    section_letter: '',
    section_name: '',
    capacity: 30,
    status: 'Active',
    is_irregular: false,
})

// Tracks whether the user has hand-edited Section Name — once they have,
// we stop silently overwriting it as they change other fields.
let nameTouched = false

const selectedProgram = computed(() =>
    props.programs.find(program => program.id === form.program_id) ?? null
)

const isBscrim = computed(() => requiresSpecialization(selectedProgram.value))

const specializationOptions = computed(() =>
    selectedProgram.value?.specializations ?? []
)

const selectedSpecialization = computed(() =>
    specializationOptions.value.find(spec => spec.id === form.specialization_id) ?? null
)

const yearLevelOptions = computed(() => {
    const years = selectedProgram.value?.years ?? 0
    return Array.from({ length: years }, (_, i) => i + 1)
})

// Whether the current Program/Specialization/Year Level combination is
// resolved enough to know which letters are already taken.
const scopeReady = computed(() =>
    !!selectedProgram.value
    && !!form.year_level
    && (!isBscrim.value || !!form.specialization_id)
)

const scopeKey = computed(() =>
    `${form.program_id}_${form.specialization_id || 'null'}_${form.year_level}`
)

const usedLettersForScope = computed(() => {
    if (!scopeReady.value) {
        return []
    }

    const bucket = props.usedLetters[scopeKey.value]

    if (!bucket) {
        return []
    }

    return (form.is_irregular ? bucket.irregular : bucket.regular) ?? []
})

const availableLetters = computed(() =>
    SECTION_LETTERS.filter(letter => !usedLettersForScope.value.includes(letter))
)

const scopeFull = computed(() =>
    scopeReady.value && availableLetters.value.length === 0
)

const generatedCode = computed(() => generateSectionCode({
    program: selectedProgram.value,
    specialization: selectedSpecialization.value,
    yearLevel: form.year_level,
    letter: form.section_letter,
}))

const generatedName = computed(() => generateSectionName({
    program: selectedProgram.value,
    specialization: selectedSpecialization.value,
    yearLevel: form.year_level,
    letter: form.section_letter,
}))

// Reset fields that no longer apply whenever Program changes.
watch(() => form.program_id, () => {
    form.specialization_id = ''

    if (form.year_level && form.year_level > yearLevelOptions.value.length) {
        form.year_level = ''
    }
})

// Auto-select the next available letter whenever the scope changes —
// keeps the current letter if it's still valid for the new scope,
// otherwise picks the first free one (or clears it if the scope is full).
watch([() => form.program_id, () => form.specialization_id, () => form.year_level, () => form.is_irregular], () => {
    if (!scopeReady.value) {
        return
    }

    if (form.section_letter && availableLetters.value.includes(form.section_letter)) {
        return
    }

    form.section_letter = availableLetters.value[0] ?? ''
})

// Keep Section Name in sync with the generated preview until the user
// types something of their own into it.
watch(generatedName, (value) => {
    if (!nameTouched && value) {
        form.section_name = value
    }
})

function onSectionNameInput() {
    nameTouched = true
}

function useAutoName() {
    nameTouched = false
    form.section_name = generatedName.value
}

function clampCapacity() {
    if (form.capacity === '' || form.capacity === null) {
        return
    }

    const value = Number(form.capacity)

    if (Number.isNaN(value)) {
        return
    }

    form.capacity = Math.min(45, Math.max(20, value))
}

function submit() {
    if (scopeFull.value) {
        return
    }

    form.post(route('sections.store'))
}

// Whether every field submit() actually needs is already filled in —
// used to decide whether flipping the Irregular toggle can save right
// away, versus just recording the intent and waiting for the user to
// finish the rest of the form.
const readyToSave = computed(() =>
    scopeReady.value
    && !!form.section_letter
    && !scopeFull.value
    && !!form.section_name
    && !!form.capacity
)

// Flipping Irregular ON is the moment the user wants to see the Subject
// picker — but that picker needs a real Section id (see
// IrregularSubjectPicker.vue), which only exists once this record is
// saved. So instead of making them flip the toggle, then separately
// click Save Section, then navigate to Edit, we just do that save for
// them right here and let the backend redirect to Edit (where the
// picker renders immediately). If the rest of the form isn't filled in
// yet, we fall back to just toggling the field — Save Section will
// still land on Edit once they do finish it, thanks to the same
// backend redirect.
function toggleIrregular() {
    form.is_irregular = !form.is_irregular

    if (form.is_irregular && readyToSave.value && !form.processing) {
        form.post(route('sections.store'))
    }
}
</script>

<template>
    <DashboardLayout>

        <Head title="Add Section" />

        <div class="relative">

            <!-- Subtle brand texture: faint grid + one soft gold glow, static (no animation) -->
            <div class="pointer-events-none absolute -inset-x-6 -inset-y-6 -z-10 overflow-hidden">
                <div
                    class="absolute inset-0 opacity-[0.03] dark:opacity-[0.05]"
                    style="background-image: linear-gradient(#1e3a5f 1px, transparent 1px), linear-gradient(90deg, #1e3a5f 1px, transparent 1px); background-size: 42px 42px;"
                ></div>
                <div class="absolute -top-16 right-0 h-64 w-64 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
            </div>

            <div class="mx-auto max-w-2xl">

                <!-- Header -->
                <div class="flex items-center gap-3 mb-6">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                        <UserGroupIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Add Section
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            Create a new section for a program
                        </p>
                    </div>
                </div>

                <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-lg p-6 transition-colors duration-300">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <form @submit.prevent="submit">

                    <div class="mb-4">
                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Program
                        </label>

                        <select
                            v-model="form.program_id"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="" disabled>
                                Select a program
                            </option>
                            <option
                                v-for="program in programs"
                                :key="program.id"
                                :value="program.id"
                            >
                                {{ program.code }} - {{ program.name }}
                            </option>
                        </select>

                        <p v-if="form.errors.program_id" class="text-red-500 text-sm mt-1">
                            {{ form.errors.program_id }}
                        </p>
                    </div>

                    <div v-if="isBscrim" class="mb-4">
                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Specialization
                        </label>

                        <select
                            v-model="form.specialization_id"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="" disabled>
                                Select a specialization
                            </option>
                            <option
                                v-for="specialization in specializationOptions"
                                :key="specialization.id"
                                :value="specialization.id"
                            >
                                {{ specialization.code }} - {{ specialization.name }}
                            </option>
                        </select>

                        <p v-if="form.errors.specialization_id" class="text-red-500 text-sm mt-1">
                            {{ form.errors.specialization_id }}
                        </p>
                    </div>

                    <div class="mb-4 grid grid-cols-2 gap-4">

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Year Level
                            </label>

                            <select
                                v-model="form.year_level"
                                :disabled="!selectedProgram"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30 disabled:bg-[var(--card-border)]/30 disabled:text-[var(--text-muted)]"
                            >
                                <option value="" disabled>
                                    Select year level
                                </option>
                                <option
                                    v-for="year in yearLevelOptions"
                                    :key="year"
                                    :value="year"
                                >
                                    Year {{ year }}
                                </option>
                            </select>

                            <p v-if="form.errors.year_level" class="text-red-500 text-sm mt-1">
                                {{ form.errors.year_level }}
                            </p>
                        </div>

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Section Letter
                            </label>

                            <select
                                v-model="form.section_letter"
                                :disabled="!scopeReady"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30 disabled:bg-[var(--card-border)]/30 disabled:text-[var(--text-muted)]"
                            >
                                <option value="" disabled>
                                    Select letter
                                </option>
                                <option
                                    v-for="letter in SECTION_LETTERS"
                                    :key="letter"
                                    :value="letter"
                                    :disabled="usedLettersForScope.includes(letter)"
                                >
                                    {{ letter }}{{ usedLettersForScope.includes(letter) ? ' (Taken)' : '' }}
                                </option>
                            </select>

                            <p v-if="form.errors.section_letter" class="text-red-500 text-sm mt-1">
                                {{ form.errors.section_letter }}
                            </p>
                        </div>

                    </div>

                    <p
                        v-if="scopeFull"
                        class="mb-4 text-sm bg-amber-500/10 border border-amber-500/30 text-amber-600 dark:text-amber-300 rounded-xl p-3"
                    >
                        All available sections (A–E) have already been created for this year level.
                    </p>

                    <div class="mb-4">
                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Generated Section Code
                        </label>

                        <div class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm font-mono text-[var(--text-secondary)]">
                            {{ generatedCode ?? 'Complete the fields above to generate a code' }}
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="flex items-center justify-between mb-1">
                            <label class="block font-medium text-sm text-[var(--text-secondary)]">
                                Section Name
                            </label>

                            <button
                                v-if="generatedName"
                                type="button"
                                @click="useAutoName"
                                class="text-sm text-blue-500 hover:underline"
                            >
                                Use auto-generated name
                            </button>
                        </div>

                        <input
                            v-model="form.section_name"
                            @input="onSectionNameInput"
                            type="text"
                            placeholder="e.g. BS Information Technology - 1A"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >

                        <p v-if="form.errors.section_name" class="text-red-500 text-sm mt-1">
                            {{ form.errors.section_name }}
                        </p>
                    </div>

                    <div class="mb-4">
                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Capacity
                        </label>

                        <input
                            v-model="form.capacity"
                            @blur="clampCapacity"
                            type="number"
                            min="20"
                            max="45"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >

                        <p class="text-[var(--text-muted)] text-sm mt-1">
                            Must be between 20 and 45 students.
                        </p>

                        <p v-if="form.errors.capacity" class="text-red-500 text-sm mt-1">
                            {{ form.errors.capacity }}
                        </p>
                    </div>

                    <div class="mb-6">
                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Status
                        </label>

                        <select
                            v-model="form.status"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>

                        <p v-if="form.errors.status" class="text-red-500 text-sm mt-1">
                            {{ form.errors.status }}
                        </p>
                    </div>

                    <div class="mb-6 flex items-start justify-between gap-4 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] p-4">
                        <div>
                            <label for="is_irregular" class="block font-medium text-sm text-[var(--text-primary)] cursor-pointer">
                                Irregular Section
                            </label>
                            <p class="text-xs text-[var(--text-muted)] mt-1 max-w-sm">
                                Skips automatic Subject Offering generation from the
                                curriculum/year level. Subjects are hand-picked instead.
                                <template v-if="readyToSave">
                                    Turning this on saves the section right away and
                                    takes you to the Subject picker.
                                </template>
                                <template v-else>
                                    Fill in the fields above first — once you do,
                                    turning this on will save and take you straight
                                    to the Subject picker.
                                </template>
                            </p>
                        </div>

                        <button
                            id="is_irregular"
                            type="button"
                            role="switch"
                            :aria-checked="form.is_irregular"
                            @click="toggleIrregular"
                            :class="[
                                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200',
                                form.is_irregular ? 'bg-[#D4A62A]' : 'bg-[var(--card-border)]',
                            ]"
                        >
                            <span
                                :class="[
                                    'inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200',
                                    form.is_irregular ? 'translate-x-6' : 'translate-x-1',
                                ]"
                            ></span>
                        </button>
                    </div>

                    <div class="flex justify-end gap-2">

                        <Link
                            :href="route('sections.index')"
                            class="btn-neutral"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            :disabled="form.processing || scopeFull"
                            class="btn-save"
                        >
                            Save Section
                        </button>

                    </div>

                </form>

            </div>

            </div>

        </div>

    </DashboardLayout>
</template>