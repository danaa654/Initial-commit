<script setup>
import { computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';

const page = usePage();

const data = computed(() => page.props.semesterTransition);

const processing = computed(() => router.processing);

function closeSemester() {
    router.post(route('academic-terms.close-active'), {}, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div
        v-if="data"
        class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3"
        style="background: rgba(217, 119, 6, 0.1); border-color: rgba(217, 119, 6, 0.3);"
    >
        <div class="text-sm" style="color: var(--text-primary)">
            <span class="font-semibold text-amber-500">Semester Ended:</span>
            {{ data.activeTerm.semester_label }} &bull; SY {{ data.activeTerm.academic_year }}
            ended on {{ data.activeTerm.class_end_date }}.
            <template v-if="data.planningTerm && data.planningReady">
                {{ data.planningTerm.semester_label }} &bull; SY {{ data.planningTerm.academic_year }}
                is ready to take over.
            </template>
            <template v-else-if="data.planningTerm">
                {{ data.planningTerm.semester_label }} &bull; SY {{ data.planningTerm.academic_year }}
                is being prepared but its Class Start hasn't arrived yet — closing now will leave no
                Active Academic Term until one is activated manually.
            </template>
            <template v-else>
                No Planning Academic Term is set up yet — closing now will leave no Active Academic
                Term until one is activated manually.
            </template>
        </div>

        <button
            type="button"
            :disabled="processing"
            @click="closeSemester"
            class="inline-flex shrink-0 items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 disabled:cursor-not-allowed disabled:opacity-50"
        >
            {{ data.planningReady ? 'Archive & Activate Next Term' : 'Archive Semester' }}
        </button>
    </div>
</template>