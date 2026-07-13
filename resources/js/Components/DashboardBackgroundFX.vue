<script setup>
// Shared visual system pulled straight from BlockSchedule/Landing.vue —
// same faint timetable-grid texture, ambient corner glows, and the
// light-mode rising bubbles / dark-mode drifting fireflies. Wrap any
// page's content in this so it reads as part of the same app, not a
// flat plain background.
import { useAppShell } from '@/Composables/useAppShell'

const { darkMode } = useAppShell()

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

// Slightly lighter counts than the Landing page since dashboards
// already have a lot of content on screen — keeps the effect
// atmospheric instead of distracting from the stat cards/charts.
const bubbles = Array.from({ length: 20 }, (_, i) => ({
    id: i,
    left: randomBetween(0, 100),
    size: randomBetween(8, 34),
    duration: randomBetween(9, 24),
    delay: randomBetween(-20, 0),
    drift: randomBetween(-50, 50),
    color: BUBBLE_COLORS[i % BUBBLE_COLORS.length],
}))

const fireflies = Array.from({ length: 26 }, (_, i) => ({
    id: i,
    left: randomBetween(0, 100),
    top: randomBetween(0, 100),
    size: randomBetween(3, 6),
    duration: randomBetween(4, 9),
    delay: randomBetween(0, 8),
    driftX: randomBetween(-60, 60),
    driftY: randomBetween(-60, 60),
}))
</script>

<template>
    <div class="relative">
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

            <!-- Soft ambient background blobs -->
            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-gradient-to-br from-blue-100/50 to-purple-100/40 blur-3xl dark:from-blue-500/10 dark:to-purple-500/10"></div>
            <div class="pointer-events-none absolute bottom-0 left-0 h-56 w-56 rounded-full bg-gradient-to-tr from-purple-50/60 to-transparent blur-2xl dark:from-purple-500/10"></div>

            <!-- Light mode: rising bubbles -->
            <template v-if="!darkMode">
                <span
                    v-for="b in bubbles"
                    :key="'bubble-' + b.id"
                    class="dash-bubble"
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
            </template>

            <!-- Dark mode: drifting fireflies -->
            <template v-else>
                <span
                    v-for="f in fireflies"
                    :key="'firefly-' + f.id"
                    class="dash-firefly"
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
            </template>
        </div>

        <!-- Actual page content, above the effects -->
        <div class="relative z-10">
            <slot />
        </div>
    </div>
</template>

<style scoped>
/* Light mode — soft rising bubbles */
.dash-bubble {
    position: absolute;
    bottom: -60px;
    border-radius: 9999px;
    background: radial-gradient(circle at 30% 30%, var(--bubble-core), var(--bubble-mid) 60%, transparent 75%);
    border: 1px solid var(--bubble-border);
    animation-name: dash-bubble-rise;
    animation-timing-function: ease-in;
    animation-iteration-count: infinite;
}

@keyframes dash-bubble-rise {
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
.dash-firefly {
    position: absolute;
    border-radius: 9999px;
    background: radial-gradient(circle, rgba(253, 224, 71, 1) 0%, rgba(163, 230, 53, 0.65) 50%, transparent 75%);
    box-shadow: 0 0 10px 3px rgba(253, 224, 71, 0.75), 0 0 22px 7px rgba(163, 230, 53, 0.4);
    animation-name: dash-firefly-drift, dash-firefly-flicker;
    animation-timing-function: ease-in-out, ease-in-out;
    animation-iteration-count: infinite, infinite;
    animation-direction: alternate, alternate;
}

@keyframes dash-firefly-drift {
    0% {
        transform: translate(0, 0);
    }
    100% {
        transform: translate(var(--dx), var(--dy));
    }
}

@keyframes dash-firefly-flicker {
    0%, 100% {
        opacity: 0.35;
    }
    35% {
        opacity: 1;
    }
    60% {
        opacity: 0.55;
    }
    80% {
        opacity: 0.95;
    }
}

@media (prefers-reduced-motion: reduce) {
    .dash-bubble,
    .dash-firefly {
        animation: none;
        display: none;
    }
}
</style>