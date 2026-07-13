<script setup>
import { computed } from 'vue'
import { collegeClasses } from '@/Utils/collegeColors'

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: null },
    suffix: { type: String, default: '' },
    accent: { type: String, default: '' }, // e.g. 'text-emerald-500' for a highlighted number
    college: { type: String, default: null }, // e.g. 'CCS', 'CRIM', 'CTE', 'SHTM' — tints the card by department
    // Raw hex accent (e.g. '#8b5cf6') for pages with no college context
    // (Admin/Registrar) — draws the same colored top bar as `college`
    // and, in dark mode, a soft neon glow around the card on hover.
    // Ignored if `college` is set, since that already has its own palette.
    glowColor: { type: String, default: null },
})

// When no college is passed, the card falls back to the original
// neutral indigo hover treatment — Admin/Registrar's cross-department
// cards look exactly as before, while Dean/Assistant Dean cards can
// pick up their own department's color automatically.
const palette = computed(() => (props.college ? collegeClasses(props.college) : null))
const showGlow = computed(() => !palette.value && !!props.glowColor)
</script>

<template>
    <div
        class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden
               transition-all duration-300 ease-out
               hover:-translate-y-1 hover:shadow-lg"
        :class="palette ? palette.hoverBorder : (showGlow ? '' : 'hover:border-indigo-300 dark:hover:border-indigo-500')"
        :style="showGlow ? {
            '--glow': glowColor,
            borderColor: 'color-mix(in srgb, var(--glow) 35%, var(--card-border))',
        } : null"
    >
        <!-- College accent bar, brightens on hover -->
        <div
            v-if="palette"
            class="absolute top-0 left-0 right-0 h-1 opacity-70 group-hover:opacity-100 transition-opacity duration-300"
            :class="palette.dot"
        ></div>
        <div
            v-else-if="showGlow"
            class="absolute top-0 left-0 right-0 h-1 opacity-80 group-hover:opacity-100 transition-opacity duration-300"
            :style="{ background: glowColor }"
        ></div>
        <!-- Dark-mode-only neon glow, brightens on hover -->
        <div
            v-if="showGlow"
            class="pointer-events-none absolute inset-0 hidden rounded-xl opacity-40 transition-opacity duration-300 group-hover:opacity-90 dark:block"
            :style="{ boxShadow: `inset 0 0 0 1px color-mix(in srgb, ${glowColor} 45%, transparent), 0 0 22px 0 color-mix(in srgb, ${glowColor} 35%, transparent)` }"
        ></div>

        <div class="flex items-center justify-between">
            <h2 class="text-[var(--text-secondary)] text-xs">{{ label }}</h2>
            <span
                v-if="palette"
                class="w-1.5 h-1.5 rounded-full transition-transform duration-300 group-hover:scale-125"
                :class="palette.dot"
            ></span>
            <span
                v-else-if="showGlow"
                class="w-1.5 h-1.5 rounded-full transition-transform duration-300 group-hover:scale-125"
                :style="{ background: glowColor }"
            ></span>
        </div>

        <p
            class="text-xl font-bold mt-1 leading-tight break-words transition-transform duration-300 group-hover:scale-[1.03] origin-left"
            :class="accent || 'text-[var(--text-primary)]'"
        >
            {{ value ?? '—' }}<span v-if="value !== null && suffix" class="text-sm">{{ suffix }}</span>
        </p>
        <p v-if="$slots.default" class="text-xs text-[var(--text-secondary)] mt-1">
            <slot />
        </p>
    </div>
</template>