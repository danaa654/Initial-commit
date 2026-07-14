<script setup>
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { MagnifyingGlassIcon, PlusIcon, ClipboardDocumentListIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    section: { type: Object, required: true },
})

// Available Subjects to pick from (every active Subject-type Curriculum
// Item under the same Program as this Section, across every active
// Curriculum/Year Level, minus whatever's already offered to this
// Section this Term) and the Subject Offerings already attached to it —
// both come from SubjectOfferingController::irregularSubjects().
const loading = ref(true)
const errorMessage = ref(null)
const academicTermLabel = ref(null)
const availableSubjects = ref([])
const attachedOfferings = ref([])

const search = ref('')
const selected = ref(new Set())
const submitting = ref(false)
const submitError = ref(null)

async function loadSubjects() {
    loading.value = true
    errorMessage.value = null

    try {
        const { data } = await axios.get(route('sections.irregular-subjects', props.section.id))

        academicTermLabel.value = data.academic_term
        availableSubjects.value = data.subjects ?? []
        attachedOfferings.value = data.attached ?? []
        errorMessage.value = data.error ?? null
    } catch (e) {
        errorMessage.value = e?.response?.data?.message ?? 'Unable to load subjects right now.'
    } finally {
        loading.value = false
    }
}

onMounted(loadSubjects)

const filteredSubjects = computed(() => {
    const term = search.value.trim().toLowerCase()

    const matches = term
        ? availableSubjects.value.filter(subject =>
            subject.subject_code?.toLowerCase().includes(term)
            || subject.descriptive_title?.toLowerCase().includes(term)
            || subject.curriculum?.toLowerCase().includes(term)
        )
        : availableSubjects.value

    // Ordered 1st Yr -> 4th Yr (then alphabetically by Subject Code
    // within the same year) so the list reads as a natural curriculum
    // progression instead of the raw alphabetical order it comes back
    // in from SubjectOfferingController::irregularSubjects().
    return [...matches].sort((a, b) => {
        const yearDiff = (a.year_level ?? 0) - (b.year_level ?? 0)
        if (yearDiff !== 0) return yearDiff

        return (a.subject_code ?? '').localeCompare(b.subject_code ?? '')
    })
})

// Year-level groups (only the years actually present in the filtered
// results) — powers the sticky "1st Yr" / "2nd Yr" headers in the list
// below, so subjects read as a clear year-by-year progression rather
// than one long undifferentiated list.
const groupedSubjects = computed(() => {
    const groups = new Map()

    for (const subject of filteredSubjects.value) {
        const year = subject.year_level ?? 0
        if (! groups.has(year)) groups.set(year, [])
        groups.get(year).push(subject)
    }

    return Array.from(groups.entries())
        .sort(([a], [b]) => a - b)
        .map(([year, subjects]) => ({ year, subjects }))
})

function toggle(curriculumItemId) {
    if (selected.value.has(curriculumItemId)) {
        selected.value.delete(curriculumItemId)
    } else {
        selected.value.add(curriculumItemId)
    }

    // Force reactivity — Set mutations aren't tracked in-place by Vue.
    selected.value = new Set(selected.value)
}

const selectedCount = computed(() => selected.value.size)

function yearLabel(yearLevel) {
    return { 1: '1st Yr', 2: '2nd Yr', 3: '3rd Yr', 4: '4th Yr' }[yearLevel] ?? `Yr ${yearLevel}`
}

function attachSelected() {
    if (selectedCount.value === 0 || submitting.value) {
        return
    }

    submitting.value = true
    submitError.value = null

    router.post(
        route('sections.irregular-subjects.store', props.section.id),
        { curriculum_item_ids: Array.from(selected.value) },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = new Set()
                loadSubjects()
            },
            onError: (errors) => {
                submitError.value = errors.curriculum_item_ids ?? 'Unable to attach the selected subjects.'
            },
            onFinish: () => {
                submitting.value = false
            },
        }
    )
}
</script>

