<script setup>
import { computed } from 'vue'
import { collegeLabel } from '@/Utils/collegeColors'
import { accentColor } from '@/Utils/roomAccentColor'

const props = defineProps({
    rooms: { type: Array, default: () => [] },
})

const emit = defineEmits(['select'])

// Busiest rooms first — this panel's whole point is "here's what's
// already scheduled," so the rooms with the most going on should be
// the first thing seen, not buried below empty ones.
const sortedRooms = computed(() =>
    [...props.rooms].sort((a, b) => (b.utilization_percent ?? 0) - (a.utilization_percent ?? 0))
)

const totalScheduled = computed(() =>
    props.rooms.reduce((sum, r) => sum + (r.scheduled_count ?? 0), 0)
)

const roomsWithClasses = computed(() =>
    props.rooms.filter((r) => (r.scheduled_count ?? 0) > 0).length
)

const avgUtilization = computed(() => {
    if (!props.rooms.length) return 0
    const sum = props.rooms.reduce((s, r) => s + (r.utilization_percent ?? 0), 0)
    return Math.round(sum / props.rooms.length)
})
</script>

<template>
    <div class="p-6 max-w-3xl mx-auto">
        <div v-if="rooms.length === 0" class="p-10 text-center text-sm text-slate-400">
            No active rooms configured for this term yet.
        </div>

        <template v-else>
            <div class="text-center mb-5">
                <p class="text-sm font-semibold" style="color: var(--text-primary)">
                    Select a Room on the right to view its full timetable — it never overlays
                    every Room's classes into the same cells.
                </p>
                <p class="text-xs mt-1" style="color: var(--text-muted)">
                    Or click a Room below to jump straight to it.
                </p>
            </div>

            <!-- Quick totals strip -->
            <div class="grid grid-cols-3 gap-3 mb-5">
                <div class="rounded-lg px-3 py-2 text-center transition-transform duration-150 hover:-translate-y-0.5" style="background: var(--card-bg); border: 1px solid var(--card-border)">
                    <p class="text-lg font-black" style="color: var(--text-primary)">{{ totalScheduled }}</p>
                    <p class="text-[10px] uppercase tracking-wide font-bold" style="color: var(--text-muted)">Classes Scheduled</p>
                </div>
                <div class="rounded-lg px-3 py-2 text-center transition-transform duration-150 hover:-translate-y-0.5" style="background: var(--card-bg); border: 1px solid var(--card-border)">
                    <p class="text-lg font-black" style="color: var(--text-primary)">{{ roomsWithClasses }}/{{ rooms.length }}</p>
                    <p class="text-[10px] uppercase tracking-wide font-bold" style="color: var(--text-muted)">Rooms In Use</p>
                </div>
                <div class="rounded-lg px-3 py-2 text-center transition-transform duration-150 hover:-translate-y-0.5" style="background: var(--card-bg); border: 1px solid var(--card-border)">
                    <p class="text-lg font-black" style="color: var(--text-primary)">{{ avgUtilization }}%</p>
                    <p class="text-[10px] uppercase tracking-wide font-bold" style="color: var(--text-muted)">Avg. Utilization</p>
                </div>
            </div>

            <!-- Per-room utilization bars, clickable -->
            <div class="space-y-1.5 max-h-[360px] overflow-y-auto custom-scrollbar-theme pr-1">
                <button
                    v-for="room in sortedRooms"
                    :key="room.id"
                    type="button"
                    class="room-util-card w-full text-left rounded-lg px-3 py-2 transition-all duration-150 ease-out hover:-translate-y-0.5 hover:shadow-md active:translate-y-0 active:shadow-sm"
                    :style="{
                        background: 'var(--card-bg)',
                        border: '1px solid var(--card-border)',
                        borderLeft: '4px solid var(--room-accent)',
                        '--room-accent': accentColor(room.college_code, room.room_group_codes),
                    }"
                    @click="emit('select', room)"
                >
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <span class="font-black text-[12px] truncate" style="color: var(--text-primary)">
                                {{ room.room_code }}
                            </span>
                            <span
                                class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase text-white shrink-0"
                                :style="{ background: accentColor(room.college_code, room.room_group_codes) }"
                            >
                                {{ collegeLabel(room.college_code, room.room_group_codes) }}
                            </span>
                        </div>
                        <span class="text-[11px] font-bold shrink-0" style="color: var(--text-secondary)">
                            {{ room.scheduled_count > 0 ? `${room.scheduled_count} class(es)` : 'Empty' }}
                        </span>
                    </div>

                    <div class="h-1.5 rounded-full overflow-hidden" style="background: var(--card-border)">
                        <div
                            class="h-full rounded-full transition-all"
                            :style="{
                                width: `${room.utilization_percent}%`,
                                background: room.utilization_percent > 0 ? '#10b981' : 'transparent',
                            }"
                        ></div>
                    </div>

                    <p class="text-[10px] mt-1" style="color: var(--text-muted)">
                        {{ room.hours_used }}/{{ room.weekly_capacity_hours }} hrs · {{ room.utilization_percent }}%
                    </p>
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.room-util-card {
    border-left-width: 4px;
}
.room-util-card:hover {
    border-left-width: 6px;
    padding-left: calc(0.75rem - 2px);
}
</style>