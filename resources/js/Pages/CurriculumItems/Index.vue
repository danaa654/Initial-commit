<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import {
    ClipboardDocumentListIcon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline'

defineOptions({
    layout: DashboardLayout,
})

const props = defineProps({
    curriculumItems: Array,
})

const YEAR_LABELS = {
    1: '1st Year',
    2: '2nd Year',
    3: '3rd Year',
    4: '4th Year',
    
}

const SEMESTER_LABELS = {
    1: 'First Semester',
    2: 'Second Semester',
    3: 'Summer',
}

function yearLabel(year) {
    return YEAR_LABELS[year] ?? `Year ${year}`
}

function semesterLabel(semester) {
    return SEMESTER_LABELS[semester] ?? '—'
}

function curriculumLabel(curriculum) {
    return `${curriculum.code} — ${curriculum.name}`
}

// Falls back to computing these client-side in case the backend
// resource hasn't appended display_title / display_code. Subject and
// Practicum/OJT items both carry a subject_id now, so both resolve
// through the linked subject; `title` is kept only as a fallback for
// any legacy free-text OJT rows.
function displayTitle(item) {
    if (item.display_title !== undefined) return item.display_title
    return item.subject?.descriptive_title ?? item.title
}

function displayCode(item) {
    if (item.display_code !== undefined) return item.display_code
    return item.subject?.subject_code ?? null
}

function itemLabel(item) {
    return item.subject?.subject_code ?? item.title
}

function destroyCurriculumItem(item) {
    if (!confirm(`Remove ${itemLabel(item)} from ${item.curriculum.code}? This cannot be undone.`)) {
        return
    }

    router.delete(route('curriculum-items.destroy', item.id), {
        preserveScroll: true,
    })
}
</script>

<template>

<Head title="Curriculum Items" />

<div class="relative">

    <!-- Subtle brand texture: faint grid + one soft gold glow, static (no animation) -->
    <div class="pointer-events-none absolute -inset-x-6 -inset-y-6 -z-10 overflow-hidden">
        <div
            class="absolute inset-0 opacity-[0.03] dark:opacity-[0.05]"
            style="background-image: linear-gradient(#1e3a5f 1px, transparent 1px), linear-gradient(90deg, #1e3a5f 1px, transparent 1px); background-size: 42px 42px;"
        ></div>
        <div class="absolute -top-16 right-0 h-64 w-64 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
    </div>

    <!-- Header -->

    <div class="flex justify-between items-center mb-6">

        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D4A62A]/30 bg-[#D4A62A]/10 text-[#D4A62A]">
                <ClipboardDocumentListIcon class="h-5.5 w-5.5" />
            </div>
            <div>
                <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                    Curriculum Items
                </h1>
                <p class="text-sm text-[var(--text-muted)]">
                    Every Subject and Practicum/OJT item across all curriculums.
                </p>
            </div>
        </div>

        <Link
            :href="route('curriculum-items.create')"
            class="btn-save inline-flex items-center gap-1.5"
        >
            <PlusIcon class="h-4 w-4" />
            Add Item
        </Link>

    </div>

    <!-- Table -->

    <div class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg transition-colors duration-300">

        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

        <div class="overflow-x-auto">

        <table class="min-w-full">

            <thead class="border-b border-[var(--card-border)] bg-[var(--page-bg)]">

                <tr>

                    <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Curriculum
                    </th>

                    <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Type
                    </th>

                    <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Subject Code
                    </th>

                    <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Title
                    </th>

                    <th class="px-4 py-3 text-center text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Year
                    </th>

                    <th class="px-4 py-3 text-center text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Semester
                    </th>

                    <th class="px-4 py-3 text-center text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Status
                    </th>

                    <th class="px-4 py-3 text-center text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Actions
                    </th>

                </tr>

            </thead>

            <tbody>

                <tr
                    v-for="item in curriculumItems"
                    :key="item.id"
                    class="group border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                >

                    <td class="px-4 py-3 transition-shadow duration-150 group-hover:shadow-[inset_3px_0_0_#D4A62A]">
                        <Link
                            :href="route('curriculums.items.manage', item.curriculum.id)"
                            class="text-blue-500 hover:underline"
                        >
                            {{ curriculumLabel(item.curriculum) }}
                        </Link>
                    </td>

                    <td class="px-4 py-3">

                        <span
                            v-if="item.item_type === 'Subject'"
                            class="inline-flex px-2 py-1 rounded-full bg-blue-500/10 text-blue-600 dark:text-blue-400 text-xs font-medium"
                        >
                            Subject
                        </span>

                        <span
                            v-else
                            class="inline-flex px-2 py-1 rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400 text-xs font-medium"
                        >
                            Practicum / OJT
                        </span>

                    </td>

                    <td class="px-4 py-3">
                        <span
                            v-if="displayCode(item)"
                            class="inline-flex items-center rounded-md border border-[#D4A62A]/30 bg-[#D4A62A]/10 px-2 py-1 text-xs font-semibold text-[#A8790E] dark:text-[#E8C766]"
                        >
                            {{ displayCode(item) }}
                        </span>
                        <span v-else class="text-[var(--text-muted)]">—</span>
                    </td>

                    <td class="px-4 py-3 text-[var(--text-primary)]">
                        {{ displayTitle(item) }}
                    </td>

                    <td class="px-4 py-3 text-center text-[var(--text-secondary)]">
                        {{ yearLabel(item.year_level) }}
                    </td>

                    <td class="px-4 py-3 text-center text-[var(--text-secondary)]">
                        {{ semesterLabel(item.semester) }}
                    </td>

                    <td class="px-4 py-3 text-center">

                        <span
                            v-if="item.active"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-100 text-green-700 text-xs font-medium"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                            Active
                        </span>

                        <span
                            v-else
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-red-100 text-red-700 text-xs font-medium"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                            Inactive
                        </span>

                    </td>

                    <td class="px-4 py-3 text-center whitespace-nowrap">

                        <div class="flex justify-center gap-2">

                            <Link
                                :href="route('curriculum-items.edit', item.id)"
                                class="btn-edit inline-flex items-center gap-1.5"
                            >
                                <PencilSquareIcon class="h-3.5 w-3.5" />
                                Edit
                            </Link>

                            <button
                                @click="destroyCurriculumItem(item)"
                                class="btn-delete inline-flex items-center gap-1.5"
                            >
                                <TrashIcon class="h-3.5 w-3.5" />
                                Delete
                            </button>

                        </div>

                    </td>

                </tr>

                <tr v-if="curriculumItems.length === 0">

                    <td colspan="8" class="p-12 text-center">
                        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[#D4A62A]/10 text-[#D4A62A]">
                            <ClipboardDocumentListIcon class="h-6 w-6" />
                        </div>
                        <p class="font-medium text-[var(--text-secondary)]">No curriculum items yet</p>
                        <p class="mt-1 text-sm text-[var(--text-muted)]">Add a subject or Practicum/OJT item to get started.</p>
                    </td>

                </tr>

            </tbody>

        </table>

        </div>

    </div>

</div>

</template>