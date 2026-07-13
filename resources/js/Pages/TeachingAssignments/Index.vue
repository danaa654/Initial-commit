<script setup>
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AssignSubjectModal from './Partials/AssignSubjectModal.vue';
import CircularLoadIndicator from './Partials/CircularLoadIndicator.vue';
import OverloadRequestModal from './Partials/OverloadRequestModal.vue';
import PendingOverloadsPanel from './Partials/PendingOverloadsPanel.vue';
import { UserGroupIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    planningTerm: { type: Object, default: null },
    faculties: { type: Array, required: true },
    departments: { type: Array, required: true },
    teachingAssignments: { type: Array, required: true },
    subjectOfferings: { type: Array, required: true },
    pendingOverloadRequests: { type: Array, default: () => [] },
    recentActivity: { type: Array, default: () => [] },
});

/*
|--------------------------------------------------------------------------
| Faculty Load Overload — role check
|--------------------------------------------------------------------------
|
| Admin/Registrar requests auto-approve immediately; Dean/Assistant
| Dean/OIC requests land as pending — mirrors
| FacultyLoadOverloadService's own role check server-side. This is
| purely for copy/labels in the UI; the server re-validates
| independently regardless of what's sent here.
*/

const currentUserRoles = computed(() => usePage().props.auth?.user?.roles ?? []);
const isAdminOrRegistrar = computed(
    () => currentUserRoles.value.includes('Admin') || currentUserRoles.value.includes('Registrar')
);

/*
|--------------------------------------------------------------------------
| Roster filters
|--------------------------------------------------------------------------
*/

const search = ref('');
const departmentFilter = ref('');
const scopeFilter = ref('');
const employmentFilter = ref('');

const scopeLabels = {
    general: 'General Education',
    departmental: 'Departmental',
    cross_department: 'Cross Department',
};

function surnameOf(faculty) {
    const parts = faculty.full_name.trim().split(/\s+/);
    return parts[parts.length - 1] ?? '';
}

const filteredFaculties = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.faculties
        .filter((faculty) => {
            if (departmentFilter.value && String(faculty.department_id) !== departmentFilter.value) return false;
            if (scopeFilter.value && faculty.faculty_scope !== scopeFilter.value) return false;
            if (employmentFilter.value && faculty.employment_type !== employmentFilter.value) return false;

            if (!term) return true;

            const haystack = [faculty.full_name, employeeId(faculty), faculty.department?.name]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return haystack.includes(term);
        })
        .sort((a, b) => surnameOf(a).localeCompare(surnameOf(b), undefined, { sensitivity: 'base' }));
});

function employeeId(faculty) {
    return `FAC-${String(faculty.id).padStart(4, '0')}`;
}

/*
|--------------------------------------------------------------------------
| Load calculations
|--------------------------------------------------------------------------
|
| All computed from teachingAssignments (the single source of truth for
| this module) scoped to the active academic term, which is all that's
| ever handed down from the controller.
*/

function assignmentsFor(facultyId) {
    return props.teachingAssignments.filter((a) => a.faculty_id === facultyId);
}

function activeAssignmentsFor(facultyId) {
    return assignmentsFor(facultyId).filter((a) => a.active);
}

function unitsOf(assignment) {
    return assignment.subject_offering?.subject?.units ?? 0;
}

/**
 * The Room currently PREFERRED for this assignment's Subject Offering
 * (via Rooms > Manage Subjects), or null. This is never a final
 * schedule — no day/time has been decided — but it's real, useful
 * information the Faculty Loading workspace shouldn't hide just
 * because the Scheduler hasn't run yet. Comes from
 * subject_offering.preferred_by_rooms, eager-loaded by
 * TeachingAssignmentController@index.
 */
function preferredRoomOf(assignment) {
    return assignment.subject_offering?.preferred_by_rooms?.[0] ?? null;
}

// Tries every field name the Room model might expose its display text
// under, since that wasn't confirmed against the actual Room model.
function roomLabel(room) {
    if (!room) return null;
    return room.name ?? room.room_name ?? room.room_number ?? room.room_code ?? `Room #${room.id}`;
}

/**
 * The actual committed Master Grid schedule block for this
 * assignment's Subject Offering, if Generate Schedule + Save Schedule
 * has already run for it — see TeachingAssignment::schedule() and
 * MasterGridController::save(). Unlike preferredRoomOf() above, this
 * is a real fact, not a wish: day, start/end minutes, and room are all
 * finalized. Eager-loaded as `schedule.room` by
 * TeachingAssignmentController@index.
 */
function scheduleOf(assignment) {
    return assignment.schedule ?? null;
}

/*
|--------------------------------------------------------------------------
| Recent Activity
|--------------------------------------------------------------------------
|
| "Just now" / "5m ago" / "3h ago" / "2d ago" for each entry in
| props.recentActivity — small and dependency-free rather than pulling
| in a date library for one relative-time string (mirrors the same
| helper in Topbar.vue).
*/

