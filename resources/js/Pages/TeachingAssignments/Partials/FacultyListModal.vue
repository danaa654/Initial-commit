<script setup>
import { computed, ref } from 'vue'
import { PencilIcon, TrashIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

/*
|--------------------------------------------------------------------------
| Faculty List Modal
|--------------------------------------------------------------------------
|
| Brings back the old standalone Faculty page's table view, now as a
| modal launched from Faculty Loading instead of its own route. Shows
| every faculty member regardless of the sidebar roster's current
| filters, with the same Edit/Delete actions the roster and detail
| panel already use — this component doesn't own that logic, it just
| emits back up to Index.vue (openEditFaculty / openDeleteFaculty),
| which is the single place those modals are actually opened.
*/

const props = defineProps({
    show: { type: Boolean, default: false },
    faculties: { type: Array, required: true },
    departments: { type: Array, required: true },
    scopeLabels: { type: Object, required: true },
    canManageFaculty: { type: Boolean, default: false },
    totalLoad: { type: Function, required: true },
    effectiveMaxUnits: { type: Function, required: true },
})

const emit = defineEmits(['close', 'add', 'edit', 'delete', 'select'])

const search = ref('')
const departmentFilter = ref('')
const scopeFilter = ref('')
const employmentFilter = ref('')

function employeeId(faculty) {
    return `FAC-${String(faculty.id).padStart(4, '0')}`
}

function surnameOf(faculty) {
    const parts = faculty.full_name.trim().split(/\s+/)
    return parts[parts.length - 1] ?? ''
}

const filteredFaculties = computed(() => {
    const term = search.value.trim().toLowerCase()

    return props.faculties
        .filter((faculty) => {
            if (departmentFilter.value && String(faculty.department_id) !== departmentFilter.value) return false
            if (scopeFilter.value && faculty.faculty_scope !== scopeFilter.value) return false
            if (employmentFilter.value && faculty.employment_type !== employmentFilter.value) return false

            if (!term) return true

            const haystack = [faculty.full_name, employeeId(faculty), faculty.department?.name, faculty.email]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()

            return haystack.includes(term)
        })
        .sort((a, b) => surnameOf(a).localeCompare(surnameOf(b), undefined, { sensitivity: 'base' }))
})

function close() {
    emit('close')
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-8">
        <div class="relative flex w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-xl max-h-[90vh]">

            <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

            <!-- Header -->
            <div class="flex items-center justify-between border-b border-[var(--card-border)] px-5 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-[var(--text-primary)]">Faculty List</h3>
                    <p class="text-xs text-[var(--text-muted)]">{{ filteredFaculties.length }} of {{ faculties.length }} faculty members</p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="canManageFaculty"
                        type="button"
                        class="btn-save !px-3 !py-1.5 !text-xs"
                        @click="emit('add')"
                    >
                        + Add Faculty
                    </button>
                    <button type="button" class="text-[var(--text-muted)] hover:text-[var(--text-primary)]" @click="close">✕</button>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-2 border-b border-[var(--card-border)] px-5 py-3">
                <div class="relative min-w-[14rem] flex-1">
                    <MagnifyingGlassIcon class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search faculty..."
                        class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] py-1.5 pl-8 pr-3 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    />
                </div>

                <select
                    v-model="departmentFilter"
                    class="rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-2.5 py-1.5 text-xs text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All Departments</option>
                    <option v-for="dept in departments" :key="dept.id" :value="String(dept.id)">{{ dept.name }}</option>
                </select>

                <select
                    v-model="scopeFilter"
                    class="rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-2.5 py-1.5 text-xs text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All Scopes</option>
                    <option v-for="(label, value) in scopeLabels" :key="value" :value="value">{{ label }}</option>
                </select>

                <select
                    v-model="employmentFilter"
                    class="rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-2.5 py-1.5 text-xs text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All Types</option>
                    <option value="Full-Time">Full-Time</option>
                    <option value="Part-Time">Part-Time</option>
                </select>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-y-auto custom-scrollbar-theme">
                <table class="w-full table-fixed divide-y divide-[var(--card-border)]">
                    <thead class="sticky top-0 bg-[var(--page-bg)]">
                        <tr>
                            <th class="w-[22%] px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Name</th>
                            <th class="w-[10%] px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Employee ID</th>
                            <th class="w-[18%] px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Department</th>
                            <th class="w-[14%] px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Scope</th>
                            <th class="w-[10%] px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Type</th>
                            <th class="w-[10%] px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Load</th>
                            <th class="w-[8%] px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Status</th>
                            <th class="w-[8%] px-3 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--card-border)]">
                        <tr v-if="filteredFaculties.length === 0">
                            <td colspan="8" class="px-4 py-10 text-center text-sm text-[var(--text-muted)]">
                                No faculty match your filters.
                            </td>
                        </tr>
                        <tr
                            v-for="faculty in filteredFaculties"
                            :key="faculty.id"
                            class="cursor-pointer hover:bg-[var(--page-bg)]"
                            @click="emit('select', faculty)"
                        >
                            <td class="whitespace-normal break-words px-3 py-3 align-middle text-sm font-medium text-[var(--text-primary)]">
                                {{ faculty.full_name }}
                                <span v-if="!faculty.status" class="ml-1 text-xs font-normal text-red-500">(Inactive)</span>
                            </td>
                            <td class="whitespace-normal break-words px-3 py-3 align-middle text-xs text-[var(--text-muted)]">
                                {{ employeeId(faculty) }}
                            </td>
                            <td class="whitespace-normal break-words px-3 py-3 align-middle text-xs text-[var(--text-primary)]">
                                {{ faculty.department?.name ?? 'General Education' }}
                            </td>
                            <td class="whitespace-normal break-words px-3 py-3 align-middle text-xs text-[var(--text-primary)]">
                                {{ scopeLabels[faculty.faculty_scope] }}
                            </td>
                            <td class="whitespace-normal break-words px-3 py-3 align-middle text-xs text-[var(--text-primary)]">
                                {{ faculty.employment_type }}
                            </td>
                            <td class="whitespace-normal break-words px-3 py-3 align-middle text-xs text-[var(--text-primary)]">
                                {{ totalLoad(faculty.id) }} / {{ effectiveMaxUnits(faculty) }}
                            </td>
                            <td class="whitespace-normal break-words px-3 py-3 align-middle">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="faculty.status ? 'bg-green-500/10 text-green-600 dark:text-green-400' : 'bg-[var(--page-bg)] text-[var(--text-muted)]'"
                                >
                                    {{ faculty.status ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 align-middle text-right" @click.stop>
                                <div class="inline-flex items-center gap-1.5">
                                    <button
                                        v-if="faculty.can_edit"
                                        type="button"
                                        title="Edit Faculty"
                                        class="flex h-7 w-7 items-center justify-center rounded-lg border border-[var(--card-border)] text-[var(--text-secondary)] transition hover:border-blue-400/50 hover:bg-blue-500/10 hover:text-blue-500"
                                        @click="emit('edit', faculty)"
                                    >
                                        <PencilIcon class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        v-if="canManageFaculty"
                                        type="button"
                                        title="Delete Faculty"
                                        class="flex h-7 w-7 items-center justify-center rounded-lg border border-[var(--card-border)] text-[var(--text-secondary)] transition hover:border-red-400/50 hover:bg-red-500/10 hover:text-red-500"
                                        @click="emit('delete', faculty)"
                                    >
                                        <TrashIcon class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>