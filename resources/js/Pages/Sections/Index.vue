<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import Toast from '@/Components/Toast.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useFlashToast } from '@/Composables/useFlashToast'
import {
    UserGroupIcon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    MagnifyingGlassIcon,
    ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
    sections: Array,
    programs: Array,
    // Current filter state as resolved server-side, so the inputs below
    // start in sync with the URL (?search=&program_id=&status=) instead
    // of resetting on refresh — see SectionController::index().
    filters: {
        type: Object,
        default: () => ({ search: '', program_id: null, status: null }),
    },
})

/*
|--------------------------------------------------------------------------
| Search / Program / Status filtering
|--------------------------------------------------------------------------
|
| The URL query string is the source of truth for the current filter
| state (so it survives a refresh/share/back-button), and these refs are
| just the form-bound mirror of it. Program/Status changes re-query
| immediately; Search debounces on typing but also submits instantly on
| Enter. Every visit uses preserveState + replace so filtering never
| pollutes browser history with one entry per keystroke.
|
*/

const search = ref(props.filters.search ?? '')
const programId = ref(props.filters.program_id ?? '')
const status = ref(props.filters.status ?? '')

let searchDebounce = null

function applyFilters() {
    router.get(route('sections.index'), {
        search: search.value || undefined,
        program_id: programId.value || undefined,
        status: status.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

function applyFiltersNow() {
    clearTimeout(searchDebounce)
    applyFilters()
}

watch(search, () => {
    clearTimeout(searchDebounce)
    searchDebounce = setTimeout(applyFilters, 300)
})

watch([programId, status], applyFilters)

onBeforeUnmount(() => clearTimeout(searchDebounce))

const hasActiveFilters = computed(() =>
    !!search.value || !!programId.value || !!status.value
)

// Server-driven success/warning/error toasts (created, updated, deleted,
// and the "can't delete" guard all flow through this) — same pattern as
// Academic Terms/Index.vue.
const { toast, show } = useFlashToast()

/*
|--------------------------------------------------------------------------
| Delete — Double Confirmation
|--------------------------------------------------------------------------
|
| Same shape as Academic Terms' delete flow:
|
|   Step 1: a plain "Delete Section?" confirm dialog.
|   Step 2: a modal requiring the user to type the section's own code
|           exactly before the Delete button enables.
|
| Sections add a third state Academic Terms doesn't need: if the section
| is currently in use (Section::is_in_use, computed server-side — see
| SectionController::index()), requestDelete() short-circuits into an
| "Unable to Delete" notice instead of Step 1. The server
| (SectionController::destroy) re-checks this itself as the real guard;
| the client-side check here is just for instant feedback.
|
*/

const pendingSection = ref(null)     // section in the "Delete Section?" step
const blockedSection = ref(null)     // section in the "Unable to Delete" step
const confirmingSection = ref(null)  // section in the "type the code" step
const deleteConfirmText = ref('')

const deleteConfirmValid = computed(() =>
    !!confirmingSection.value
    && deleteConfirmText.value === confirmingSection.value.section_code
)

const deleteConfirmMismatch = computed(() =>
    deleteConfirmText.value.length > 0 && !deleteConfirmValid.value
)

function requestDelete(section) {
    if (section.is_in_use) {
        blockedSection.value = section
        return
    }

    pendingSection.value = section
}

function proceedToTypedConfirm() {
    confirmingSection.value = pendingSection.value
    pendingSection.value = null
    deleteConfirmText.value = ''
}

function cancelDelete() {
    pendingSection.value = null
    blockedSection.value = null
    confirmingSection.value = null
    deleteConfirmText.value = ''
}

function finalizeDelete() {
    if (! deleteConfirmValid.value || ! confirmingSection.value) {
        return
    }

    const section = confirmingSection.value

    router.delete(route('sections.destroy', section.id), {
        onFinish: () => cancelDelete(),
        onError: () => show('Unable to delete the selected section.', 'error'),
    })
}
</script>

<template>
    <DashboardLayout>

        <Head title="Sections" />

        <Toast :toast="toast" />

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
                        <UserGroupIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Sections
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            {{ sections.length }} {{ sections.length === 1 ? 'section' : 'sections' }} on record
                        </p>
                    </div>
                </div>

                <Link
                    :href="route('sections.create')"
                    class="btn-save inline-flex items-center gap-1.5"
                >
                    <PlusIcon class="h-4 w-4" />
                    Add Section
                </Link>

            </div>

            <!-- Toolbar with Filters -->
            <div class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg p-4 mb-4 flex flex-col sm:flex-row gap-3 transition-colors duration-300">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <div class="relative w-full sm:flex-1">
                    <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search section code or section name..."
                        class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] pl-9 pr-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        @keyup.enter="applyFiltersNow"
                    >
                </div>

                <select
                    v-model="programId"
                    class="w-full sm:w-56 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All Programs</option>
                    <option
                        v-for="program in programs"
                        :key="program.id"
                        :value="program.id"
                    >
                        {{ program.code }} - {{ program.name }}
                    </option>
                </select>

                <select
                    v-model="status"
                    class="w-full sm:w-40 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>

            </div>

            <div class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg transition-colors duration-300">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <div class="overflow-x-auto">

                <table class="w-full min-w-[640px]">

                    <thead class="bg-[var(--page-bg)] border-b border-[var(--card-border)]">

                        <tr>
                            <th class="p-4 text-left w-12 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">#</th>
                            <th class="p-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Section Code</th>
                            <th class="p-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Section Name</th>
                            <th class="p-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Capacity</th>
                            <th class="p-4 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Status</th>
                            <th class="p-4 text-center whitespace-nowrap text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                                Actions
                            </th>
                        </tr>

                    </thead>

                    <tbody>

                        <tr
                            v-for="(section, index) in sections"
                            :key="section.id"
                            class="border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                        >

                            <td class="p-4 text-[var(--text-secondary)]">
                                {{ index + 1 }}
                            </td>

                            <td class="p-4 font-medium text-[var(--text-primary)]">
                                {{ section.section_code }}
                                <span
                                    v-if="section.is_irregular"
                                    class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full bg-[#D4A62A]/10 text-[#D4A62A] text-xs font-medium border border-[#D4A62A]/30"
                                >
                                    Irregular
                                </span>
                            </td>

                            <td class="p-4 text-[var(--text-primary)]">
                                {{ section.section_name }}
                            </td>

                            <td class="p-4 text-[var(--text-secondary)]">
                                {{ section.capacity }}
                            </td>

                            <td class="p-4 text-center">

                                <span
                                    v-if="section.status === 'Active'"
                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-100 text-green-700 text-sm font-medium"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                    Active
                                </span>

                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-red-100 text-red-700 text-sm font-medium"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                    Inactive
                                </span>

                            </td>

                            <td class="p-4 text-center whitespace-nowrap">

                                <div class="flex justify-center gap-2">

                                    <Link
                                        v-if="section.is_irregular"
                                        :href="`${route('sections.edit', section.id)}#manually-assigned-subjects`"
                                        class="btn-save inline-flex items-center gap-1.5"
                                        title="Hand-pick Subjects for this Irregular Section"
                                    >
                                        <ClipboardDocumentListIcon class="h-3.5 w-3.5" />
                                        Assign Subjects
                                    </Link>

                                    <Link
                                        :href="route('sections.edit', section.id)"
                                        class="btn-edit inline-flex items-center gap-1.5"
                                    >
                                        <PencilSquareIcon class="h-3.5 w-3.5" />
                                        Edit
                                    </Link>

                                    <button
                                        @click="requestDelete(section)"
                                        class="btn-delete inline-flex items-center gap-1.5"
                                    >
                                        <TrashIcon class="h-3.5 w-3.5" />
                                        Delete
                                    </button>

                                </div>

                            </td>

                        </tr>

                        <tr v-if="sections.length === 0">

                            <td
                                colspan="6"
                                class="text-center p-8 text-[var(--text-muted)]"
                            >
                                {{ hasActiveFilters ? 'No sections match your filters.' : 'No sections found.' }}
                            </td>

                        </tr>

                    </tbody>

                </table>

                </div>

            </div>

        </div>

        <!-- Step 1: Delete Section? -->
        <div
            v-if="pendingSection"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-sm p-6">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <h3 class="text-lg font-semibold mb-2 text-[var(--text-primary)]">
                    Delete Section?
                </h3>

                <p class="text-[var(--text-muted)] text-sm mb-6">
                    {{ pendingSection.section_code }} ({{ pendingSection.section_name }})
                    will be permanently removed.
                    <br><br>
                    This action cannot be undone.
                </p>

                <div class="flex justify-end gap-2">

                    <button
                        type="button"
                        @click="cancelDelete"
                        class="btn-neutral"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        @click="proceedToTypedConfirm"
                        class="btn-delete"
                    >
                        Continue
                    </button>

                </div>

            </div>
        </div>

        <!-- Blocked: section is currently in use -->
        <div
            v-if="blockedSection"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-sm p-6">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <h3 class="text-lg font-semibold mb-2 text-[var(--text-primary)]">
                    Unable to Delete
                </h3>

                <p class="text-[var(--text-muted)] text-sm mb-6">
                    This section is currently being used by the system.
                    <br><br>
                    Please remove all related records before deleting this section.
                </p>

                <div class="flex justify-end">

                    <button
                        type="button"
                        @click="cancelDelete"
                        class="btn-neutral"
                    >
                        OK
                    </button>

                </div>

            </div>
        </div>

        <!-- Step 2: Type the section code to confirm -->
        <div
            v-if="confirmingSection"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-sm p-6">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <h3 class="text-lg font-semibold mb-2 text-[var(--text-primary)]">
                    Final Confirmation
                </h3>

                <p class="text-[var(--text-muted)] text-sm mb-4">
                    To prevent accidental deletion, please type the section code.
                </p>

                <p class="text-sm text-[var(--text-muted)] mb-2">
                    Type:
                    <span class="font-mono font-semibold text-[var(--text-primary)]">
                        {{ confirmingSection.section_code }}
                    </span>
                </p>

                <input
                    v-model="deleteConfirmText"
                    type="text"
                    :placeholder="confirmingSection.section_code"
                    autocomplete="off"
                    spellcheck="false"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] font-mono uppercase transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    @keyup.enter="finalizeDelete"
                >

                <p v-if="deleteConfirmMismatch" class="text-red-500 text-sm mt-1">
                    Section code does not match.
                </p>

                <div class="flex justify-end gap-2 mt-6">

                    <button
                        type="button"
                        @click="cancelDelete"
                        class="btn-neutral"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        @click="finalizeDelete"
                        :disabled="! deleteConfirmValid"
                        class="btn-delete"
                    >
                        Delete Section
                    </button>

                </div>

            </div>
        </div>

    </DashboardLayout>
</template>