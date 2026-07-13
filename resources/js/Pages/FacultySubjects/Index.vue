<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'

defineProps({
    facultySubjects: Array,
})

const page = usePage()

function destroy(facultySubject) {
    if (!confirm(`Remove ${facultySubject.subject.subject_code} from ${facultySubject.faculty.full_name}?`)) {
        return
    }

    router.delete(route('faculty-subjects.destroy', facultySubject.id), {
        preserveScroll: true,
    })
}
</script>

<template>
    <Head title="Faculty Subject Assignments" />

    <DashboardLayout>
        <div class="p-6">

            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800">
                        Faculty Subject Assignments
                    </h1>

                    <p class="text-sm text-slate-500 mt-1">
                        Indicate which subjects each faculty member is qualified to teach.
                    </p>
                </div>

                <Link
                    :href="route('faculty-subjects.create')"
                    class="bg-slate-900 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-700"
                >
                    + Assign Subject
                </Link>
            </div>

            <!-- Success Message -->
            <div
                v-if="page.props.flash?.success"
                class="mb-4 bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-lg"
            >
                {{ page.props.flash.success }}
            </div>

            <!-- Table -->
            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-slate-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3">Faculty Name</th>
                            <th class="px-4 py-3">Department</th>
                            <th class="px-4 py-3">Subject Code</th>
                            <th class="px-4 py-3">Subject Title</th>
                            <th class="px-4 py-3 text-center">Preferred</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3">Remarks</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="facultySubject in facultySubjects"
                            :key="facultySubject.id"
                            class="hover:bg-slate-50"
                        >
                            <td class="px-4 py-3 font-medium text-slate-800">
                                {{ facultySubject.faculty.full_name }}
                            </td>

                            <td class="px-4 py-3 text-slate-600">
                                {{ facultySubject.faculty.department?.name ?? '—' }}
                            </td>

                            <td class="px-4 py-3 font-mono text-slate-700">
                                {{ facultySubject.subject.subject_code }}
                            </td>

                            <td class="px-4 py-3 text-slate-600">
                                {{ facultySubject.subject.descriptive_title }}
                            </td>

                            <td class="px-4 py-3 text-center">
                                <span
                                    v-if="facultySubject.preferred"
                                    class="inline-block bg-amber-100 text-amber-700 text-xs font-medium px-2 py-1 rounded-full"
                                >
                                    Preferred
                                </span>
                                <span v-else class="text-slate-300">—</span>
                            </td>

                            <td class="px-4 py-3 text-center">
                                <span
                                    v-if="facultySubject.active"
                                    class="inline-block bg-green-100 text-green-700 text-xs font-medium px-2 py-1 rounded-full"
                                >
                                    Active
                                </span>
                                <span
                                    v-else
                                    class="inline-block bg-slate-100 text-slate-500 text-xs font-medium px-2 py-1 rounded-full"
                                >
                                    Inactive
                                </span>
                            </td>

                            <td class="px-4 py-3 text-slate-500">
                                {{ facultySubject.remarks ?? '—' }}
                            </td>

                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <Link
                                    :href="route('faculty-subjects.edit', facultySubject.id)"
                                    class="text-indigo-600 hover:text-indigo-800 text-sm font-medium mr-3"
                                >
                                    Edit
                                </Link>

                                <button
                                    @click="destroy(facultySubject)"
                                    class="text-red-600 hover:text-red-800 text-sm font-medium"
                                >
                                    Delete
                                </button>
                            </td>
                        </tr>

                        <tr v-if="facultySubjects.length === 0">
                            <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                No faculty subject assignments yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </DashboardLayout>
</template>