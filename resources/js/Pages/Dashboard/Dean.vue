<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import DashboardBackgroundFX from '@/Components/DashboardBackgroundFX.vue'
import StatCard from '@/Components/Dashboard/StatCard.vue'
import ChartCard from '@/Components/Dashboard/ChartCard.vue'
import { collegeClasses, collegeLabel } from '@/Utils/collegeColors'

const props = defineProps({
    stats: { type: Object, required: true },
    charts: { type: Object, required: true },
    tables: { type: Object, required: true },
    // 'Dean' or 'OIC' — same dashboard/data for both, only the heading
    // text changes so an OIC user isn't shown someone else's title.
    roleLabel: { type: String, default: 'Dean' },
})

// Every number on this page is already scoped to this user's own
// department by DashboardService (deanStats/deanCharts/deanTables all
// filter on $user->department_id) — a CCS Dean only ever sees CCS
// faculty, CCS programs, CCS sections, and CCS subject offerings.
// `college` here just drives the color tint, it isn't what does the
// actual data scoping.
const college = computed(() => props.stats.college_code ?? null)
const palette = computed(() => (college.value ? collegeClasses(college.value) : null))
const collegeName = computed(() => (college.value ? collegeLabel(college.value) : null))

const today = computed(() => {
    const d = new Date()
    return {
        date: d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }),
        weekday: d.toLocaleDateString('en-US', { weekday: 'long' }),
        time: d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }),
    }
})

// Department Scheduling Progress — deanCharts() sends
// { scheduled, total, percent } rather than labels/datasets, so it's
// shaped here into the same doughnut format ChartCard already expects.
const progressChart = computed(() => {
    const p = props.charts.department_progress ?? {}
    const scheduled = p.scheduled ?? 0
    const total = p.total ?? 0
    const remaining = Math.max(total - scheduled, 0)

    return {
        labels: ['Scheduled', 'Remaining'],
        datasets: [{ data: [scheduled, remaining], backgroundColor: ['#6366f1', '#e2e8f0'] }],
        percent: p.percent ?? 0,
    }
})

// Alerts & Notifications — this dashboard doesn't get a conflicts
// widget from the controller, so it's assembled from the three
// department-scoped tables deanTables() already provides.
const alerts = computed(() => {
    const needingAssignment = props.tables.faculty_needing_assignment?.length ?? 0
    const overload = props.tables.faculty_overload?.length ?? 0
    const withoutFaculty = props.tables.subjects_without_faculty?.length ?? 0

    const items = []
    if (overload > 0) {
        items.push({
            icon: 'exclamation', color: '#ef4444', bg: '#fee2e2',
            title: `${overload} Faculty Overloaded`,
            sub: 'Assigned units exceed their max load',
        })
    }
    if (withoutFaculty > 0) {
        items.push({
            icon: 'clock', color: '#f97316', bg: '#ffedd5',
            title: `${withoutFaculty} Subject${withoutFaculty === 1 ? '' : 's'} Without Faculty`,
            sub: 'Needs a faculty assignment',
        })
    }
    if (needingAssignment > 0) {
        items.push({
            icon: 'clock', color: '#3b82f6', bg: '#dbeafe',
            title: `${needingAssignment} Faculty Needing Assignment`,
            sub: 'Active faculty with no teaching load yet',
        })
    }
    if (!items.length) {
        items.push({
            icon: 'check', color: '#22c55e', bg: '#dcfce7',
            title: 'All clear', sub: 'No overloads or unassigned subjects right now',
        })
    }
    return items
})

