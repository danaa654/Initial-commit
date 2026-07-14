<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import Toast from '@/Components/Toast.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useAcademicTermForm, SEMESTERS, DAYS, TIME_INTERVALS } from '@/Composables/useAcademicTermForm'
import { useFlashToast } from '@/Composables/useFlashToast'
import { CalendarDaysIcon, ClockIcon, CalendarIcon, CheckCircleIcon, PlusCircleIcon, Cog6ToothIcon, Squares2X2Icon } from '@heroicons/vue/24/outline'

const form = useForm({
    // The user only ever types the first year (e.g. 2026). The
    // "2026-2027" academic_year string is generated server-side from
    // this value on save — see AcademicTermRequest::validatedForSave().
    start_year: '',
    semester: '',

    class_start_date: '',
    class_end_date: '',

    // Default to a sensible 7:00 AM - 5:00 PM school day, same pattern as
    // the lunch defaults below — the user only has to change these if the
    // institution's hours differ, instead of picking AM/PM from scratch.
    school_start_time: '07:00',
    school_end_time: '17:00',

    // New terms default to a 12:00 PM - 1:00 PM lunch break. The user can
    // still edit or clear both fields if the institution has none. Edit.vue
    // is unaffected — it always initializes from the saved record instead.
    lunch_start_time: '12:00',
    lunch_end_time: '13:00',

    time_interval: 30,

    monday: true,
    tuesday: true,
    wednesday: true,
    thursday: true,
    friday: true,
    saturday: false,
    sunday: false,

    status: 'Draft',
    active: false,
})

// Shared Start Year -> Academic Year preview, academic-year-aware date
// range restriction + auto-clearing, and inline validation hints. Kept in
// one composable so Create.vue and Edit.vue don't duplicate this logic.
const {
    academicYearPreview,
    semesterLabel,
    dateRange,
    classDatesInvalid,
    schoolHoursInvalid,
    lunchIncomplete,
    lunchOrderInvalid,
    lunchOutsideSchoolHours,
    activeRequiresPublished,
    workingDaysInvalid,
    hasBlockingErrors,
    onStartYearInput,
} = useAcademicTermForm(form)

const { toast } = useFlashToast()

/*
|--------------------------------------------------------------------------
| Save Confirmation
|--------------------------------------------------------------------------
|
| Submitting the form opens a review dialog summarizing the Academic
| Year, Semester, and Class Dates instead of saving immediately. The
| actual POST only fires once the user taps "Confirm Save".
|
*/

const showConfirm = ref(false)

function openConfirm() {
    showConfirm.value = true
}

function confirmSave() {
    showConfirm.value = false
    form.post(route('academic-terms.store'))
}
</script>

