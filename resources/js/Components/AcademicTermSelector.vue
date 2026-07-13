<script setup>
/**
 * AcademicTermSelector
 * --------------------
 * A reusable dropdown for picking an Academic Term. Meant to be dropped
 * into any page that needs to scope its data to a term — Sections,
 * Schedules, Reports, etc — without re-writing the same <select> markup
 * and "which term is active" logic each time.
 *
 * The list of terms is passed in as a prop (from the controller that
 * renders the page, same as `curriculums` is passed into Sections/Create)
 * rather than fetched by the component itself, to stay consistent with
 * how the rest of the app moves data through Inertia props.
 *
 * Usage:
 *   <AcademicTermSelector
 *       v-model="selectedTermId"
 *       :terms="academicTerms"
 *   />
 *
 * With an "All Terms" option (handy for Reports):
 *   <AcademicTermSelector
 *       v-model="selectedTermId"
 *       :terms="academicTerms"
 *       include-all-option
 *   />
 */

import { computed, onMounted } from 'vue'

const props = defineProps({
    // Currently selected academic_term id. Use `null` (or the value
    // passed to allOptionValue) to represent "no term / all terms".
    modelValue: {
        type: [Number, String, null],
        default: null,
    },

    // Array of AcademicTerm objects, each expected to carry at least:
    // id, display_name, active, status.
    terms: {
        type: Array,
        required: true,
    },

    label: {
        type: String,
        default: 'Academic Term',
    },

    // If true, adds an "All Academic Terms" option at the top —
    // useful for Reports pages that aggregate across terms.
    includeAllOption: {
        type: Boolean,
        default: false,
    },

    allOptionValue: {
        type: [Number, String, null],
        default: null,
    },

    allOptionLabel: {
        type: String,
        default: 'All Academic Terms',
    },

    // When nothing is selected yet, auto-select whichever term is
    // currently active. Set to false if the parent page wants full
    // control over the initial value instead.
    autoSelectActive: {
        type: Boolean,
        default: true,
    },

    error: {
        type: String,
        default: null,
    },
})

const emit = defineEmits(['update:modelValue'])

const activeTerm = computed(() =>
    props.terms.find((term) => term.active) ?? null
)

onMounted(() => {
    if (props.autoSelectActive && !props.modelValue && activeTerm.value) {
        emit('update:modelValue', activeTerm.value.id)
    }
})

function onChange(event) {
    const raw = event.target.value

    // Coerce back to a number when it looks numeric, so callers doing
    // strict `===` comparisons against term.id aren't tripped up by the
    // native <select> always giving back a string.
    const value = raw === '' ? null : (isNaN(raw) ? raw : Number(raw))

    emit('update:modelValue', value)
}
</script>

<template>
    <div>

        <label v-if="label" class="block font-medium mb-1">
            {{ label }}
        </label>

        <select
            :value="modelValue ?? ''"
            class="w-full border rounded p-2"
            @change="onChange"
        >
            <option v-if="includeAllOption" :value="allOptionValue ?? ''">
                {{ allOptionLabel }}
            </option>

            <option v-else value="" disabled>
                Select an academic term
            </option>

            <option
                v-for="term in terms"
                :key="term.id"
                :value="term.id"
            >
                {{ term.display_name }}{{ term.active ? ' (Active)' : '' }}
            </option>

        </select>

        <p v-if="error" class="text-red-500 text-sm mt-1">
            {{ error }}
        </p>

        <p v-if="terms.length === 0" class="text-gray-400 text-sm mt-1">
            No academic terms have been created yet.
        </p>

    </div>
</template>