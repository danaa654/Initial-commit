<script setup>
/**
 * Drop this into any scheduling page (Subject Offerings, Faculty
 * Loading, Teaching Assignments, Room Management, Master Grid,
 * Schedule Preview, etc.) so the user always knows which Academic
 * Term they're currently editing.
 *
 * Pass the `planningTerm` prop that each scheduling controller now
 * shares via SchedulingWorkspaceService — do NOT add a per-page
 * Academic Term dropdown; the whole point of the Planning Academic
 * Term is that scheduling modules never ask the user to pick one.
 */
defineProps({
    planningTerm: {
        type: Object,
        default: null,
    },
});
</script>

<template>
    <div
        v-if="planningTerm"
        class="mb-4 flex items-center justify-between rounded-md border border-indigo-800/50 bg-indigo-500/10 px-4 py-3"
    >
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-indigo-300">
                Planning Academic Term
            </p>
            <p class="text-sm font-semibold text-indigo-100">
                AY {{ planningTerm.academic_year }} &bull; {{ planningTerm.semester_label }}
            </p>
        </div>
        <span class="inline-flex items-center rounded-full bg-indigo-500/20 px-2.5 py-0.5 text-xs font-medium text-indigo-300">
            Status: Planning
        </span>
    </div>

    <div
        v-else
        class="mb-4 rounded-md border border-amber-700/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-300"
    >
        No Planning Academic Term is set. An Admin or Registrar can configure one in
        Settings &raquo; Scheduling Workspace.
    </div>
</template>