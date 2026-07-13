<script setup>
import { computed } from 'vue'
import { collegeClasses } from '@/Utils/collegeColors'

const props = defineProps({
    label: { type: String, required: true },
    percent: { type: Number, default: 0 },
    college: { type: String, default: null }, // tints the fill bar by department
})

const palette = computed(() => (props.college ? collegeClasses(props.college) : null))
</script>

<template>
    <div
        class="group bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)]
               transition-all duration-300 ease-out hover:-translate-y-0.5 hover:shadow-lg"
        :class="palette ? palette.hoverBorder : 'hover:border-indigo-300 dark:hover:border-indigo-500'"
    >
        <div class="flex justify-between items-center mb-2">
            <h2 class="text-[var(--text-secondary)] text-xs">{{ label }}</h2>
            <span class="text-[var(--text-primary)] text-sm font-semibold">{{ percent }}%</span>
        </div>
        <div class="w-full h-2 rounded-full bg-[var(--card-border)] overflow-hidden">
            <div
                class="h-full rounded-full transition-all duration-700 ease-out bg-gradient-to-r"
                :class="palette ? palette.gradient : 'from-indigo-400 to-indigo-500'"
                :style="{ width: Math.min(percent, 100) + '%' }"
            ></div>
        </div>
    </div>
</template>