<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import DashboardBackgroundFX from '@/Components/DashboardBackgroundFX.vue'
import StatCard from '@/Components/Dashboard/StatCard.vue'
import { collegeClasses, collegeLabel } from '@/Utils/collegeColors'

const props = defineProps({
    stats: { type: Object, required: true },
})

// assistantDeanStats() already scopes faculty_assignments/pending_assignments/
// conflicts/recent_activity to $user->department_id — an Assistant Dean in
// CCS only ever sees CCS numbers. `college` here just drives the color tint.
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

const conflictTotal = computed(() => {
    const c = props.stats.conflicts ?? {}
    return (c.faculty ?? 0) + (c.room ?? 0) + (c.section ?? 0) + (c.time ?? 0)
})

// Same set Dean gets — Assistant Dean shares that operational reach
// within its own department.
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

// Alerts card — built from conflicts + pending_assignments, same pattern
// used on the other redesigned dashboards.
const alerts = computed(() => {
    const pending = props.stats.pending_assignments ?? 0

    const items = []
    if (conflictTotal.value > 0) {
        items.push({
            icon: 'exclamation', color: '#ef4444', bg: '#fee2e2',
            title: `${conflictTotal.value} Scheduling Conflict${conflictTotal.value === 1 ? '' : 's'}`,
            sub: 'Requires immediate attention',
        })
    }
    if (pending > 0) {
        items.push({
            icon: 'clock', color: '#f97316', bg: '#ffedd5',
            title: `${pending} Pending Assignment${pending === 1 ? '' : 's'}`,
            sub: 'Subjects still need a faculty member',
        })
    }
    if (!items.length) {
        items.push({
            icon: 'check', color: '#22c55e', bg: '#dcfce7',
            title: 'All clear', sub: 'No conflicts or pending assignments right now',
        })
    }
    return items
})
</script>

<template>
    <DashboardLayout>
        <DashboardBackgroundFX>

            <!-- Welcome header -->
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-extrabold text-[var(--text-primary)]">
                            Welcome back, Assistant Dean! 👋
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

            <!-- Stat cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <StatCard label="Faculty Assignments" :value="stats.faculty_assignments" accent="text-emerald-500" :college="college" glow-color="#22c55e">This Term</StatCard>
                <StatCard label="Pending Assignments" :value="stats.pending_assignments" accent="text-amber-500" :college="college" glow-color="#f97316">This Term</StatCard>
            </div>

            <!-- Conflicts / Alerts -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">

                <div class="xl:col-span-2 group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="border-color: color-mix(in srgb, #6366f1 35%, var(--card-border))">
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #6366f1"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #6366f1 45%, transparent), 0 0 22px 0 color-mix(in srgb, #6366f1 35%, transparent)"></div>
                    <h2 class="text-base font-semibold mb-3 text-[var(--text-primary)]">Conflicts</h2>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div class="text-center rounded-lg py-2 transition-colors duration-150 hover:bg-[var(--page-bg)]">
                            <p class="text-2xl font-bold text-[var(--text-primary)]">{{ stats.conflicts.faculty }}</p>
                            <p class="text-[var(--text-secondary)]">Faculty</p>
                        </div>
                        <div class="text-center rounded-lg py-2 transition-colors duration-150 hover:bg-[var(--page-bg)]">
                            <p class="text-2xl font-bold text-[var(--text-primary)]">{{ stats.conflicts.room }}</p>
                            <p class="text-[var(--text-secondary)]">Room</p>
                        </div>
                        <div class="text-center rounded-lg py-2 transition-colors duration-150 hover:bg-[var(--page-bg)]">
                            <p class="text-2xl font-bold text-[var(--text-primary)]">{{ stats.conflicts.section }}</p>
                            <p class="text-[var(--text-secondary)]">Section</p>
                        </div>
                        <div class="text-center rounded-lg py-2 transition-colors duration-150 hover:bg-[var(--page-bg)]">
                            <p class="text-2xl font-bold text-[var(--text-primary)]">{{ stats.conflicts.time }}</p>
                            <p class="text-[var(--text-secondary)]">Time</p>
                        </div>
                    </div>
                </div>

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

            <!-- Faculty Without Assignment / Recent Updates / Quick Actions -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">

                <div class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="border-color: color-mix(in srgb, #ec4899 35%, var(--card-border))">
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #ec4899"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #ec4899 45%, transparent), 0 0 22px 0 color-mix(in srgb, #ec4899 35%, transparent)"></div>
                    <h2 class="text-base font-semibold mb-3 text-[var(--text-primary)]">Faculty Without Assignment</h2>
                    <ul v-if="stats.faculty_needing_assignment?.length" class="divide-y divide-[var(--card-border)] text-sm max-h-80 overflow-y-auto overflow-x-hidden pr-1 thin-scrollbar">
                        <li
                            v-for="f in stats.faculty_needing_assignment"
                            :key="f.id"
                            class="py-2 text-[var(--text-primary)] rounded-md px-2 -mx-2 transition-colors duration-150 hover:bg-[var(--page-bg)]"
                        >
                            {{ f.first_name }} {{ f.last_name }}
                        </li>
                    </ul>
                    <p v-else class="text-sm text-[var(--text-secondary)]">Every active faculty member has an assignment.</p>
                </div>

                <div class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="border-color: color-mix(in srgb, #06b6d4 35%, var(--card-border))">
                    <div class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300" style="background: #06b6d4"></div>
                    <div class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block" style="box-shadow: inset 0 0 0 1px color-mix(in srgb, #06b6d4 45%, transparent), 0 0 22px 0 color-mix(in srgb, #06b6d4 35%, transparent)"></div>
                    <h2 class="text-base font-semibold mb-3 text-[var(--text-primary)]">Recent Updates</h2>
                    <ul v-if="stats.recent_activity.length" class="divide-y divide-[var(--card-border)] text-sm max-h-80 overflow-y-auto overflow-x-hidden pr-1 thin-scrollbar">
                        <li
                            v-for="(item, i) in stats.recent_activity"
                            :key="i"
                            class="py-2 text-[var(--text-secondary)] rounded-md px-2 -mx-2 transition-colors duration-150 hover:bg-[var(--page-bg)] hover:text-[var(--text-primary)] truncate"
                        >
                            {{ item.description }} — {{ item.created_at }}
                        </li>
                    </ul>
                    <p v-else class="text-sm text-[var(--text-secondary)]">No recent updates.</p>
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