<script setup>
/**
 * Generic Chart.js wrapper card.
 *
 * Requires `chart.js` to be installed:
 *   npm install chart.js
 *
 * Usage:
 *   <ChartCard
 *     title="Faculty Load Distribution"
 *     type="bar"
 *     :labels="charts.faculty_load.labels"
 *     :datasets="[{ label: 'Units', data: charts.faculty_load.data }]"
 *     college="CCS"
 *   />
 */
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import {
    Chart,
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    DoughnutController,
    ArcElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
} from 'chart.js'
import { collegeClasses } from '@/Utils/collegeColors'

Chart.register(
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    DoughnutController,
    ArcElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend
)

const props = defineProps({
    title: { type: String, required: true },
    type: { type: String, default: 'bar' }, // 'bar' | 'line' | 'doughnut'
    labels: { type: Array, default: () => [] },
    datasets: { type: Array, default: () => [] },
    height: { type: Number, default: 200 },
    emptyMessage: { type: String, default: 'No data yet for the working term.' },
    college: { type: String, default: null }, // e.g. 'CCS' — tints the chart's primary series and card hover
    badge: { type: String, default: null }, // small pill in the header, e.g. 'This Term'
    // Doughnut-only: shows a number centered inside the ring — either
    // the sum of all segments ('total') or the first segment's share
    // of the whole as a percent ('percent'). Off by default so every
    // existing ChartCard usage renders exactly as before.
    centerMode: { type: String, default: null }, // null | 'total' | 'percent'
    centerCaption: { type: String, default: '' },
    // Raw hex accent for pages with no college context — colored top
    // bar + dark-mode neon glow on hover, same treatment as StatCard.
    glowColor: { type: String, default: null },
})

const centerValue = computed(() => {
    if (!props.centerMode) return null
    const data = props.datasets[0]?.data ?? []
    const total = data.reduce((sum, v) => sum + (Number(v) || 0), 0)

    if (props.centerMode === 'percent') {
        return total ? Math.round(((Number(data[0]) || 0) / total) * 100) + '%' : '0%'
    }

    return total
})

const canvasEl = ref(null)
let chartInstance = null

const palette = computed(() => (props.college ? collegeClasses(props.college) : null))
const showGlow = computed(() => !palette.value && !!props.glowColor)

// Actual hex values behind each college color, used for Chart.js
// (which needs real color values, not Tailwind classes).
const COLLEGE_HEX = {
    CCS: '#f59e0b',    // amber
    CRIM: '#a855f7',   // purple
    CTE: '#3b82f6',    // blue
    SHTM: '#f97316',   // orange
    CBA: '#10b981',    // emerald
    General: '#64748b',
    Shared: '#64748b',
}

const defaultPalette = ['#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#0ea5e9', '#a855f7', '#14b8a6']

function seriesPalette() {
    if (props.college && COLLEGE_HEX[props.college]) {
        // Lead with the department's own color, then fall back to the
        // shared palette for any additional series/doughnut segments.
        return [COLLEGE_HEX[props.college], ...defaultPalette]
    }
    return defaultPalette
}

function readCssVar(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name)
    return value?.trim() || fallback
}

function buildDatasets() {
    const colors = seriesPalette()
    return props.datasets.map((ds, i) => ({
        borderRadius: props.type === 'bar' ? 6 : 0,
        backgroundColor: props.type === 'doughnut'
            ? props.labels.map((_, idx) => colors[idx % colors.length])
            : colors[i % colors.length] + (props.type === 'line' ? '33' : 'cc'),
        borderColor: colors[i % colors.length],
        borderWidth: props.type === 'line' ? 2 : 1,
        tension: 0.35,
        fill: props.type === 'line',
        ...ds,
    }))
}

function renderChart() {
    if (!canvasEl.value) return

    if (chartInstance) {
        chartInstance.destroy()
        chartInstance = null
    }

    const textColor = readCssVar('--text-secondary', '#94a3b8')
    const gridColor = readCssVar('--card-border', 'rgba(148,163,184,0.2)')

    chartInstance = new Chart(canvasEl.value, {
        type: props.type,
        data: {
            labels: props.labels,
            datasets: buildDatasets(),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 600,
                easing: 'easeOutQuart',
            },
            plugins: {
                legend: {
                    display: props.type === 'doughnut',
                    labels: { color: textColor },
                },
                tooltip: { mode: 'index', intersect: false },
            },
            scales: props.type === 'doughnut' ? {} : {
                x: {
                    ticks: { color: textColor },
                    grid: { color: gridColor },
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: textColor },
                    grid: { color: gridColor },
                },
            },
        },
    })
}

onMounted(async () => {
    await nextTick()
    renderChart()
})

onBeforeUnmount(() => {
    chartInstance?.destroy()
})

watch(() => [props.labels, props.datasets, props.type, props.college], () => {
    renderChart()
}, { deep: true })
</script>

<template>
    <div
        class="group relative bg-[var(--card-bg)] rounded-xl shadow p-4 border border-[var(--card-border)] overflow-hidden
               transition-all duration-300 ease-out hover:-translate-y-1 hover:shadow-lg"
        :class="palette ? palette.hoverBorder : (showGlow ? '' : 'hover:border-indigo-300 dark:hover:border-indigo-500')"
        :style="showGlow ? {
            borderColor: 'color-mix(in srgb, ' + glowColor + ' 35%, var(--card-border))',
        } : null"
    >
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

        <div class="flex items-center justify-between gap-2 mb-3">
            <div class="flex items-center gap-2">
                <span v-if="palette" class="w-1.5 h-1.5 rounded-full" :class="palette.dot"></span>
                <span v-else-if="showGlow" class="w-1.5 h-1.5 rounded-full" :style="{ background: glowColor }"></span>
                <h2 class="text-base font-semibold text-[var(--text-primary)]">{{ title }}</h2>
            </div>
            <span
                v-if="badge"
                class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold dark:bg-white/10 text-[var(--text-secondary)]"
            >
                {{ badge }}
            </span>
        </div>

        <div v-if="!labels.length" class="text-sm text-[var(--text-secondary)] py-10 text-center">
            {{ emptyMessage }}
        </div>

        <div v-else class="relative" :style="{ height: height + 'px' }">
            <canvas ref="canvasEl"></canvas>
            <div
                v-if="centerMode && type === 'doughnut'"
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"
            >
                <span class="text-2xl font-extrabold text-[var(--text-primary)]">{{ centerValue }}</span>
                <span v-if="centerCaption" class="text-[11px] text-[var(--text-secondary)]">{{ centerCaption }}</span>
            </div>
        </div>
    </div>
</template>