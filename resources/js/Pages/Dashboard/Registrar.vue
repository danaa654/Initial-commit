<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import DashboardBackgroundFX from '@/Components/DashboardBackgroundFX.vue'
import StatCard from '@/Components/Dashboard/StatCard.vue'
import ChartCard from '@/Components/Dashboard/ChartCard.vue'

const props = defineProps({
    stats: { type: Object, required: true },
    conflicts: { type: Object, required: true },
    charts: { type: Object, required: true },
    widgets: { type: Object, required: true },
})

const userName = computed(() => 'Registrar')

// Faculty Members card — click to cycle Total -> per-department ->
// General Education, same pattern as Admin's dashboard and the Total
// Faculty card on Teaching Assignments' Index.vue. -1 means "show the
// grand total"; clicking walks forward through department buckets and
// wraps back.
const facultyDeptBuckets = computed(() => {
    const roster = props.widgets.faculty_roster ?? []
    const departments = props.widgets.departments ?? []

    return [
        ...departments.map((dept) => ({
            key: `dept-${dept.id}`,
            label: dept.name,
            count: roster.filter((f) => f.department_id === dept.id).length,
        })),
        {
            key: 'gened',
            label: 'General Education',
            count: roster.filter((f) => !f.department_id).length,
        },
    ]
})

const facultyViewIndex = ref(-1)

function cycleFacultyView() {
    const lastIndex = facultyDeptBuckets.value.length - 1
    facultyViewIndex.value = facultyViewIndex.value >= lastIndex ? -1 : facultyViewIndex.value + 1
}

const totalFacultyCount = computed(() => (props.widgets.faculty_roster ?? []).length)

const facultyCardView = computed(() => {
    if (facultyViewIndex.value === -1) {
        return { label: 'Faculty Members', count: totalFacultyCount.value, caption: 'Total Faculty' }
    }

    const bucket = facultyDeptBuckets.value[facultyViewIndex.value]
    return { label: bucket.label, count: bucket.count, caption: `Faculty under ${bucket.label}` }
})

const today = computed(() => {
    const d = new Date()
    return {
        date: d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }),
        weekday: d.toLocaleDateString('en-US', { weekday: 'long' }),
        time: d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }),
    }
})

// Faculty Load Distribution — bucketed client-side from the raw
// per-faculty unit data the backend already sends
// (charts.faculty_load: { labels: [facultyName...], data: [units...] }).
const facultyLoadChart = computed(() => {
    const units = props.charts.faculty_load?.data ?? []
    const buckets = { full: 0, near: 0, under: 0, none: 0 }

    units.forEach((u) => {
        if (u >= 18) buckets.full++
        else if (u >= 15) buckets.near++
        else if (u > 0) buckets.under++
        else buckets.none++
    })

    return {
        labels: ['Full Load (18+ units)', 'Near Full (15-17 units)', 'Underloaded (<15 units)', 'No Load'],
        datasets: [{
            data: [buckets.full, buckets.near, buckets.under, buckets.none],
            backgroundColor: ['#22c55e', '#f97316', '#ef4444', '#3b82f6'],
        }],
    }
})

const scheduleCompletionPercent = computed(() => {
    const [scheduled = 0, remaining = 0] = props.charts.schedule_completion?.data ?? []
    const total = scheduled + remaining
    return total ? Math.round((scheduled / total) * 100) : (props.stats.completion_percent ?? 0)
})

// Alerts & Notifications — built from the conflicts summary + unscheduled
// subject count the controller already computes via registrarConflicts()
// and widgets.unscheduled_subjects.
const alerts = computed(() => {
    const c = props.conflicts ?? {}
    const conflictTotal = (c.faculty ?? 0) + (c.room ?? 0) + (c.section ?? 0) + (c.time ?? 0)
    const unscheduled = props.widgets.unscheduled_subjects?.length ?? 0

    const items = []
    if (conflictTotal > 0) {
        items.push({
            icon: 'exclamation', color: '#ef4444', bg: '#fee2e2',
            title: `${conflictTotal} Scheduling Conflict${conflictTotal === 1 ? '' : 's'}`,
            sub: 'Requires immediate attention',
        })
    }
    if (unscheduled > 0) {
        items.push({
            icon: 'clock', color: '#f97316', bg: '#ffedd5',
            title: `${unscheduled} Subject${unscheduled === 1 ? '' : 's'} Unscheduled`,
            sub: 'Needs room or time assignment',
        })
    }
    if (!items.length) {
        items.push({
            icon: 'check', color: '#22c55e', bg: '#dcfce7',
            title: 'All clear', sub: 'No conflicts or unscheduled subjects right now',
        })
    }
    return items
})

