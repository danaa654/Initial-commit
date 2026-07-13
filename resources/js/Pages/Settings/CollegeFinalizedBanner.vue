<script setup>
/**
 * Drop into any of the four scheduling modules (Master Grid, Faculty
 * Loading, Subject Offerings, Teaching Assignments) for the college
 * currently being viewed, alongside PlanningTermBanner.vue. Pass the
 * `finalized` boolean each of those controllers now shares for the
 * college in view — see TermFinalizationService::abortIfDepartmentFinalized(),
 * which is the actual server-side enforcement this banner is just
 * announcing.
 */
defineProps({
    finalized: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div
        v-if="finalized"
        class="mb-4 flex items-center justify-between rounded-md border border-purple-800/50 bg-purple-500/10 px-4 py-3"
    >
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-purple-300">
                Finalized &bull; Read-Only
            </p>
            <p class="text-sm font-semibold text-purple-100">
                This college's schedule is finalized and read-only. An Admin/Registrar can
                unfinalize it in Settings &raquo; Scheduling Workspace.
            </p>
        </div>
    </div>
</template>