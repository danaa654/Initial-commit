<script setup>
import { ref } from 'vue'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import Toast from '@/Components/Toast.vue'
import { useForm, router } from '@inertiajs/vue3'
import { useFlashToast } from '@/Composables/useFlashToast'
import {
    BuildingLibraryIcon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    ChevronRightIcon,
    AcademicCapIcon,
    BookOpenIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
    departments: Array,
})

const { toast } = useFlashToast()

// Which college / program rows are expanded. Keyed by id so state
// survives the Inertia partial reloads that happen after each save.
const openDepartments = ref(new Set())
const openPrograms = ref(new Set())

function toggleDepartment(id) {
    openDepartments.value.has(id) ? openDepartments.value.delete(id) : openDepartments.value.add(id)
}
function toggleProgram(id) {
    openPrograms.value.has(id) ? openPrograms.value.delete(id) : openPrograms.value.add(id)
}

/* ------------------------------------------------------------------ */
/*  Modal state — one generic modal, its shape depends on `modal.type` */
/*  ('department' | 'program' | 'specialization') and `modal.mode`    */
/*  ('create' | 'edit').                                              */
/* ------------------------------------------------------------------ */

const modal = ref(null) // { type, mode, departmentId?, programId?, record? }

const deptForm = useForm({ name: '', abbreviation: '', description: '', active: true })
const progForm = useForm({ department_id: null, code: '', name: '', years: 4, active: true })
const specForm = useForm({ program_id: null, code: '', name: '', active: true })

function openCreateDepartment() {
    deptForm.reset()
    deptForm.clearErrors()
    modal.value = { type: 'department', mode: 'create' }
}
function openEditDepartment(dept) {
    deptForm.reset()
    deptForm.clearErrors()
    deptForm.name = dept.name
    deptForm.abbreviation = dept.abbreviation
    deptForm.description = dept.description
    deptForm.active = dept.active
    modal.value = { type: 'department', mode: 'edit', record: dept }
}

function openCreateProgram(departmentId) {
    progForm.reset()
    progForm.clearErrors()
    progForm.department_id = departmentId
    progForm.years = 4
    progForm.active = true
    modal.value = { type: 'program', mode: 'create', departmentId }
}
function openEditProgram(program) {
    progForm.reset()
    progForm.clearErrors()
    progForm.department_id = program.department_id
    progForm.code = program.code
    progForm.name = program.name
    progForm.years = program.years
    progForm.active = program.active
    modal.value = { type: 'program', mode: 'edit', record: program }
}

function openCreateSpecialization(programId) {
    specForm.reset()
    specForm.clearErrors()
    specForm.program_id = programId
    specForm.active = true
    modal.value = { type: 'specialization', mode: 'create', programId }
}
function openEditSpecialization(spec) {
    specForm.reset()
    specForm.clearErrors()
    specForm.program_id = spec.program_id
    specForm.code = spec.code
    specForm.name = spec.name
    specForm.active = spec.active
    modal.value = { type: 'specialization', mode: 'edit', record: spec }
}

function closeModal() {
    modal.value = null
}

function submitModal() {
    if (!modal.value) return
    const { type, mode, record } = modal.value

    const forms = { department: deptForm, program: progForm, specialization: specForm }
    const bases = { department: 'departments', program: 'programs', specialization: 'specializations' }
    const form = forms[type]
    const base = bases[type]

    // Guard against double-clicks/double-submits firing the request twice
    // before Inertia flips `processing` to true.
    if (form.processing) return

    const onSuccess = () => closeModal()

    if (mode === 'create') {
        form.post(`/${base}`, { preserveScroll: true, onSuccess })
    } else {
        form.put(`/${base}/${record.id}`, { preserveScroll: true, onSuccess })
    }
}

/* ------------------------------------------------------------------ */
/*  Deletes — same confirm() pattern as the original three pages.     */
/* ------------------------------------------------------------------ */

function destroyDepartment(dept) {
    if (confirm(`Delete college "${dept.name}"? This cannot be undone.`)) {
        router.delete(`/departments/${dept.id}`, { preserveScroll: true })
    }
}
function destroyProgram(program) {
    if (confirm(`Delete program "${program.name}"? This cannot be undone.`)) {
        router.delete(`/programs/${program.id}`, { preserveScroll: true })
    }
}
function destroySpecialization(spec) {
    if (confirm(`Delete major "${spec.name}"? This cannot be undone.`)) {
        router.delete(`/specializations/${spec.id}`, { preserveScroll: true })
    }
}
</script>