// Scoped to the Dean/OIC's own department the same way the pages
// themselves already gate write access — see BlockSchedule/Master Grid
// canManageDepartment() from project history.
const quickActions = [
    { label: 'Master Grid', href: route('master-grid.index'), color: '#8b5cf6', bg: '#f3e8ff',
        icon: 'M3 21h18M5 21V7l8-4v18M13 21V11l6 3v7M9 9h.01M9 12h.01M9 15h.01' },
    { label: 'Faculty Loading', href: route('teaching-assignments.index'), color: '#22c55e', bg: '#dcfce7',
        icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4' },
    { label: 'Block Schedule', href: route('block-schedule.landing'), color: '#3b82f6', bg: '#dbeafe',
        icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
    { label: 'Teaching Assignments', href: route('teaching-assignments.index'), color: '#f97316', bg: '#ffedd5',
        icon: 'M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422A12.083 12.083 0 0118 15.5c0 2.485-2.686 4.5-6 4.5s-6-2.015-6-4.5c0-1.098.336-2.107.908-2.922L12 14zm0 0v6' },
]
</script>

<template>
    <DashboardLayout>
        <DashboardBackgroundFX>

            <!-- Welcome header -->
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-extrabold text-[var(--text-primary)]">
                            Welcome back, {{ roleLabel }}! 👋
                        </h1>
                        <span
                            v-if="collegeName"
                            class="text-xs font-semibold px-2.5 py-1 rounded-full border"
                            :class="palette.badge"
                        >
                            {{ collegeName }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-[var(--text-secondary)]">
                        Here's what's happening in your department today.
                    </p>
                </div>

                <div class="flex items-center gap-3 rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] px-4 py-2.5 shadow-sm">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div class="text-left">
                        <p class="text-sm font-semibold text-[var(--text-primary)]">{{ today.date }}</p>
                        <p class="text-xs text-[var(--text-secondary)]">{{ today.weekday }}, {{ today.time }}</p>
                    </div>
                </div>
            </div>

            <!-- Stat cards — tinted by department color via the `college` prop -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
                <StatCard label="Faculty" :value="stats.faculty" :college="college" glow-color="#3b82f6">In Department</StatCard>
                <StatCard label="Programs" :value="stats.programs" :college="college" glow-color="#8b5cf6">Active Programs</StatCard>
                <StatCard label="Sections" :value="stats.sections" :college="college" glow-color="#14b8a6">Total Sections</StatCard>
                <StatCard label="Scheduled Subjects" :value="stats.scheduled_subjects" accent="text-emerald-500" :college="college" glow-color="#22c55e">This Term</StatCard>
                <StatCard label="Remaining Subjects" :value="stats.remaining_subjects" accent="text-amber-500" :college="college" glow-color="#f97316">This Term</StatCard>
            </div>

            <!-- Faculty Load / Department Progress / Alerts -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
                <ChartCard
                    title="Faculty Load"
                    type="bar"
                    badge="This Term"
                    :college="college"
                    glow-color="#6366f1"
                    :labels="charts.faculty_load.labels"
                    :datasets="[{ label: 'Units', data: charts.faculty_load.data }]"
                />

                <ChartCard
                    title="Department Scheduling Progress"
                    type="doughnut"
                    center-mode="percent"
                    :center-caption="`${progressChart.percent}% Completed`"
                    :college="college"
                    glow-color="#22c55e"
                    :labels="progressChart.labels"
                    :datasets="progressChart.datasets"
                    :height="180"
                />

                <div class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="border-color: color-mix(in srgb, #ef4444 35%, var(--card-border))">
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #ef4444"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #ef4444 45%, transparent), 0 0 22px 0 color-mix(in srgb, #ef4444 35%, transparent)"></div>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-semibold text-[var(--text-primary)]">Alerts &amp; Notifications</h2>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[var(--text-secondary)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </div>
                    <ul class="flex flex-col gap-3">
                        <li v-for="(a, i) in alerts" :key="i" class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" :style="{ background: a.bg }">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" :stroke="a.color">
                                    <path v-if="a.icon === 'exclamation'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    <path v-else-if="a.icon === 'clock'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-[var(--text-primary)]">{{ a.title }}</p>
                                <p class="text-xs text-[var(--text-secondary)]">{{ a.sub }}</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Faculty Needing Assignment / Faculty Overload / Subjects Without Faculty / Quick Actions -->
            <div class="grid grid-cols-1 xl:grid-cols-4 gap-4">

                <div class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="border-color: color-mix(in srgb, #06b6d4 35%, var(--card-border))">
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #06b6d4"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #06b6d4 45%, transparent), 0 0 22px 0 color-mix(in srgb, #06b6d4 35%, transparent)"></div>
                    <h2 class="text-base font-semibold mb-3 text-[var(--text-primary)]">Faculty Needing Assignment</h2>
                    <ul v-if="tables.faculty_needing_assignment.length" class="divide-y divide-[var(--card-border)] text-sm max-h-72 overflow-y-auto overflow-x-hidden pr-1 thin-scrollbar">
                        <li
                            v-for="f in tables.faculty_needing_assignment"
                            :key="f.id"
                            class="py-2 text-[var(--text-primary)] rounded-md px-2 -mx-2 transition-colors duration-150 hover:bg-[var(--page-bg)]"
                        >
                            {{ f.first_name }} {{ f.last_name }}
                        </li>
                    </ul>
                    <p v-else class="text-sm text-[var(--text-secondary)]">Every active faculty member has an assignment.</p>
                </div>

                <div class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="border-color: color-mix(in srgb, #f97316 35%, var(--card-border))">
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #f97316"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #f97316 45%, transparent), 0 0 22px 0 color-mix(in srgb, #f97316 35%, transparent)"></div>
                    <h2 class="text-base font-semibold mb-3 text-[var(--text-primary)]">Faculty Overload</h2>
                    <ul v-if="tables.faculty_overload.length" class="divide-y divide-[var(--card-border)] text-sm max-h-72 overflow-y-auto overflow-x-hidden pr-1 thin-scrollbar">
                        <li
                            v-for="f in tables.faculty_overload"
                            :key="f.id"
                            class="py-2 flex justify-between gap-3 rounded-md px-2 -mx-2 transition-colors duration-150 hover:bg-[var(--page-bg)]"
                        >
                            <span class="text-[var(--text-primary)] truncate">{{ f.first_name }} {{ f.last_name }}</span>
                            <span class="text-amber-500 font-medium shrink-0">{{ f.assigned_units }}/{{ f.max_units }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-[var(--text-secondary)]">No faculty are currently overloaded.</p>
                </div>

                <div class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="border-color: color-mix(in srgb, #ec4899 35%, var(--card-border))">
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #ec4899"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #ec4899 45%, transparent), 0 0 22px 0 color-mix(in srgb, #ec4899 35%, transparent)"></div>
                    <h2 class="text-base font-semibold mb-3 text-[var(--text-primary)]">Subjects Without Faculty</h2>
                    <ul v-if="tables.subjects_without_faculty.length" class="divide-y divide-[var(--card-border)] text-sm max-h-72 overflow-y-auto overflow-x-hidden pr-1 thin-scrollbar">
                        <li
                            v-for="s in tables.subjects_without_faculty"
                            :key="s.id"
                            class="py-2 text-[var(--text-primary)] rounded-md px-2 -mx-2 transition-colors duration-150 hover:bg-[var(--page-bg)]"
                        >
                            {{ s.subject?.subject_code }} — {{ s.section?.section_code }}
                        </li>
                    </ul>
                    <p v-else class="text-sm text-[var(--text-secondary)]">Every offering has a faculty member assigned.</p>
                </div>

                <div class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden transition-all duration-300 hover:shadow-lg" style="border-color: color-mix(in srgb, #8b5cf6 35%, var(--card-border))">
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #8b5cf6"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #8b5cf6 45%, transparent), 0 0 22px 0 color-mix(in srgb, #8b5cf6 35%, transparent)"></div>
                    <h2 class="text-base font-semibold text-[var(--text-primary)] mb-3">Quick Actions</h2>
                    <div class="grid grid-cols-2 gap-3">
                        <Link
                            v-for="action in quickActions"
                            :key="action.label"
                            :href="action.href"
                            class="flex flex-col items-start gap-2 rounded-xl border border-[var(--card-border)] p-3 text-left transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
                        >
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg" :style="{ background: action.bg }">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" :stroke="action.color">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="action.icon" />
                                </svg>
                            </div>
                            <span class="text-xs font-semibold leading-tight text-[var(--text-primary)]">{{ action.label }}</span>
                        </Link>
                    </div>
                </div>

            </div>

        </DashboardBackgroundFX>
    </DashboardLayout>
</template>

<style scoped>
.thin-scrollbar {
    scrollbar-width: thin;
    scrollbar-color: var(--card-border) transparent;
}
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
    background-color: var(--text-secondary);
}
</style>