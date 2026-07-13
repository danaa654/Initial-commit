<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
    show: { type: Boolean, default: false },
    faculty: { type: Object, default: null },
})

const emit = defineEmits(['close', 'updated'])

const loading = ref(false)
const saving = ref(false)
const error = ref(null)

const activeTerm = ref(null)
const offerings = ref([])
const selectedIds = ref(new Set())
const search = ref('')

watch(() => props.show, (visible) => {
    if (visible && props.faculty) {
        loadOfferings()
    } else {
        offerings.value = []
        selectedIds.value = new Set()
        search.value = ''
        error.value = null
    }
})

function loadOfferings() {
    loading.value = true
    error.value = null

    axios.get(route('faculty.manage-subjects', props.faculty.id))
        .then(({ data }) => {
            activeTerm.value = data.active_academic_term
            offerings.value = data.offerings
            selectedIds.value = new Set(
                data.offerings.filter((o) => o.is_preferred).map((o) => o.id)
            )
        })
        .catch(() => {
            error.value = 'Failed to load Subject Offerings for this faculty member.'
        })
        .finally(() => {
            loading.value = false
        })
}

const filteredOfferings = computed(() => {
    const term = search.value.trim().toLowerCase()
    if (!term) return offerings.value

    return offerings.value.filter((o) =>
        [o.edp_code, o.subject_code, o.subject_title, o.program_code, o.section_code]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(term)
    )
})

// Recommended offerings (Faculty Scope eligible) surface first — same
// grouping idea as Rooms' is_recommended, informational only.
const sortedOfferings = computed(() =>
    [...filteredOfferings.value].sort((a, b) => {
        if (a.is_recommended === b.is_recommended) return a.edp_code.localeCompare(b.edp_code)
        return a.is_recommended ? -1 : 1
    })
)

function toggle(offering) {
    const next = new Set(selectedIds.value)
    if (next.has(offering.id)) {
        next.delete(offering.id)
    } else {
        next.add(offering.id)
    }
    selectedIds.value = next
}

function save() {
    saving.value = true
    error.value = null

    axios.put(route('faculty.manage-subjects.update', props.faculty.id), {
        subject_offering_ids: Array.from(selectedIds.value),
    })
        .then(({ data }) => {
            emit('updated', data)
            emit('close')
        })
        .catch(() => {
            error.value = 'Failed to save preferences. Please try again.'
        })
        .finally(() => {
            saving.value = false
        })
}

function close() {
    if (saving.value) return
    emit('close')
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col">

            <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

            <div class="flex items-center justify-between px-5 py-4 border-b border-[var(--card-border)]">
                <div>
                    <h3 class="font-semibold text-lg text-[var(--text-primary)]">Manage Subjects</h3>
                    <p class="text-xs text-[var(--text-muted)]">
                        {{ faculty?.full_name }}
                        <template v-if="activeTerm"> · {{ activeTerm.display_name }}</template>
                    </p>
                </div>
                <button type="button" class="text-[var(--text-muted)] hover:text-[var(--text-primary)]" @click="close">✕</button>
            </div>

            <div class="px-5 py-3 border-b border-[var(--card-border)]">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search subject, section, or EDP code..."
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-3">
                <p v-if="loading" class="py-10 text-center text-sm text-[var(--text-muted)]">Loading…</p>

                <p v-else-if="!activeTerm" class="py-10 text-center text-sm text-[var(--text-muted)]">
                    There is no active Academic Term to manage preferences for.
                </p>

                <p v-else-if="sortedOfferings.length === 0" class="py-10 text-center text-sm text-[var(--text-muted)]">
                    No Subject Offerings match your search.
                </p>

                <ul v-else class="space-y-1.5">
                    <li
                        v-for="offering in sortedOfferings"
                        :key="offering.id"
                        class="flex items-start gap-3 rounded-xl border px-3 py-2 transition-colors duration-150"
                        :class="selectedIds.has(offering.id) ? 'border-[#D4A62A]/50 bg-[#D4A62A]/10' : 'border-transparent hover:bg-[var(--page-bg)]'"
                    >
                        <input
                            type="checkbox"
                            class="mt-1 accent-[#D4A62A]"
                            :checked="selectedIds.has(offering.id)"
                            @change="toggle(offering)"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-sm font-semibold text-[var(--text-primary)]">
                                    {{ offering.subject_code }} — {{ offering.subject_title }}
                                </p>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                    :class="offering.classification === 'Major' ? 'bg-purple-100 text-purple-700' : 'bg-sky-100 text-sky-700'"
                                >
                                    {{ offering.classification }}
                                </span>
                                <span v-if="offering.is_recommended" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-700">
                                    Recommended
                                </span>
                            </div>
                            <p class="text-xs text-[var(--text-muted)]">
                                {{ offering.edp_code }} · {{ offering.program_code }} · Yr {{ offering.year_level }} · {{ offering.section_code }} · {{ offering.units }} units
                            </p>
                            <p v-if="offering.claimed_by_faculty_name" class="text-xs font-medium text-amber-600 dark:text-amber-300">
                                Currently preferred by {{ offering.claimed_by_faculty_name }} — selecting this will transfer it.
                            </p>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="flex items-center justify-between gap-3 px-5 py-4 border-t border-[var(--card-border)]">
                <p v-if="error" class="text-xs font-medium text-red-500">{{ error }}</p>
                <div v-else></div>

                <div class="flex gap-2">
                    <button type="button" class="btn-neutral" :disabled="saving" @click="close">Cancel</button>
                    <button type="button" class="btn-save" :disabled="saving || loading || !activeTerm" @click="save">
                        {{ saving ? 'Saving…' : 'Save Preferences' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>