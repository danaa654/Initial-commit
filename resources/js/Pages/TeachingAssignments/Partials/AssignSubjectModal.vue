<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    faculty: { type: Object, required: true },
    offerings: { type: Array, required: true }, // unassigned-to-anyone + assigned-to-this-faculty, active term
    assignedOfferingIds: { type: Set, default: () => new Set() },
    currentLoad: { type: Number, required: true },
    checkEligibility: { type: Function, required: true }, // (faculty, offering) => { ok, reason }
    error: { type: String, default: null },
    success: { type: String, default: null },
    // id of the offering currently being submitted, or null when idle.
    assigningOfferingId: { type: [Number, String], default: null },
});

const emit = defineEmits(['close', 'assign', 'unassign']);

const search = ref('');
const programFilter = ref('');
const classificationFilter = ref('');
const yearFilter = ref('');
const sectionFilter = ref('');
const roomTypeFilter = ref('');

// Defaults to "All" rather than "Unassigned" — a manager who just assigned
// a subject should still see it sitting in the list (now marked Assigned)
// instead of having it disappear, and a manager reviewing an existing load
// needs the Assigned rows visible too, in case they've changed their mind.
const statusFilter = ref('all');

const STATUSES = [
    { value: 'all', label: 'All' },
    { value: 'assigned', label: 'Assigned' },
    { value: 'unassigned', label: 'Unassigned' },
];

const programs = computed(() => {
    const set = new Set(
        props.offerings
            .map((o) => o.section?.curriculum?.program?.code)
            .filter(Boolean)
    );
    return Array.from(set).sort();
});

// Populated dynamically from whatever offerings are currently loaded for
// this faculty/term. Cascades off the Program filter — once a Program is
// selected, only that program's sections make sense to offer, otherwise
// you could pick e.g. BSIT-1A while filtering by BSED and get an (empty)
// contradictory combination.
const sections = computed(() => {
    const scoped = programFilter.value
        ? props.offerings.filter((o) => o.section?.curriculum?.program?.code === programFilter.value)
        : props.offerings;

    const set = new Set(
        scoped
            .map((o) => o.section?.section_code)
            .filter(Boolean)
    );
    return Array.from(set).sort();
});

// If the Program filter changes to something that no longer contains the
// currently-selected Section, clear the Section filter rather than leave
// it pointed at a section that can no longer match anything.
watch(programFilter, () => {
    if (sectionFilter.value && !sections.value.includes(sectionFilter.value)) {
        sectionFilter.value = '';
    }
});

// Classification and Room Type are fixed, small option sets — no need to
// derive them from the data.
const CLASSIFICATIONS = [
    { value: 'major', label: 'Major' },
    { value: 'minor', label: 'Minor' },
];

const ROOM_TYPES = [
    { value: 'Lecture', label: 'Lecture' },
    { value: 'Laboratory', label: 'Laboratory' },
];

const filteredOfferings = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.offerings.filter((o) => {
        const programCode = o.section?.curriculum?.program?.code ?? null;
        const sectionCode = o.section?.section_code ?? null;
        const isMajor = !!o.subject?.is_major;
        const roomType = o.subject?.required_room_type ?? null;
        const isAssigned = props.assignedOfferingIds.has(o.id);

        if (statusFilter.value === 'assigned' && !isAssigned) return false;
        if (statusFilter.value === 'unassigned' && isAssigned) return false;

        if (programFilter.value && programCode !== programFilter.value) return false;

        if (classificationFilter.value) {
            const wantsMajor = classificationFilter.value === 'major';
            if (isMajor !== wantsMajor) return false;
        }

        if (yearFilter.value && String(o.year_level) !== yearFilter.value) return false;

        if (sectionFilter.value && sectionCode !== sectionFilter.value) return false;

        if (roomTypeFilter.value) {
            if (!roomType || roomType.toLowerCase() !== roomTypeFilter.value.toLowerCase()) return false;
        }

        if (!term) return true;

        const haystack = [
            o.edp_code,
            o.subject?.subject_code,
            o.subject?.descriptive_title,
            programCode,
            sectionCode,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return haystack.includes(term);
    });
});