<template>
    <DashboardLayout>

        <Toast :toast="toast" />

        <div class="relative mx-auto max-w-3xl">

            <!-- Subtle brand texture: faint grid + one soft gold glow, static (no animation) -->
            <div class="pointer-events-none absolute -inset-x-6 -inset-y-6 -z-10 overflow-hidden">
                <div
                    class="absolute inset-0 opacity-[0.03] dark:opacity-[0.05]"
                    style="background-image: linear-gradient(#1e3a5f 1px, transparent 1px), linear-gradient(90deg, #1e3a5f 1px, transparent 1px); background-size: 42px 42px;"
                ></div>
                <div class="absolute -top-16 right-0 h-64 w-64 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
            </div>

            <div class="flex items-center gap-3 mb-6">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                    <PlusCircleIcon class="h-5.5 w-5.5" />
                </div>
                <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                    Add Academic Term
                </h1>
            </div>

            <div class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg p-6 transition-colors duration-300">

            <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

            <form @submit.prevent="openConfirm">

                <!-- Academic Period -->
                <div class="mb-6">

                    <h2 class="flex items-center gap-2 text-lg font-semibold mb-3 text-[var(--text-primary)]">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#D4A62A]/10 text-[#D4A62A]"><CalendarDaysIcon class="h-4 w-4" /></span>
                        Academic Period
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Start Year
                            </label>

                            <input
                                :value="form.start_year"
                                @input="onStartYearInput"
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                maxlength="4"
                                placeholder="e.g. 2026"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >

                            <!-- Live "Academic Year: 2026-2027" preview -->
                            <p v-if="academicYearPreview" class="text-[var(--text-muted)] text-sm mt-1">
                                Academic Year: <span class="font-medium text-[var(--text-primary)]">{{ academicYearPreview }}</span>
                            </p>

                            <p v-if="form.errors.start_year" class="text-red-500 text-sm mt-1">
                                {{ form.errors.start_year }}
                            </p>
                        </div>

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Semester
                            </label>

                            <select
                                v-model="form.semester"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                                <option value="" disabled>
                                    Select a semester
                                </option>
                                <option
                                    v-for="semester in SEMESTERS"
                                    :key="semester.value"
                                    :value="semester.value"
                                >
                                    {{ semester.label }}
                                </option>
                            </select>

                            <p v-if="form.errors.semester" class="text-red-500 text-sm mt-1">
                                {{ form.errors.semester }}
                            </p>
                        </div>

                    </div>

                </div>

                <!-- Semestral Period -->
                <div class="mb-6">

                    <h2 class="flex items-center gap-2 text-lg font-semibold mb-3 pt-6 border-t border-[var(--card-border)] text-[var(--text-primary)]">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#D4A62A]/10 text-[#D4A62A]"><CalendarIcon class="h-4 w-4" /></span>
                        Semestral Period
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Semestral Start
                            </label>

                            <input
                                v-model="form.class_start_date"
                                type="date"
                                :min="dateRange.min"
                                :max="dateRange.max"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >

                            <p v-if="form.errors.class_start_date" class="text-red-500 text-sm mt-1">
                                {{ form.errors.class_start_date }}
                            </p>
                        </div>

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Semestral End
                            </label>

                            <input
                                v-model="form.class_end_date"
                                type="date"
                                :min="dateRange.min"
                                :max="dateRange.max"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >

                            <p v-if="form.errors.class_end_date" class="text-red-500 text-sm mt-1">
                                {{ form.errors.class_end_date }}
                            </p>
                        </div>

                        <!-- Inline hint, in addition to any server-side error -->
                        <p v-if="classDatesInvalid" class="md:col-span-2 text-red-500 text-sm -mt-2">
                            Semestral End cannot be before Semestral Start.
                        </p>

                    </div>

                </div>

                <!-- School Hours -->
                <div class="mb-6">

                    <h2 class="flex items-center gap-2 text-lg font-semibold mb-3 pt-6 border-t border-[var(--card-border)] text-[var(--text-primary)]">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#D4A62A]/10 text-[#D4A62A]"><ClockIcon class="h-4 w-4" /></span>
                        School Hours
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                School Start Time
                            </label>

                            <input
                                v-model="form.school_start_time"
                                type="time"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >

                            <p v-if="form.errors.school_start_time" class="text-red-500 text-sm mt-1">
                                {{ form.errors.school_start_time }}
                            </p>
                        </div>

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                School End Time
                            </label>

                            <input
                                v-model="form.school_end_time"
                                type="time"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >

                            <p v-if="form.errors.school_end_time" class="text-red-500 text-sm mt-1">
                                {{ form.errors.school_end_time }}
                            </p>
                        </div>

                        <!-- Inline hint, in addition to any server-side error -->
                        <p v-if="schoolHoursInvalid" class="md:col-span-2 text-red-500 text-sm -mt-2">
                            School End Time must be after School Start Time.
                        </p>

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Lunch Start
                                <span class="text-[var(--text-muted)] font-normal">(optional)</span>
                            </label>

                            <input
                                v-model="form.lunch_start_time"
                                type="time"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >

                            <p v-if="form.errors.lunch_start_time" class="text-red-500 text-sm mt-1">
                                {{ form.errors.lunch_start_time }}
                            </p>
                        </div>

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Lunch End
                                <span class="text-[var(--text-muted)] font-normal">(optional)</span>
                            </label>

                            <input
                                v-model="form.lunch_end_time"
                                type="time"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >

                            <p v-if="form.errors.lunch_end_time" class="text-red-500 text-sm mt-1">
                                {{ form.errors.lunch_end_time }}
                            </p>
                        </div>

                        <!-- Inline hints, in addition to any server-side error -->
                        <p v-if="lunchIncomplete" class="md:col-span-2 text-red-500 text-sm -mt-2">
                            Please fill in both Lunch Start and Lunch End, or leave both blank.
                        </p>
                        <p v-else-if="lunchOrderInvalid" class="md:col-span-2 text-red-500 text-sm -mt-2">
                            Lunch End must be after Lunch Start.
                        </p>
                        <p v-else-if="lunchOutsideSchoolHours" class="md:col-span-2 text-red-500 text-sm -mt-2">
                            Lunch Break must fall within School Hours.
                        </p>

                    </div>

                </div>

                <!-- Scheduler Settings -->
                <div class="mb-6">

                    <h2 class="flex items-center gap-2 text-lg font-semibold mb-3 pt-6 border-t border-[var(--card-border)] text-[var(--text-primary)]">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#D4A62A]/10 text-[#D4A62A]"><Cog6ToothIcon class="h-4 w-4" /></span>
                        Scheduler Settings
                    </h2>

                    <div class="max-w-xs">
                        <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                            Time Interval (minutes)
                        </label>

                        <!-- Standardized to 30 minutes — no longer user-editable.
                        form.time_interval stays fixed at 30 (its existing default,
                        see useForm() above), so nothing downstream changes:
                        GreedyScheduleService/ScheduleRecommendationService already
                        fall back to `$term->time_interval ?: 30`, and
                        AcademicTermRequest still validates against
                        AcademicTerm::TIME_INTERVALS server-side. -->
                        <div
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-secondary)] flex items-center justify-between"
                        >
                            <span>30 minutes</span>
                            <span class="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)]">Fixed</span>
                        </div>

                        <p class="text-[var(--text-muted)] text-sm mt-1">
                            Controls how finely the scheduler can slice the school day. Standardized across all terms.
                        </p>
                    </div>

                </div>

                <!-- Working Days -->
                <div class="mb-6">

                    <h2 class="flex items-center gap-2 text-lg font-semibold mb-3 pt-6 border-t border-[var(--card-border)] text-[var(--text-primary)]">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#D4A62A]/10 text-[#D4A62A]"><Squares2X2Icon class="h-4 w-4" /></span>
                        Working Days
                    </h2>

                    <div class="flex flex-wrap gap-4">

                        <label
                            v-for="day in DAYS"
                            :key="day.key"
                            class="flex items-center gap-2 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] cursor-pointer transition-colors duration-150 hover:border-[#D4A62A]/40"
                        >
                            <input
                                v-model="form[day.key]"
                                type="checkbox"
                            >
                            {{ day.label }}
                        </label>

                    </div>

                    <!-- Inline hint, in addition to any server-side error -->
                    <p v-if="workingDaysInvalid" class="text-red-500 text-sm mt-2">
                        At least one Working Day must be selected.
                    </p>
                    <p v-else-if="form.errors.monday" class="text-red-500 text-sm mt-2">
                        {{ form.errors.monday }}
                    </p>

                </div>

                <!-- Status & Activation -->
                <div class="mb-6">

                    <h2 class="flex items-center gap-2 text-lg font-semibold mb-3 pt-6 border-t border-[var(--card-border)] text-[var(--text-primary)]">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#D4A62A]/10 text-[#D4A62A]"><CheckCircleIcon class="h-4 w-4" /></span>
                        Status & Activation
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">

                        <div>
                            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                Status
                            </label>

                            <select
                                v-model="form.status"
                                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                                <option value="Draft">Draft</option>
                                <option value="Published">Published</option>
                                <option value="Archived">Archived</option>
                            </select>

                            <p v-if="form.errors.status" class="text-red-500 text-sm mt-1">
                                {{ form.errors.status }}
                            </p>
                        </div>

                        <div>
                            <label class="flex items-center gap-2 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] cursor-pointer transition-colors duration-150 hover:border-[#D4A62A]/40 mt-1">
                                <input
                                    v-model="form.active"
                                    type="checkbox"
                                >
                                Set as the active Academic Term
                            </label>

                            <p class="text-[var(--text-muted)] text-sm mt-1">
                                Activating this term will automatically deactivate any other active term.
                            </p>

                            <!-- Inline hint: only a Published term may be Active -->
                            <p v-if="activeRequiresPublished" class="text-red-500 text-sm mt-1">
                                Only a Published Academic Term can be set as Active.
                            </p>
                            <p v-else-if="form.errors.active" class="text-red-500 text-sm mt-1">
                                {{ form.errors.active }}
                            </p>
                        </div>

                    </div>

                </div>

                <div class="flex justify-end gap-2">

                    <Link
                        :href="route('academic-terms.index')"
                        class="btn-neutral"
                    >
                        Cancel
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing || hasBlockingErrors"
                        class="btn-save"
                    >
                        Save Academic Term
                    </button>

                </div>

            </form>

            </div>

        </div>

        <!-- Review Confirmation Modal -->
        <div
            v-if="showConfirm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-md p-6">

                <h3 class="text-lg font-semibold mb-1 text-[var(--text-primary)]">
                    Review Academic Term
                </h3>

                <p class="text-[var(--text-secondary)] text-sm mb-4">
                    Please review the Academic Year, Semester and Class Dates before saving.
                </p>

                <dl class="space-y-2 text-sm mb-6">

                    <div class="flex justify-between border-b border-[var(--card-border)] pb-2">
                        <dt class="text-[var(--text-secondary)]">Academic Year</dt>
                        <dd class="font-medium text-[var(--text-primary)]">{{ academicYearPreview ?? '—' }}</dd>
                    </div>

                    <div class="flex justify-between border-b border-[var(--card-border)] pb-2">
                        <dt class="text-[var(--text-secondary)]">Semester</dt>
                        <dd class="font-medium text-[var(--text-primary)]">{{ semesterLabel ?? '—' }}</dd>
                    </div>

                    <div class="flex justify-between border-b border-[var(--card-border)] pb-2">
                        <dt class="text-[var(--text-secondary)]">Semestral Start</dt>
                        <dd class="font-medium text-[var(--text-primary)]">{{ form.class_start_date || '—' }}</dd>
                    </div>

                    <div class="flex justify-between">
                        <dt class="text-[var(--text-secondary)]">Semestral End</dt>
                        <dd class="font-medium text-[var(--text-primary)]">{{ form.class_end_date || '—' }}</dd>
                    </div>

                </dl>

                <div class="flex justify-end gap-2">

                    <button
                        type="button"
                        @click="showConfirm = false"
                        class="btn-neutral"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        @click="confirmSave"
                        :disabled="form.processing || hasBlockingErrors"
                        class="btn-save"
                    >
                        Confirm Save
                    </button>

                </div>

            </div>
        </div>

    </DashboardLayout>
</template>