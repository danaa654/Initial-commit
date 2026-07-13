<script setup>
import { computed } from 'vue';

const props = defineProps({
    percent: { type: Number, required: true }, // 0-100+ (values over 100 are clamped for the ring, but color still reflects overage)
    size: { type: Number, default: 44 },
    strokeWidth: { type: Number, default: 5 },
});

const radius = computed(() => (props.size - props.strokeWidth) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);
const clamped = computed(() => Math.min(Math.max(props.percent, 0), 100));
const offset = computed(() => circumference.value * (1 - clamped.value / 100));

const colorClass = computed(() => {
    if (props.percent >= 100) return 'text-red-500';
    if (props.percent >= 80) return 'text-amber-500';
    return 'text-emerald-500';
});
</script>

<template>
    <div class="relative inline-flex items-center justify-center" :style="{ width: `${size}px`, height: `${size}px` }">
        <svg :width="size" :height="size" class="-rotate-90">
            <circle
                :cx="size / 2"
                :cy="size / 2"
                :r="radius"
                fill="none"
                class="stroke-[var(--card-border)]"
                :stroke-width="strokeWidth"
            />
            <circle
                :cx="size / 2"
                :cy="size / 2"
                :r="radius"
                fill="none"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="offset"
                class="transition-all duration-300"
                :class="colorClass"
                stroke="currentColor"
            />
        </svg>
        <span class="absolute text-[10px] font-semibold text-[var(--text-secondary)]">{{ Math.round(percent) }}%</span>
    </div>
</template>