function timeAgo(isoString) {
    const seconds = Math.floor((Date.now() - new Date(isoString).getTime()) / 1000);
    if (seconds < 60) return 'Just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

function activityFacultyName(entry) {
    return entry.faculty?.full_name ?? entry.faculty_name_snapshot ?? 'a faculty member';
}

function activitySubjectTitle(entry) {
    return entry.subject_offering?.subject?.descriptive_title ?? entry.subject_snapshot ?? 'a subject';
}

function activityEdpCode(entry) {
    return entry.subject_offering?.edp_code ?? entry.edp_code_snapshot ?? null;
}

function isOverloadActivity(entry) {
    return entry.action === 'overload_added';
}

function activityDotClass(entry) {
    if (isOverloadActivity(entry)) return 'bg-sky-500';
    return entry.action === 'assigned' ? 'bg-emerald-500' : 'bg-red-500';
}

function formatMinutes(minutes) {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    const period = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
}

// "monday" -> "Monday" — the day column is stored/returned lowercase,
// this is display-only capitalization.
function capitalizeDay(day) {
    if (!day) return day;
    return day.charAt(0).toUpperCase() + day.slice(1).toLowerCase();
}

/**
 * Day and time range for a Schedule row, split so the template can
 * render "Monday" on its own line with the time underneath instead of
 * one long inline string — keeps the Assigned Subjects table narrow
 * enough to avoid horizontal scrolling. Returns null when the offering
 * hasn't been scheduled on the Master Grid yet.
 */
function scheduleParts(schedule) {
    if (!schedule) return null;

    return {
        day: capitalizeDay(schedule.day),
        time: `${formatMinutes(schedule.start_minutes)}–${formatMinutes(schedule.end_minutes)}`,
    };
}

/**
 * The room to display for this assignment: the real, committed
 * Schedule room takes priority over the pre-scheduling preference —
 * a schedule is a fact, a preference is only a wish. Falls back to
 * the preferred room, then to '—'.
 */
function displayRoomOf(assignment) {
    const schedule = scheduleOf(assignment);

    if (schedule?.room) {
        return roomLabel(schedule.room);
    }

    return roomLabel(preferredRoomOf(assignment));
}


function isMajorAssignment(assignment) {
    return !!assignment.subject_offering?.subject?.is_major;
}

function totalLoad(facultyId) {
    return activeAssignmentsFor(facultyId).reduce((sum, a) => sum + unitsOf(a), 0);
}

function majorLoad(facultyId) {
    return activeAssignmentsFor(facultyId)
        .filter(isMajorAssignment)
        .reduce((sum, a) => sum + unitsOf(a), 0);
}

function minorLoad(facultyId) {
    return activeAssignmentsFor(facultyId)
        .filter((a) => !isMajorAssignment(a))
        .reduce((sum, a) => sum + unitsOf(a), 0);
}

// effective_max_units (max_units + any APPROVED Faculty Load
// Overload) is what actually governs load display and eligibility —
// falls back to max_units for safety if the prop hasn't been
// hydrated with it for some reason.
function effectiveMaxUnits(faculty) {
    return faculty.effective_max_units ?? faculty.max_units;
}

function loadPercent(faculty) {
    const cap = effectiveMaxUnits(faculty);
    if (!cap) return 0;
    return (totalLoad(faculty.id) / cap) * 100;
}

/*
|--------------------------------------------------------------------------
| Selected faculty
|--------------------------------------------------------------------------
*/

const selectedFacultyId = ref(null);

const selectedFaculty = computed(
    () => props.faculties.find((f) => f.id === selectedFacultyId.value) ?? null
);

const selectedAssignments = computed(() =>
    selectedFaculty.value ? assignmentsFor(selectedFaculty.value.id) : []
);

function selectFaculty(faculty) {
    selectedFacultyId.value = faculty.id;
}

/*
|--------------------------------------------------------------------------
| Faculty Scope Eligibility (mirrors TeachingAssignmentController's
| assertFacultyScopeAllowsSubject / assertWithinMaxUnits / assertFacultyIsActive
| on the server — this is only a client-side preview so the scheduler
| sees friendly reasons before submitting; the server remains the
| authoritative check).
|--------------------------------------------------------------------------
*/

function checkEligibility(faculty, offering) {
    if (!faculty.status) {
        return { ok: false, reason: 'Inactive faculty' };
    }

    const subject = offering.subject;
    const isMajor = !!subject?.is_major;
    const subjectDepartmentId = offering.section?.curriculum?.program?.department_id ?? null;

    if (faculty.faculty_scope === 'general' && isMajor) {
        return { ok: false, reason: 'General Ed: Minor only' };
    }

    if (faculty.faculty_scope === 'departmental' && !isMajor) {
        return { ok: false, reason: 'Departmental: Major only' };
    }

    if (faculty.faculty_scope === 'departmental' && subjectDepartmentId !== faculty.department_id) {
        return { ok: false, reason: 'Outside faculty department' };
    }

    if (faculty.faculty_scope === 'cross_department' && isMajor && subjectDepartmentId !== faculty.department_id) {
        return { ok: false, reason: 'Major outside department' };
    }

    const incomingUnits = subject?.units ?? 0;
    const projectedLoad = totalLoad(faculty.id) + incomingUnits;
    const cap = effectiveMaxUnits(faculty);

    if (projectedLoad > cap) {
        return { ok: false, reason: `Exceeds max units (${projectedLoad}/${cap})` };
    }

    return { ok: true, reason: null };
}

/*
|--------------------------------------------------------------------------
| Assign Subject modal
|--------------------------------------------------------------------------
*/

const showAssignModal = ref(false);
const assignError = ref(null);
const assignSuccess = ref(null);

// Tracks the offering currently being submitted so its row can show a
// spinner/disabled state — prevents a user double-clicking "Assign" on
// the same offering (or in rapid succession on several offerings)
// from firing overlapping requests before the load/unassigned list
// has had a chance to refresh.
const assigningOfferingId = ref(null);

let successTimeout = null;

const assignedOfferingIds = computed(
    () => new Set(props.teachingAssignments.map((a) => a.subject_offering_id))
);

/*
|--------------------------------------------------------------------------
| Overview stats (shown only before a faculty member is selected)
|--------------------------------------------------------------------------
*/

const totalFacultyCount = computed(() => props.faculties.length);
const totalSubjectOfferingCount = computed(() => props.subjectOfferings.length);
const totalAssignedOfferingCount = computed(() => assignedOfferingIds.value.size);
const totalUnassignedOfferingCount = computed(
    () => Math.max(totalSubjectOfferingCount.value - totalAssignedOfferingCount.value, 0)
);

const assignedOfferingPercent = computed(() => {
    if (!totalSubjectOfferingCount.value) return 0;
    return Math.round((totalAssignedOfferingCount.value / totalSubjectOfferingCount.value) * 100);
});

const unassignedOfferingPercent = computed(() => {
    if (!totalSubjectOfferingCount.value) return 0;
    return Math.max(100 - assignedOfferingPercent.value, 0);
});

/*
|--------------------------------------------------------------------------
| Total Faculty card — click-to-cycle through departments
|--------------------------------------------------------------------------
|
| One bucket per active department (by name), plus a trailing "General
| Education" bucket for faculty with no department_id at all (mirrors
| every other "General Education (no department)" fallback already used
| elsewhere on this page). -1 means "show the grand total"; clicking the
| card walks forward through the buckets and wraps back to -1 after the
| last one.
*/

const facultyDeptBuckets = computed(() =>
    [
        ...props.departments.map((dept) => ({
            key: `dept-${dept.id}`,
            label: dept.name,
            count: props.faculties.filter((f) => f.department_id === dept.id).length,
        })),
        {
            key: 'gened',
            label: 'General Education',
            count: props.faculties.filter((f) => !f.department_id).length,
        },
    ]
);

const facultyViewIndex = ref(-1);

function cycleFacultyView() {
    const lastIndex = facultyDeptBuckets.value.length - 1;
    facultyViewIndex.value = facultyViewIndex.value >= lastIndex ? -1 : facultyViewIndex.value + 1;
}

const facultyCardView = computed(() => {
    if (facultyViewIndex.value === -1) {
        return {
            label: 'Total Faculty',
            count: totalFacultyCount.value,
            caption: 'Faculty members in the active scope',
        };
    }

    const bucket = facultyDeptBuckets.value[facultyViewIndex.value];

    return {
        label: bucket.label,
        count: bucket.count,
        caption: `Faculty members under ${bucket.label}`,
    };
});

const facultyCardPercent = computed(() => {
    if (!totalFacultyCount.value) return 0;
    return Math.round((facultyCardView.value.count / totalFacultyCount.value) * 100);
});

/*
|--------------------------------------------------------------------------
| Total Subjects / Assigned Subjects / Unassigned Left cards — click-to-
| cycle through departments, same pattern as the Total Faculty card above.
|--------------------------------------------------------------------------
|
| One bucket per active department (by name), plus a trailing "General
| Education" bucket for offerings whose program carries no department_id
| at all. Each bucket precomputes total/assigned/unassigned counts so all
| three cards can cycle independently while still sharing the same
| underlying buckets.
*/

function offeringDepartmentId(offering) {
    return offering.section?.curriculum?.program?.department_id ?? null;
}

function summarizeOfferings(offerings) {
    const assigned = offerings.filter((o) => assignedOfferingIds.value.has(o.id)).length;

    return {
        total: offerings.length,
        assigned,
        unassigned: Math.max(offerings.length - assigned, 0),
    };
}

const offeringDeptBuckets = computed(() => [
    ...props.departments.map((dept) => ({
        key: `dept-${dept.id}`,
        label: dept.name,
        ...summarizeOfferings(props.subjectOfferings.filter((o) => offeringDepartmentId(o) === dept.id)),
    })),
    {
        key: 'gened',
        label: 'General Education',
        ...summarizeOfferings(props.subjectOfferings.filter((o) => !offeringDepartmentId(o))),
    },
]);

const subjectViewIndex = ref(-1);
const assignedViewIndex = ref(-1);
const unassignedViewIndex = ref(-1);

function cycleView(indexRef) {
    const lastIndex = offeringDeptBuckets.value.length - 1;
    indexRef.value = indexRef.value >= lastIndex ? -1 : indexRef.value + 1;
}

const cycleSubjectView = () => cycleView(subjectViewIndex);
const cycleAssignedView = () => cycleView(assignedViewIndex);
const cycleUnassignedView = () => cycleView(unassignedViewIndex);

const subjectCardView = computed(() => {
    if (subjectViewIndex.value === -1) {
        return {
            label: 'Total Subjects',
            count: totalSubjectOfferingCount.value,
            caption: 'Total class offerings this term',
            percent: 100,
            percentLabel: '100% of active scope',
        };
    }

    const bucket = offeringDeptBuckets.value[subjectViewIndex.value];
    const percent = totalSubjectOfferingCount.value
        ? Math.round((bucket.total / totalSubjectOfferingCount.value) * 100)
        : 0;

    return {
        label: bucket.label,
        count: bucket.total,
        caption: `Class offerings under ${bucket.label}`,
        percent,
        percentLabel: `${percent}% of active scope`,
    };
});

const assignedCardView = computed(() => {
    if (assignedViewIndex.value === -1) {
        const percent = assignedOfferingPercent.value;
        return {
            label: 'Assigned Subjects',
            count: totalAssignedOfferingCount.value,
            caption: 'Classes with a faculty member assigned',
            percent,
            percentLabel: `${percent}% of total`,
        };
    }

    const bucket = offeringDeptBuckets.value[assignedViewIndex.value];
    const percent = bucket.total ? Math.round((bucket.assigned / bucket.total) * 100) : 0;

    return {
        label: bucket.label,
        count: bucket.assigned,
        caption: `Assigned classes under ${bucket.label}`,
        percent,
        percentLabel: `${percent}% of total`,
    };
});

const unassignedCardView = computed(() => {
    if (unassignedViewIndex.value === -1) {
        const percent = unassignedOfferingPercent.value;
        return {
            label: 'Unassigned Left',
            count: totalUnassignedOfferingCount.value,
            caption: 'Remaining classes without a faculty',
            percent,
            percentLabel: `${percent}% unassigned`,
        };
    }

    const bucket = offeringDeptBuckets.value[unassignedViewIndex.value];
    const percent = bucket.total ? Math.round((bucket.unassigned / bucket.total) * 100) : 0;

    return {
        label: bucket.label,
        count: bucket.unassigned,
        caption: `Unassigned classes under ${bucket.label}`,
        percent,
        percentLabel: `${percent}% unassigned`,
    };
});

// Every offering assigned to the currently selected faculty member, keyed
// by subject_offering_id, so the modal can show it as "Assigned" (with an
// Unassign action) instead of just dropping it from the list.
const assignmentByOfferingId = computed(() => {
    const map = new Map();
    if (!selectedFaculty.value) return map;
    selectedAssignments.value.forEach((a) => map.set(a.subject_offering_id, a));
    return map;
});

// Offerings the Assign Subject modal is allowed to show at all: either
// still unassigned to anyone, or already assigned to THIS faculty member
// (so a manager who changes their mind can unassign it from the same
// screen). Offerings claimed by a different faculty member stay hidden —
// that's not this faculty's business to see or touch here.
const modalOfferings = computed(() => {
    if (!selectedFaculty.value) return [];

    return props.subjectOfferings.filter((offering) => {
        if (!assignedOfferingIds.value.has(offering.id)) return true;
        return assignmentByOfferingId.value.has(offering.id);
    });
});

/*
|--------------------------------------------------------------------------
| Overload Request modal
|--------------------------------------------------------------------------
*/

const showOverloadModal = ref(false);

function openOverloadModal() {
    showOverloadModal.value = true;
}

function closeOverloadModal() {
    showOverloadModal.value = false;
}

function openAssignModal() {
    assignError.value = null;
    assignSuccess.value = null;
    showAssignModal.value = true;
}

function closeAssignModal() {
    clearTimeout(successTimeout);
    showAssignModal.value = false;
    assignError.value = null;
    assignSuccess.value = null;
    assigningOfferingId.value = null;
}

function handleAssign(offering) {
    if (!selectedFaculty.value || !props.planningTerm) return;

    // Guard against double-clicks / rapid-fire clicks on other rows
    // while a previous assignment is still in flight.
    if (assigningOfferingId.value) return;

    assignError.value = null;
    assignSuccess.value = null;
    assigningOfferingId.value = offering.id;
    clearTimeout(successTimeout);

    router.post(
        route('teaching-assignments.store'),
        {
            subject_offering_id: offering.id,
            faculty_id: selectedFaculty.value.id,
            remarks: '',
            active: true,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                // Deliberately NOT closing the modal here — the manager
                // is very likely assigning several subjects to the same
                // faculty member in one sitting, so we let them keep
                // picking. `modalOfferings` will reflect the new
                // "Assigned" status on this row once teachingAssignments
                // refreshes — the row stays put, it just changes state.
                const title = offering.subject?.descriptive_title ?? 'Subject';
                assignSuccess.value = `${title} assigned successfully.`;
                successTimeout = setTimeout(() => {
                    assignSuccess.value = null;
                }, 3000);
            },
            onError: (errors) => {
                assignSuccess.value = null;
                assignError.value = Object.values(errors)[0] ?? 'Something went wrong while assigning this subject.';
            },
            onFinish: () => {
                assigningOfferingId.value = null;
            },
        }
    );
}

