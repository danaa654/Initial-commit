<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Form from './Partials/Form.vue';
import { UserGroupIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    teachingAssignment: { type: Object, required: true },
    academicTerms: { type: Array, required: true },
    sections: { type: Array, required: true },
    curriculumItems: { type: Array, required: true },
    faculties: { type: Array, required: true },
    facultySubjects: { type: Array, required: true },
});

const form = useForm({
    academic_term_id: props.teachingAssignment.academic_term_id,
    section_id: props.teachingAssignment.section_id,
    curriculum_item_id: props.teachingAssignment.curriculum_item_id,
    faculty_id: props.teachingAssignment.faculty_id,
    remarks: props.teachingAssignment.remarks ?? '',
    active: props.teachingAssignment.active,
});

const submit = () => {
    form.put(route('teaching-assignments.update', props.teachingAssignment.id));
};
</script>

<template>
    <AppLayout>
        <Head title="Configure Faculty Load" />

        <div class="relative mx-auto max-w-2xl px-8 py-8">

            <!-- Subtle brand texture: faint grid + one soft gold glow, static (no animation) -->
            <div class="pointer-events-none absolute -inset-x-6 -inset-y-6 -z-10 overflow-hidden">
                <div
                    class="absolute inset-0 opacity-[0.03] dark:opacity-[0.05]"
                    style="background-image: linear-gradient(#1e3a5f 1px, transparent 1px), linear-gradient(90deg, #1e3a5f 1px, transparent 1px); background-size: 42px 42px;"
                ></div>
                <div class="absolute -top-16 right-0 h-64 w-64 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
            </div>

            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                        <UserGroupIcon class="h-5.5 w-5.5" />
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">Configure Faculty Load</h1>
                        <p class="text-sm text-[var(--text-muted)]">
                            Update the assigned faculty, section, or remarks for this subject load.
                        </p>
                    </div>
                </div>
                <Link
                    :href="route('teaching-assignments.index')"
                    class="inline-flex items-center gap-1.5 text-sm text-[var(--text-secondary)] transition-colors duration-150 hover:text-[var(--text-primary)]"
                >
                    <ArrowLeftIcon class="h-4 w-4" />
                    Back
                </Link>
            </div>

            <form
                @submit.prevent="submit"
                class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] p-6 shadow-lg transition-colors duration-300"
            >

                <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

                <Form
                    :form="form"
                    :academic-terms="academicTerms"
                    :sections="sections"
                    :curriculum-items="curriculumItems"
                    :faculties="faculties"
                    :faculty-subjects="facultySubjects"
                />

                <div class="mt-8 flex justify-end gap-3">
                    <Link
                        :href="route('teaching-assignments.index')"
                        class="btn-neutral"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="btn-save"
                    >
                        {{ form.processing ? 'Saving...' : 'Update Assignment' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>