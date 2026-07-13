<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useAppShell } from '@/Composables/useAppShell'

defineProps({
    department: Object,
    sections: Array,
    academicTerm: Object,
})

const { darkMode } = useAppShell()

// Maps the backend-derived schedule_status ('red' | 'orange' | 'green')
// to the dot color + label shown on each block card. Kept as a lookup
// rather than inline ternaries so the legend footer and the per-card
// dot both read from the same single source of truth.
const STATUS_META = {
    green: { dot: 'bg-emerald-500', ring: 'ring-emerald-300', label: 'Fully scheduled' },
    orange: { dot: 'bg-amber-500', ring: 'ring-amber-300', label: 'Partially scheduled' },
    red: { dot: 'bg-rose-500', ring: 'ring-rose-300', label: 'Unscheduled' },
}

function statusMeta(section) {
    return STATUS_META[section.schedule_status] ?? STATUS_META.red
}

// Same ambient background treatment as the rest of Block Schedule —
// grid-line texture plus rising bubbles (light mode) / drifting
// fireflies (dark mode) — computed once at setup time so
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
                    class="sections-bubble"
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
                    class="sections-firefly"
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
                <Link :href="route('block-schedule.index')" class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
                    &lsaquo; Back to Departments
                </Link>

                <div class="mb-6 flex items-start justify-between">
                    <div>
                        <h1 class="text-2xl font-extrabold tracking-tight" style="color: var(--text-primary)">{{ department.code }} — Blocks</h1>
                        <p class="text-sm font-medium" style="color: var(--text-muted)">
                            {{ department.name }}
                            <span v-if="academicTerm"> — {{ academicTerm.display_name }}</span>
                        </p>
                    </div>

                    <a
                        :href="route('block-schedule.sections.print', department.id)"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-slate-700 dark:bg-white/10 dark:hover:bg-white/20"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a1 1 0 001-1v-4a1 1 0 00-1-1H9a1 1 0 00-1 1v4a1 1 0 001 1zm8-12V5a1 1 0 00-1-1H8a1 1 0 00-1 1v4h10z" />
                        </svg>
                        Print
                    </a>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="section in sections"
                        :key="section.id"
                        :href="route('block-schedule.show', [department.id, section.id])"
                        class="flex items-center justify-between rounded-xl border p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                        style="background: var(--card-bg); border-color: var(--card-border)"
                    >
                        <div class="flex items-center gap-3">
                            <!-- Blinking status light: green = every offering has a
                                 committed schedule, orange = some but not all,
                                 red = none scheduled yet. See
                                 BlockScheduleController::sections() for how
                                 schedule_status is derived. -->
                            <span class="relative flex h-3 w-3 shrink-0" :title="statusMeta(section).label">
                                <span
                                    class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"
                                    :class="statusMeta(section).dot"
                                ></span>
                                <span
                                    class="relative inline-flex h-3 w-3 rounded-full"
                                    :class="statusMeta(section).dot"
                                ></span>
                            </span>

                            <div>
                                <p class="text-base font-bold" style="color: var(--text-primary)">{{ section.section_code }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">Year {{ section.year_level }} — {{ section.offering_count }} subject(s)</p>
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </Link>
                </div>

                <!-- Legend -->
                <div v-if="sections.length" class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border px-4 py-3" style="background: var(--card-bg); border-color: var(--card-border)">
                    <div v-for="(meta, key) in STATUS_META" :key="key" class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full" :class="meta.dot"></span>
                        <span class="text-xs font-semibold" style="color: var(--text-muted)">{{ meta.label }}</span>
                    </div>
                </div>

                <p v-if="!sections.length" class="mt-10 text-center text-sm" style="color: var(--text-muted)">
                    No blocks have Subject Offerings for the current term yet.
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Light mode — soft rising bubbles */
.sections-bubble {
    position: absolute;
    bottom: -60px;
    border-radius: 9999px;
    background: radial-gradient(circle at 30% 30%, var(--bubble-core), var(--bubble-mid) 60%, transparent 75%);
    border: 1px solid var(--bubble-border);
    animation-name: sections-bubble-rise;
    animation-timing-function: ease-in;
    animation-iteration-count: infinite;
}

@keyframes sections-bubble-rise {
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
.sections-firefly {
    position: absolute;
    border-radius: 9999px;
    background: radial-gradient(circle, rgba(253, 224, 71, 0.95) 0%, rgba(163, 230, 53, 0.5) 55%, transparent 75%);
    box-shadow: 0 0 6px 2px rgba(253, 224, 71, 0.55), 0 0 14px 4px rgba(163, 230, 53, 0.25);
    animation-name: sections-firefly-drift, sections-firefly-flicker;
    animation-timing-function: ease-in-out, ease-in-out;
    animation-iteration-count: infinite, infinite;
    animation-direction: alternate, alternate;
}

@keyframes sections-firefly-drift {
    0% {
        transform: translate(0, 0);
    }
    100% {
        transform: translate(var(--dx), var(--dy));
    }
}

@keyframes sections-firefly-flicker {
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
    .sections-bubble,
    .sections-firefly {
        animation: none;
        display: none;
    }
}
</style>