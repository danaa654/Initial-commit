<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    faculty: { type: Object, required: true },
    // Whether the current user is Admin/Registrar — auto-approves
    // instead of going into a pending queue. Purely for copy/labels;
    // the server re-checks the role independently.
    isUnscoped: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

// Faculty::OVERLOAD_INCREMENT_UNITS / MAX_OVERLOAD_UNITS mirrored here
// for the option list — the server is still the source of truth and
// re-validates both on submit.
const INCREMENT = 3;
const MAX_OVERLOAD = 12;

const availableUnits = computed(() => props.faculty.available_overload_units ?? MAX_OVERLOAD);

const unitOptions = computed(() => {
    const options = [];
    for (let u = INCREMENT; u <= MAX_OVERLOAD; u += INCREMENT) {
        options.push({ value: u, disabled: u > availableUnits.value });
    }
    return options;
});

const selectedUnits = ref(unitOptions.value.find((o) => !o.disabled)?.value ?? INCREMENT);
const reason = ref('');
const submitting = ref(false);
const error = ref(null);

function submit() {
    if (submitting.value) return;

    if (!props.isUnscoped && !reason.value.trim()) {
        error.value = 'Please explain why this faculty member needs additional units.';
        return;
    }

    submitting.value = true;
    error.value = null;

    router.post(
        route('faculty-load-overloads.store'),
        {
            faculty_id: props.faculty.id,
            units: selectedUnits.value,
            reason: reason.value.trim() || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => emit('close'),
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'Something went wrong while submitting this request.';
            },
            onFinish: () => {
                submitting.value = false;
            },
        }
    );
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-md overflow-hidden rounded-2xl bg-[var(--card-bg)] shadow-xl">
            <div class="border-b border-[var(--card-border)] px-6 py-4">
                <h3 class="text-base font-bold text-[var(--text-primary)]">Add Overload Units</h3>
                <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                    {{ faculty.full_name }} — currently {{ faculty.max_units }} base units
                    <span v-if="faculty.approved_overload_units"> + {{ faculty.approved_overload_units }} approved overload</span>
                </p>
            </div>

            <div class="space-y-4 px-6 py-5">
                <p v-if="availableUnits === 0" class="rounded-lg bg-amber-500/10 px-3 py-2 text-xs font-medium text-amber-600 dark:text-amber-400">
                    This faculty member is already at the {{ MAX_OVERLOAD }}-unit overload cap (approved + pending) — no further requests can be submitted.
                </p>

                <template v-else>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">
                            Additional Units
                        </label>
                        <select
                            v-model.number="selectedUnits"
                            class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option v-for="opt in unitOptions" :key="opt.value" :value="opt.value" :disabled="opt.disabled">
                                +{{ opt.value }} units{{ opt.disabled ? ' (exceeds cap)' : '' }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-[var(--text-muted)]">
                            Up to {{ MAX_OVERLOAD }} units total may be added on top of the standard cap. {{ availableUnits }} unit(s) still available for this faculty member.
                        </p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">
                            Reason<span v-if="!isUnscoped" class="text-red-500"> *</span>
                            <span v-else class="normal-case font-normal text-[var(--text-muted)]"> (optional)</span>
                        </label>
                        <textarea
                            v-model="reason"
                            rows="3"
                            :placeholder="isUnscoped ? 'Optional note...' : 'e.g. Department is short-staffed this term...'"
                            class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        ></textarea>
                        <p v-if="!isUnscoped" class="mt-1 text-xs text-[var(--text-muted)]">
                            This will be sent to Admin/Registrar for approval.
                        </p>
                    </div>

                    <p v-if="error" class="rounded-lg bg-red-500/10 px-3 py-2 text-xs font-medium text-red-600 dark:text-red-400">
                        {{ error }}
                    </p>
                </template>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-[var(--card-border)] px-6 py-4">
                <button type="button" class="btn-cancel" @click="emit('close')">Cancel</button>
                <button
                    v-if="availableUnits > 0"
                    type="button"
                    class="btn-save disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="submitting"
                    @click="submit"
                >
                    {{ submitting ? 'Submitting...' : isUnscoped ? 'Add Units' : 'Submit Request' }}
                </button>
            </div>
        </div>
    </div>
</template>