<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import Toast from '@/Components/Toast.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useFlashToast } from '@/Composables/useFlashToast'
import {
    BookOpenIcon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    MagnifyingGlassIcon,
    ClipboardDocumentListIcon,
    PrinterIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
    curricula: Array,
    programs: Array,
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
| state, and these refs are just the form-bound mirror of it. Changes
| re-query immediately; Search debounces on typing but also submits
| instantly on Enter. Every visit uses preserveState + replace so
| filtering never pollutes browser history.
|
*/

const search = ref(props.filters.search ?? '')
const programId = ref(props.filters.program_id ?? '')
const status = ref(props.filters.status ?? '')

let searchDebounce = null

function applyFilters() {
    router.get(route('curriculums.index'), {
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

// Server-driven success/warning/error toasts
const { toast, show } = useFlashToast()

/*
|--------------------------------------------------------------------------
| Delete — Double Confirmation
|--------------------------------------------------------------------------
|
| Same pattern as Sections:
|   Step 1: a plain "Delete Curriculum?" confirm dialog.
|   Step 2: a modal requiring the user to type the curriculum code
|           exactly before the Delete button enables.
|
*/

const pendingCurriculum = ref(null)
const blockedCurriculum = ref(null)
const confirmingCurriculum = ref(null)
const deleteConfirmText = ref('')

const deleteConfirmValid = computed(() =>
    !!confirmingCurriculum.value
    && deleteConfirmText.value === confirmingCurriculum.value.code
)

const deleteConfirmMismatch = computed(() =>
    deleteConfirmText.value.length > 0 && !deleteConfirmValid.value
)

function requestDelete(curriculum) {
    if (curriculum.has_sections || curriculum.has_items) {
        blockedCurriculum.value = curriculum
        return
    }

    pendingCurriculum.value = curriculum
}

function proceedToTypedConfirm() {
    confirmingCurriculum.value = pendingCurriculum.value
    pendingCurriculum.value = null
    deleteConfirmText.value = ''
}

function cancelDelete() {
    pendingCurriculum.value = null
    blockedCurriculum.value = null
    confirmingCurriculum.value = null
    deleteConfirmText.value = ''
}

function finalizeDelete() {
    if (!deleteConfirmValid.value || !confirmingCurriculum.value) {
        return
    }

    const curriculum = confirmingCurriculum.value

    router.delete(route('curriculums.destroy', curriculum.id), {
        onFinish: () => cancelDelete(),
        onError: () => show('Unable to delete the selected curriculum.', 'error'),
    })
}

function curriculumLabel(curriculum) {
    if (curriculum.specialization) {
        return `${curriculum.program.code} - ${curriculum.specialization.name}`
    }

    return curriculum.program.code
}
</script>

<template>
    <DashboardLayout>

        <Head title="Curriculums" />

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
                        <BookOpenIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Curriculums
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            {{ curricula.length }} {{ curricula.length === 1 ? 'curriculum' : 'curriculums' }} on record
                        </p>
                    </div>
                </div>

                <Link
                    :href="route('curriculums.create')"
                    class="btn-save inline-flex items-center gap-1.5"
                >
                    <PlusIcon class="h-4 w-4" />
                    Add Curriculum
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
                        placeholder="Search curriculum code or name..."
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

            <!-- Table or Empty State -->
            <div
                v-if="curricula.length === 0"
                class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg p-12 text-center transition-colors duration-300"
            >

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[#D4A62A]/10 text-[#D4A62A]">
                    <BookOpenIcon class="h-6 w-6" />
                </div>

                <h3 class="font-medium text-[var(--text-secondary)]">
                    {{ hasActiveFilters ? 'No curriculums match your filters' : 'No curriculums found' }}
                </h3>

                <p class="mt-1 text-sm text-[var(--text-muted)] mb-6">
                    {{ hasActiveFilters ? 'Try adjusting your search criteria.' : 'Create your first curriculum to get started.' }}
                </p>

                <Link
                    v-if="!hasActiveFilters"
                    :href="route('curriculums.create')"
                    class="btn-save inline-flex items-center gap-1.5"
                >
                    <PlusIcon class="h-4 w-4" />
                    Add Curriculum
                </Link>

            </div>

            <div v-else class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg transition-colors duration-300">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <div class="overflow-x-auto">

                <table class="w-full min-w-[800px]">

                    <thead class="border-b border-[var(--card-border)] bg-[var(--page-bg)]">

                        <tr>
                            <th class="p-4 text-left w-12 text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">#</th>
                            <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Code</th>
                            <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Name</th>
                            <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Program</th>
                            <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Academic Year</th>
                            <th class="p-4 text-center text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Effective Year</th>
                            <th class="p-4 text-center text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Status</th>
                            <th class="p-4 text-center whitespace-nowrap text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Actions</th>
                        </tr>

                    </thead>

                    <tbody>

                        <tr
                            v-for="(curriculum, index) in curricula"
                            :key="curriculum.id"
                            class="group border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                        >

                            <td class="p-4 text-[var(--text-secondary)] transition-shadow duration-150 group-hover:shadow-[inset_3px_0_0_#D4A62A]">
                                {{ index + 1 }}
                            </td>

                            <td class="p-4 font-semibold text-[var(--text-primary)]">
                                <span class="inline-flex items-center rounded-md border border-[#D4A62A]/30 bg-[#D4A62A]/10 px-2 py-1 text-xs font-semibold text-[#A8790E] dark:text-[#E8C766]">
                                    {{ curriculum.code }}
                                </span>
                            </td>

                            <td class="p-4 text-[var(--text-primary)]">
                                {{ curriculum.name }}
                            </td>

                            <td class="p-4 text-[var(--text-secondary)]">
                                {{ curriculumLabel(curriculum) }}
                                <span class="text-[var(--text-muted)] text-xs block">
                                    {{ curriculum.program.department?.abbreviation }}
                                </span>
                            </td>

                            <td class="p-4 text-[var(--text-secondary)]">
                                {{ curriculum.academic_year }}
                            </td>

                            <td class="p-4 text-center">
                                <span class="inline-flex items-center rounded-full bg-[var(--page-bg)] border border-[var(--card-border)] px-2.5 py-0.5 text-xs font-medium text-[var(--text-secondary)]">
                                    {{ curriculum.effective_year }}
                                </span>
                            </td>

                            <td class="p-4 text-center">

                                <span
                                    v-if="curriculum.active"
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
                                        :href="route('curriculums.items.manage', curriculum.id)"
                                        class="btn-info inline-flex items-center gap-1.5"
                                    >
                                        <ClipboardDocumentListIcon class="h-3.5 w-3.5" />
                                        Manage Subjects
                                    </Link>

                                    <a
                                        :href="route('curriculums.print', curriculum.id)"
                                        target="_blank"
                                        rel="noopener"
                                        class="btn-info inline-flex items-center gap-1.5"
                                    >
                                        <PrinterIcon class="h-3.5 w-3.5" />
                                        Print
                                    </a>

                                    <Link
                                        :href="route('curriculums.edit', curriculum.id)"
                                        class="btn-edit inline-flex items-center gap-1.5"
                                    >
                                        <PencilSquareIcon class="h-3.5 w-3.5" />
                                        Edit
                                    </Link>

                                    <button
                                        @click="requestDelete(curriculum)"
                                        class="btn-delete inline-flex items-center gap-1.5"
                                    >
                                        <TrashIcon class="h-3.5 w-3.5" />
                                        Delete
                                    </button>

                                </div>

                            </td>

                        </tr>

                    </tbody>

                </table>

                </div>

            </div>

        </div>

        <!-- Step 1: Delete Curriculum? -->
        <div
            v-if="pendingCurriculum"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-sm p-6">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <h3 class="text-lg font-semibold mb-2 text-[var(--text-primary)]">
                    Delete Curriculum?
                </h3>

                <p class="text-[var(--text-muted)] text-sm mb-6">
                    {{ pendingCurriculum.code }} ({{ pendingCurriculum.name }})
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

        <!-- Blocked: curriculum is in use -->
        <div
            v-if="blockedCurriculum"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-sm p-6">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <h3 class="text-lg font-semibold mb-2 text-[var(--text-primary)]">
                    Unable to Delete
                </h3>

                <p class="text-[var(--text-muted)] text-sm mb-6">
                    This curriculum is currently being used by the system.
                    <br><br>
                    Please remove all related sections and subjects before deleting this curriculum.
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

        <!-- Step 2: Type the curriculum code to confirm -->
        <div
            v-if="confirmingCurriculum"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-sm p-6">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <h3 class="text-lg font-semibold mb-2 text-[var(--text-primary)]">
                    Final Confirmation
                </h3>

                <p class="text-[var(--text-muted)] text-sm mb-4">
                    To prevent accidental deletion, please type the curriculum code.
                </p>

                <p class="text-sm text-[var(--text-muted)] mb-2">
                    Type:
                    <span class="font-mono font-semibold text-[var(--text-primary)]">
                        {{ confirmingCurriculum.code }}
                    </span>
                </p>

                <input
                    v-model="deleteConfirmText"
                    type="text"
                    :placeholder="confirmingCurriculum.code"
                    autocomplete="off"
                    spellcheck="false"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] font-mono uppercase transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    @keyup.enter="finalizeDelete"
                >

                <p v-if="deleteConfirmMismatch" class="text-red-500 text-sm mt-1">
                    Curriculum code does not match.
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
                        :disabled="!deleteConfirmValid"
                        class="btn-delete"
                    >
                        Delete Curriculum
                    </button>

                </div>

            </div>
        </div>

    </DashboardLayout>
</template>