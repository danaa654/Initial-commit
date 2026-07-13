<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'

const props = defineProps({
    facultySubject: Object,
    faculties: Array,
    subjects: Array,
})

const form = useForm({
    faculty_id: props.facultySubject.faculty_id,
    subject_id: props.facultySubject.subject_id,
    preferred: props.facultySubject.preferred,
    active: props.facultySubject.active,
    remarks: props.facultySubject.remarks ?? '',
})

function submit() {
    form.put(route('faculty-subjects.update', props.facultySubject.id))
}
</script>

<template>
    <Head title="Edit Faculty Subject Assignment" />

    <DashboardLayout>
        <div class="p-6 max-w-2xl">

            <h1 class="text-2xl font-bold text-slate-800 mb-6">
                Edit Faculty Subject Assignment
            </h1>

            <form @submit.prevent="submit" class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-5">

                <!-- Faculty -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Faculty
                    </label>

                    <select
                        v-model="form.faculty_id"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-slate-800 focus:border-slate-800"
                    >
                        <option
                            v-for="faculty in faculties"
                            :key="faculty.id"
                            :value="faculty.id"
                        >
                            {{ faculty.full_name }}
                        </option>
                    </select>

                    <p v-if="form.errors.faculty_id" class="text-red-600 text-xs mt-1">
                        {{ form.errors.faculty_id }}
                    </p>
                </div>

                <!-- Subject -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Subject
                    </label>

                    <select
                        v-model="form.subject_id"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-slate-800 focus:border-slate-800"
                    >
                        <option
                            v-for="subject in subjects"
                            :key="subject.id"
                            :value="subject.id"
                        >
                            {{ subject.subject_code }} — {{ subject.descriptive_title }}
                        </option>
                    </select>

                    <p v-if="form.errors.subject_id" class="text-red-600 text-xs mt-1">
                        {{ form.errors.subject_id }}
                    </p>
                </div>

                <!-- Preferred -->
                <div class="flex items-center gap-2">
                    <input
                        id="preferred"
                        type="checkbox"
                        v-model="form.preferred"
                        class="rounded border-slate-300 text-slate-800 focus:ring-slate-800"
                    />
                    <label for="preferred" class="text-sm text-slate-700">
                        Preferred faculty for this subject
                    </label>
                </div>

                <!-- Active -->
                <div class="flex items-center gap-2">
                    <input
                        id="active"
                        type="checkbox"
                        v-model="form.active"
                        class="rounded border-slate-300 text-slate-800 focus:ring-slate-800"
                    />
                    <label for="active" class="text-sm text-slate-700">
                        Active (can currently teach this subject)
                    </label>
                </div>

                <!-- Remarks -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Remarks
                    </label>

                    <textarea
                        v-model="form.remarks"
                        rows="3"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-slate-800 focus:border-slate-800"
                        placeholder="Optional notes"
                    ></textarea>

                    <p v-if="form.errors.remarks" class="text-red-600 text-xs mt-1">
                        {{ form.errors.remarks }}
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-3 pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-slate-900 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-700 disabled:opacity-50"
                    >
                        Update Assignment
                    </button>

                    <Link
                        :href="route('faculty-subjects.index')"
                        class="text-slate-500 text-sm hover:text-slate-700"
                    >
                        Cancel
                    </Link>
                </div>

            </form>

        </div>
    </DashboardLayout>
</template>