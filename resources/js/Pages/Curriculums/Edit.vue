<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import Toast from '@/Components/Toast.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { computed, watch, ref } from 'vue'
import { useFlashToast } from '@/Composables/useFlashToast'
import { BookOpenIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    curriculum: Object,
    programs: Array,
    specializations: Array,
})

const form = useForm({
    program_id: props.curriculum.program_id,
    specialization_id: props.curriculum.specialization_id,
    code: props.curriculum.code,
    name: props.curriculum.name,
    academic_year: props.curriculum.academic_year,
    effective_year: props.curriculum.effective_year,
    active: props.curriculum.active,
})

const { toast } = useFlashToast()

const showConfirm = ref(false)

const filteredSpecializations = computed(() => {
    return props.specializations.filter(
        s => s.program_id == form.program_id
    )
})

const selectedProgram = computed(() =>
    props.programs.find(p => p.id == form.program_id) || null
)

const selectedSpecialization = computed(() =>
    filteredSpecializations.value.find(s => s.id == form.specialization_id) || null
)

watch(() => form.program_id, () => {
    form.specialization_id = ''
    generateFields()
})

watch(() => form.specialization_id, generateFields)

watch(() => form.effective_year, generateFields)

function generateFields() {
    // See Create.vue's generateFields() for why academic_year lives here
    // instead of its own watcher: a watcher scoped to effective_year only
    // fires when that field itself changes, so editing only the Program
    // (leaving effective_year as-is) would silently skip recomputing it.
    if (form.effective_year) {
        form.academic_year =
            form.effective_year + '-' + (Number(form.effective_year) + 1)
    }

    const program = selectedProgram.value

    if (!program) return

    let code = program.code
    let name = program.name

    if (form.specialization_id) {
        const specialization = selectedSpecialization.value

        if (specialization) {
            code += '-' + specialization.code
            name += ' - ' + specialization.name
        }
    }

    code += '-' + form.effective_year
    name += ' Curriculum ' + form.effective_year

    form.code = code.toUpperCase()
    form.name = name
}

function openConfirm() {
    showConfirm.value = true
}

