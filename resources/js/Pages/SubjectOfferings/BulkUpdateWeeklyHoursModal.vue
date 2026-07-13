<script setup>
import { computed, ref, watch } from 'vue'
import { ClockIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    open: { type: Boolean, default: false },
    // Array of { id, edp_code, subject_code, descriptive_title, hours }
    offerings: { type: Array, default: () => [] },
    submitting: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'apply'])

const newHours = ref('')

// Reset the input every time the modal is (re)opened with a fresh
// selection, so a leftover value from a previous bulk action never
// silently carries over to a different set of offerings.
watch(() => props.open, (isOpen) => {
    if (isOpen) newHours.value = ''
})

const currentHoursValues = computed(() => {
    const values = [...new Set(props.offerings.map(o => o.hours ?? null))]
    return values
})

const currentHoursLabel = computed(() => {
    if (currentHoursValues.value.length === 0) return '—'
    if (currentHoursValues.value.length === 1) return String(currentHoursValues.value[0] ?? '—')
    return 'Varies'
})

const canApply = computed(() => {
    const n = Number(newHours.value)
    return props.offerings.length > 0 && newHours.value !== '' && Number.isInteger(n) && n >= 2 && n <= 5 && ! props.submitting
})

function apply() {
    if (! canApply.value) return
    emit('apply', Number(newHours.value))
}
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4"
        @click.self="$emit('close')"
    >
        <div class="w-full max-w-lg rounded-2xl bg-[var(--card-bg)] border border-[var(--card-border)] shadow-2xl">

            <div class="flex items-center justify-between border-b border-[var(--card-border)] px-5 py-4">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                        <ClockIcon class="h-4.5 w-4.5" />
                    </div>
                    <h2 class="text-lg font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                        Bulk Update Weekly Hours
                    </h2>
                </div>
                <button
                    type="button"
                    class="rounded-lg p-1.5 text-[var(--text-muted)] hover:bg-[var(--page-bg)]"
                    @click="$emit('close')"
                >
                    <XMarkIcon class="h-5 w-5" />
                </button>
            </div>

            <div class="max-h-[60vh] overflow-y-auto px-5 py-4 thin-scrollbar">

                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                    Selected Subject Offerings ({{ offerings.length }})
                </p>

                <ul class="mb-5 space-y-1.5 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] p-3">
                    <li
                        v-for="offering in offerings"
                        :key="offering.id"
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span class="text-[var(--text-primary)]">
                            <span class="font-medium">{{ offering.subject_code }}</span>
                            <span class="text-[var(--text-muted)]"> — {{ offering.descriptive_title }}</span>
                        </span>
                        <span class="shrink-0 font-mono text-xs text-[var(--text-muted)]">{{ offering.hours ?? '—' }} hrs</span>
                    </li>
                </ul>

                <div class="flex items-center gap-4 rounded-xl border border-[var(--card-border)] p-4">
                    <div class="flex-1">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            Current Weekly Hours
                        </p>
                        <p class="text-2xl font-bold text-[var(--text-primary)]">{{ currentHoursLabel }}</p>
                    </div>

                    <div class="text-2xl text-[var(--text-muted)]">→</div>

                    <div class="flex-1">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            New Weekly Hours
                        </label>
                        <input
                            v-model="newHours"
                            type="number"
                            min="2"
                            max="5"
                            step="1"
                            autofocus
                            class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm font-semibold text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            placeholder="2–5"
                        />
                        <p class="mt-1 text-xs text-[var(--text-muted)]">Allowed range: 2–5 hours per week.</p>
                    </div>
                </div>

                <p class="mt-4 text-xs text-[var(--text-muted)]">
                    This only changes Weekly Hours on the Subject Offerings selected above, for this
                    Academic Term. The Subject master, Curriculum, and Prospectus are never modified.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-[var(--card-border)] px-5 py-4">
                <button
                    type="button"
                    class="btn-neutral rounded-lg px-4 py-2 text-sm font-semibold"
                    :disabled="submitting"
                    @click="$emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn-info rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="! canApply"
                    @click="apply"
                >
                    {{ submitting ? 'Applying…' : 'Apply' }}
                </button>
            </div>

        </div>
    </div>
</template>

<style scoped>
/* Thin scrollbar for the modal's scrollable body — Firefox */
.thin-scrollbar {
    scrollbar-width: thin;
    scrollbar-color: var(--card-border) transparent;
}

/* Thin scrollbar — Chrome/Safari/Edge */
.thin-scrollbar::-webkit-scrollbar {
    width: 6px;
}

.thin-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}

.thin-scrollbar::-webkit-scrollbar-thumb {
    background-color: var(--card-border);
    border-radius: 9999px;
}

.thin-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: #D4A62A;
}
</style>