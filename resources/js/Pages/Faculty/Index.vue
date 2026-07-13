<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import FacultyDeleteModal from './FacultyDeleteModal.vue'
import {
    AcademicCapIcon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
    faculties: Array,
    canManageFaculty: Boolean,
})

/*
|--------------------------------------------------------------------------
| Delete — Preview Modal for Already-Scheduled Faculty
|--------------------------------------------------------------------------
|
| A faculty member with no scheduled classes still just needs a plain
| confirm() — no need to open a modal to show an empty list. Once
| faculty.has_schedule is true (computed server-side in
| FacultyController::index(), from the SAME check destroy() enforces),
| Delete opens FacultyDeleteModal instead, which fetches the actual list
| of scheduled classes so Admin/Registrar can see exactly what they're
| about to orphan before confirming.
|
*/

const deleteModalOpen = ref(false)
const facultyPendingDelete = ref(null)

function destroy(faculty) {
    if (faculty.has_schedule) {
        facultyPendingDelete.value = faculty
        deleteModalOpen.value = true

        return
    }

    if (confirm(`Delete ${faculty.full_name}? This cannot be undone.`)) {
        router.delete(route('faculty.destroy', faculty.id))
    }
}

function closeDeleteModal() {
    deleteModalOpen.value = false
    facultyPendingDelete.value = null
}
</script>

<template>
    <DashboardLayout>

        <Head title="Faculty Members" />

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
                        <AcademicCapIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Faculty Members
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            {{ faculties.length }} {{ faculties.length === 1 ? 'faculty member' : 'faculty members' }} on record
                        </p>
                    </div>
                </div>

                <Link
                    v-if="canManageFaculty"
                    :href="route('faculty.create')"
                    class="btn-save inline-flex items-center gap-1.5"
                >
                    <PlusIcon class="h-4 w-4" />
                    Add Faculty
                </Link>

            </div>

            <div class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg transition-colors duration-300">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <div class="overflow-x-auto">

                <table class="w-full min-w-[900px]">

                    <thead class="bg-[var(--page-bg)] border-b border-[var(--card-border)]">

                        <tr>
                            <th class="p-4 text-left w-12 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">#</th>
                            <th class="p-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Faculty Name</th>
                            <th class="p-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Email</th>
                            <th class="p-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Department</th>
                            <th class="p-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Employment</th>
                            <th class="p-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Max Units</th>
                            <th class="p-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Faculty Scope</th>
                            <th class="p-4 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Status</th>
                            <th class="p-4 text-center whitespace-nowrap text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                                Actions
                            </th>
                        </tr>

                    </thead>

                    <tbody>

                        <tr
                            v-for="(faculty, index) in faculties"
                            :key="faculty.id"
                            class="border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                        >

                            <td class="p-4 text-[var(--text-secondary)]">
                                {{ index + 1 }}
                            </td>

                            <td class="p-4 font-medium text-[var(--text-primary)]">
                                {{ faculty.full_name }}
                            </td>

                            <td class="p-4 text-[var(--text-secondary)]">
                                {{ faculty.email || '-' }}
                            </td>

                            <td class="p-4 text-[var(--text-secondary)]">
                                {{ faculty.department?.abbreviation ?? 'N/A' }}
                            </td>

                            <td class="p-4">

                                <span
                                    v-if="faculty.employment_type === 'Full-Time'"
                                    class="inline-flex px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-sm font-medium"
                                >
                                    Full-Time
                                </span>

                                <span
                                    v-else
                                    class="inline-flex px-3 py-1 rounded-full bg-yellow-100 text-yellow-700 text-sm font-medium"
                                >
                                    Part-Time
                                </span>

                            </td>

                            <td class="p-4 text-[var(--text-secondary)]">
                                {{ faculty.max_units }}
                            </td>

                            <td class="p-4">

                                <span
                                    v-if="faculty.faculty_scope === 'general'"
                                    class="inline-flex px-3 py-1 rounded-full bg-purple-100 text-purple-700 text-sm font-medium"
                                >
                                    General Education
                                </span>

                                <span
                                    v-else-if="faculty.faculty_scope === 'departmental'"
                                    class="inline-flex px-3 py-1 rounded-full bg-indigo-100 text-indigo-700 text-sm font-medium"
                                >
                                    Departmental
                                </span>

                                <span
                                    v-else
                                    class="inline-flex px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-sm font-medium"
                                >
                                    Cross Department
                                </span>

                            </td>

                            <td class="p-4 text-center">

                                <span
                                    v-if="faculty.status"
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

                                <div class="flex justify-center items-center gap-2">

                                    <Link
                                        v-if="faculty.can_edit"
                                        :href="route('faculty.edit', faculty.id)"
                                        class="btn-edit inline-flex items-center gap-1.5"
                                    >
                                        <PencilSquareIcon class="h-3.5 w-3.5" />
                                        Edit
                                    </Link>

                                    <button
                                        v-if="canManageFaculty"
                                        @click="destroy(faculty)"
                                        class="btn-delete inline-flex items-center gap-1.5"
                                    >
                                        <TrashIcon class="h-3.5 w-3.5" />
                                        Delete
                                    </button>

                                    <span
                                        v-if="!faculty.can_edit && !canManageFaculty"
                                        class="text-sm text-[var(--text-muted)]"
                                    >
                                        View only
                                    </span>

                                </div>

                            </td>

                        </tr>

                        <tr v-if="faculties.length === 0">

                            <td
                                colspan="9"
                                class="text-center p-8 text-[var(--text-muted)]"
                            >
                                No faculty members found.
                            </td>

                        </tr>

                    </tbody>

                </table>

                </div>

            </div>

        </div>

        <FacultyDeleteModal
            :show="deleteModalOpen"
            :faculty="facultyPendingDelete"
            @close="closeDeleteModal"
        />

    </DashboardLayout>
</template>