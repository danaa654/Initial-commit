<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    groups: { type: Array, required: true },
    academicTerms: { type: Array, default: () => [] },
    moduleOptions: { type: Array, default: () => [] },
    eventOptions: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
});

/*
|--------------------------------------------------------------------------
| Filters — same local-ref-seeded-from-props pattern as AuditLogs/Index,
| just posting to activity-history.index instead.
|--------------------------------------------------------------------------
*/

const search = ref(props.filters.search ?? '');
const academicTermId = ref(props.filters.academic_term_id ?? '');
const moduleFilter = ref(props.filters.module ?? '');
const eventFilter = ref(props.filters.event ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

let searchTimeout = null;

function applyFilters() {
    router.get(route('activity-history.index'), {
        search: search.value || undefined,
        academic_term_id: academicTermId.value || undefined,
        module: moduleFilter.value || undefined,
        event: eventFilter.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 350);
}

function clearFilters() {
    search.value = '';
    academicTermId.value = '';
    moduleFilter.value = '';
    eventFilter.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
}

const hasActiveFilters = computed(() =>
    search.value || academicTermId.value || moduleFilter.value || eventFilter.value ||
    dateFrom.value || dateTo.value
);

const isEmpty = computed(() => props.groups.length === 0);

/*
|--------------------------------------------------------------------------
| Display helpers — icon/color/label
|--------------------------------------------------------------------------
*/

// Maps the six spec color names to Tailwind classes. Everything else
// (icon glyphs, module labels) is derived below rather than baked
// into the backend, so new events never require a backend change to
// render sensibly.
const COLOR_MAP = {
    blue: { dot: 'bg-sky-500', ring: 'ring-sky-500/20', badge: 'bg-sky-500/10 text-sky-600 dark:text-sky-400', line: 'bg-sky-500/30' },
    green: { dot: 'bg-emerald-500', ring: 'ring-emerald-500/20', badge: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400', line: 'bg-emerald-500/30' },
    yellow: { dot: 'bg-amber-500', ring: 'ring-amber-500/20', badge: 'bg-amber-500/10 text-amber-600 dark:text-amber-400', line: 'bg-amber-500/30' },
    red: { dot: 'bg-red-500', ring: 'ring-red-500/20', badge: 'bg-red-500/10 text-red-600 dark:text-red-400', line: 'bg-red-500/30' },
    purple: { dot: 'bg-purple-500', ring: 'ring-purple-500/20', badge: 'bg-purple-500/10 text-purple-600 dark:text-purple-400', line: 'bg-purple-500/30' },
    gray: { dot: 'bg-gray-400', ring: 'ring-gray-400/20', badge: 'bg-gray-400/10 text-gray-600 dark:text-gray-400', line: 'bg-gray-400/30' },
};

const ICON_MAP = {
    'subject-offering': '📄',
    'faculty': '👤',
    'faculty-loading': '📋',
    'master-grid': '🗓️',
    'conflict': '⚠️',
    'publish': '📢',
    'archive': '🗄️',
    'calendar': '📅',
    'schedule': '🕘',
    'room': '🏢',
    'system': '⚙️',
    'activity': '●',
};

function colorClasses(color) {
    return COLOR_MAP[color] ?? COLOR_MAP.gray;
}

function iconGlyph(icon) {
    return ICON_MAP[icon] ?? '●';
}

function eventLabel(event) {
    const verb = String(event ?? '').split('.').pop() ?? '';
    return verb.replace(/_/g, ' ');
}

function formatTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function formatDay(value) {
    if (!value) return '';
    return new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>

<template>
    <AppLayout>
        <Head title="Activity History" />

        <div class="mx-auto max-w-[1100px] px-4 py-6 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h1 class="text-xl font-semibold text-[var(--text-primary)]">Activity History</h1>
                <p class="mt-1 text-sm text-[var(--text-muted)]">
                    The story of how each semester's schedule came together — not a record of who changed what
                    (that's Audit Logs), but a timeline of major scheduling milestones.
                </p>
            </div>

            <!-- ==================== FILTERS ==================== -->
            <div class="mb-6 rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search title, description, user..."
                        class="input-field sm:col-span-2 xl:col-span-2"
                        @input="onSearchInput"
                    />

                    <select v-model="academicTermId" class="input-field" @change="applyFilters">
                        <option value="">All Academic Terms</option>
                        <option v-for="t in academicTerms" :key="t.id" :value="t.id">
                            {{ t.academic_year }} — {{ t.semester_label }}
                        </option>
                    </select>

                    <select v-model="moduleFilter" class="input-field" @change="applyFilters">
                        <option value="">All Modules</option>
                        <option v-for="m in moduleOptions" :key="m" :value="m">{{ m }}</option>
                    </select>

                    <select v-model="eventFilter" class="input-field" @change="applyFilters">
                        <option value="">All Events</option>
                        <option v-for="e in eventOptions" :key="e" :value="e">{{ eventLabel(e) }}</option>
                    </select>

                    <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-3 xl:col-span-6">
                        <label class="shrink-0 text-xs font-medium text-[var(--text-muted)]">From</label>
                        <input v-model="dateFrom" type="date" class="input-field w-full" @change="applyFilters" />
                        <label class="shrink-0 text-xs font-medium text-[var(--text-muted)]">To</label>
                        <input v-model="dateTo" type="date" class="input-field w-full" @change="applyFilters" />
                    </div>
                </div>

                <div v-if="hasActiveFilters" class="mt-3">
                    <button type="button" class="text-xs font-medium text-[var(--text-muted)] underline hover:text-[var(--text-primary)]" @click="clearFilters">
                        Clear all filters
                    </button>
                </div>
            </div>

            <!-- ==================== EMPTY STATE ==================== -->
            <div v-if="isEmpty" class="rounded-xl border border-dashed border-[var(--card-border)] bg-[var(--card-bg)] px-6 py-16 text-center">
                <div class="mb-2 text-2xl">🗓️</div>
                <p class="text-sm text-[var(--text-muted)]">
                    No scheduling activity has been recorded for this Academic Term yet.
                </p>
            </div>

            <!-- ==================== TIMELINE, GROUPED BY TERM ==================== -->
            <div v-else class="space-y-10">
                <div v-for="group in groups" :key="group.academic_term?.id ?? 'unassigned'">
                    <div class="mb-4 flex items-center gap-3">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-[var(--text-secondary)]">
                            {{ group.academic_term?.display_name ?? 'Unassigned Term' }}
                        </h2>
                        <div class="h-px flex-1 bg-[var(--card-border)]" />
                    </div>

                    <ol class="relative ml-3 space-y-6 border-l-2 border-[var(--card-border)] pl-6">
                        <li v-for="activity in group.activities" :key="activity.id" class="relative">
                            <!-- Timeline dot -->
                            <span
                                class="absolute -left-[31px] flex h-4 w-4 items-center justify-center rounded-full ring-4"
                                :class="[colorClasses(activity.color).dot, colorClasses(activity.color).ring]"
                            />

                            <!-- Card -->
                            <div class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-2">
                                        <span class="text-lg leading-none">{{ iconGlyph(activity.icon) }}</span>
                                        <div>
                                            <h3 class="text-sm font-semibold text-[var(--text-primary)]">
                                                {{ activity.title }}
                                            </h3>
                                            <p v-if="activity.description" class="mt-0.5 text-xs text-[var(--text-secondary)]">
                                                {{ activity.description }}
                                            </p>
                                        </div>
                                    </div>

                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                        :class="colorClasses(activity.color).badge"
                                    >
                                        {{ eventLabel(activity.event) }}
                                    </span>
                                </div>

                                <!-- Optional metadata -->
                                <div v-if="activity.metadata" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--text-muted)]">
                                    <span v-for="(value, key) in activity.metadata" :key="key">
                                        <span class="font-medium text-[var(--text-secondary)] capitalize">{{ String(key).replace(/_/g, ' ') }}:</span>
                                        {{ value }}
                                    </span>
                                </div>

                                <div class="mt-3 flex items-center justify-between text-xs text-[var(--text-muted)]">
                                    <span>{{ activity.module }} · Created by {{ activity.user_name ?? 'System' }}</span>
                                    <span>{{ formatDay(activity.created_at) }}, {{ formatTime(activity.created_at) }}</span>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.input-field {
    @apply w-full rounded-md border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)];
}
</style>