const rows = computed(() =>
    filteredOfferings.value
        .map((offering) => ({
            offering,
            isAssigned: props.assignedOfferingIds.has(offering.id),
            eligibility: props.checkEligibility(props.faculty, offering),
        }))
        // An offering earns its place in the list either because this
        // faculty member is eligible to take it on, or because it's
        // already assigned to them — an already-assigned row stays
        // visible (and unassignable from here) even if, say, a Faculty
        // Scope change since the assignment was made would no longer
        // make it eligible today.
        .filter((row) => row.eligibility.ok || row.isAssigned)
);
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="flex max-h-[85vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-[var(--card-bg)] shadow-xl">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-[var(--card-border)] px-6 py-4">
                <div>
                    <h2 class="text-lg font-bold text-[var(--text-primary)]">Assign Subject</h2>
                    <p class="text-sm text-[var(--text-muted)]">
                        Unassigned offerings for {{ faculty.full_name }} —
                        current load {{ currentLoad }} / {{ faculty.max_units }} units
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg p-2 text-[var(--text-muted)] transition hover:bg-[var(--page-bg)] hover:text-[var(--text-secondary)]"
                    @click="emit('close')"
                >
                    ✕
                </button>
            </div>

            <!-- Assign failure banner — a rejected assignment (e.g. business rule
                 violation caught server-side) used to fail with no visible feedback -->
            <div
                v-if="error"
                class="border-b border-red-500/20 bg-red-500/10 px-6 py-3 text-sm font-medium text-red-600 dark:text-red-400"
            >
                {{ error }}
            </div>

            <!-- Assign success banner — confirms the assignment went through
                 without closing the modal, since a manager will often assign
                 several subjects to the same faculty member in one sitting. -->
            <div
                v-if="success"
                class="border-b border-emerald-500/20 bg-emerald-500/10 px-6 py-3 text-sm font-medium text-emerald-600 dark:text-emerald-400"
            >
                ✓ {{ success }}
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3 border-b border-[var(--card-border)] px-6 py-3">
                <select
                    v-model="statusFilter"
                    class="rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm font-medium text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option v-for="s in STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search code, subject, section..."
                    class="min-w-[220px] flex-1 rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />
                <select
                    v-model="programFilter"
                    class="rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All Programs</option>
                    <option v-for="code in programs" :key="code" :value="code">{{ code }}</option>
                </select>
                <select
                    v-model="classificationFilter"
                    class="rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All Classifications</option>
                    <option v-for="c in CLASSIFICATIONS" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
                <select
                    v-model="yearFilter"
                    class="rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All Years</option>
                    <option v-for="y in [1, 2, 3, 4]" :key="y" :value="String(y)">Year {{ y }}</option>
                </select>
                <select
                    v-model="sectionFilter"
                    class="rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All Sections</option>
                    <option v-for="code in sections" :key="code" :value="code">{{ code }}</option>
                </select>
                <select
                    v-model="roomTypeFilter"
                    class="rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="">All Types</option>
                    <option v-for="rt in ROOM_TYPES" :key="rt.value" :value="rt.value">{{ rt.label }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-y-auto custom-scrollbar-theme">
                <table class="min-w-full divide-y divide-[var(--card-border)]">
                    <thead class="sticky top-0 bg-[var(--page-bg)]">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Code</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Subject</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Program</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Year</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Sem</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Units</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Type</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Section</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Status</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--card-border)]">
                        <tr v-if="rows.length === 0">
                            <td colspan="10" class="px-4 py-10 text-center text-sm text-[var(--text-muted)]">
                                No offerings match your filters.
                            </td>
                        </tr>
                        <tr v-for="row in rows" :key="row.offering.id" class="hover:bg-[var(--page-bg)]">
                            <td class="whitespace-nowrap px-4 py-2.5 text-sm font-medium text-[var(--text-primary)]">
                                {{ row.offering.edp_code }}
                            </td>
                            <td class="px-4 py-2.5 text-sm text-[var(--text-primary)]">
                                <span class="text-[var(--text-muted)]">{{ row.offering.subject?.subject_code }}</span>
                                {{ row.offering.subject?.descriptive_title }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-sm text-[var(--text-primary)]">
                                {{ row.offering.section?.curriculum?.program?.code ?? '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-sm text-[var(--text-primary)]">{{ row.offering.year_level }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-sm text-[var(--text-primary)]">{{ row.offering.semester }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-sm text-[var(--text-primary)]">{{ row.offering.subject?.units ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-sm">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="row.offering.subject?.is_major ? 'bg-purple-500/10 text-purple-600 dark:text-purple-400' : 'bg-sky-500/10 text-sky-600 dark:text-sky-400'"
                                    >
                                        {{ row.offering.subject?.is_major ? 'Major' : 'Minor' }}
                                    </span>
                                    <span
                                        v-if="row.offering.subject?.required_room_type"
                                        class="rounded-full bg-slate-500/10 px-2 py-0.5 text-xs font-medium text-slate-600 dark:text-slate-400"
                                    >
                                        {{ row.offering.subject.required_room_type }}
                                    </span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-sm text-[var(--text-primary)]">{{ row.offering.section?.section_code }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-sm">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="row.isAssigned ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-slate-500/10 text-slate-600 dark:text-slate-400'"
                                >
                                    {{ row.isAssigned ? 'Assigned' : 'Unassigned' }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                <button
                                    v-if="row.isAssigned"
                                    type="button"
                                    class="rounded-lg border border-red-500/30 px-3 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-500/10 disabled:cursor-not-allowed disabled:opacity-60 dark:text-red-400"
                                    :disabled="!!assigningOfferingId"
                                    @click="emit('unassign', row.offering)"
                                >
                                    {{ assigningOfferingId === row.offering.id ? 'Removing…' : 'Unassign' }}
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="btn-save disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="!!assigningOfferingId"
                                    @click="emit('assign', row.offering)"
                                >
                                    {{ assigningOfferingId === row.offering.id ? 'Assigning…' : 'Assign' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>