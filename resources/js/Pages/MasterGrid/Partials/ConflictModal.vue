<script setup>
const props = defineProps({
    show: { type: Boolean, default: false },
    conflicts: { type: Array, default: () => [] },
    recommendations: { type: Object, default: null }, // { faculty: [], rooms: [], times: [], meeting_splits: [] }
    // Same meaning/gating as EditScheduleModal's prop of the same name
    // — meeting_splits changes meetings_per_week, which is only safe
    // to offer for a fresh, not-yet-committed placement. See that
    // prop's docblock on EditScheduleModal for the full reasoning.
    allowSessionSettings: { type: Boolean, default: false },
})

const emit = defineEmits(['dismiss', 'apply-faculty', 'apply-room', 'apply-time', 'apply-meeting-split'])

function typeLabel(type) {
    return {
        faculty_conflict: 'Faculty Conflict',
        room_conflict: 'Room Conflict',
        section_conflict: 'Section Conflict',
        outside_school_hours: 'Outside School Hours',
        lunch_break_violation: 'Lunch Break Violation',
        non_working_day: 'Non-Working Day',
        invalid_room_type: 'Invalid Room Type',
    }[type] ?? 'Conflict'
}

function timeLabel(minutes) {
    const h24 = Math.floor(minutes / 60) % 24
    const m = minutes % 60
    const period = h24 >= 12 ? 'PM' : 'AM'
    const h12 = h24 % 12 === 0 ? 12 : h24 % 12
    return `${h12}:${String(m).padStart(2, '0')} ${period}`
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg p-5 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-black text-red-700 dark:text-red-300">Conflict Resolution</h3>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('dismiss')">✕</button>
            </div>

            <div v-for="(conflict, i) in conflicts" :key="i" class="mb-4 rounded-lg border border-red-200 dark:border-red-800 p-3">
                <p class="text-[11px] font-black uppercase tracking-wide text-red-600 dark:text-red-300 mb-1">
                    {{ typeLabel(conflict.type) }}
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-300 mb-2">{{ conflict.reason }}</p>

                <div v-if="conflict.conflicting" class="text-[11px] bg-slate-50 dark:bg-slate-900/40 rounded p-2 space-y-0.5">
                    <p class="font-bold text-slate-700 dark:text-slate-200">Conflicting Assignment</p>
                    <p>{{ conflict.conflicting.subject_code }} · {{ conflict.conflicting.section_code }}</p>
                    <p>{{ conflict.conflicting.day ? conflict.conflicting.day.charAt(0).toUpperCase() + conflict.conflicting.day.slice(1) : '' }}
                        {{ conflict.conflicting.start_minutes != null ? timeLabel(conflict.conflicting.start_minutes) : '' }}–{{ conflict.conflicting.end_minutes != null ? timeLabel(conflict.conflicting.end_minutes) : '' }}
                    </p>
                    <p v-if="conflict.conflicting.room_code">{{ conflict.conflicting.room_code }}</p>
                </div>
            </div>

            <template v-if="recommendations">
                <div v-if="recommendations.faculty?.length" class="mb-4">
                    <p class="text-[11px] font-black uppercase tracking-wide text-slate-500 mb-1.5">Suggested Faculty</p>
                    <button
                        v-for="f in recommendations.faculty"
                        :key="f.faculty_id"
                        type="button"
                        class="w-full text-left rounded-lg border border-slate-200 dark:border-slate-600 px-3 py-2 mb-1.5 hover:bg-blue-50 dark:hover:bg-blue-500/10 transition"
                        @click="emit('apply-faculty', f.faculty_id)"
                    >
                        <p class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ f.full_name }}</p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">Current Load: <strong class="font-bold text-slate-700 dark:text-slate-200">{{ f.current_load }}/{{ f.max_units }}</strong> units</p>
                    </button>
                </div>

                <div v-if="recommendations.rooms?.length" class="mb-4">
                    <p class="text-[11px] font-black uppercase tracking-wide text-slate-500 mb-1.5">Suggested Room</p>
                    <button
                        v-for="r in recommendations.rooms"
                        :key="r.room_id"
                        type="button"
                        class="w-full text-left rounded-lg border border-slate-200 dark:border-slate-600 px-3 py-2 mb-1.5 hover:bg-blue-50 dark:hover:bg-blue-500/10 transition"
                        @click="emit('apply-room', r.room_id)"
                    >
                        <p class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ r.room_code }} <span v-if="r.is_preferred" class="text-[10px] text-blue-500 font-black">PREFERRED</span></p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">{{ r.room_type }} · Available</p>
                    </button>
                </div>

                <div v-if="recommendations.times?.length" class="mb-2">
                    <p class="text-[11px] font-black uppercase tracking-wide text-slate-500 mb-1.5">Suggested Time</p>
                    <button
                        v-for="(t, i) in recommendations.times"
                        :key="i"
                        type="button"
                        class="w-full text-left rounded-lg border border-slate-200 dark:border-slate-600 px-3 py-2 mb-1.5 hover:bg-blue-50 dark:hover:bg-blue-500/10 transition"
                        @click="emit('apply-time', { days: t.days ?? [t.day], start_minutes: t.start_minutes, end_minutes: t.end_minutes })"
                    >
                        <p class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ t.label }}</p>
                    </button>
                </div>

                <div v-if="allowSessionSettings && recommendations.meeting_splits?.length" class="mb-2">
                    <p class="text-[11px] font-black uppercase tracking-wide text-slate-500 mb-1.5">Or Meet More Often</p>
                    <button
                        v-for="(s, i) in recommendations.meeting_splits"
                        :key="i"
                        type="button"
                        class="w-full text-left rounded-lg border border-slate-200 dark:border-slate-600 px-3 py-2 mb-1.5 hover:bg-blue-50 dark:hover:bg-blue-500/10 transition"
                        @click="emit('apply-meeting-split', s)"
                    >
                        <p class="text-xs font-bold text-slate-700 dark:text-slate-200">
                            {{ s.meetings_per_week }}x/week — {{ s.hours_per_meeting }} hrs each
                        </p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">{{ s.message }}</p>
                    </button>
                </div>

                <p v-if="!recommendations.faculty?.length && !recommendations.rooms?.length && !recommendations.times?.length && !(allowSessionSettings && recommendations.meeting_splits?.length)" class="text-xs text-slate-400 italic">
                    No automatic alternatives found — try a different manual change.
                </p>
            </template>

            <div class="flex justify-end mt-4">
                <button type="button" class="btn-neutral" @click="emit('dismiss')">
                    Close
                </button>
            </div>
        </div>
    </div>
</template>