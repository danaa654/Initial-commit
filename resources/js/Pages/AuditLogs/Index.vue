<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    moduleOptions: { type: Array, default: () => [] },
    actionOptions: { type: Array, default: () => [] },
    roleOptions: { type: Array, default: () => [] },
    userOptions: { type: Array, default: () => [] },
});

/*
|--------------------------------------------------------------------------
| Filters — mirrors the pattern already used on Curriculums/Index and
| SubjectOfferings/Index: local refs seeded from `filters`, submitted
| via router.get with preserveState so typing doesn't jank the table.
|--------------------------------------------------------------------------
*/

const search = ref(props.filters.search ?? '');
const userId = ref(props.filters.user_id ?? '');
const role = ref(props.filters.role ?? '');
const moduleFilter = ref(props.filters.module ?? '');
const action = ref(props.filters.action ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

let searchTimeout = null;

function applyFilters() {
    router.get(route('audit-logs.index'), {
        search: search.value || undefined,
        user_id: userId.value || undefined,
        role: role.value || undefined,
        module: moduleFilter.value || undefined,
        action: action.value || undefined,
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
    userId.value = '';
    role.value = '';
    moduleFilter.value = '';
    action.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
}

const hasActiveFilters = computed(() =>
    search.value || userId.value || role.value || moduleFilter.value || action.value || dateFrom.value || dateTo.value
);

/*
|--------------------------------------------------------------------------
| Row detail panel
|--------------------------------------------------------------------------
|
| Fetched on demand via GET audit-logs/{id} rather than embedded in the
| paginated list payload — see AuditLogController::show()'s docblock.
*/

const selectedLog = ref(null);
const loadingDetail = ref(false);

async function openDetail(row) {
    loadingDetail.value = true;
    selectedLog.value = null;

    try {
        const { data } = await axios.get(route('audit-logs.show', row.id));
        selectedLog.value = data.log;
    } finally {
        loadingDetail.value = false;
    }
}

function closeDetail() {
    selectedLog.value = null;
}

/*
|--------------------------------------------------------------------------
| Display helpers
|--------------------------------------------------------------------------
*/

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    return date.toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit',
    });
}

function actionBadgeClass(actionValue) {
    if (['deleted', 'unassigned', 'deactivated', 'unpublished'].includes(actionValue)) {
        return 'bg-red-500/10 text-red-600 dark:text-red-400';
    }
    if (['created', 'assigned', 'activated', 'published', 'login'].includes(actionValue)) {
        return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
    }
    if (['updated', 'moved', 'overridden', 'role_changed', 'conflict_overridden'].includes(actionValue)) {
        return 'bg-amber-500/10 text-amber-600 dark:text-amber-400';
    }
    return 'bg-sky-500/10 text-sky-600 dark:text-sky-400';
}

function actionLabel(actionValue) {
    return String(actionValue ?? '').replace(/_/g, ' ');
}
</script>