<template>
    <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-lg p-6 transition-colors duration-300">

        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

        <div class="flex items-center gap-3 mb-4">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                <ClipboardDocumentListIcon class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-lg font-bold text-[var(--text-primary)]">
                    Manually Assigned Subjects
                </h2>
                <p class="text-xs text-[var(--text-muted)]">
                    <template v-if="academicTermLabel">
                        {{ academicTermLabel }} — Irregular Sections pick Subjects individually instead of generating from a curriculum/year level.
                    </template>
                    <template v-else>
                        Irregular Sections pick Subjects individually instead of generating from a curriculum/year level.
                    </template>
                </p>
            </div>
        </div>

        <p v-if="errorMessage" class="mb-4 text-sm bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-300 rounded-xl p-3">
            {{ errorMessage }}
        </p>

        <!-- Already-attached Subject Offerings -->
        <div v-if="attachedOfferings.length" class="mb-5">
            <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)] mb-2">
                Already Offered ({{ attachedOfferings.length }})
            </p>
            <div class="flex flex-wrap gap-2">
                <span
                    v-for="offering in attachedOfferings"
                    :key="offering.id"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-2.5 py-1 text-xs text-[var(--text-secondary)]"
                >
                    <span class="font-mono text-[#D4A62A]">{{ offering.edp_code }}</span>
                    <span>{{ offering.subject_code }} — {{ offering.descriptive_title }}</span>
                </span>
            </div>
        </div>

        <div v-if="loading" class="text-sm text-[var(--text-muted)] py-6 text-center">
            Loading available subjects…
        </div>

        <template v-else>

            <!-- Search -->
            <div class="relative mb-3">
                <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-muted)]" />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search by subject code, title, or curriculum…"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] pl-9 pr-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
            </div>

            <!-- Multi-select list, grouped 1st Yr -> 4th Yr -->
            <div class="max-h-80 overflow-y-auto rounded-xl border border-[var(--card-border)]">
                <p v-if="!filteredSubjects.length" class="text-sm text-[var(--text-muted)] p-4 text-center">
                    No subjects match your search.
                </p>

                <div v-for="group in groupedSubjects" :key="group.year">
                    <p class="sticky top-0 z-10 bg-[var(--page-bg)] px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)] border-b border-[var(--card-border)]">
                        {{ yearLabel(group.year) }}
                    </p>

                    <div class="divide-y divide-[var(--card-border)]">
                        <label
                            v-for="subject in group.subjects"
                            :key="subject.curriculum_item_id"
                            class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-[var(--page-bg)] transition-colors"
                        >
                            <input
                                type="checkbox"
                                :checked="selected.has(subject.curriculum_item_id)"
                                @change="toggle(subject.curriculum_item_id)"
                                class="h-4 w-4 rounded border-[var(--card-border)] text-[#D4A62A] focus:ring-[#D4A62A]/30"
                            >

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-[var(--text-primary)]">
                                        {{ subject.subject_code }} — {{ subject.descriptive_title }}
                                    </p>
                                    <span
                                        v-if="subject.curriculum"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded bg-[#D4A62A]/10 text-[#D4A62A] text-[10px] font-semibold uppercase tracking-wide"
                                    >
                                        {{ subject.curriculum }}
                                    </span>
                                </div>
                                <p class="text-xs text-[var(--text-muted)] truncate">
                                    {{ yearLabel(subject.year_level) }}
                                    <span v-if="subject.units">· {{ subject.units }} unit{{ subject.units === 1 ? '' : 's' }}</span>
                                </p>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <p v-if="submitError" class="text-red-500 text-sm mt-3">
                {{ submitError }}
            </p>

            <div class="flex items-center justify-between mt-4">
                <p class="text-xs text-[var(--text-muted)]">
                    {{ selectedCount }} selected
                </p>

                <button
                    type="button"
                    @click="attachSelected"
                    :disabled="selectedCount === 0 || submitting"
                    class="btn-save inline-flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <PlusIcon class="h-4 w-4" />
                    {{ submitting ? 'Attaching…' : 'Attach Selected Subjects' }}
                </button>
            </div>

        </template>

    </div>
</template>