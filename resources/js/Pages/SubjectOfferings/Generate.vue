<script setup>
import { computed, watch } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { ClipboardDocumentListIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    academicTerms: Array,
    activeAcademicTermId: [Number, null],
    curriculums: Array,
})

const form = useForm({
    academic_term_id: props.activeAcademicTermId ?? '',
    curriculum_id: '',
    section_ids: [],
})

const selectedCurriculum = computed(() =>
    props.curriculums.find(c => c.id === Number(form.curriculum_id)) ?? null
)

// Sections grouped by Year Level for the checkbox layout.
const sectionsByYearLevel = computed(() => {
    if (!selectedCurriculum.value) return {}

    return selectedCurriculum.value.sections.reduce((groups, section) => {
        (groups[section.year_level] ??= []).push(section)
        return groups
    }, {})
})

// Changing Curriculum invalidates whatever Sections were checked —
// they belonged to the previous Curriculum's list.
watch(() => form.curriculum_id, () => {
    form.section_ids = []
})

function toggleSection(sectionId) {
    const index = form.section_ids.indexOf(sectionId)

    if (index === -1) {
        form.section_ids.push(sectionId)
    } else {
        form.section_ids.splice(index, 1)
    }
}

function yearLabel(yearLevel) {
    return { 1: 'First Year', 2: 'Second Year', 3: 'Third Year', 4: 'Fourth Year' }[yearLevel] ?? `Year ${yearLevel}`
}

function submit() {
    form.post(route('subject-offerings.store'), {
        preserveScroll: true,
    })
}
</script>

<template>
    <Head title="Generate Subject Offerings" />

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

            <div class="mx-auto flex max-w-3xl flex-col gap-6">

            <!-- Header -->
            <div class="flex justify-between items-center">

                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                        <ClipboardDocumentListIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Generate Subject Offerings
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            Import a Curriculum's subjects into an Academic Term
                        </p>
                    </div>
                </div>

                <Link
                    :href="route('subject-offerings.index')"
                    class="inline-flex items-center gap-1.5 text-sm text-[var(--text-secondary)] transition-colors duration-150 hover:text-[var(--text-primary)]"
                >
                    <ArrowLeftIcon class="h-4 w-4" />
                    Back to Subject Offerings
                </Link>

            </div>

            <p class="text-sm text-[var(--text-secondary)] -mt-2">
                Imports this Academic Term's semester of the selected Curriculum into
                the Sections you check below. One Subject Offering is created for
                every Subject in that semester, for every checked Section. Offerings
                that already exist are never touched or duplicated.
            </p>

            <form
                @submit.prevent="submit"
                class="relative overflow-hidden flex flex-col gap-5 bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-lg p-6 transition-colors duration-300"
            >

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <div>
                    <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                        Academic Term
                    </label>
                    <select
                        v-model="form.academic_term_id"
                        required
                        class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    >
                        <option value="" disabled>Select an Academic Term&hellip;</option>
                        <option v-for="term in academicTerms" :key="term.id" :value="term.id">
                            {{ term.display_name }}{{ term.active ? ' (Active)' : '' }}
                        </option>
                    </select>
                    <p v-if="form.errors.academic_term_id" class="text-red-500 text-sm mt-1">
                        {{ form.errors.academic_term_id }}
                    </p>
                </div>

                <div>
                    <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                        Curriculum
                    </label>
                    <select
                        v-model="form.curriculum_id"
                        required
                        class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    >
                        <option value="" disabled>Select a Curriculum&hellip;</option>
                        <option
                            v-for="curriculum in curriculums"
                            :key="curriculum.id"
                            :value="curriculum.id"
                            :disabled="!curriculum.has_items"
                        >
                            {{ curriculum.display_name }}{{ !curriculum.has_items ? ' (no Curriculum Items yet)' : '' }}
                        </option>
                    </select>
                    <p v-if="form.errors.curriculum_id" class="text-red-500 text-sm mt-1">
                        {{ form.errors.curriculum_id }}
                    </p>
                </div>

                <div v-if="selectedCurriculum">
                    <label class="block font-medium mb-2 text-sm text-[var(--text-secondary)]">
                        Sections to Open
                    </label>

                    <p v-if="selectedCurriculum.sections.length === 0" class="text-sm text-[var(--text-muted)]">
                        This Curriculum has no active Sections yet.
                    </p>

                    <div v-else class="flex flex-col gap-4 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] p-3">
                        <div v-for="(sections, yearLevel) in sectionsByYearLevel" :key="yearLevel">
                            <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                                {{ yearLabel(Number(yearLevel)) }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="section in sections"
                                    :key="section.id"
                                    class="flex items-center gap-2 px-3 py-1.5 rounded-full border text-sm cursor-pointer select-none transition-colors duration-150"
                                    :class="form.section_ids.includes(section.id)
                                        ? 'bg-blue-100 border-blue-300 text-blue-700'
                                        : 'bg-[var(--card-bg)] border-[var(--card-border)] text-[var(--text-secondary)] hover:border-[#D4A62A]/40'"
                                >
                                    <input
                                        type="checkbox"
                                        class="rounded accent-[#D4A62A]"
                                        :checked="form.section_ids.includes(section.id)"
                                        @change="toggleSection(section.id)"
                                    />
                                    {{ section.section_code }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.section_ids" class="text-red-500 text-sm mt-2">
                        {{ form.errors.section_ids }}
                    </p>
                </div>

                <div class="flex justify-end gap-2 pt-4 border-t border-[var(--card-border)]">
                    <Link
                        :href="route('subject-offerings.index')"
                        class="btn-neutral"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        class="btn-info rounded-lg px-5 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="form.processing || form.section_ids.length === 0"
                    >
                        {{ form.processing ? 'Generating…' : 'Generate Subject Offerings' }}
                    </button>
                </div>
            </form>
            </div>
        </div>
    </AppLayout>
</template>