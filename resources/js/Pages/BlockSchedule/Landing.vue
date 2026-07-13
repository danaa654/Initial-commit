<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useAppShell } from '@/Composables/useAppShell'

defineProps({
    academicTerm: Object,
})

const { darkMode } = useAppShell()

const folders = [
    {
        title: 'Section Schedule',
        description: 'Browse each block\'s weekly schedule by department.',
        href: () => route('block-schedule.index'),
        accent: {
            badge: 'from-blue-600 to-blue-500',
            iconWrap: 'bg-blue-50 dark:bg-blue-500/10',
            iconColor: 'text-blue-600 dark:text-blue-400',
            barFrom: 'from-blue-600',
            barTo: 'to-blue-400',
            cta: 'text-blue-700 group-hover:text-blue-800 dark:text-blue-400 dark:group-hover:text-blue-300',
            glow: 'from-blue-100/60 dark:from-blue-500/10',
        },
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    },
    {
        title: 'Faculty Schedule',
        description: 'Browse each faculty member\'s weekly load.',
        href: () => route('block-schedule.faculty'),
        accent: {
            badge: 'from-purple-600 to-fuchsia-500',
            iconWrap: 'bg-purple-50 dark:bg-purple-500/10',
            iconColor: 'text-purple-600 dark:text-purple-400',
            barFrom: 'from-purple-600',
            barTo: 'to-fuchsia-400',
            cta: 'text-purple-700 group-hover:text-purple-800 dark:text-purple-400 dark:group-hover:text-purple-300',
            glow: 'from-purple-100/60 dark:from-purple-500/10',
        },
        icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4',
    },
]

// Computed once at setup time (not per-render) so positions/timings
// stay stable for the life of the component — only the light/dark
// v-if toggles which set is mounted.
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

const bubbles = Array.from({ length: 34 }, (_, i) => ({
    id: i,
    left: randomBetween(0, 100),
    size: randomBetween(8, 38),
    duration: randomBetween(9, 24),
    delay: randomBetween(-20, 0),
    drift: randomBetween(-50, 50),
    color: BUBBLE_COLORS[i % BUBBLE_COLORS.length],
}))

