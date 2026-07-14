<script setup>
import { useForm } from '@inertiajs/vue3'
import { watch } from 'vue'

/*
|--------------------------------------------------------------------------
| Faculty Form Modal — Add + Edit, combined
|--------------------------------------------------------------------------
|
| Replaces the old standalone Faculty/Create.vue and Faculty/Edit.vue
| pages. `faculty` is null for Add mode, or a Faculty object for Edit
| mode — everything else (fields, validation display, submit target)
| branches off that one prop. Posts straight to the same
| faculty.store / faculty.update endpoints those pages used to hit;
| both now redirect back to teaching-assignments.index (Faculty
| Loading), which is this modal's only caller, so Inertia's redirect
| just re-visits this same page with fresh props.
*/

const props = defineProps({
    show: { type: Boolean, default: false },
    faculty: { type: Object, default: null },
    departments: { type: Array, required: true },
})

const emit = defineEmits(['close'])

const isEdit = () => !!props.faculty

function blankForm() {
    return {
        first_name: '',
        middle_name: '',
        last_name: '',
        suffix: '',
        gender: '',
        email: '',
        contact_number: '',
        faculty_scope: 'departmental',
        department_id: '',
        employment_type: 'Full-Time',
        max_units: 24,
        status: true,
    }
}

function formFromFaculty(faculty) {
    return {
        first_name: faculty.first_name,
        middle_name: faculty.middle_name ?? '',
        last_name: faculty.last_name,
        suffix: faculty.suffix ?? '',
        gender: faculty.gender ?? '',
        email: faculty.email ?? '',
        contact_number: faculty.contact_number ?? '',
        faculty_scope: faculty.faculty_scope,
        department_id: faculty.department_id ?? '',
        employment_type: faculty.employment_type,
        max_units: faculty.max_units,
        status: faculty.status,
    }
}

const form = useForm(props.faculty ? formFromFaculty(props.faculty) : blankForm())

// Reset the form whenever the modal is (re)opened — covers both
// switching from Edit-one-faculty to Add, and reopening Edit for a
// different faculty member without a full remount.
watch(
    () => [props.show, props.faculty],
    ([show, faculty]) => {
        if (!show) return
        form.reset()
        form.clearErrors()
        Object.assign(form, faculty ? formFromFaculty(faculty) : blankForm())
    },
)

watch(
    () => form.employment_type,
    (value) => {
        form.max_units = value === 'Full-Time' ? 24 : 18
    },
)

watch(
    () => form.faculty_scope,
    (value) => {
        if (value === 'general') {
            form.department_id = ''
        }
    },
)

function submit() {
    const options = {
        onSuccess: () => emit('close'),
    }

    if (isEdit()) {
        form.put(route('faculty.update', props.faculty.id), options)
    } else {
        form.post(route('faculty.store'), options)
    }
}

