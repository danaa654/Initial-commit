<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import Toast from '@/Components/Toast.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { useFlashToast } from '@/Composables/useFlashToast'
import { CalendarDaysIcon, PlusIcon, PencilSquareIcon, TrashIcon, LockClosedIcon } from '@heroicons/vue/24/outline'

defineProps({
    academicTerms: Array,
})

// Server-driven success/warning/error toasts (created, updated, archived,
// deleted, and the "can't delete" guards all flow through this).
const { toast, show } = useFlashToast()

function formatDate(value) {
    if (!value) {
        return '-'
    }

    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

function formatTime(value) {
    if (!value) {
        return null
    }

    const [hour, minute] = value.split(':')
    const date = new Date()
    date.setHours(hour, minute)

    return date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
    })
}

function statusClasses(status) {
    return {
        Draft: 'bg-gray-100 text-gray-700',
        Published: 'bg-green-100 text-green-700',
        Archived: 'bg-red-100 text-red-700',
    }[status] ?? 'bg-gray-100 text-gray-700'
}

/*
|--------------------------------------------------------------------------
| Delete — Double Confirmation
|--------------------------------------------------------------------------
|
| Step 1: a plain "Delete Academic Term?" confirm dialog.
| Step 2: a modal requiring the user to type DELETE exactly before the
|         Delete button enables. Only then is the destroy request sent.
|
| The active-term guard is also checked client-side first for instant
| feedback, but the server (AcademicTermController::destroy) is the real
| source of truth — it also blocks deletion of terms with scheduling data
| and reports back via a warning toast either way.
|
*/

const pendingTerm = ref(null)   // term selected for the "Delete Academic Term?" step
const confirmingTerm = ref(null) // term in the "type DELETE" step
const deleteConfirmText = ref('')

const deleteConfirmValid = computed(() => deleteConfirmText.value === 'DELETE')

function requestDelete(term) {
    if (term.active) {
        show('The active Academic Term cannot be deleted. Activate another Academic Term first.', 'warning')
        return
    }

    if (term.status === 'Archived') {
        show('Archived Academic Terms are permanent historical record and cannot be deleted.', 'warning')
        return
    }

    pendingTerm.value = term
}

function proceedToTypedConfirm() {
    confirmingTerm.value = pendingTerm.value
    pendingTerm.value = null
    deleteConfirmText.value = ''
}

function cancelDelete() {
    pendingTerm.value = null
    confirmingTerm.value = null
    deleteConfirmText.value = ''
}

function finalizeDelete() {
    if (! deleteConfirmValid.value || ! confirmingTerm.value) {
        return
    }

    const term = confirmingTerm.value

    router.delete(route('academic-terms.destroy', term.id), {
        onFinish: () => cancelDelete(),
        onError: () => show('Something went wrong while deleting this Academic Term.', 'error'),
    })
}
</script>