// Registrar has the same operational reach as Admin over the
// scheduling modules — same Quick Actions set, minus user-account
// management (which stays Admin-only).
const quickActions = [
    { label: 'Subject Offerings', href: route('subject-offerings.index'), color: '#3b82f6', bg: '#dbeafe',
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' },
    { label: 'Faculty Loading', href: route('teaching-assignments.index'), color: '#22c55e', bg: '#dcfce7',
        icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4' },
    { label: 'Master Grid', href: route('master-grid.index'), color: '#8b5cf6', bg: '#f3e8ff',
        icon: 'M3 21h18M5 21V7l8-4v18M13 21V11l6 3v7M9 9h.01M9 12h.01M9 15h.01' },
    { label: 'Room Management', href: route('rooms.index'), color: '#f97316', bg: '#ffedd5',
        icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
    { label: 'Curriculum', href: route('curriculums.index'), color: '#ec4899', bg: '#fce7f3',
        icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253' },
    { label: 'Settings', href: route('settings.scheduling-workspace'), color: '#64748b', bg: '#f1f5f9',
        icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z' },
]
</script>

<template>
    <DashboardLayout>
        <DashboardBackgroundFX>

            <!-- Welcome header -->
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-[var(--text-primary)]">
                        Welcome back, {{ userName }}! 👋
                    </h1>
                    <p class="mt-1 text-sm text-[var(--text-secondary)]">
                        Here's what's happening in your system today.
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

            <!-- Stat cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
                <StatCard label="Subject Offerings" :value="stats.subject_offerings" glow-color="#3b82f6">This Term</StatCard>
                <StatCard label="Scheduled Subjects" :value="stats.scheduled_subjects" accent="text-emerald-500" glow-color="#22c55e">This Term</StatCard>
                <StatCard label="Remaining Subjects" :value="stats.remaining_subjects" accent="text-amber-500" glow-color="#f97316">This Term</StatCard>
                <StatCard label="Faculty Assigned" :value="stats.faculty_assigned" glow-color="#8b5cf6">This Term</StatCard>
                <StatCard label="Rooms Assigned" :value="stats.rooms_assigned" glow-color="#14b8a6">This Term</StatCard>

                <button
                    type="button"
                    class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden text-left transition-all duration-300 hover:-translate-y-1 hover:shadow-lg"
                    style="border-color: color-mix(in srgb, #ec4899 35%, var(--card-border))"
                    @click="cycleFacultyView"
                >
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #ec4899"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #ec4899 45%, transparent), 0 0 22px 0 color-mix(in srgb, #ec4899 35%, transparent)"></div>
                    <div class="flex items-center justify-between">
                        <h2 class="text-[var(--text-secondary)] text-xs truncate">{{ facultyCardView.label }}</h2>
                        <span class="w-1.5 h-1.5 rounded-full shrink-0 transition-transform duration-300 group-hover:scale-125" style="background: #ec4899"></span>
                    </div>
                    <p class="text-xl font-bold mt-1 leading-tight text-[var(--text-primary)] transition-transform duration-300 group-hover:scale-[1.03] origin-left">
                        {{ facultyCardView.count }}
                    </p>
                    <p class="text-xs text-[var(--text-secondary)] mt-1 truncate">{{ facultyCardView.caption }}</p>
                    <!-- Position dots: which bucket is currently showing -->
                    <div class="mt-1.5 flex items-center gap-1">
                        <span
                            v-for="n in facultyDeptBuckets.length + 1"
                            :key="n"
                            class="h-1 rounded-full transition-all duration-300"
                            :class="(n - 2) === facultyViewIndex ? 'w-3 bg-pink-500' : 'w-1 bg-[var(--card-border)]'"
                        ></span>
                    </div>
                </button>
            </div>

            <!-- Faculty Load / Room Utilization / Alerts -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
                <ChartCard
                    title="Faculty Load Distribution"
                    type="doughnut"
                    badge="This Term"
                    center-mode="total"
                    center-caption="Total"
                    glow-color="#22c55e"
                    :labels="facultyLoadChart.labels"
                    :datasets="facultyLoadChart.datasets"
                />

                <ChartCard
                    title="Room Utilization (hrs / week)"
                    type="bar"
                    badge="This Term"
                    glow-color="#6366f1"
                    :labels="charts.room_utilization.labels"
                    :datasets="[{ label: 'Hours Used', data: charts.room_utilization.data }]"
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

            <!-- Schedule Completion / Subjects by Department / Recent Activity / Quick Actions -->
            <div class="grid grid-cols-1 xl:grid-cols-4 gap-4">

                <ChartCard
                    title="Schedule Completion"
                    type="doughnut"
                    center-mode="percent"
                    :center-caption="`${scheduleCompletionPercent}% Completed`"
                    glow-color="#6366f1"
                    :labels="charts.schedule_completion.labels"
                    :datasets="[{ data: charts.schedule_completion.data, backgroundColor: ['#6366f1', '#e2e8f0'] }]"
                    :height="180"
                />

                <ChartCard
                    title="Subjects by Department"
                    type="doughnut"
                    badge="This Term"
                    center-mode="total"
                    center-caption="Total"
                    glow-color="#f97316"
                    :labels="charts.subjects_by_department.labels"
                    :datasets="[{ data: charts.subjects_by_department.data }]"
                    :height="180"
                />

                <div class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="border-color: color-mix(in srgb, #06b6d4 35%, var(--card-border))">
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #06b6d4"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #06b6d4 45%, transparent), 0 0 22px 0 color-mix(in srgb, #06b6d4 35%, transparent)"></div>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-semibold text-[var(--text-primary)]">Recent Activity</h2>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[var(--text-secondary)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </div>
                    <ul v-if="widgets.recent_activity.length" class="flex flex-col gap-3 text-xs">
                        <li v-for="(item, i) in widgets.recent_activity.slice(0, 5)" :key="i" class="flex items-start gap-2">
                            <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500"></span>
                            <div>
                                <p class="text-[var(--text-primary)]">{{ item.description }}</p>
                                <p class="text-[var(--text-secondary)]">{{ item.created_at }}</p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-[var(--text-secondary)]">No recent activity recorded.</p>
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