function close() {
    if (form.processing) return
    emit('close')
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-8">
        <div class="relative flex w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-xl max-h-[90vh]">

            <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

            <div class="flex items-center justify-between border-b border-[var(--card-border)] px-5 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-[var(--text-primary)]">
                        {{ isEdit() ? 'Edit Faculty Member' : 'Add Faculty Member' }}
                    </h3>
                    <p class="text-xs text-[var(--text-muted)]">
                        {{ isEdit() ? faculty.full_name : 'Register a new faculty member' }}
                    </p>
                </div>
                <button type="button" class="text-[var(--text-muted)] hover:text-[var(--text-primary)]" @click="close">✕</button>
            </div>

            <form @submit.prevent="submit" class="flex-1 overflow-y-auto px-5 py-4 space-y-5 custom-scrollbar-theme">

                <!-- PERSONAL INFORMATION -->
                <div>
                    <h4 class="mb-2 text-sm font-semibold text-[var(--text-primary)]">Personal Information</h4>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">First Name</label>
                            <input
                                v-model="form.first_name"
                                type="text"
                                class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                            <p v-if="form.errors.first_name" class="mt-1 text-xs text-red-500">{{ form.errors.first_name }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Middle Name</label>
                            <input
                                v-model="form.middle_name"
                                type="text"
                                class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Last Name</label>
                            <input
                                v-model="form.last_name"
                                type="text"
                                class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                            <p v-if="form.errors.last_name" class="mt-1 text-xs text-red-500">{{ form.errors.last_name }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Suffix</label>
                            <input
                                v-model="form.suffix"
                                type="text"
                                placeholder="Jr., Sr., III"
                                class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                        </div>
                    </div>
                </div>

                <!-- GENDER -->
                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Gender</label>
                    <select
                        v-model="form.gender"
                        class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    >
                        <option disabled value="">Select Gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                    <p v-if="form.errors.gender" class="mt-1 text-xs text-red-500">{{ form.errors.gender }}</p>
                </div>

                <!-- CONTACT -->
                <div>
                    <h4 class="mb-2 text-sm font-semibold text-[var(--text-primary)]">Contact Information</h4>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Email</label>
                            <input
                                v-model="form.email"
                                type="email"
                                class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                            <p v-if="form.errors.email" class="mt-1 text-xs text-red-500">{{ form.errors.email }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Contact Number</label>
                            <input
                                v-model="form.contact_number"
                                type="text"
                                class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                            <p v-if="form.errors.contact_number" class="mt-1 text-xs text-red-500">{{ form.errors.contact_number }}</p>
                        </div>
                    </div>
                </div>

                <!-- FACULTY SCOPE -->
                <div>
                    <h4 class="mb-2 text-sm font-semibold text-[var(--text-primary)]">Teaching Permissions</h4>

                    <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Faculty Scope</label>
                    <select
                        v-model="form.faculty_scope"
                        class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    >
                        <option value="general">General Education</option>
                        <option value="departmental">Departmental</option>
                        <option value="cross_department">Cross Department</option>
                    </select>

                    <p class="mt-1.5 text-xs text-[var(--text-muted)]">
                        <span v-if="form.faculty_scope === 'general'">Not tied to any department. Can only teach Minor subjects, across all programs.</span>
                        <span v-else-if="form.faculty_scope === 'departmental'">Can teach Major and Minor subjects, but only within their own department.</span>
                        <span v-else>Can teach Major subjects only within their own department, and Minor subjects both inside and outside their department.</span>
                    </p>

                    <p v-if="form.errors.faculty_scope" class="mt-1 text-xs text-red-500">{{ form.errors.faculty_scope }}</p>
                </div>

                <!-- DEPARTMENT -->
                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Department</label>
                    <select
                        v-model="form.department_id"
                        :disabled="form.faculty_scope === 'general'"
                        class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30 disabled:bg-[var(--card-border)]/30 disabled:text-[var(--text-muted)]"
                    >
                        <option value="">Select Department</option>
                        <option v-for="department in departments" :key="department.id" :value="department.id">
                            {{ department.abbreviation }} - {{ department.name }}
                        </option>
                    </select>

                    <p v-if="form.faculty_scope === 'general'" class="mt-1 text-xs text-[var(--text-muted)]">
                        General Education faculty are not assigned to a department.
                    </p>
                    <p v-if="form.errors.department_id" class="mt-1 text-xs text-red-500">{{ form.errors.department_id }}</p>
                </div>

                <!-- EMPLOYMENT -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Employment Type</label>
                        <select
                            v-model="form.employment_type"
                            class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="Full-Time">Full-Time</option>
                            <option value="Part-Time">Part-Time</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Maximum Units</label>
                        <input
                            v-model="form.max_units"
                            type="number"
                            min="1"
                            max="24"
                            :readonly="form.employment_type === 'Full-Time'"
                            class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--card-border)]/20 px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                    </div>
                </div>

                <!-- STATUS -->
                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--text-secondary)]">Status</label>
                    <select
                        v-model="form.status"
                        class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    >
                        <option :value="true">Active</option>
                        <option :value="false">Inactive</option>
                    </select>
                </div>

            </form>

            <div class="flex items-center justify-end gap-2 border-t border-[var(--card-border)] px-5 py-4">
                <button type="button" class="btn-neutral" :disabled="form.processing" @click="close">
                    Cancel
                </button>
                <button type="button" class="btn-save" :disabled="form.processing" @click="submit">
                    {{ form.processing ? 'Saving...' : 'Save Faculty' }}
                </button>
            </div>

        </div>
    </div>
</template>