const fireflies = Array.from({ length: 22 }, (_, i) => ({
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
        <div class="relative flex min-h-[calc(100vh-12rem)] flex-col items-center justify-center overflow-hidden px-8 py-10">
            <!-- ===================== GRID LINE TEXTURE ===================== -->
            <!-- Same faint timetable-grid texture as the Welcome page,
                 so this landing page reads as part of the same visual
                 system rather than a plain flat background. -->
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

            <!-- Soft ambient background blobs -->
            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-gradient-to-br from-blue-100/50 to-purple-100/40 blur-3xl dark:from-blue-500/10 dark:to-purple-500/10"></div>
            <div class="pointer-events-none absolute bottom-0 left-0 h-56 w-56 rounded-full bg-gradient-to-tr from-purple-50/60 to-transparent blur-2xl dark:from-purple-500/10"></div>

            <!-- Light mode: rising bubbles -->
            <div v-if="!darkMode" class="pointer-events-none absolute inset-0 overflow-hidden">
                <span
                    v-for="b in bubbles"
                    :key="'bubble-' + b.id"
                    class="landing-bubble"
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
                    class="landing-firefly"
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

            <div class="relative z-10 flex w-full max-w-3xl flex-col items-center text-center">
                <!-- Header -->
                <div class="mb-8 flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                    <div class="rounded-2xl bg-slate-900 p-3.5 text-white shadow-lg shadow-slate-900/10 dark:bg-white/10 dark:shadow-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl font-extrabold tracking-tight" style="color: var(--text-primary)">Block Schedule</h1>
                        <p class="mt-0.5 text-sm font-medium" style="color: var(--text-muted)">
                            Choose Section Schedule or Faculty Schedule
                            <template v-if="academicTerm">
                                — {{ academicTerm.display_name }}
                                <span class="mx-1 text-slate-300 dark:text-slate-600">•</span>
                                <span class="font-semibold text-blue-600 dark:text-blue-400">{{ academicTerm.semester_label ?? academicTerm.semester }}</span>
                            </template>
                        </p>
                    </div>
                </div>

                <!-- Folder cards -->
                <div class="grid w-full grid-cols-1 gap-6 sm:grid-cols-2">
                    <Link
                        v-for="folder in folders"
                        :key="folder.title"
                        :href="folder.href()"
                        class="group relative overflow-hidden rounded-2xl border p-7 text-center shadow-sm transition-all duration-300 hover:-translate-y-1.5 hover:shadow-xl"
                        style="background: var(--card-bg); border-color: var(--card-border)"
                    >
                        <!-- Decorative glow on hover -->
                        <div
                            class="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-gradient-to-br opacity-0 blur-2xl transition-opacity duration-300 group-hover:opacity-100"
                            :class="folder.accent.glow"
                        ></div>

                        <!-- Icon -->
                        <div
                            class="relative mx-auto mb-5 mt-5 flex h-16 w-16 items-center justify-center rounded-2xl transition-transform duration-300 group-hover:scale-105"
                            :class="folder.accent.iconWrap"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" :class="folder.accent.iconColor" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="folder.icon" />
                            </svg>
                        </div>

                        <h2 class="relative text-lg font-extrabold" style="color: var(--text-primary)">{{ folder.title }}</h2>
                        <p class="relative mx-auto mb-4 mt-1 max-w-[220px] text-sm leading-relaxed" style="color: var(--text-muted)">
                            {{ folder.description }}
                        </p>

                        <div class="relative mx-auto mb-4 h-px w-10" style="background: var(--card-border)"></div>

                        <p
                            class="relative flex items-center justify-center gap-1 text-xs font-bold uppercase tracking-wide transition-colors"
                            :class="folder.accent.cta"
                        >
                            Open Folder
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                            </svg>
                        </p>

                        <!-- Bottom accent bar -->
                        <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r opacity-70 transition-opacity duration-300 group-hover:opacity-100" :class="[folder.accent.barFrom, folder.accent.barTo]"></div>
                    </Link>
                </div>

                <!-- Info footer -->
                <div
                    class="relative mt-8 flex w-full items-center justify-between gap-4 rounded-2xl border px-6 py-4 backdrop-blur-sm"
                    style="background: var(--card-bg); border-color: var(--card-border)"
                >
                    <div class="flex items-start gap-3 text-left">
                        <div class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm font-semibold" style="color: var(--text-primary)">Select an option above to view and manage schedules.</p>
                            <p class="text-xs" style="color: var(--text-muted)">Schedules are organized by department and faculty for easy access.</p>
                        </div>
                    </div>
                    <div class="hidden shrink-0 items-center justify-center rounded-xl bg-slate-50 p-2.5 text-slate-300 dark:bg-white/5 dark:text-slate-600 sm:flex">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Light mode — soft rising bubbles */
.landing-bubble {
    position: absolute;
    bottom: -60px;
    border-radius: 9999px;
    background: radial-gradient(circle at 30% 30%, var(--bubble-core), var(--bubble-mid) 60%, transparent 75%);
    border: 1px solid var(--bubble-border);
    animation-name: bubble-rise;
    animation-timing-function: ease-in;
    animation-iteration-count: infinite;
}

@keyframes bubble-rise {
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
.landing-firefly {
    position: absolute;
    border-radius: 9999px;
    background: radial-gradient(circle, rgba(253, 224, 71, 0.95) 0%, rgba(163, 230, 53, 0.5) 55%, transparent 75%);
    box-shadow: 0 0 6px 2px rgba(253, 224, 71, 0.55), 0 0 14px 4px rgba(163, 230, 53, 0.25);
    animation-name: firefly-drift, firefly-flicker;
    animation-timing-function: ease-in-out, ease-in-out;
    animation-iteration-count: infinite, infinite;
    animation-direction: alternate, alternate;
}

@keyframes firefly-drift {
    0% {
        transform: translate(0, 0);
    }
    100% {
        transform: translate(var(--dx), var(--dy));
    }
}

@keyframes firefly-flicker {
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
    .landing-bubble,
    .landing-firefly {
        animation: none;
        display: none;
    }
}
</style>