<template>
    <DashboardLayout>

        <Toast :toast="toast" />

        <div class="relative">

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
                        <BuildingLibraryIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Academic Structure
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            {{ departments.length }} {{ departments.length === 1 ? 'college' : 'colleges' }} · manage colleges, programs, and majors in one place
                        </p>
                    </div>
                </div>

                <button @click="openCreateDepartment" class="btn-save inline-flex items-center gap-1.5">
                    <PlusIcon class="h-4 w-4" />
                    Add College
                </button>
            </div>

            <div class="space-y-3">

                <div
                    v-for="dept in departments"
                    :key="dept.id"
                    class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg transition-colors duration-300"
                >
                    <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                    <!-- College row -->
                    <div class="flex items-center justify-between gap-3 p-4">
                        <button
                            @click="toggleDepartment(dept.id)"
                            class="flex flex-1 items-center gap-3 text-left"
                        >
                            <ChevronRightIcon
                                class="h-4 w-4 shrink-0 text-[var(--text-muted)] transition-transform duration-150"
                                :class="{ 'rotate-90': openDepartments.has(dept.id) }"
                            />
                            <span class="inline-flex items-center rounded-md border border-[#D4A62A]/30 bg-[#D4A62A]/10 px-2 py-1 text-xs font-semibold text-[#A8790E] dark:text-[#E8C766]">
                                {{ dept.abbreviation }}
                            </span>
                            <span class="font-medium text-[var(--text-primary)]">{{ dept.name }}</span>
                            <span class="text-xs text-[var(--text-muted)]">
                                {{ dept.programs.length }} {{ dept.programs.length === 1 ? 'program' : 'programs' }}
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="dept.active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                            >
                                {{ dept.active ? 'Active' : 'Inactive' }}
                            </span>
                        </button>

                        <div class="flex items-center gap-2 shrink-0">
                            <button @click="openCreateProgram(dept.id)" class="btn-edit inline-flex items-center gap-1.5">
                                <PlusIcon class="h-3.5 w-3.5" />
                                Program
                            </button>
                            <button @click="openEditDepartment(dept)" class="btn-edit inline-flex items-center gap-1.5">
                                <PencilSquareIcon class="h-3.5 w-3.5" />
                                Edit
                            </button>
                            <button @click="destroyDepartment(dept)" class="btn-delete inline-flex items-center gap-1.5">
                                <TrashIcon class="h-3.5 w-3.5" />
                                Delete
                            </button>
                        </div>
                    </div>

                    <!-- Programs -->
                    <div v-if="openDepartments.has(dept.id)" class="border-t border-[var(--card-border)] bg-[var(--page-bg)]">

                        <div
                            v-for="program in dept.programs"
                            :key="program.id"
                            class="border-b border-[var(--card-border)] last:border-b-0"
                        >
                            <div class="flex items-center justify-between gap-3 py-3 pl-10 pr-4">
                                <button
                                    @click="toggleProgram(program.id)"
                                    class="flex flex-1 items-center gap-3 text-left"
                                >
                                    <ChevronRightIcon
                                        class="h-3.5 w-3.5 shrink-0 text-[var(--text-muted)] transition-transform duration-150"
                                        :class="{ 'rotate-90': openPrograms.has(program.id) }"
                                    />
                                    <AcademicCapIcon class="h-4 w-4 shrink-0 text-[var(--text-muted)]" />
                                    <span class="text-sm font-semibold text-[var(--text-primary)]">{{ program.code }}</span>
                                    <span class="text-sm text-[var(--text-secondary)]">{{ program.name }}</span>
                                    <span class="text-xs text-[var(--text-muted)]">{{ program.years }} yrs</span>
                                    <span class="text-xs text-[var(--text-muted)]">
                                        {{ program.specializations.length }} {{ program.specializations.length === 1 ? 'major' : 'majors' }}
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="program.active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                    >
                                        {{ program.active ? 'Active' : 'Inactive' }}
                                    </span>
                                </button>

                                <div class="flex items-center gap-2 shrink-0">
                                    <button @click="openCreateSpecialization(program.id)" class="btn-edit inline-flex items-center gap-1.5">
                                        <PlusIcon class="h-3.5 w-3.5" />
                                        Major
                                    </button>
                                    <button @click="openEditProgram(program)" class="btn-edit inline-flex items-center gap-1.5">
                                        <PencilSquareIcon class="h-3.5 w-3.5" />
                                        Edit
                                    </button>
                                    <button @click="destroyProgram(program)" class="btn-delete inline-flex items-center gap-1.5">
                                        <TrashIcon class="h-3.5 w-3.5" />
                                        Delete
                                    </button>
                                </div>
                            </div>

                            <!-- Majors -->
                            <div v-if="openPrograms.has(program.id)" class="bg-[var(--card-bg)]">
                                <div
                                    v-for="spec in program.specializations"
                                    :key="spec.id"
                                    class="flex items-center justify-between gap-3 py-2.5 pl-[4.5rem] pr-4 border-t border-[var(--card-border)]"
                                >
                                    <div class="flex flex-1 items-center gap-3">
                                        <BookOpenIcon class="h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]" />
                                        <span v-if="spec.code" class="text-xs font-semibold text-[var(--text-primary)]">{{ spec.code }}</span>
                                        <span class="text-sm text-[var(--text-secondary)]">{{ spec.name }}</span>
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                            :class="spec.active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                        >
                                            {{ spec.active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <button @click="openEditSpecialization(spec)" class="btn-edit inline-flex items-center gap-1.5">
                                            <PencilSquareIcon class="h-3.5 w-3.5" />
                                            Edit
                                        </button>
                                        <button @click="destroySpecialization(spec)" class="btn-delete inline-flex items-center gap-1.5">
                                            <TrashIcon class="h-3.5 w-3.5" />
                                            Delete
                                        </button>
                                    </div>
                                </div>

                                <p v-if="program.specializations.length === 0" class="py-3 pl-[4.5rem] pr-4 text-xs text-[var(--text-muted)] border-t border-[var(--card-border)]">
                                    No majors under this program yet.
                                </p>
                            </div>
                        </div>

                        <p v-if="dept.programs.length === 0" class="py-3 pl-10 pr-4 text-xs text-[var(--text-muted)]">
                            No programs under this college yet.
                        </p>
                    </div>
                </div>

                <div
                    v-if="departments.length === 0"
                    class="rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-12 text-center shadow-lg"
                >
                    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[#D4A62A]/10 text-[#D4A62A]">
                        <BuildingLibraryIcon class="h-6 w-6" />
                    </div>
                    <p class="font-medium text-[var(--text-secondary)]">No colleges yet</p>
                    <p class="mt-1 text-sm text-[var(--text-muted)]">Add your first college, then build out its programs and majors.</p>
                </div>

            </div>
        </div>

        <!-- ============================= MODAL ============================= -->
        <div
            v-if="modal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeModal"
        >
            <div class="w-full max-w-md rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-6 shadow-2xl">

                <!-- Department form -->
                <template v-if="modal.type === 'department'">
                    <h2 class="mb-4 text-lg font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                        {{ modal.mode === 'create' ? 'Add College' : 'Edit College' }}
                    </h2>

                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-muted)]">College Name</label>
                            <input v-model="deptForm.name" type="text" class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30" placeholder="e.g. College of Computing Studies" />
                            <p v-if="deptForm.errors.name" class="mt-1 text-xs text-red-500">{{ deptForm.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-muted)]">Abbreviation</label>
                            <input v-model="deptForm.abbreviation" type="text" class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30" placeholder="e.g. CCS" />
                            <p v-if="deptForm.errors.abbreviation" class="mt-1 text-xs text-red-500">{{ deptForm.errors.abbreviation }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-muted)]">Description</label>
                            <textarea v-model="deptForm.description" rows="2" class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"></textarea>
                            <p v-if="deptForm.errors.description" class="mt-1 text-xs text-red-500">{{ deptForm.errors.description }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                            <input v-model="deptForm.active" type="checkbox" class="rounded" />
                            Active
                        </label>
                    </div>
                </template>

                <!-- Program form -->
                <template v-else-if="modal.type === 'program'">
                    <h2 class="mb-4 text-lg font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                        {{ modal.mode === 'create' ? 'Add Program' : 'Edit Program' }}
                    </h2>

                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-muted)]">Program Code</label>
                            <input v-model="progForm.code" type="text" class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30" placeholder="e.g. BSIT" />
                            <p v-if="progForm.errors.code" class="mt-1 text-xs text-red-500">{{ progForm.errors.code }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-muted)]">Program Name</label>
                            <input v-model="progForm.name" type="text" class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30" placeholder="e.g. BS Information Technology" />
                            <p v-if="progForm.errors.name" class="mt-1 text-xs text-red-500">{{ progForm.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-muted)]">Years</label>
                            <input v-model.number="progForm.years" type="number" min="1" max="8" class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30" />
                            <p v-if="progForm.errors.years" class="mt-1 text-xs text-red-500">{{ progForm.errors.years }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                            <input v-model="progForm.active" type="checkbox" class="rounded" />
                            Active
                        </label>
                    </div>
                </template>

                <!-- Major form -->
                <template v-else-if="modal.type === 'specialization'">
                    <h2 class="mb-4 text-lg font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                        {{ modal.mode === 'create' ? 'Add Major' : 'Edit Major' }}
                    </h2>

                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-muted)]">Code (optional)</label>
                            <input v-model="specForm.code" type="text" class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30" placeholder="e.g. WEBDEV" />
                            <p v-if="specForm.errors.code" class="mt-1 text-xs text-red-500">{{ specForm.errors.code }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-muted)]">Major Name</label>
                            <input v-model="specForm.name" type="text" class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30" placeholder="e.g. Web Development" />
                            <p v-if="specForm.errors.name" class="mt-1 text-xs text-red-500">{{ specForm.errors.name }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                            <input v-model="specForm.active" type="checkbox" class="rounded" />
                            Active
                        </label>
                    </div>
                </template>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="closeModal" class="btn-edit">Cancel</button>
                    <button
                        @click="submitModal"
                        class="btn-save disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="{ department: deptForm.processing, program: progForm.processing, specialization: specForm.processing }[modal.type]"
                    >
                        {{
                            { department: deptForm.processing, program: progForm.processing, specialization: specForm.processing }[modal.type]
                                ? 'Saving…'
                                : (modal.mode === 'create' ? 'Create' : 'Save Changes')
                        }}
                    </button>
                </div>
            </div>
        </div>

    </DashboardLayout>
</template>