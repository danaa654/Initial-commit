<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    departments: { type: Array, default: () => [] },
    programs: { type: Array, default: () => [] },
    specializations: { type: Array, default: () => [] },
    // Used only to derive Section options client-side — filtered to the
    // active term already, so any section listed here genuinely has
    // offerings waiting to be scheduled. See MasterGridDataService's
    // presentOffering() for program_id/section_id.
    subjectOfferings: { type: Array, default: () => [] },
    generating: { type: Boolean, default: false },
    error: { type: String, default: null },
})

const emit = defineEmits(['close', 'generate'])

const departmentId = ref(null)
const programId = ref(null)
const specializationId = ref(null)
const yearLevel = ref(null)
const sectionId = ref(null)

watch(() => props.show, (visible) => {
    if (visible) {
        departmentId.value = null
        programId.value = null
        specializationId.value = null
        yearLevel.value = null
        sectionId.value = null
    }
})

// Program, Specialization, and Year Level changing should all clear
// the section pick if it no longer applies — Specialization is
// included here now too, since sectionsForSelection filters by it
// and a Section picked under one Specialization is never valid under
// another (e.g. switching BSCRIM from Fingerprint Identification to
// Firearms Identification must not leave BSCRIM-FI-1A selected).
watch([programId, specializationId, yearLevel], () => {
    sectionId.value = null
})

const programsForDepartment = computed(() =>
    props.programs.filter((program) => !departmentId.value || program.department_id === departmentId.value)
)

const specializationsForProgram = computed(() =>
    props.specializations.filter((spec) => spec.program_id === programId.value)
)

const hasSpecializations = computed(() => specializationsForProgram.value.length > 0)

/**
 * Distinct Sections that actually have Subject Offerings for the
 * selected Program + Year Level (+ Specialization, when the Program
 * has one, e.g. BSCRIM's FI/FB/LD/QD) in the active term — built from
 * the offerings already on the page rather than a separate fetch.
 *
 * Filtering on specialization_id here is required, not optional: for
 * a Program with multiple Specializations, every Specialization's
 * Sections previously got merged into one list regardless of which
 * Specialization was actually selected above (e.g. picking BSCRIM ->
 * Fingerprint Identification still showed BSCRIM-QD-1A, BSCRIM-FB-1A,
 * BSCRIM-LD-1A alongside the correct BSCRIM-FI-1A). Picking the wrong
 * one there produced a section with zero matching Subject Offerings,
 * surfacing later as "No Subject Offerings to schedule" in Session
 * Settings instead of at the point the mistake was actually made.
 */
const sectionsForSelection = computed(() => {
    if (!programId.value || !yearLevel.value) return []
    if (hasSpecializations.value && !specializationId.value) return []

    const seen = new Map()

    props.subjectOfferings
        .filter((offering) =>
            offering.program_id === programId.value
            && offering.year_level === yearLevel.value
            && (!hasSpecializations.value || offering.specialization_id === specializationId.value)
        )
        .forEach((offering) => {
            if (offering.section_id && !seen.has(offering.section_id)) {
                seen.set(offering.section_id, offering.section_code)
            }
        })

    return Array.from(seen, ([id, code]) => ({ id, code }))
})

const canGenerate = computed(() => {
    if (!departmentId.value || !programId.value || !yearLevel.value || !sectionId.value) return false
    if (hasSpecializations.value && !specializationId.value) return false
    return true
})

function close() {
    emit('close')
}

function proceedToSessionSettings() {
    if (!canGenerate.value || props.generating) return

    emit('session-settings', {
        department_id: departmentId.value,
        program_id: programId.value,
        specialization_id: specializationId.value,
        year_level: yearLevel.value,
        section_id: sectionId.value,
    })
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-black text-slate-800 dark:text-slate-100">Generate Schedule</h3>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="close">✕</button>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                Pick a section, then review its Session Settings before the Greedy Scheduling
                Algorithm runs. Nothing is saved until you review the generated preview on the
                Master Grid and explicitly confirm it.
            </p>

            <div class="space-y-3">
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500 mb-1">Department</label>
                    <select v-model="departmentId" class="w-full rounded-lg border-slate-300 text-sm text-black dark:text-white dark:bg-slate-900 dark:border-slate-600">
                        <option :value="null">Select department</option>
                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500 mb-1">Program</label>
                    <select v-model="programId" class="w-full rounded-lg border-slate-300 text-sm text-black dark:text-white dark:bg-slate-900 dark:border-slate-600">
                        <option :value="null">Select program</option>
                        <option v-for="program in programsForDepartment" :key="program.id" :value="program.id">{{ program.name }}</option>
                    </select>
                </div>

                <div v-if="hasSpecializations">
                    <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500 mb-1">Specialization</label>
                    <select v-model="specializationId" class="w-full rounded-lg border-slate-300 text-sm text-black dark:text-white dark:bg-slate-900 dark:border-slate-600">
                        <option :value="null">Select specialization</option>
                        <option v-for="spec in specializationsForProgram" :key="spec.id" :value="spec.id">{{ spec.name }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500 mb-1">Year Level</label>
                    <select v-model="yearLevel" class="w-full rounded-lg border-slate-300 text-sm text-black dark:text-white dark:bg-slate-900 dark:border-slate-600">
                        <option :value="null">Select year level</option>
                        <option v-for="year in [1, 2, 3, 4]" :key="year" :value="year">Year {{ year }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500 mb-1">Section</label>
                    <select
                        v-model="sectionId"
                        :disabled="!programId || !yearLevel"
                        class="w-full rounded-lg border-slate-300 text-sm text-black dark:text-white dark:bg-slate-900 dark:border-slate-600 disabled:opacity-50"
                    >
                        <option :value="null">
                            {{ !programId || !yearLevel ? 'Select program and year first' : 'Select section' }}
                        </option>
                        <option v-for="section in sectionsForSelection" :key="section.id" :value="section.id">
                            {{ section.code }}
                        </option>
                    </select>
                    <p v-if="programId && yearLevel && sectionsForSelection.length === 0" class="text-[11px] text-amber-600 mt-1">
                        No unscheduled Subject Offerings found for that Program + Year in the active term.
                    </p>
                </div>
            </div>

            <p v-if="error" class="text-xs font-semibold text-red-600 mt-4">
                {{ error }}
            </p>

            <div class="flex justify-end gap-2 mt-5">
                <button type="button" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700" @click="close">
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="!canGenerate || generating"
                    class="px-4 py-1.5 rounded-lg text-sm font-bold text-white transition"
                    :class="canGenerate && !generating ? 'bg-blue-600 hover:bg-blue-700' : 'bg-blue-300 cursor-not-allowed'"
                    @click="proceedToSessionSettings"
                >
                    {{ generating ? 'Loading…' : 'Session Settings' }}
                </button>
            </div>
        </div>
    </div>
</template>