<script setup>
import { computed } from 'vue'
import { useTimetableGrid } from '@/Composables/useTimetableGrid'

const props = defineProps({
    activeTerm: { type: Object, default: null },
    selectedRoom: { type: Object, default: null },
    // True only while a single block edit's save request is in flight
    // (see Index.vue's applyEdit) — every edit commits immediately now,
    // there is no separate "unsaved draft" state to Cancel or Save.
    saving: { type: Boolean, default: false },
    // Admin/Registrar only — Dean/Assistant Dean/OIC can view the
    // grid and open a block's details, but never see Generate
    // Schedule at all (it's the entry point to writing new blocks,
    // and the backend already rejects generate/save for them — see
    // MasterGridController::middleware()). Hiding it here just avoids
    // showing a button that would only ever end in a 403.
    canManage: { type: Boolean, default: false },
})

const emit = defineEmits(['clear-room', 'generate'])

const { workingDays } = useTimetableGrid(computed(() => props.activeTerm))

const schoolHoursLabel = computed(() => {
    if (!props.activeTerm) return '—'
    return `${props.activeTerm.school_start_time} – ${props.activeTerm.school_end_time}`
})
</script>

<template>
    <header class="master-grid-header shrink-0 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-5 py-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <!-- Academic Term summary -->
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Academic Term</p>
                    <p class="font-bold text-slate-800 dark:text-slate-100 text-sm">
                        {{ activeTerm?.display_name ?? 'No Active Term' }}
                    </p>
                </div>

                <div v-if="activeTerm">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">School Hours</p>
                    <p class="font-bold text-slate-700 dark:text-slate-200 text-sm">{{ schoolHoursLabel }}</p>
                </div>

                <div v-if="activeTerm">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Interval</p>
                    <p class="font-bold text-slate-700 dark:text-slate-200 text-sm">{{ activeTerm.time_interval }} mins</p>
                </div>

                <div v-if="activeTerm">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Working Days</p>
                    <div class="flex gap-1">
                        <span
                            v-for="day in workingDays"
                            :key="day.field"
                            class="px-1.5 py-0.5 rounded text-[11px] font-bold bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300"
                        >
                            {{ day.label }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-2">
                <button
                    v-if="selectedRoom"
                    type="button"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-200 text-xs font-bold dark:bg-indigo-500/10 dark:text-indigo-300 dark:border-indigo-500/30"
                    @click="emit('clear-room')"
                >
                    Room View: {{ selectedRoom.room_code }}
                    <span class="text-indigo-400">✕</span>
                </button>

                <button
                    v-if="canManage"
                    type="button"
                    class="btn-info"
                    :disabled="saving"
                    @click="emit('generate')"
                >
                    {{ saving ? 'Saving…' : 'Generate Schedule' }}
                </button>
            </div>
        </div>
    </header>
</template>