function removeAssignment(assignment) {
    const label = assignment.subject_offering?.subject?.descriptive_title ?? 'this subject';

    if (!confirm(`Remove ${label} from ${selectedFaculty.value?.full_name}'s load?`)) {
        return;
    }

    router.delete(route('teaching-assignments.destroy', assignment.id), {
        preserveScroll: true,
    });
}

// Unassign, triggered from a row inside the Assign Subject modal (the
// "changed my mind" case) rather than from the Assigned Subjects table.
// Shares the same assigningOfferingId in-flight guard and success/error
// banners as handleAssign, since from the modal's point of view this is
// just the other direction of the same action.
function handleUnassign(offering) {
    const assignment = assignmentByOfferingId.value.get(offering.id);
    if (!assignment || assigningOfferingId.value) return;

    const label = offering.subject?.descriptive_title ?? 'this subject';

    if (!confirm(`Remove ${label} from ${selectedFaculty.value?.full_name}'s load?`)) {
        return;
    }

    assignError.value = null;
    assignSuccess.value = null;
    assigningOfferingId.value = offering.id;
    clearTimeout(successTimeout);

    router.delete(route('teaching-assignments.destroy', assignment.id), {
        preserveScroll: true,
        onSuccess: () => {
            assignSuccess.value = `${label} unassigned.`;
            successTimeout = setTimeout(() => {
                assignSuccess.value = null;
            }, 3000);
        },
        onError: (errors) => {
            assignSuccess.value = null;
            assignError.value = Object.values(errors)[0] ?? 'Something went wrong while unassigning this subject.';
        },
        onFinish: () => {
            assigningOfferingId.value = null;
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Faculty Loading" />

        <div class="flex h-[calc(100vh-4rem)] overflow-hidden">
            <!-- ==================== LEFT PANEL: FACULTY ROSTER ==================== -->
            <aside class="flex w-[17.5rem] flex-shrink-0 flex-col border-r border-[var(--card-border)] bg-[var(--card-bg)]">
                <div class="border-b border-[var(--card-border)] px-4 py-4">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                            <UserGroupIcon class="h-4.5 w-4.5" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="truncate text-lg font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">Faculty Loading</h1>
                            <p class="truncate text-xs text-[var(--text-muted)]">
                                <template v-if="planningTerm">{{ planningTerm.display_name }}</template>
                                <template v-else>No active academic term set</template>
                            </p>
                        </div>
                    </div>

                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search faculty..."
                        class="mt-3 w-full rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-3 py-2 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    />

                    <div class="mt-2 grid grid-cols-1 gap-2">
                        <select
                            v-model="departmentFilter"
                            class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-2.5 py-1.5 text-xs text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        >
                            <option value="">All Departments</option>
                            <option v-for="dept in departments" :key="dept.id" :value="String(dept.id)">
                                {{ dept.name }}
                            </option>
                        </select>

                        <div class="grid grid-cols-2 gap-2">
                            <select
                                v-model="scopeFilter"
                                class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-2.5 py-1.5 text-xs text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                                <option value="">All Scopes</option>
                                <option v-for="(label, value) in scopeLabels" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>

                            <select
                                v-model="employmentFilter"
                                class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-2.5 py-1.5 text-xs text-[var(--text-primary)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                            >
                                <option value="">All Types</option>
                                <option value="Full-Time">Full-Time</option>
                                <option value="Part-Time">Part-Time</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Pending Overload Requests — Admin/Registrar only -->
                <PendingOverloadsPanel v-if="isAdminOrRegistrar" :requests="pendingOverloadRequests" />

                <div class="flex-1 overflow-y-auto p-2 custom-scrollbar-theme">
                    <p v-if="filteredFaculties.length === 0" class="px-2 py-6 text-center text-sm text-[var(--text-muted)]">
                        No faculty match your filters.
                    </p>

                    <button
                        v-for="faculty in filteredFaculties"
                        :key="faculty.id"
                        type="button"
                        class="mb-1.5 flex w-full items-center gap-3 rounded-xl border p-3 text-left transition"
                        :class="
                            selectedFacultyId === faculty.id
                                ? 'border-blue-400/50 bg-blue-500/10'
                                : 'border-transparent hover:bg-[var(--page-bg)]'
                        "
                        @click="selectFaculty(faculty)"
                    >
                        <CircularLoadIndicator :percent="loadPercent(faculty)" :size="40" :stroke-width="4" />

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-[var(--text-primary)]">
                                {{ faculty.full_name }}
                                <span v-if="!faculty.status" class="ml-1 text-xs font-normal text-red-500">(Inactive)</span>
                            </p>
                            <p class="truncate text-xs text-[var(--text-muted)]">{{ employeeId(faculty) }}</p>
                            <p class="truncate text-xs text-[var(--text-muted)]">
                                {{ faculty.department?.name ?? 'General Education' }} · {{ faculty.employment_type }}
                            </p>
                            <p class="truncate text-xs text-[var(--text-muted)]">{{ scopeLabels[faculty.faculty_scope] }}</p>
                            <p class="mt-0.5 text-xs font-medium text-[var(--text-secondary)]">
                                {{ totalLoad(faculty.id) }} / {{ effectiveMaxUnits(faculty) }} units
                                <span v-if="faculty.approved_overload_units" class="text-emerald-600 dark:text-emerald-400">(+{{ faculty.approved_overload_units }})</span>
                            </p>
                        </div>
                    </button>
                </div>
            </aside>

            <!-- ==================== RIGHT PANEL: FACULTY WORKSPACE ==================== -->
            <main class="flex-1 overflow-y-auto bg-[var(--page-bg)] px-8 py-8 custom-scrollbar-theme">
                <div v-if="!selectedFaculty">
                    <!-- Overview Header -->
                    <div class="mb-6">
                        <p class="text-xs font-bold uppercase tracking-widest text-blue-600 dark:text-blue-400">
                            Faculty Loading &amp; Scheduling
                        </p>
                        <h2 class="mt-1 text-3xl font-bold [font-family:'Fraunces',serif] tracking-tight text-[var(--text-primary)]">
                            Department Overview
                        </h2>
                        <p class="mt-1 text-sm text-[var(--text-muted)]">
                            Select a faculty member to begin assignment, or review the term's subject distribution below.
                        </p>
                    </div>

                    <!-- Overview Cards -->
                    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <!-- Total Faculty (click to cycle through departments) -->
                        <button
                            type="button"
                            class="group relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-5 text-left shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-blue-300/60 hover:shadow-lg hover:shadow-blue-500/10 dark:hover:border-blue-500/40"
                            @click="cycleFacultyView"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate text-xs font-bold uppercase tracking-wide text-blue-600 dark:text-blue-400">
                                    {{ facultyCardView.label }}
                                </p>
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600 transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3 dark:text-blue-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9" cy="7" r="4"></circle>
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                    </svg>
                                </div>
                            </div>
                            <p class="mt-3 text-4xl font-black tabular-nums text-[var(--text-primary)] transition-transform duration-200 group-hover:scale-[1.04]">
                                {{ facultyCardView.count }}
                            </p>
                            <p class="mt-2 truncate text-xs leading-snug text-[var(--text-muted)]">{{ facultyCardView.caption }}</p>
                            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-[var(--page-bg)]">
                                <div class="h-full rounded-full bg-blue-500 transition-all duration-500 ease-out" :style="{ width: `${facultyCardPercent}%` }"></div>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <p class="text-xs font-medium text-blue-600 dark:text-blue-400">{{ facultyCardPercent }}% of total faculty</p>
                                <span class="whitespace-nowrap text-[10px] font-semibold uppercase tracking-wide text-[var(--text-muted)] opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                                    Tap to filter →
                                </span>
                            </div>
                            <!-- Position dots: which bucket is currently showing -->
                            <div class="mt-2.5 flex items-center gap-1">
                                <span
                                    v-for="n in facultyDeptBuckets.length + 1"
                                    :key="n"
                                    class="h-1 rounded-full transition-all duration-300"
                                    :class="
                                        (n - 2) === facultyViewIndex
                                            ? 'w-3 bg-blue-500'
                                            : 'w-1 bg-[var(--card-border)]'
                                    "
                                ></span>
                            </div>
                        </button>

                        <!-- Total Subjects (click to cycle through departments) -->
                        <button
                            type="button"
                            class="group relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-5 text-left shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-indigo-300/60 hover:shadow-lg hover:shadow-indigo-500/10 dark:hover:border-indigo-500/40"
                            @click="cycleSubjectView"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate text-xs font-bold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                                    {{ subjectCardView.label }}
                                </p>
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-600 transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3 dark:text-indigo-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                                    </svg>
                                </div>
                            </div>
                            <p class="mt-3 text-4xl font-black tabular-nums text-[var(--text-primary)] transition-transform duration-200 group-hover:scale-[1.04]">
                                {{ subjectCardView.count }}
                            </p>
                            <p class="mt-2 truncate text-xs leading-snug text-[var(--text-muted)]">{{ subjectCardView.caption }}</p>
                            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-[var(--page-bg)]">
                                <div class="h-full rounded-full bg-indigo-500 transition-all duration-500 ease-out" :style="{ width: `${subjectCardView.percent}%` }"></div>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400">{{ subjectCardView.percentLabel }}</p>
                                <span class="whitespace-nowrap text-[10px] font-semibold uppercase tracking-wide text-[var(--text-muted)] opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                                    Tap to filter →
                                </span>
                            </div>
                            <!-- Position dots: which bucket is currently showing -->
                            <div class="mt-2.5 flex items-center gap-1">
                                <span
                                    v-for="n in offeringDeptBuckets.length + 1"
                                    :key="n"
                                    class="h-1 rounded-full transition-all duration-300"
                                    :class="
                                        (n - 2) === subjectViewIndex
                                            ? 'w-3 bg-indigo-500'
                                            : 'w-1 bg-[var(--card-border)]'
                                    "
                                ></span>
                            </div>
                        </button>

                        <!-- Assigned Subjects (click to cycle through departments) -->
                        <button
                            type="button"
                            class="group relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-5 text-left shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-emerald-300/60 hover:shadow-lg hover:shadow-emerald-500/10 dark:hover:border-emerald-500/40"
                            @click="cycleAssignedView"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate text-xs font-bold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">
                                    {{ assignedCardView.label }}
                                </p>
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3 dark:text-emerald-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                    </svg>
                                </div>
                            </div>
                            <p class="mt-3 text-4xl font-black tabular-nums text-[var(--text-primary)] transition-transform duration-200 group-hover:scale-[1.04]">
                                {{ assignedCardView.count }}
                            </p>
                            <p class="mt-2 truncate text-xs leading-snug text-[var(--text-muted)]">{{ assignedCardView.caption }}</p>
                            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-[var(--page-bg)]">
                                <div class="h-full rounded-full bg-emerald-500 transition-all duration-500 ease-out" :style="{ width: `${assignedCardView.percent}%` }"></div>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <p class="text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ assignedCardView.percentLabel }}</p>
                                <span class="whitespace-nowrap text-[10px] font-semibold uppercase tracking-wide text-[var(--text-muted)] opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                                    Tap to filter →
                                </span>
                            </div>
                            <!-- Position dots: which bucket is currently showing -->
                            <div class="mt-2.5 flex items-center gap-1">
                                <span
                                    v-for="n in offeringDeptBuckets.length + 1"
                                    :key="n"
                                    class="h-1 rounded-full transition-all duration-300"
                                    :class="
                                        (n - 2) === assignedViewIndex
                                            ? 'w-3 bg-emerald-500'
                                            : 'w-1 bg-[var(--card-border)]'
                                    "
                                ></span>
                            </div>
                        </button>

                        <!-- Unassigned Subjects (click to cycle through departments) -->
                        <button
                            type="button"
                            class="group relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-5 text-left shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-red-300/60 hover:shadow-lg hover:shadow-red-500/10 dark:hover:border-red-500/40"
                            @click="cycleUnassignedView"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate text-xs font-bold uppercase tracking-wide text-red-600 dark:text-red-400">
                                    {{ unassignedCardView.label }}
                                </p>
                                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-red-500/10 text-red-600 transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3 dark:text-red-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <line x1="12" y1="8" x2="12" y2="12"></line>
                                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                    </svg>
                                </div>
                            </div>
                            <p class="mt-3 text-4xl font-black tabular-nums text-[var(--text-primary)] transition-transform duration-200 group-hover:scale-[1.04]">
                                {{ unassignedCardView.count }}
                            </p>
                            <p class="mt-2 truncate text-xs leading-snug text-[var(--text-muted)]">{{ unassignedCardView.caption }}</p>
                            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-[var(--page-bg)]">
                                <div class="h-full rounded-full bg-red-500 transition-all duration-500 ease-out" :style="{ width: `${unassignedCardView.percent}%` }"></div>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <p class="text-xs font-medium text-red-600 dark:text-red-400">{{ unassignedCardView.percentLabel }}</p>
                                <span class="whitespace-nowrap text-[10px] font-semibold uppercase tracking-wide text-[var(--text-muted)] opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                                    Tap to filter →
                                </span>
                            </div>
                            <!-- Position dots: which bucket is currently showing -->
                            <div class="mt-2.5 flex items-center gap-1">
                                <span
                                    v-for="n in offeringDeptBuckets.length + 1"
                                    :key="n"
                                    class="h-1 rounded-full transition-all duration-300"
                                    :class="
                                        (n - 2) === unassignedViewIndex
                                            ? 'w-3 bg-red-500'
                                            : 'w-1 bg-[var(--card-border)]'
                                    "
                                ></span>
                            </div>
                        </button>
                    </div>

                    <!-- Status Legend -->
                    <div class="mb-8 rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-5 shadow-sm transition-all duration-300 hover:shadow-md">
                        <p class="mb-3 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-[var(--text-secondary)]">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            </svg>
                            Assignment Status Legend
                        </p>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex-shrink-0 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400">✓ Assigned</span>
                                <p class="text-xs leading-snug text-[var(--text-muted)]">
                                    A faculty member has been given this Subject Offering as part of their load.
                                </p>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex-shrink-0 rounded-full bg-red-500/10 px-2 py-0.5 text-xs font-semibold text-red-600 dark:text-red-400">Unassigned</span>
                                <p class="text-xs leading-snug text-[var(--text-muted)]">
                                    No faculty has been assigned to this class yet — select a faculty member to close the gap.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity — this whole block only ever
                         renders while no faculty is selected (see the
                         v-if="!selectedFaculty" on the parent div), so
                         it disappears the moment someone clicks a
                         faculty in the roster and reappears when they
                         click "Department Overview" again. -->
                    <div class="rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-5 shadow-sm transition-all duration-300 hover:shadow-md">
                        <p class="mb-3 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-[var(--text-secondary)]">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            Recent Activity
                        </p>

                        <div v-if="!recentActivity.length" class="flex items-center justify-center py-8 text-center">
                            <p class="text-xs text-[var(--text-muted)]">
                                No faculty loading activity yet this term.
                            </p>
                        </div>

                        <ul v-else class="divide-y divide-[var(--card-border)]">
                            <li
                                v-for="entry in recentActivity"
                                :key="entry.id"
                                class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <span
                                    class="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full"
                                    :class="activityDotClass(entry)"
                                ></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs leading-snug text-[var(--text-primary)]">
                                        <template v-if="isOverloadActivity(entry)">
                                            <span class="font-semibold">{{ entry.performed_by?.name ?? 'Someone' }}</span>
                                            added
                                            <span class="font-semibold">{{ entry.units }} overload units</span>
                                            to
                                            <span class="font-semibold">{{ activityFacultyName(entry) }}</span>'s load
                                        </template>
                                        <template v-else>
                                            <span class="font-semibold">{{ entry.performed_by?.name ?? 'Someone' }}</span>
                                            {{ entry.action === 'assigned' ? 'assigned' : 'removed' }}
                                            <span class="font-semibold">{{ activityFacultyName(entry) }}</span>
                                            {{ entry.action === 'assigned' ? 'to' : 'from' }}
                                            <span class="font-semibold">{{ activitySubjectTitle(entry) }}</span>
                                            <span v-if="activityEdpCode(entry)" class="text-[var(--text-muted)]">
                                                ({{ activityEdpCode(entry) }})
                                            </span>
                                        </template>
                                    </p>
                                    <p class="mt-0.5 text-[10px] text-[var(--text-muted)]">
                                        {{ timeAgo(entry.created_at) }}
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <template v-else>
                    <!-- Back to Overview — clears the selected faculty so
                         the Department Overview (including Recent
                         Activity, which only renders in that empty
                         state) is visible again. -->
                    <button
                        type="button"
                        class="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--text-secondary)] transition-colors duration-150 hover:text-[var(--text-primary)]"
                        @click="selectedFacultyId = null"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                            <path d="M19 12H5"></path>
                            <path d="M12 19l-7-7 7-7"></path>
                        </svg>
                        Back to Overview
                    </button>

                    <!-- Faculty Info Header -->
                    <div class="relative overflow-hidden mb-6 rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-6 shadow-lg transition-colors duration-300">
                        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h2 class="text-2xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">{{ selectedFaculty.full_name }}</h2>
                                <p class="text-sm text-[var(--text-muted)]">{{ employeeId(selectedFaculty) }}</p>
                                <p class="mt-1 text-sm text-[var(--text-secondary)]">
                                    {{ selectedFaculty.department?.name ?? 'General Education (no department)' }}
                                </p>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span class="rounded-full bg-indigo-500/10 px-2.5 py-1 text-xs font-medium text-indigo-600 dark:text-indigo-400">
                                        {{ scopeLabels[selectedFaculty.faculty_scope] }}
                                    </span>
                                    <span
                                        class="rounded-full px-2.5 py-1 text-xs font-medium"
                                        :class="
                                            selectedFaculty.employment_type === 'Full-Time'
                                                ? 'bg-green-500/10 text-green-600 dark:text-green-400'
                                                : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                        "
                                    >
                                        {{ selectedFaculty.employment_type }}
                                    </span>
                                    <span
                                        class="rounded-full px-2.5 py-1 text-xs font-medium"
                                        :class="selectedFaculty.status ? 'bg-green-500/10 text-green-600 dark:text-green-400' : 'bg-[var(--page-bg)] text-[var(--text-muted)]'"
                                    >
                                        {{ selectedFaculty.status ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex items-center gap-4">
                                <CircularLoadIndicator :percent="loadPercent(selectedFaculty)" :size="72" :stroke-width="7" />
                                <div class="text-sm">
                                    <p class="text-[var(--text-muted)]">
                                        Max Units
                                        <span class="font-semibold text-[var(--text-primary)]">{{ effectiveMaxUnits(selectedFaculty) }}</span>
                                        <span v-if="selectedFaculty.approved_overload_units" class="text-xs text-emerald-600 dark:text-emerald-400">
                                            ({{ selectedFaculty.max_units }} + {{ selectedFaculty.approved_overload_units }} overload)
                                        </span>
                                    </p>
                                    <p class="text-[var(--text-muted)]">Current <span class="font-semibold text-[var(--text-primary)]">{{ totalLoad(selectedFaculty.id) }}</span></p>
                                    <p class="text-[var(--text-muted)]">Remaining <span class="font-semibold text-[var(--text-primary)]">{{ Math.max(effectiveMaxUnits(selectedFaculty) - totalLoad(selectedFaculty.id), 0) }}</span></p>
                                    <p v-if="selectedFaculty.pending_overload_units" class="mt-0.5 text-xs font-medium text-amber-600 dark:text-amber-400">
                                        +{{ selectedFaculty.pending_overload_units }} overload pending review
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Load Warning -->
                    <div
                        v-if="loadPercent(selectedFaculty) >= 80"
                        class="mb-6 rounded-xl border px-4 py-3 text-sm font-medium"
                        :class="
                            loadPercent(selectedFaculty) >= 100
                                ? 'border-red-500/30 bg-red-500/10 text-red-600 dark:text-red-400'
                                : 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400'
                        "
                    >
                        <template v-if="loadPercent(selectedFaculty) >= 100">
                            ⚠ This faculty member has reached or exceeded their maximum load. No further subjects can be assigned.
                        </template>
                        <template v-else>
                            ⚠ This faculty member is at {{ Math.round(loadPercent(selectedFaculty)) }}% of their maximum load.
                        </template>
                    </div>

                    <!-- Load Summary Cards -->
                    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <div class="rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Total Load</p>
                            <p class="mt-1 text-xl font-bold text-[var(--text-primary)]">
                                {{ totalLoad(selectedFaculty.id) }} / {{ effectiveMaxUnits(selectedFaculty) }}
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">Units</p>
                        </div>
                        <div class="rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Major Load</p>
                            <p class="mt-1 text-xl font-bold text-[var(--text-primary)]">{{ majorLoad(selectedFaculty.id) }}</p>
                            <p class="text-xs text-[var(--text-muted)]">Units</p>
                        </div>
                        <div class="rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Minor Load</p>
                            <p class="mt-1 text-xl font-bold text-[var(--text-primary)]">{{ minorLoad(selectedFaculty.id) }}</p>
                            <p class="text-xs text-[var(--text-muted)]">Units</p>
                        </div>
                        <div class="rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Remaining Capacity</p>
                            <p class="mt-1 text-xl font-bold text-[var(--text-primary)]">
                                {{ Math.max(effectiveMaxUnits(selectedFaculty) - totalLoad(selectedFaculty.id), 0) }}
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">Units</p>
                        </div>
                    </div>

                    <!-- Load Progress Bar -->
                    <div class="mb-8 rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-4 shadow-sm">
                        <div class="mb-1.5 flex items-center justify-between text-xs font-medium text-[var(--text-muted)]">
                            <span>Load Progress</span>
                            <span>{{ Math.round(loadPercent(selectedFaculty)) }}%</span>
                        </div>
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-[var(--page-bg)]">
                            <div
                                class="h-full rounded-full transition-all duration-300"
                                :class="
                                    loadPercent(selectedFaculty) >= 100
                                        ? 'bg-red-500'
                                        : loadPercent(selectedFaculty) >= 80
                                        ? 'bg-amber-500'
                                        : 'bg-emerald-500'
                                "
                                :style="{ width: `${Math.min(loadPercent(selectedFaculty), 100)}%` }"
                            ></div>
                        </div>
                    </div>

                    <!-- Assigned Subjects -->
                    <div class="rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-sm">
                        <div class="flex items-center justify-between border-b border-[var(--card-border)] px-6 py-4">
                            <h3 class="text-base font-bold text-[var(--text-primary)]">Assigned Subjects</h3>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    :disabled="!selectedFaculty.status || selectedFaculty.available_overload_units === 0"
                                    class="inline-flex items-center justify-center rounded-full bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors duration-150 hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                                    :title="selectedFaculty.available_overload_units === 0 ? 'Already at the overload cap' : ''"
                                    @click="openOverloadModal"
                                >
                                    + Add Units
                                </button>
                                <button
                                    type="button"
                                    :disabled="!planningTerm || loadPercent(selectedFaculty) >= 100 || !selectedFaculty.status"
                                    class="btn-save"
                                    @click="openAssignModal"
                                >
                                    + Manage Load
                                </button>
                            </div>
                        </div>

                        <p v-if="!planningTerm" class="px-6 py-6 text-sm text-[var(--text-muted)]">
                            No active academic term is set — activate a term before assigning subjects.
                        </p>

                        <div v-else>
                            <table class="w-full table-fixed divide-y divide-[var(--card-border)]">
                                <thead class="bg-[var(--page-bg)]">
                                    <tr>
                                        <th class="w-[9%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Code</th>
                                        <th class="w-[19%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Subject</th>
                                        <th class="w-[9%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Offering</th>
                                        <th class="w-[8%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Program</th>
                                        <th class="w-[6%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Year</th>
                                        <th class="w-[6%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Sem</th>
                                        <th class="w-[7%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Units</th>
                                        <th class="w-[8%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Type</th>
                                        <th class="w-[14%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Room</th>
                                        <th class="w-[10%] px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Schedule</th>
                                        <th class="w-[7%] px-2 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[var(--card-border)]">
                                    <tr v-if="selectedAssignments.length === 0">
                                        <td colspan="11" class="px-4 py-10 text-center text-sm text-[var(--text-muted)]">
                                            No subjects assigned yet. Use "Assign Subject" to build this faculty member's load.
                                        </td>
                                    </tr>
                                    <tr v-for="assignment in selectedAssignments" :key="assignment.id" class="hover:bg-[var(--page-bg)]">
                                        <td class="whitespace-normal break-words px-2 py-5 align-middle text-xs text-[var(--text-primary)]">
                                            {{ assignment.subject_offering?.edp_code ?? '—' }}
                                        </td>
                                        <td class="whitespace-normal break-words px-2 py-5 align-middle text-xs text-[var(--text-primary)]">
                                            {{ assignment.subject_offering?.subject?.descriptive_title ?? '—' }}
                                        </td>
                                        <td class="whitespace-normal break-words px-2 py-5 align-middle text-xs text-[var(--text-primary)]">
                                            {{ assignment.subject_offering?.section?.section_code }}
                                        </td>
                                        <td class="whitespace-normal break-words px-2 py-5 align-middle text-xs text-[var(--text-primary)]">
                                            {{ assignment.subject_offering?.section?.curriculum?.program?.code ?? '—' }}
                                        </td>
                                        <td class="whitespace-normal break-words px-2 py-5 align-middle text-xs text-[var(--text-primary)]">
                                            {{ assignment.subject_offering?.year_level ?? '—' }}
                                        </td>
                                        <td class="whitespace-normal break-words px-2 py-5 align-middle text-xs text-[var(--text-primary)]">
                                            {{ assignment.subject_offering?.semester ?? '—' }}
                                        </td>
                                        <td class="whitespace-normal break-words px-2 py-5 align-middle text-xs text-[var(--text-primary)]">
                                            {{ unitsOf(assignment) }}
                                        </td>
                                        <td class="whitespace-normal break-words px-2 py-5 align-middle">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="isMajorAssignment(assignment) ? 'bg-purple-500/10 text-purple-600 dark:text-purple-400' : 'bg-sky-500/10 text-sky-600 dark:text-sky-400'"
                                            >
                                                {{ isMajorAssignment(assignment) ? 'Major' : 'Minor' }}
                                            </span>
                                        </td>
                                        <td
                                            class="whitespace-normal break-words px-2 py-5 align-middle text-xs"
                                            :class="scheduleOf(assignment)?.room ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'"
                                            :title="!scheduleOf(assignment)?.room && roomLabel(preferredRoomOf(assignment)) ? `${roomLabel(preferredRoomOf(assignment))} — preferred, not yet a finalized schedule` : null"
                                        >
                                            {{ displayRoomOf(assignment) ?? '—' }}
                                        </td>
                                        <td class="whitespace-normal break-words px-2 py-5 align-middle text-xs">
                                            <template v-if="scheduleParts(scheduleOf(assignment))">
                                                <p class="font-semibold text-[var(--text-primary)]">{{ scheduleParts(scheduleOf(assignment)).day }}</p>
                                                <p class="text-[var(--text-muted)]">{{ scheduleParts(scheduleOf(assignment)).time }}</p>
                                            </template>
                                            <span v-else class="text-[var(--text-muted)]">Not yet scheduled</span>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-5 align-middle text-right text-sm">
                                            <button
                                                type="button"
                                                class="btn-delete inline-flex items-center justify-center !p-2"
                                                title="Remove"
                                                aria-label="Remove assignment"
                                                @click="removeAssignment(assignment)"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                                    <polyline points="3 6 5 6 21 6"></polyline>
                                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                                    <path d="M10 11v6"></path>
                                                    <path d="M14 11v6"></path>
                                                    <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </main>
        </div>

        <!-- ==================== ASSIGN SUBJECT MODAL ==================== -->
        <AssignSubjectModal
            v-if="showAssignModal && selectedFaculty"
            :faculty="selectedFaculty"
            :offerings="modalOfferings"
            :assigned-offering-ids="new Set(assignmentByOfferingId.keys())"
            :current-load="totalLoad(selectedFaculty.id)"
            :check-eligibility="checkEligibility"
            :error="assignError"
            :success="assignSuccess"
            :assigning-offering-id="assigningOfferingId"
            @close="closeAssignModal"
            @assign="handleAssign"
            @unassign="handleUnassign"
        />

        <!-- ==================== OVERLOAD REQUEST MODAL ==================== -->
        <OverloadRequestModal
            v-if="showOverloadModal && selectedFaculty"
            :faculty="selectedFaculty"
            :is-unscoped="isAdminOrRegistrar"
            @close="closeOverloadModal"
        />
    </AppLayout>
</template>