<template>
    <DashboardLayout>

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

            <div class="flex justify-between items-center mb-6">

                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                        <CalendarDaysIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Academic Terms
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            {{ academicTerms.length }} {{ academicTerms.length === 1 ? 'term' : 'terms' }} on record
                        </p>
                    </div>
                </div>

                <Link
                    :href="route('academic-terms.create')"
                    class="btn-save inline-flex items-center gap-1.5"
                >
                    <PlusIcon class="h-4 w-4" />
                    Add Academic Term
                </Link>

            </div>

        <div class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg transition-colors duration-300">

            <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

            <table class="w-full">

                <thead class="border-b border-[var(--card-border)] bg-[var(--page-bg)]">

                    <tr>
                        <th class="p-4 text-left w-12 text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">#</th>
                        <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Academic Year</th>
                        <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Semester</th>
                        <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Class Dates</th>
                        <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">School Hours</th>
                        <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Status</th>
                        <th class="p-4 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Active</th>
                        <th class="p-4 text-center whitespace-nowrap text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            Actions
                        </th>
                    </tr>

                </thead>

                <tbody>

                    <tr
                        v-for="(term, index) in academicTerms"
                        :key="term.id"
                        class="group border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                    >

                        <td class="p-4 text-[var(--text-secondary)] transition-shadow duration-150 group-hover:shadow-[inset_3px_0_0_#D4A62A]">
                            {{ index + 1 }}
                        </td>

                        <td class="p-4 font-medium text-[var(--text-primary)]">
                            {{ term.academic_year }}
                        </td>

                        <td class="p-4 text-[var(--text-secondary)]">
                            {{ term.semester_label }}
                        </td>

                        <td class="p-4 whitespace-nowrap text-[var(--text-secondary)]">
                            {{ formatDate(term.class_start_date) }}
                            &ndash;
                            {{ formatDate(term.class_end_date) }}
                        </td>

                        <td class="p-4 whitespace-nowrap text-[var(--text-secondary)]">
                            {{ formatTime(term.school_start_time) }}
                            &ndash;
                            {{ formatTime(term.school_end_time) }}
                        </td>

                        <td class="p-4">
                            <span
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm"
                                :class="statusClasses(term.status)"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                {{ term.status }}
                            </span>
                        </td>

                        <td class="p-4">

                            <span
                                v-if="term.active"
                                class="inline-flex items-center gap-1.5 bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                Active
                            </span>

                            <span
                                v-else
                                class="inline-flex items-center gap-1.5 bg-gray-100 text-gray-500 px-3 py-1 rounded-full text-sm"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                Inactive
                            </span>

                        </td>

                        <td class="p-4 whitespace-nowrap">

                            <div class="flex justify-center gap-2">

                                <Link
                                    v-if="term.status !== 'Archived'"
                                    :href="route('academic-terms.edit', term.id)"
                                    class="btn-edit inline-flex items-center gap-1.5"
                                >
                                    <PencilSquareIcon class="h-3.5 w-3.5" />
                                    Edit
                                </Link>

                                <span
                                    v-else
                                    title="Archived Academic Terms are read-only."
                                    class="inline-flex items-center gap-1.5 rounded-full bg-[var(--page-bg)] text-[var(--text-muted)] px-5 py-2 text-sm font-semibold cursor-not-allowed"
                                >
                                    <LockClosedIcon class="h-3.5 w-3.5" />
                                    Edit
                                </span>

                                <button
                                    @click="requestDelete(term)"
                                    :disabled="term.active || term.status === 'Archived'"
                                    :title="term.active
                                        ? 'Activate a different term before deleting this one.'
                                        : term.status === 'Archived'
                                            ? 'Archived Academic Terms are permanent historical record and cannot be deleted.'
                                            : 'Delete this Academic Term'"
                                    class="btn-delete inline-flex items-center gap-1.5"
                                    :class="(term.active || term.status === 'Archived') && 'opacity-50 cursor-not-allowed'"
                                >
                                    <TrashIcon class="h-3.5 w-3.5" />
                                    Delete
                                </button>

                            </div>

                        </td>

                    </tr>

                    <tr v-if="academicTerms.length === 0">

                        <td
                            colspan="8"
                            class="p-12 text-center"
                        >
                            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[#D4A62A]/10 text-[#D4A62A]">
                                <CalendarDaysIcon class="h-6 w-6" />
                            </div>
                            <p class="font-medium text-[var(--text-secondary)]">No academic terms yet</p>
                            <p class="mt-1 text-sm text-[var(--text-muted)]">Add your first academic term to get scheduling started.</p>
                        </td>

                    </tr>

                </tbody>

            </table>

        </div>

        </div>

        <!-- Step 1: Delete Academic Term? -->
        <div
            v-if="pendingTerm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-sm p-6">

                <h3 class="text-lg font-semibold mb-2 text-[var(--text-primary)]">
                    Delete Academic Term?
                </h3>

                <p class="text-[var(--text-secondary)] text-sm mb-6">
                    {{ pendingTerm.academic_year }} &bull; {{ pendingTerm.semester_label }} will be permanently removed. This cannot be undone.
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

        <!-- Step 2: Type DELETE to confirm -->
        <div
            v-if="confirmingTerm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-sm p-6">

                <h3 class="text-lg font-semibold mb-2 text-[var(--text-primary)]">
                    Type DELETE to permanently delete this Academic Term.
                </h3>

                <p class="text-[var(--text-secondary)] text-sm mb-4">
                    {{ confirmingTerm.academic_year }} &bull; {{ confirmingTerm.semester_label }}
                </p>

                <input
                    v-model="deleteConfirmText"
                    type="text"
                    placeholder="DELETE"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] text-[var(--text-primary)] p-2 mb-6 uppercase tracking-wide"
                    @keyup.enter="finalizeDelete"
                >

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
                        @click="finalizeDelete"
                        :disabled="! deleteConfirmValid"
                        class="btn-delete"
                    >
                        Delete
                    </button>

                </div>

            </div>
        </div>

    </DashboardLayout>
</template>