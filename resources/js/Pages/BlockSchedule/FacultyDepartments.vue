<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useAppShell } from '@/Composables/useAppShell'

const props = defineProps({
    departments: Array,
    academicTerm: Object,
})

const { darkMode } = useAppShell()

// Layout is deliberately fixed at 2-then-3 regardless of how many
// department folders exist, rather than left to the grid to wrap
// naturally — see topRow/bottomRow below.
const topRow = computed(() => props.departments.slice(0, 2))
const bottomRow = computed(() => props.departments.slice(2))

// Cycle the same four accent colors Section Schedule's Index.vue
// uses, so the two folders feel like one module — just tinted
// purple/fuchsia here to match the Faculty Schedule card on Landing.
const accents = [
    { badge: 'bg-purple-100 text-purple-700 dark:bg-purple-500/10 dark:text-purple-300', tab: 'bg-purple-500' },
    { badge: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-500/10 dark:text-fuchsia-300', tab: 'bg-fuchsia-500' },
    { badge: 'bg-violet-100 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300', tab: 'bg-violet-500' },
    { badge: 'bg-pink-100 text-pink-700 dark:bg-pink-500/10 dark:text-pink-300', tab: 'bg-pink-500' },
]

// Same ambient background treatment as the Block Schedule landing
// page — grid-line texture plus rising bubbles (light mode) /
// drifting fireflies (dark mode) — computed once at setup time so
// positions/timings stay stable for the life of the component.
function randomBetween(min, max) {
    return Math.random() * (max - min) + min
}

const BUBBLE_COLORS = [
    { core: 'rgba(99, 102, 241, 0.24)', mid: 'rgba(59, 130, 246, 0.12)', border: 'rgba(99, 102, 241, 0.20)' },   // indigo/blue
    { core: 'rgba(168, 85, 247, 0.24)', mid: 'rgba(217, 70, 239, 0.12)', border: 'rgba(168, 85, 247, 0.20)' },  // purple/fuchsia
    { core: 'rgba(56, 189, 248, 0.24)', mid: 'rgba(14, 165, 233, 0.12)', border: 'rgba(56, 189, 248, 0.20)' },  // sky
    { core: 'rgba(52, 211, 153, 0.24)', mid: 'rgba(16, 185, 129, 0.12)', border: 'rgba(52, 211, 153, 0.20)' },  // emerald
    { core: 'rgba(251, 191, 36, 0.22)', mid: 'rgba(245, 158, 11, 0.10)', border: 'rgba(251, 191, 36, 0.20)' },  // amber
    { core: 'rgba(244, 114, 182, 0.24)', mid: 'rgba(236, 72, 153, 0.12)', border: 'rgba(244, 114, 182, 0.20)' }, // pink
]

const bubbles = Array.from({ length: 24 }, (_, i) => ({
    id: i,
    left: randomBetween(0, 100),
    size: randomBetween(8, 38),
    duration: randomBetween(9, 24),
    delay: randomBetween(-20, 0),
    drift: randomBetween(-50, 50),
    color: BUBBLE_COLORS[i % BUBBLE_COLORS.length],
}))

const fireflies = Array.from({ length: 16 }, (_, i) => ({
    id: i,
    left: randomBetween(0, 100),
    top: randomBetween(0, 100),
    size: randomBetween(2, 4),
    duration: randomBetween(4, 9),
    delay: randomBetween(0, 8),
    driftX: randomBetween(-60, 60),
    driftY: randomBetween(-60, 60),
}))
</script>

<template>
    <AppLayout>
        <div class="relative min-h-[calc(100vh-6rem)] overflow-hidden p-8">
            <!-- ===================== GRID LINE TEXTURE ===================== -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <!-- Light mode grid -->
                <div
                    class="absolute inset-0 opacity-[0.07] dark:hidden"
                    style="
                        background-image:
                            linear-gradient(#1e3a5f 1px, transparent 1px),
                            linear-gradient(90deg, #1e3a5f 1px, transparent 1px);
                        background-size: 56px 56px;
                    "
                ></div>
                <!-- Dark mode grid -->
                <div
                    class="absolute inset-0 hidden opacity-[0.05] dark:block"
                    style="
                        background-image:
                            linear-gradient(#f3efe6 1px, transparent 1px),
                            linear-gradient(90deg, #f3efe6 1px, transparent 1px);
                        background-size: 56px 56px;
                    "
                ></div>
            </div>

            <!-- Light mode: rising bubbles -->
            <div v-if="!darkMode" class="pointer-events-none absolute inset-0 overflow-hidden">
                <span
                    v-for="b in bubbles"
                    :key="'bubble-' + b.id"
                    class="faculty-bubble"
                    :style="{
                        left: b.left + '%',
                        width: b.size + 'px',
                        height: b.size + 'px',
                        animationDuration: b.duration + 's',
                        animationDelay: b.delay + 's',
                        '--drift': b.drift + 'px',
                        '--bubble-core': b.color.core,
                        '--bubble-mid': b.color.mid,
                        '--bubble-border': b.color.border,
                    }"
                />
            </div>

            <!-- Dark mode: drifting fireflies -->
            <div v-else class="pointer-events-none absolute inset-0 overflow-hidden">
                <span
                    v-for="f in fireflies"
                    :key="'firefly-' + f.id"
                    class="faculty-firefly"
                    :style="{
                        left: f.left + '%',
                        top: f.top + '%',
                        width: f.size + 'px',
                        height: f.size + 'px',
                        animationDuration: f.duration + 's',
                        animationDelay: f.delay + 's',
                        '--dx': f.driftX + 'px',
                        '--dy': f.driftY + 'px',
                    }"
                />
            </div>

            <div class="relative z-10">
                <Link :href="route('block-schedule.landing')" class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
                    &lsaquo; Back to Block Schedule
                </Link>

                <div class="mb-6 flex items-center gap-3">
                    <div class="rounded-xl bg-slate-900 p-3 text-white dark:bg-white/10">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl font-extrabold tracking-tight" style="color: var(--text-primary)">FACULTY SCHEDULE</h1>
                        <p class="text-sm font-medium" style="color: var(--text-muted)">
                            Select a department folder to view faculty schedules
                            <span v-if="academicTerm"> — {{ academicTerm.display_name }}</span>
                        </p>
                    </div>
                </div>

                <div class="mx-auto max-w-3xl">
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <Link
                            v-for="(department, i) in topRow"
                            :key="department.id"
                            :href="department.is_general ? route('block-schedule.faculty.general') : route('block-schedule.faculty.list', department.id)"
                            class="group relative rounded-2xl border p-6 text-center shadow-sm transition hover:-translate-y-1 hover:shadow-lg"
                            style="background: var(--card-bg); border-color: var(--card-border)"
                            :class="department.is_general ? 'ring-1 ring-amber-300/60' : ''"
                        >
                            <span
                                class="absolute left-0 top-0 rounded-tl-2xl rounded-br-lg px-3 py-1 text-xs font-bold text-white"
                                :class="department.is_general ? 'bg-amber-500' : accents[i % accents.length].tab"
                            >
                                {{ department.code }}
                            </span>

                            <div class="mx-auto mb-4 mt-4 flex h-16 w-16 items-center justify-center rounded-xl bg-slate-100 dark:bg-white/5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-slate-500 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4" />
                                </svg>
                            </div>

                            <h2 class="text-lg font-extrabold" style="color: var(--text-primary)">{{ department.code }}</h2>
                            <p class="mb-3 text-sm font-bold" style="color: var(--text-primary)">{{ department.name }}</p>

                            <span
                                class="inline-block rounded-full px-3 py-1 text-xs font-bold"
                                :class="department.is_general ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : accents[i % accents.length].badge"
                            >
                                {{ department.faculty_count }} FACULTY
                            </span>

                            <p class="mt-3 text-xs font-bold uppercase tracking-wide text-slate-600 group-hover:text-slate-900 dark:text-slate-400 dark:group-hover:text-slate-200">
                                Open Folder &rsaquo;
                            </p>
                        </Link>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-3">
                        <Link
                            v-for="(department, i) in bottomRow"
                            :key="department.id"
                            :href="department.is_general ? route('block-schedule.faculty.general') : route('block-schedule.faculty.list', department.id)"
                            class="group relative rounded-2xl border p-6 text-center shadow-sm transition hover:-translate-y-1 hover:shadow-lg"
                            style="background: var(--card-bg); border-color: var(--card-border)"
                            :class="department.is_general ? 'ring-1 ring-amber-300/60' : ''"
                        >
                            <span
                                class="absolute left-0 top-0 rounded-tl-2xl rounded-br-lg px-3 py-1 text-xs font-bold text-white"
                                :class="department.is_general ? 'bg-amber-500' : accents[(i + topRow.length) % accents.length].tab"
                            >
                                {{ department.code }}
                            </span>

                            <div class="mx-auto mb-4 mt-4 flex h-16 w-16 items-center justify-center rounded-xl bg-slate-100 dark:bg-white/5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-slate-500 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4" />
                                </svg>
                            </div>

                            <h2 class="text-lg font-extrabold" style="color: var(--text-primary)">{{ department.code }}</h2>
                            <p class="mb-3 text-sm font-bold" style="color: var(--text-primary)">{{ department.name }}</p>

                            <span
                                class="inline-block rounded-full px-3 py-1 text-xs font-bold"
                                :class="department.is_general ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : accents[(i + topRow.length) % accents.length].badge"
                            >
                                {{ department.faculty_count }} FACULTY
                            </span>

                            <p class="mt-3 text-xs font-bold uppercase tracking-wide text-slate-600 group-hover:text-slate-900 dark:text-slate-400 dark:group-hover:text-slate-200">
                                Open Folder &rsaquo;
                            </p>
                        </Link>
                    </div>
                </div>

                <p v-if="!departments.length" class="mt-10 text-center text-sm" style="color: var(--text-muted)">
                    No departments to show.
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Light mode — soft rising bubbles */
.faculty-bubble {
    position: absolute;
    bottom: -60px;
    border-radius: 9999px;
    background: radial-gradient(circle at 30% 30%, var(--bubble-core), var(--bubble-mid) 60%, transparent 75%);
    border: 1px solid var(--bubble-border);
    animation-name: faculty-bubble-rise;
    animation-timing-function: ease-in;
    animation-iteration-count: infinite;
}

@keyframes faculty-bubble-rise {
    0% {
        transform: translate(0, 0) scale(0.8);
        opacity: 0;
    }
    10% {
        opacity: 0.9;
    }
    90% {
        opacity: 0.5;
    }
    100% {
        transform: translate(var(--drift), -115vh) scale(1.05);
        opacity: 0;
    }
}

/* Dark mode — flickering fireflies */
.faculty-firefly {
    position: absolute;
    border-radius: 9999px;
    background: radial-gradient(circle, rgba(253, 224, 71, 0.95) 0%, rgba(163, 230, 53, 0.5) 55%, transparent 75%);
    box-shadow: 0 0 6px 2px rgba(253, 224, 71, 0.55), 0 0 14px 4px rgba(163, 230, 53, 0.25);
    animation-name: faculty-firefly-drift, faculty-firefly-flicker;
    animation-timing-function: ease-in-out, ease-in-out;
    animation-iteration-count: infinite, infinite;
    animation-direction: alternate, alternate;
}

@keyframes faculty-firefly-drift {
    0% {
        transform: translate(0, 0);
    }
    100% {
        transform: translate(var(--dx), var(--dy));
    }
}

@keyframes faculty-firefly-flicker {
    0%, 100% {
        opacity: 0.15;
    }
    35% {
        opacity: 0.95;
    }
    60% {
        opacity: 0.35;
    }
    80% {
        opacity: 0.85;
    }
}

@media (prefers-reduced-motion: reduce) {
    .faculty-bubble,
    .faculty-firefly {
        animation: none;
        display: none;
    }
}
</style>