<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    department: Object,
    section: Object,
    offerings: Array,
    academicTerm: Object,
})

function formatTime(minutes) {
    if (minutes === null || minutes === undefined) return null
    const h = Math.floor(minutes / 60)
    const m = minutes % 60
    const suffix = h >= 12 ? 'PM' : 'AM'
    const h12 = h % 12 === 0 ? 12 : h % 12
    return `${h12}:${String(m).padStart(2, '0')} ${suffix}`
}

function capitalize(day) {
    return day.charAt(0).toUpperCase() + day.slice(1)
}

function timeRange(row) {
    if (!row.days?.length) return 'Unscheduled'
    const dayList = row.days.map(capitalize).join(', ')
    return `${dayList} · ${formatTime(row.start_minutes)} – ${formatTime(row.end_minutes)}`
}
</script>

<template>
    <AppLayout>
        <div class="p-8">
            <Link :href="route('block-schedule.sections', department.id)" class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800">
                &lsaquo; Back to {{ department.code }} Blocks
            </Link>

            <div class="mb-6">
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ section.section_code }}</h1>
                <p class="text-sm font-medium text-slate-400">
                    {{ department.name }} — Year {{ section.year_level }}
                    <span v-if="academicTerm"> — {{ academicTerm.display_name }}</span>
                </p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 shadow-sm">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-900 text-white">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">EDP Code</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Subject</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Units</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Day &amp; Time</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Room</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide">Faculty</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-for="row in offerings" :key="row.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3 text-sm font-semibold text-slate-700">
                                {{ row.edp_code ?? '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <p class="font-semibold text-slate-900">{{ row.subject_code }}</p>
                                <p class="text-xs text-slate-400">{{ row.descriptive_title }}</p>
                            </td>
                            <td class="px-5 py-3 text-sm font-medium text-slate-700">
                                {{ row.units ?? '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    :class="row.days?.length ? 'text-slate-700' : 'italic text-slate-400'"
                                    class="text-sm font-medium"
                                >
                                    {{ timeRange(row) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-sm font-medium text-slate-700">
                                {{ row.room_code ?? 'TBA' }}
                            </td>
                            <td class="px-5 py-3 text-sm font-medium text-slate-700">
                                {{ row.faculty_name ?? 'Unassigned' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="!offerings.length" class="mt-10 text-center text-sm text-slate-400">
                No Subject Offerings for this block yet.
            </p>
        </div>
    </AppLayout>
</template>

<style>
/* Sidebar/Topbar come from AppLayout and are visible during
   normal browsing (same chrome as every other page). They're only
   suppressed here for the *printed* output, so the "Print" button above
   still produces a clean Subject/Day/Room/Faculty report. */
@media print {
    #app-sidebar,
    #sidebar-overlay {
        display: none !important;
    }
}
</style>