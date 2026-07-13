<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import { watch } from 'vue'
import { AcademicCapIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    faculty: Object,
    departments: Array,
})

const form = useForm({
    first_name: props.faculty.first_name,
    middle_name: props.faculty.middle_name ?? '',
    last_name: props.faculty.last_name,
    suffix: props.faculty.suffix ?? '',

    gender: props.faculty.gender ?? '',

    email: props.faculty.email ?? '',
    contact_number: props.faculty.contact_number ?? '',

    faculty_scope: props.faculty.faculty_scope,

    department_id: props.faculty.department_id ?? '',

    employment_type: props.faculty.employment_type,

    max_units: props.faculty.max_units,

    status: props.faculty.status,
})

watch(
    () => form.employment_type,
    (value) => {
        form.max_units = value === 'Full-Time' ? 24 : 18
    }
)

watch(
    () => form.faculty_scope,
    (value) => {
        if (value === 'general') {
            form.department_id = ''
        }
    }
)

function submit() {
    form.put(route('faculty.update', props.faculty.id))
}
</script>

<template>
    <DashboardLayout>

        <Head title="Edit Faculty Member" />

        <div class="relative">

            <!-- Subtle brand texture: faint grid + one soft gold glow, static (no animation) -->
            <div class="pointer-events-none absolute -inset-x-6 -inset-y-6 -z-10 overflow-hidden">
                <div
                    class="absolute inset-0 opacity-[0.03] dark:opacity-[0.05]"
                    style="background-image: linear-gradient(#1e3a5f 1px, transparent 1px), linear-gradient(90deg, #1e3a5f 1px, transparent 1px); background-size: 42px 42px;"
                ></div>
                <div class="absolute -top-16 right-0 h-64 w-64 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
            </div>

            <div class="max-w-5xl">

                <!-- Header -->
                <div class="flex items-center gap-3 mb-6">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                        <AcademicCapIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Edit Faculty Member
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            {{ faculty.full_name }}
                        </p>
                    </div>
                </div>

                <form
                    @submit.prevent="submit"
                    class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-lg p-6 space-y-6 transition-colors duration-300"
                >

                    <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                    <!-- Personal Information -->

                    <div>

                        <h2 class="text-lg font-semibold mb-4 text-[var(--text-primary)]">
                            Personal Information
                        </h2>

                        <div class="grid grid-cols-2 gap-4">

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">First Name</label>

                                <input
                                    v-model="form.first_name"
                                    type="text"
                                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                                >

                                <p
                                    v-if="form.errors.first_name"
                                    class="text-red-500 text-sm mt-1"
                                >
                                    {{ form.errors.first_name }}
                                </p>
                            </div>

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">Middle Name</label>

                                <input
                                    v-model="form.middle_name"
                                    type="text"
                                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                                >
                            </div>

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">Last Name</label>

                                <input
                                    v-model="form.last_name"
                                    type="text"
                                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                                >

                                <p
                                    v-if="form.errors.last_name"
                                    class="text-red-500 text-sm mt-1"
                                >
                                    {{ form.errors.last_name }}
                                </p>
                            </div>

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">Suffix</label>

                                <input
                                    v-model="form.suffix"
                                    type="text"
                                    placeholder="Jr., Sr., III"
                                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                                >
                            </div>

                        </div>

                    </div>

                    <!-- Gender -->

                    <div>

                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Gender
                        </label>

                        <select
                            v-model="form.gender"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>

                        <p
                            v-if="form.errors.gender"
                            class="text-red-500 text-sm mt-1"
                        >
                            {{ form.errors.gender }}
                        </p>

                    </div>

                    <!-- Contact Information -->

                    <div>

                        <h2 class="text-lg font-semibold mb-4 text-[var(--text-primary)]">
                            Contact Information
                        </h2>

                        <div class="grid grid-cols-2 gap-4">

                            <div>

                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                    Email
                                </label>

                                <input
                                    v-model="form.email"
                                    type="email"
                                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                                >

                                <p
                                    v-if="form.errors.email"
                                    class="text-red-500 text-sm mt-1"
                                >
                                    {{ form.errors.email }}
                                </p>

                            </div>

                            <div>

                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                    Contact Number
                                </label>

                                <input
                                    v-model="form.contact_number"
                                    type="text"
                                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                                >

                                <p
                                    v-if="form.errors.contact_number"
                                    class="text-red-500 text-sm mt-1"
                                >
                                    {{ form.errors.contact_number }}
                                </p>

                            </div>

                        </div>

                    </div>

                    <!-- Faculty Scope -->

                    <div>

                        <h2 class="text-lg font-semibold mb-4 text-[var(--text-primary)]">
                            Teaching Permissions
                        </h2>

                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Faculty Scope
                        </label>

                        <select
                            v-model="form.faculty_scope"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="general">
                                General Education
                            </option>

                            <option value="departmental">
                                Departmental
                            </option>

                            <option value="cross_department">
                                Cross Department
                            </option>
                        </select>

                        <p class="text-sm text-[var(--text-muted)] mt-2">
                            <span v-if="form.faculty_scope === 'general'">
                                Not tied to any department. Can only teach Minor subjects, across all programs.
                            </span>
                            <span v-else-if="form.faculty_scope === 'departmental'">
                                Can teach Major and Minor subjects, but only within their own department.
                            </span>
                            <span v-else>
                                Can teach Major subjects only within their own department, and Minor subjects both inside and outside their department.
                            </span>
                        </p>

                        <p
                            v-if="form.errors.faculty_scope"
                            class="text-red-500 text-sm mt-1"
                        >
                            {{ form.errors.faculty_scope }}
                        </p>

                    </div>

                    <!-- Department -->

                    <div>

                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Department
                        </label>

                        <select
                            v-model="form.department_id"
                            :disabled="form.faculty_scope === 'general'"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30 disabled:bg-[var(--card-border)]/30 disabled:text-[var(--text-muted)]"
                        >

                            <option value="">
                                Select Department
                            </option>

                            <option
                                v-for="department in departments"
                                :key="department.id"
                                :value="department.id"
                            >
                                {{ department.abbreviation }} - {{ department.name }}
                            </option>

                        </select>

                        <p
                            v-if="form.faculty_scope === 'general'"
                            class="text-sm text-[var(--text-muted)] mt-1"
                        >
                            General Education faculty are not assigned to a department.
                        </p>

                        <p
                            v-if="form.errors.department_id"
                            class="text-red-500 text-sm mt-1"
                        >
                            {{ form.errors.department_id }}
                        </p>

                    </div>

                    <!-- Employment -->

                    <div class="grid grid-cols-2 gap-4">

                        <div>

                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Employment Type
                            </label>

                            <select
                                v-model="form.employment_type"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                                <option value="Full-Time">
                                    Full-Time
                                </option>

                                <option value="Part-Time">
                                    Part-Time
                                </option>
                            </select>

                        </div>

                        <div>

                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Maximum Units
                            </label>

                            <input
                                v-model="form.max_units"
                                type="number"
                                min="1"
                                max="24"
                                :readonly="form.employment_type === 'Full-Time'"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--card-border)]/20 px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >

                        </div>

                    </div>

                    <!-- Status -->

                    <div>

                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Status
                        </label>

                        <select
                            v-model="form.status"
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option :value="true">Active</option>
                            <option :value="false">Inactive</option>
                        </select>

                    </div>

                    <!-- Buttons -->

                    <div class="flex justify-end gap-2">

                        <Link
                            :href="route('faculty.index')"
                            class="btn-neutral"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="btn-save"
                        >
                            {{ form.processing ? 'Updating...' : 'Update Faculty' }}
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </DashboardLayout>
</template>