function confirmSave() {
    showConfirm.value = false
    form.put(route('curriculums.update', props.curriculum.id))
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

            <div class="mx-auto max-w-3xl">

                <div class="flex items-center gap-3 mb-6">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                        <BookOpenIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                            Edit Curriculum
                        </h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            Update the details for this curriculum
                        </p>
                    </div>
                </div>

                <div class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg p-6 transition-colors duration-300">

                    <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                    <form @submit.prevent="openConfirm">

                    <!-- Program & Specialization -->
                    <div class="mb-6">

                        <h2 class="text-lg font-semibold mb-3 text-[var(--text-primary)]">
                            Program & Specialization
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                    Program
                                </label>

                                <select
                                    v-model="form.program_id"
                                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                                >
                                    <option value="">
                                        Select Program
                                    </option>

                                    <option
                                        v-for="program in programs"
                                        :key="program.id"
                                        :value="program.id"
                                    >
                                        {{ program.department.abbreviation }} - {{ program.code }}
                                    </option>

                                </select>

                                <p v-if="form.errors.program_id" class="text-red-500 text-sm mt-1">
                                    {{ form.errors.program_id }}
                                </p>
                            </div>

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                    Specialization (Optional)
                                </label>

                                <select
                                    v-model="form.specialization_id"
                                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                                >
                                    <option value="">
                                        None
                                    </option>

                                    <option
                                        v-for="specialization in filteredSpecializations"
                                        :key="specialization.id"
                                        :value="specialization.id"
                                    >
                                        {{ specialization.name }}
                                    </option>

                                </select>

                                <p v-if="form.errors.specialization_id" class="text-red-500 text-sm mt-1">
                                    {{ form.errors.specialization_id }}
                                </p>
                            </div>

                        </div>

                    </div>

                    <!-- Academic Period -->
                    <div class="mb-6">

                        <h2 class="text-lg font-semibold mb-3 text-[var(--text-primary)]">
                            Academic Period
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                    Effective Year
                                </label>

                                <input
                                    v-model.number="form.effective_year"
                                    type="number"
                                    min="2020"
                                    max="2099"
                                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                                >

                                <p v-if="form.errors.effective_year" class="text-red-500 text-sm mt-1">
                                    {{ form.errors.effective_year }}
                                </p>
                            </div>

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                    Academic Year
                                </label>

                                <div class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-secondary)]">
                                    {{ form.academic_year || '—' }}
                                </div>

                                <p class="text-[var(--text-muted)] text-xs mt-1">
                                    Auto-generated from Effective Year
                                </p>
                            </div>

                        </div>

                    </div>

                    <!-- Generated Fields -->
                    <div class="mb-6">

                        <h2 class="text-lg font-semibold mb-3 text-[var(--text-primary)]">
                            Generated Information
                        </h2>

                        <div class="space-y-4">

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                    Curriculum Code
                                </label>

                                <div class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm font-mono text-[var(--text-secondary)]">
                                    {{ form.code || 'Complete fields above to generate' }}
                                </div>

                                <p class="text-[var(--text-muted)] text-xs mt-1">
                                    Auto-generated from Program, Specialization, and Effective Year
                                </p>
                            </div>

                            <div>
                                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                                    Curriculum Name
                                </label>

                                <div class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-secondary)]">
                                    {{ form.name || 'Complete fields above to generate' }}
                                </div>

                                <p class="text-[var(--text-muted)] text-xs mt-1">
                                    Auto-generated from Program, Specialization, and Effective Year
                                </p>
                            </div>

                        </div>

                    </div>

                    <!-- Status -->
                    <div class="mb-6">

                        <h2 class="text-lg font-semibold mb-3 text-[var(--text-primary)]">
                            Status
                        </h2>

                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)] cursor-pointer transition-colors duration-150 hover:border-[#D4A62A]/40">
                                <input
                                    v-model="form.active"
                                    type="checkbox"
                                >
                                <span class="font-medium">Active</span>
                            </label>

                            <p class="text-[var(--text-muted)] text-sm">
                                Inactive curriculums will not be available for new sections.
                            </p>
                        </div>

                        <p v-if="form.errors.active" class="text-red-500 text-sm mt-1">
                            {{ form.errors.active }}
                        </p>
                    </div>

                    <div class="flex justify-end gap-2">

                        <Link
                            :href="route('curriculums.index')"
                            class="btn-neutral"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="btn-save"
                        >
                            Update Curriculum
                        </button>

                    </div>

                </form>

            </div>

            </div>

        </div>

        <!-- Review Confirmation Modal -->
        <div
            v-if="showConfirm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        >
            <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-md p-6">

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <h3 class="text-lg font-semibold mb-1 text-[var(--text-primary)]">
                    Review Curriculum
                </h3>

                <p class="text-[var(--text-muted)] text-sm mb-4">
                    Please review the curriculum details before saving.
                </p>

                <dl class="space-y-2 text-sm mb-6">

                    <div class="flex justify-between border-b border-[var(--card-border)] pb-2">
                        <dt class="text-[var(--text-muted)]">Curriculum Code</dt>
                        <dd class="font-mono font-medium">{{ form.code }}</dd>
                    </div>

                    <div class="flex justify-between border-b border-[var(--card-border)] pb-2">
                        <dt class="text-[var(--text-muted)]">Curriculum Name</dt>
                        <dd class="font-medium">{{ form.name }}</dd>
                    </div>

                    <div class="flex justify-between border-b border-[var(--card-border)] pb-2">
                        <dt class="text-[var(--text-muted)]">Program</dt>
                        <dd class="font-medium">{{ selectedProgram?.code }}</dd>
                    </div>

                    <div class="flex justify-between border-b border-[var(--card-border)] pb-2">
                        <dt class="text-[var(--text-muted)]">Academic Year</dt>
                        <dd class="font-medium">{{ form.academic_year }}</dd>
                    </div>

                    <div class="flex justify-between border-b border-[var(--card-border)] pb-2">
                        <dt class="text-[var(--text-muted)]">Effective Year</dt>
                        <dd class="font-medium">{{ form.effective_year }}</dd>
                    </div>

                    <div class="flex justify-between">
                        <dt class="text-[var(--text-muted)]">Status</dt>
                        <dd class="font-medium">{{ form.active ? 'Active' : 'Inactive' }}</dd>
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
                        :disabled="form.processing"
                        class="btn-save"
                    >
                        Confirm Save
                    </button>

                </div>

            </div>
        </div>

    </DashboardLayout>
</template>