<template>
    <AppLayout>
        <Head title="Audit Logs" />

        <div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h1 class="text-xl font-semibold text-[var(--text-primary)]">Audit Logs</h1>
                <p class="mt-1 text-sm text-[var(--text-muted)]">
                    A complete, read-only record of every security-relevant action taken in Classly.
                </p>
            </div>

            <!-- ==================== FILTERS ==================== -->
            <div class="mb-4 rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search description, record, user..."
                        class="input-field sm:col-span-2 xl:col-span-2"
                        @input="onSearchInput"
                    />

                    <select v-model="userId" class="input-field" @change="applyFilters">
                        <option value="">All Users</option>
                        <option v-for="u in userOptions" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>

                    <select v-model="role" class="input-field" @change="applyFilters">
                        <option value="">All Roles</option>
                        <option v-for="r in roleOptions" :key="r" :value="r">{{ r }}</option>
                    </select>

                    <select v-model="moduleFilter" class="input-field" @change="applyFilters">
                        <option value="">All Modules</option>
                        <option v-for="m in moduleOptions" :key="m" :value="m">{{ m }}</option>
                    </select>

                    <select v-model="action" class="input-field" @change="applyFilters">
                        <option value="">All Actions</option>
                        <option v-for="a in actionOptions" :key="a" :value="a">{{ actionLabel(a) }}</option>
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

            <!-- ==================== TABLE ==================== -->
            <div class="overflow-hidden rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)]">
                <table class="w-full divide-y divide-[var(--card-border)]">
                    <thead class="bg-[var(--page-bg)]">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Date &amp; Time</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">User</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Role</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Action</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Module</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Record</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Description</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">IP Address</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--card-border)]">
                        <tr v-if="logs.data.length === 0">
                            <td colspan="8" class="px-4 py-10 text-center text-sm text-[var(--text-muted)]">
                                No audit log entries match these filters.
                            </td>
                        </tr>
                        <tr
                            v-for="row in logs.data"
                            :key="row.id"
                            class="cursor-pointer hover:bg-[var(--page-bg)]"
                            @click="openDetail(row)"
                        >
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-[var(--text-primary)]">
                                {{ formatDateTime(row.created_at) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-[var(--text-primary)]">
                                {{ row.user_name ?? 'System' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-[var(--text-secondary)]">
                                {{ row.role ?? '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="actionBadgeClass(row.action)">
                                    {{ actionLabel(row.action) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-[var(--text-primary)]">
                                {{ row.module }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-[var(--text-primary)]">
                                {{ row.record_name ?? '—' }}
                            </td>
                            <td class="max-w-[320px] truncate px-4 py-3 text-xs text-[var(--text-secondary)]" :title="row.description">
                                {{ row.description ?? '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-[var(--text-muted)]">
                                {{ row.ip_address ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ==================== PAGINATION ==================== -->
            <div v-if="logs.links?.length > 3" class="mt-4 flex flex-wrap items-center gap-1">
                <template v-for="(link, i) in logs.links" :key="i">
                    <button
                        v-if="link.url"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-xs font-medium"
                        :class="link.active
                            ? 'bg-[var(--accent)] text-white'
                            : 'text-[var(--text-secondary)] hover:bg-[var(--page-bg)]'"
                        v-html="link.label"
                        @click="router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
                    />
                    <span v-else class="px-3 py-1.5 text-xs text-[var(--text-muted)]" v-html="link.label" />
                </template>
            </div>
        </div>

        <!-- ==================== ROW DETAIL MODAL ==================== -->
        <div v-if="selectedLog || loadingDetail" class="fixed inset-0 z-50 flex items-center justify-end bg-black/40" @click.self="closeDetail">
            <div class="h-full w-full max-w-md overflow-y-auto bg-[var(--card-bg)] p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-[var(--text-primary)]">Audit Log Detail</h2>
                    <button type="button" class="text-[var(--text-muted)] hover:text-[var(--text-primary)]" @click="closeDetail">✕</button>
                </div>

                <div v-if="loadingDetail" class="py-10 text-center text-sm text-[var(--text-muted)]">Loading...</div>

                <dl v-else-if="selectedLog" class="space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">User</dt>
                        <dd class="text-[var(--text-primary)]">{{ selectedLog.user_name ?? 'System' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Role</dt>
                        <dd class="text-[var(--text-primary)]">{{ selectedLog.role ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Action</dt>
                        <dd>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="actionBadgeClass(selectedLog.action)">
                                {{ actionLabel(selectedLog.action) }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Module</dt>
                        <dd class="text-[var(--text-primary)]">{{ selectedLog.module }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Record</dt>
                        <dd class="text-[var(--text-primary)]">{{ selectedLog.record_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Description</dt>
                        <dd class="text-[var(--text-primary)]">{{ selectedLog.description ?? '—' }}</dd>
                    </div>

                    <div v-if="selectedLog.old_values || selectedLog.new_values" class="grid grid-cols-2 gap-3">
                        <div>
                            <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Old Values</dt>
                            <dd class="mt-1 space-y-1 rounded-md bg-[var(--page-bg)] p-2 text-xs">
                                <div v-for="(value, key) in selectedLog.old_values" :key="key">
                                    <span class="text-[var(--text-muted)]">{{ key }}:</span>
                                    <span class="text-[var(--text-primary)]">{{ value ?? '—' }}</span>
                                </div>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">New Values</dt>
                            <dd class="mt-1 space-y-1 rounded-md bg-[var(--page-bg)] p-2 text-xs">
                                <div v-for="(value, key) in selectedLog.new_values" :key="key">
                                    <span class="text-[var(--text-muted)]">{{ key }}:</span>
                                    <span class="text-[var(--text-primary)]">{{ value ?? '—' }}</span>
                                </div>
                            </dd>
                        </div>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Browser</dt>
                        <dd class="text-[var(--text-primary)]">{{ selectedLog.browser_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">IP Address</dt>
                        <dd class="text-[var(--text-primary)]">{{ selectedLog.ip_address ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Timestamp</dt>
                        <dd class="text-[var(--text-primary)]">{{ formatDateTime(selectedLog.created_at) }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.input-field {
    @apply w-full rounded-md border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2 text-sm text-[var(--text-primary)];
}
</style>