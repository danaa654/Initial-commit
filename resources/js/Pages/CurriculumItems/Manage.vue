<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
    ClipboardDocumentListIcon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    ArrowLeftIcon,
} from '@heroicons/vue/24/outline'

defineOptions({
    layout: DashboardLayout,
})

const props = defineProps({
    curriculum: Object,
    curriculumItems: Array,
})

const YEAR_LABELS = {
    1: 'First Year',
    2: 'Second Year',
    3: 'Third Year',
    4: 'Fourth Year',
    
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

// Subject and Practicum/OJT items both carry a subject_id now, so both
// resolve through the linked subject; `title` is kept only as a
// fallback for any legacy free-text OJT rows.
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

/*
|--------------------------------------------------------------------------
| Group items by year_level, then by semester
|--------------------------------------------------------------------------
|
| Grouping is done client-side from the flat list the controller
| sends. This keeps the backend response simple (no nested key type
| issues going through JSON) and keeps the grouping/sorting logic in
| one place.
*/

const grouped = computed(() => {
    const years = {}

    for (const item of props.curriculumItems) {
        const year = item.year_level
        const semester = item.semester

        years[year] ??= {}
        years[year][semester] ??= []
        years[year][semester].push(item)
    }

    return years
})

const yearKeys = computed(() => {
    return Object.keys(grouped.value)
        .map(Number)
        .sort((a, b) => a - b)
})

function semesterKeysFor(year) {
    return Object.keys(grouped.value[year])
        .map(Number)
        .sort((a, b) => a - b)
}

// Units only make sense for Subject items — OJT items contribute
// hours instead, tallied separately below.
function totalUnits(items) {
    return items
        .filter((item) => item.item_type === 'Subject')
        .reduce((sum, item) => sum + (item.subject?.units ?? 0), 0)
}

function totalOjtHours(items) {
    return items
        .filter((item) => item.item_type === 'OJT')
        .reduce((sum, item) => sum + (item.ojt_hours ?? 0), 0)
}

const curriculumLabel = computed(() => {
    return `${props.curriculum.code} — ${props.curriculum.name}`
})

function removeItem(item) {
    if (!confirm(`Remove ${itemLabel(item)} from this curriculum? This cannot be undone.`)) {
        return
    }

    router.delete(route('curriculum-items.destroy', item.id), {
        preserveScroll: true,
    })
}
</script>

<template>

<Head :title="`Manage Items — ${curriculum.code}`" />

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
                    Manage Items
                </h1>
                <p class="text-sm text-[var(--text-muted)]">
                    {{ curriculumLabel }}
                </p>
            </div>
        </div>

        <div class="flex items-center gap-3">

            <Link
                :href="route('curriculums.index')"
                class="inline-flex items-center gap-1 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:underline transition-colors duration-150"
            >
                <ArrowLeftIcon class="h-3.5 w-3.5" />
                Back to Curriculums
            </Link>

            <Link
                :href="route('curriculum-items.create', { curriculum_id: curriculum.id })"
                class="btn-save inline-flex items-center gap-1.5"
            >
                <PlusIcon class="h-4 w-4" />
                Add Item
            </Link>

        </div>

    </div>

    <!-- Empty State -->

    <div
        v-if="curriculumItems.length === 0"
        class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg p-12 text-center transition-colors duration-300"
    >
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[#D4A62A]/10 text-[#D4A62A]">
            <ClipboardDocumentListIcon class="h-6 w-6" />
        </div>
        <p class="font-medium text-[var(--text-secondary)]">No items yet</p>
        <p class="mt-1 text-sm text-[var(--text-muted)]">Add items to build out this curriculum's prospectus.</p>
    </div>

    <!-- Grouped Prospectus -->

    <div v-else class="space-y-8">

        <div
            v-for="year in yearKeys"
            :key="year"
            class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg transition-colors duration-300"
        >

            <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

            <div class="bg-[#1e3a5f] text-white px-5 py-3">
                <h2 class="text-lg font-bold [font-family:'Fraunces',serif]">
                    {{ yearLabel(year) }}
                </h2>
            </div>

            <div
                v-for="semester in semesterKeysFor(year)"
                :key="semester"
                class="border-t border-[var(--card-border)]"
            >

                <div class="flex justify-between items-center px-5 py-3 bg-[var(--page-bg)]">

                    <h3 class="font-semibold text-[var(--text-primary)]">
                        {{ semesterLabel(semester) }}
                    </h3>

                    <span class="text-sm text-[var(--text-muted)] space-x-3">
                        <span>{{ totalUnits(grouped[year][semester]) }} units</span>
                        <span v-if="totalOjtHours(grouped[year][semester]) > 0">
                            {{ totalOjtHours(grouped[year][semester]) }} Practicum hours
                        </span>
                    </span>

                </div>

                <table class="min-w-full">

                    <thead class="border-b border-[var(--card-border)] bg-[var(--page-bg)] text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">

                        <tr>

                            <th class="px-4 py-2 text-left">
                                Type
                            </th>

                            <th class="px-4 py-2 text-left">
                                Code
                            </th>

                            <th class="px-4 py-2 text-left">
                                Title
                            </th>

                            <th class="px-4 py-2 text-center">
                                Units / Hours
                            </th>

                            <th class="px-4 py-2 text-center">
                                Status
                            </th>

                            <th class="px-4 py-2 text-center">
                                Actions
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <tr
                            v-for="item in grouped[year][semester]"
                            :key="item.id"
                            class="group border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                        >

                            <td class="px-4 py-3 transition-shadow duration-150 group-hover:shadow-[inset_3px_0_0_#D4A62A]">

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
                                {{ item.item_type === 'Subject' ? item.subject?.units : `${item.ojt_hours} hrs` }}
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
                                        @click="removeItem(item)"
                                        class="btn-delete inline-flex items-center gap-1.5"
                                    >
                                        <TrashIcon class="h-3.5 w-3.5" />
                                        Remove
                                    </button>

                                </div>

                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

</template>