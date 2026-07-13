<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    activeUsers: { type: Array, required: true },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
    roleOptions: { type: Array, default: () => [] },
    departmentOptions: { type: Array, default: () => [] },
});

/*
|--------------------------------------------------------------------------
| Filters — same local-ref + router.get pattern as AuditLogs/Index and
| ActivityHistory/Index.
|--------------------------------------------------------------------------
*/

const search = ref(props.filters.search ?? '');
const role = ref(props.filters.role ?? '');
const departmentId = ref(props.filters.department_id ?? '');
const status = ref(props.filters.status ?? '');

let searchTimeout = null;

function applyFilters() {
    router.get(route('active-users.index'), {
        search: search.value || undefined,
        role: role.value || undefined,
        department_id: departmentId.value || undefined,
        status: status.value || undefined,
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
    role.value = '';
    departmentId.value = '';
    status.value = '';
    applyFilters();
}

const hasActiveFilters = computed(() =>
    search.value || role.value || departmentId.value || status.value
);

/*
|--------------------------------------------------------------------------
| Near-real-time refresh — polls the same route every 15s via a
| partial Inertia reload (only activeUsers + summary come back down
| the wire), preserving scroll/state/filters so the page doesn't jump
| or flicker while someone is reading it.
|--------------------------------------------------------------------------
*/

let pollTimer = null;

function poll() {
    router.reload({
        only: ['activeUsers', 'summary'],
        preserveScroll: true,
        preserveState: true,
    });
}

onMounted(() => {
    pollTimer = setInterval(poll, 15000);
});

onUnmounted(() => {
    clearInterval(pollTimer);
});

/*
|--------------------------------------------------------------------------
| Display helpers
|--------------------------------------------------------------------------
*/

function formatDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('en-US', {
        month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit', second: '2-digit',
    });
}

function statusDotClass(userStatus) {
    return userStatus === 'online' ? 'bg-emerald-500' : 'bg-amber-500';
}

function statusBadgeClass(userStatus) {
    return userStatus === 'online'
        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
        : 'bg-amber-500/10 text-amber-600 dark:text-amber-400';
}

function roleBadgeClass(userRole) {
    const map = {
        'Admin': 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
        'Registrar': 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
        'Dean': 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
        'Assistant Dean': 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
        'OIC': 'bg-teal-500/10 text-teal-600 dark:text-teal-400',
    };
    return map[userRole] ?? 'bg-slate-500/10 text-slate-600 dark:text-slate-400';
}
</script>

<template>
    <AppLayout>
        <Head title="Active Users" />

        <div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-[var(--text-primary)]">Active Users</h1>
                    <p class="mt-1 text-sm text-[var(--text-muted)]">
                        Who is currently logged into Classly, updated automatically every 15 seconds.
                    </p>
                </div>
                <span class="flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>
                    Live
                </span>
            </div>

            <!-- ==================== SUMMARY CARDS ==================== -->
            <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
                <div class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Online</p>
                    <p class="mt-1 text-2xl font-bold text-emerald-500">{{ summary.online }}</p>
                </div>
                <div class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Idle</p>
                    <p class="mt-1 text-2xl font-bold text-amber-500">{{ summary.idle }}</p>
                </div>
                <div class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Total Sessions</p>
                    <p class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ summary.total }}</p>
                </div>
                <div class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Admin Online</p>
                    <p class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ summary.admin_online }}</p>
                </div>
                <div class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Registrar Online</p>
                    <p class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ summary.registrar_online }}</p>
                </div>
                <div class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Dean/OIC Online</p>
                    <p class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ summary.dean_oic_online }}</p>
                </div>
                <div class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Asst. Dean Online</p>
                    <p class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ summary.assistant_dean_online }}</p>
                </div>
            </div>

            <!-- ==================== FILTERS ==================== -->
            <div class="mb-4 rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search name or email..."
                        class="input-field"
                        @input="onSearchInput"
                    />

                    <select v-model="role" class="input-field" @change="applyFilters">
                        <option value="">All Roles</option>
                        <option v-for="r in roleOptions" :key="r" :value="r">{{ r }}</option>
                    </select>

                    <select v-model="departmentId" class="input-field" @change="applyFilters">
                        <option value="">All Departments</option>
                        <option v-for="d in departmentOptions" :key="d.id" :value="d.id">{{ d.abbreviation }}</option>
                    </select>

                    <select v-model="status" class="input-field" @change="applyFilters">
                        <option value="">Any Status</option>
                        <option value="online">Online</option>
                        <option value="idle">Idle</option>
                    </select>
                </div>

                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="mt-3 text-xs font-semibold text-[var(--accent)] hover:underline"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>

            <!-- ==================== ACTIVE USER CARDS ==================== -->
            <div v-if="activeUsers.length === 0" class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-10 text-center text-sm text-[var(--text-muted)]">
                No active sessions match these filters.
            </div>

            <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="session in activeUsers"
                    :key="session.id"
                    class="rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4"
                >
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="relative shrink-0">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-blue-600 to-indigo-700 text-xs font-black text-white">
                                    {{ session.user.initials }}
                                </div>
                                <span
                                    class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-[var(--card-bg)]"
                                    :class="statusDotClass(session.status)"
                                ></span>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-[var(--text-primary)]">{{ session.user.name }}</p>
                                <span class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide" :class="roleBadgeClass(session.role)">
                                    {{ session.role ?? '—' }}
                                </span>
                            </div>
                        </div>

                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClass(session.status)">
                            {{ session.status_label }}
                        </span>
                    </div>

                    <dl class="mt-4 space-y-1.5 text-xs">
                        <div class="flex justify-between">
                            <dt class="text-[var(--text-muted)]">Department</dt>
                            <dd class="text-[var(--text-primary)]">{{ session.department ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-[var(--text-muted)]">Current Page</dt>
                            <dd class="text-[var(--text-primary)]">{{ session.current_page ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-[var(--text-muted)]">Login Time</dt>
                            <dd class="text-[var(--text-primary)]">{{ formatDateTime(session.login_at) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-[var(--text-muted)]">Last Activity</dt>
                            <dd class="text-[var(--text-primary)]">{{ formatDateTime(session.last_activity_at) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-[var(--text-muted)]">Browser</dt>
                            <dd class="text-[var(--text-primary)]">{{ session.browser ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-[var(--text-muted)]">OS</dt>
                            <dd class="text-[var(--text-primary)]">{{ session.operating_system ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-[var(--text-muted)]">IP Address</dt>
                            <dd class="text-[var(--text-primary)]">{{ session.ip_address ?? '—' }}</dd>
                        </div>
                    </dl>
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