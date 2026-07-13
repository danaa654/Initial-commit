<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    department: Object,
    facultyMembers: Array,
    academicTerm: Object,
})
</script>

<template>
    <AppLayout>
        <div class="p-8">
            <Link :href="route('block-schedule.faculty')" class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800">
                &lsaquo; Back to Departments
            </Link>

            <div class="mb-6 flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ department.code }} — Faculty</h1>
                    <p class="text-sm font-medium text-slate-400">
                        {{ department.name }}
                        <span v-if="academicTerm"> — {{ academicTerm.display_name }}</span>
                    </p>
                </div>

                <a
                    :href="department.is_general
                        ? route('block-schedule.faculty.general.print')
                        : route('block-schedule.faculty.list.print', department.id)"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-slate-700"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a1 1 0 001-1v-4a1 1 0 00-1-1H9a1 1 0 00-1 1v4a1 1 0 001 1zm8-12V5a1 1 0 00-1-1H8a1 1 0 00-1 1v4h10z" />
                    </svg>
                    Print
                </a>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="faculty in facultyMembers"
                    :key="faculty.id"
                    :href="department.is_general
                        ? route('block-schedule.faculty.general.show', faculty.id)
                        : route('block-schedule.faculty.show', [department.id, faculty.id])"
                    class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                >
                    <div>
                        <p class="text-base font-bold text-slate-900">{{ faculty.full_name }}</p>
                        <p class="text-xs text-slate-400">{{ faculty.assignment_count }} subject(s) assigned</p>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </Link>
            </div>

            <p v-if="!facultyMembers.length" class="mt-10 text-center text-sm text-slate-400">
                No faculty have Teaching Assignments in this department for the current term yet.
            </p>
        </div>
    </AppLayout>
</template>