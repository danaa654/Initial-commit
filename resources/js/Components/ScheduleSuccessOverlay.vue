<script setup>
import { computed, ref, watch } from 'vue'

/**
 * Centered "it worked" celebration, meant to sit ON TOP of a modal
 * panel (the parent must be `position: relative` — see
 * GeneratePreviewModal.vue) rather than replacing Toast.vue's normal
 * top-right flash messages. Save Schedule is a deliberate, final
 * commit to the database — it earns a bigger, harder-to-miss moment
 * than a routine "Saved" toast, which is why this exists as its own
 * component instead of just another Toast type.
 *
 * Auto-dismisses itself after `duration` ms by emitting 'done' — the
 * parent is responsible for actually closing/resetting whatever this
 * was layered on top of when that fires (see Index.vue's
 * onScheduleSaveCelebrationDone()). This component never closes
 * anything itself; it only reports "the 3 seconds are up."
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    message: { type: String, default: 'Schedule Saved Successfully!' },
    duration: { type: Number, default: 3000 },
})

const emit = defineEmits(['done'])

let timer = null

// Bumped every time `show` flips to true, and used as part of each
// confetti piece's :key below — forces Vue to tear down and recreate
// every piece (and therefore replay every CSS animation from its 0%
// keyframe) on each new celebration, instead of a second Save
// Schedule within the animation's own lifetime silently reusing
// already-finished (invisible, opacity: 0) piece elements.
const burstKey = ref(0)

const CONFETTI_COLORS = ['#facc15', '#a855f7', '#3b82f6', '#f97316', '#10b981', '#ef4444']
const PIECE_COUNT = 60

const confettiPieces = computed(() => {
    // Reruns whenever burstKey changes so every burst is freshly
    // randomized rather than replaying an identical pattern.
    void burstKey.value

    return Array.from({ length: PIECE_COUNT }, (_, i) => ({
        id: i,
        left: Math.random() * 100,
        delay: Math.random() * 0.3,
        duration: 1.6 + Math.random() * 1.1,
        rotate: Math.round(Math.random() * 360),
        drift: Math.round((Math.random() - 0.5) * 160),
        width: 5 + Math.random() * 5,
        color: CONFETTI_COLORS[i % CONFETTI_COLORS.length],
    }))
})

watch(
    () => props.show,
    (visible) => {
        clearTimeout(timer)

        if (visible) {
            burstKey.value++
            timer = setTimeout(() => emit('done'), props.duration)
        }
    }
)
</script>

<template>
    <Transition name="success-overlay-fade">
        <div
            v-if="show"
            class="absolute inset-0 z-[70] flex items-center justify-center rounded-xl bg-white/90 dark:bg-slate-900/90 backdrop-blur-sm overflow-hidden"
        >
            <!-- Confetti burst -->
            <div class="pointer-events-none absolute inset-0">
                <span
                    v-for="piece in confettiPieces"
                    :key="`${burstKey}-${piece.id}`"
                    class="confetti-piece"
                    :style="{
                        left: piece.left + '%',
                        width: piece.width + 'px',
                        height: piece.width * 0.4 + 'px',
                        backgroundColor: piece.color,
                        animationDelay: piece.delay + 's',
                        animationDuration: piece.duration + 's',
                        '--confetti-drift': piece.drift + 'px',
                        '--confetti-rotate': piece.rotate + 'deg',
                    }"
                />
            </div>

            <!-- Success card -->
            <div class="relative z-10 flex flex-col items-center gap-3 px-8 py-6 text-center">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-500/20 success-pop">
                    <svg viewBox="0 0 24 24" class="h-9 w-9 text-emerald-500" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <p class="text-lg font-black text-slate-800 dark:text-slate-100">{{ message }}</p>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.success-overlay-fade-enter-active,
.success-overlay-fade-leave-active {
    transition: opacity 0.25s ease;
}
.success-overlay-fade-enter-from,
.success-overlay-fade-leave-to {
    opacity: 0;
}

.success-pop {
    animation: success-pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes success-pop {
    0% {
        transform: scale(0);
        opacity: 0;
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}

.confetti-piece {
    position: absolute;
    top: -10px;
    border-radius: 1px;
    animation-name: confetti-fall;
    animation-timing-function: ease-in;
    animation-fill-mode: forwards;
}

@keyframes confetti-fall {
    0% {
        transform: translate(0, 0) rotate(0deg);
        opacity: 1;
    }
    100% {
        transform: translate(var(--confetti-drift), 340px) rotate(var(--confetti-rotate));
        opacity: 0;
    }
}
</style>