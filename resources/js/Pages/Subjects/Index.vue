<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, reactive, watch } from 'vue'
import {
    BookOpenIcon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'

defineOptions({
    layout: DashboardLayout,
})

const props = defineProps({
    subjects: Object,
    filters: Object,
    roomGroupOptions: Array,
})

/*
|--------------------------------------------------------------------------
| Write Access
|--------------------------------------------------------------------------
|
| Subjects is a shared master list (GenEd/NSTP subjects span multiple
| programs at once), so Create/Edit/Delete is centralized to
| Admin/Registrar server-side — see SubjectController::middleware().
| This mirrors that same check purely so Dean/Assistant Dean/OIC (who
| can still view this list) don't see action buttons that would just
| 403 if clicked; the controller guard above is the real source of
| truth, this is only a UI convenience.
*/

const canManageSubjects = computed(() => {
    const roles = usePage().props.auth?.user?.roles ?? []

    return roles.includes('Admin') || roles.includes('Registrar')
})

/*
|--------------------------------------------------------------------------
| Filter State
|--------------------------------------------------------------------------
|
| Seeded from the `filters` prop the controller echoes back, so a page
| refresh (or a bookmarked/shared URL) restores the exact same filtered
| view instead of resetting to "all subjects".
|
| room_group here still filters against a single program at a time — it
| matches any subject that has that program among its (possibly several)
| assigned programs, via the forRoomGroup scope server-side.
|
*/

const form = reactive({
    search: props.filters.search ?? '',
    room_type: props.filters.room_type ?? '',
    classification: props.filters.classification ?? '',
    room_group: props.filters.room_group ?? '',
    status: props.filters.status ?? '',
})

/*
|--------------------------------------------------------------------------
| Push filter state to the server
|--------------------------------------------------------------------------
|
| - preserveState keeps local component state (and scroll target) intact
|   between requests instead of re-mounting the page.
| - preserveScroll stops Inertia from jumping back to the top on every
|   keystroke/selection.
| - replace avoids stacking a new browser history entry per keystroke,
|   while still keeping the final query string in the URL (bookmarkable).
| - Empty values are stripped so the URL stays clean (?search=IT instead
|   of ?search=IT&room_type=&classification=&...).
|
*/

function applyFilters(options = {}) {
    const query = {}

    for (const key in form) {
        if (form[key] !== '' && form[key] !== null) {
            query[key] = form[key]
        }
    }

    router.get(route('subjects.index'), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['subjects', 'filters'],
        ...options,
    })
}

/*
|--------------------------------------------------------------------------
| Filter Query String
|--------------------------------------------------------------------------
|
| Same non-empty-value logic as applyFilters() above, serialized to a
| "?search=NON-" style string. Appended to the Edit link and Delete
| request below so the round trip back to this page (via the controller's
| redirect) lands on the same filtered view instead of resetting.
|
| Built from `form` here rather than read from window.location directly
| in the template — referencing the bare `window` global inside a
| template expression isn't reliably resolved by Vue's compiler and can
| throw ("Cannot read properties of undefined (reading 'location')").
| Computing it here also keeps it reactive to filter changes.
|
*/

const filterQueryString = computed(() => {
    const query = {}

    for (const key in form) {
        if (form[key] !== '' && form[key] !== null) {
            query[key] = form[key]
        }
    }

    const params = new URLSearchParams(query).toString()

    return params ? `?${params}` : ''
})

/*
|--------------------------------------------------------------------------
| Debounced Search
|--------------------------------------------------------------------------
|
| Dropdown filters apply immediately (see their @change handlers below);
| only the free-text search is debounced, since it fires on every
| keystroke and would otherwise flood the server with requests.
|
*/

let searchTimeout = null

watch(() => form.search, () => {
    clearTimeout(searchTimeout)

    searchTimeout = setTimeout(() => {
        applyFilters()
    }, 350)
})

const hasActiveFilters = computed(() =>
    !!form.search || !!form.room_type || !!form.classification || !!form.room_group || !!form.status
)

function resetFilters() {
    form.search = ''
    form.room_type = ''
    form.classification = ''
    form.room_group = ''
    form.status = ''

    applyFilters()
}

function destroySubject(subject) {
    if (!confirm(`Delete ${subject.subject_code} - ${subject.descriptive_title}? This cannot be undone.`)) {
        return
    }

    // Carries the current search/filter query string along so the
    // controller's post-delete redirect lands back on the same filtered
    // view instead of resetting to "all subjects".
    router.delete(route('subjects.destroy', subject.id) + filterQueryString.value, {
        preserveScroll: true,
    })
}
</script>

<template>

<Head title="Subjects" />

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
                <BookOpenIcon class="h-5.5 w-5.5" />
            </div>
            <div>
                <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                    Subjects
                </h1>
                <p class="text-sm text-[var(--text-muted)]">
                    {{ subjects.total }} {{ subjects.total === 1 ? 'subject' : 'subjects' }} on record
                </p>
            </div>
        </div>

        <Link
            v-if="canManageSubjects"
            :href="route('subjects.create')"
            class="btn-save inline-flex items-center gap-1.5"
        >
            <PlusIcon class="h-4 w-4" />
            Add Subject
        </Link>

    </div>

    <!-- Search & Filters -->

    <div class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg p-4 mb-4 transition-colors duration-300">

        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

        <div class="flex flex-col lg:flex-row lg:items-center gap-3">

            <!-- Search -->

            <div class="relative w-full lg:flex-1">
                <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]" />
                <input
                    v-model="form.search"
                    type="text"
                    placeholder="Search by subject code or title..."
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] pl-9 pr-3 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />
            </div>

            <!-- Room Type -->

            <select
                v-model="form.room_type"
                @change="applyFilters()"
                class="w-full lg:w-44 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            >
                <option value="">All Room Types</option>
                <option value="Lecture">Lecture</option>
                <option value="Laboratory">Laboratory</option>
                <option value="Practicum">Practicum/OJT</option>
            </select>

            <!-- Classification -->

            <select
                v-model="form.classification"
                @change="applyFilters()"
                class="w-full lg:w-40 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            >
                <option value="">All Classifications</option>
                <option value="Major">Major</option>
                <option value="Minor">Minor</option>
            </select>

            <!-- Program (Room Group) -->

            <select
                v-model="form.room_group"
                @change="applyFilters()"
                class="w-full lg:w-40 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            >
                <option value="">All Programs</option>
                <option
                    v-for="option in props.roomGroupOptions"
                    :key="option"
                    :value="option"
                >
                    {{ option }}
                </option>
            </select>

            <!-- Status -->

            <select
                v-model="form.status"
                @change="applyFilters()"
                class="w-full lg:w-36 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            >
                <option value="">All Statuses</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>

            <!-- Reset -->

            <button
                @click="resetFilters"
                type="button"
                class="w-full lg:w-auto px-4 py-2.5 text-sm rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] text-[var(--text-secondary)] transition-colors duration-150 hover:text-[var(--text-primary)] whitespace-nowrap"
            >
                Reset Filters
            </button>

        </div>

    </div>

    <!-- Table -->

    <div class="relative overflow-hidden rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg transition-colors duration-300">

        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

        <div class="overflow-x-auto">

        <table class="min-w-full">

            <thead class="bg-[var(--page-bg)] border-b border-[var(--card-border)]">

                <tr>

                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Subject Code
                    </th>

                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Title
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Units
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Hours
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Classification
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Room Type
                    </th>

                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Programs
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Practicum
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Status
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Actions
                    </th>

                </tr>

            </thead>

            <tbody>

                <tr
                    v-for="subject in subjects.data"
                    :key="subject.id"
                    class="border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                >

                    <td class="px-4 py-3 font-medium text-[var(--text-primary)]">
                        {{ subject.subject_code }}
                    </td>

                    <td class="px-4 py-3 text-[var(--text-primary)]">
                        {{ subject.descriptive_title }}
                    </td>

                    <td class="px-4 py-3 text-center text-[var(--text-secondary)]">
                        {{ subject.units }}
                    </td>

                    <td class="px-4 py-3 text-center text-[var(--text-secondary)]">
                        {{ subject.total_hours }}
                    </td>

                    <td class="px-4 py-3 text-center text-[var(--text-secondary)]">
                        {{ subject.is_major ? 'Major' : 'Minor' }}
                    </td>

                    <td class="px-4 py-3 text-center text-[var(--text-secondary)]">
                        {{ subject.required_room_type }}
                    </td>

                    <!--
                        A subject can now carry several programs
                        (e.g. Business Marketing -> BSHM + BSTM), so this
                        renders one badge per assigned program instead of a
                        single value.
                    -->
                    <td class="px-4 py-3">

                        <div
                            v-if="subject.room_group_codes && subject.room_group_codes.length"
                            class="flex flex-wrap gap-1"
                        >
                            <span
                                v-for="group in subject.room_group_codes"
                                :key="group"
                                class="inline-flex px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs font-medium whitespace-nowrap"
                            >
                                {{ group }}
                            </span>
                        </div>

                        <span v-else class="text-[var(--text-muted)] text-sm">
                            —
                        </span>

                    </td>

                    <td class="px-4 py-3 text-center">

                        <span
                            v-if="subject.is_practicum"
                            class="inline-flex px-3 py-1 rounded-full bg-amber-100 text-amber-700 text-sm font-medium"
                        >
                            Yes
                        </span>

                        <span
                            v-else
                            class="inline-flex px-3 py-1 rounded-full bg-[var(--card-border)]/40 text-[var(--text-muted)] text-sm font-medium"
                        >
                            No
                        </span>

                    </td>

                    <td class="px-4 py-3 text-center">

                        <span
                            v-if="subject.active"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-100 text-green-700 text-sm font-medium"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                            Active
                        </span>

                        <span
                            v-else
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-red-100 text-red-700 text-sm font-medium"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                            Inactive
                        </span>

                    </td>

                    <td class="px-4 py-3 text-center whitespace-nowrap">

                        <template v-if="canManageSubjects">

                            <div class="flex justify-center gap-2">

                                <Link
                                    :href="route('subjects.edit', subject.id) + filterQueryString"
                                    class="btn-edit inline-flex items-center gap-1.5"
                                >
                                    <PencilSquareIcon class="h-3.5 w-3.5" />
                                    Edit
                                </Link>

                                <button
                                    @click="destroySubject(subject)"
                                    class="btn-delete inline-flex items-center gap-1.5"
                                >
                                    <TrashIcon class="h-3.5 w-3.5" />
                                    Delete
                                </button>

                            </div>

                        </template>

                        <span v-else class="text-[var(--text-muted)] text-xs">
                            View only
                        </span>

                    </td>

                </tr>

                <tr v-if="subjects.data.length === 0">

                    <td
                        colspan="10"
                        class="text-center py-8 text-[var(--text-muted)]"
                    >
                        {{ hasActiveFilters ? 'No subjects match your filters.' : 'No subjects found.' }}
                    </td>

                </tr>

            </tbody>

        </table>

        </div>

        <!-- Pagination -->

        <div
            v-if="subjects.links.length > 3"
            class="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--card-border)] px-4 py-3"
        >

            <p class="text-sm text-[var(--text-muted)]">
                Showing {{ subjects.from ?? 0 }}–{{ subjects.to ?? 0 }} of {{ subjects.total }} subjects
            </p>

            <div class="flex flex-wrap gap-1">

                <template v-for="(link, index) in subjects.links" :key="index">

                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-state
                        preserve-scroll
                        class="px-3 py-1.5 text-sm rounded-lg border transition-colors duration-150"
                        :class="link.active
                            ? 'bg-[#D4A62A] border-[#D4A62A] text-[#0B1220] font-semibold'
                            : 'border-[var(--card-border)] text-[var(--text-secondary)] hover:bg-[var(--page-bg)] hover:text-[var(--text-primary)]'"
                        v-html="link.label"
                    />

                    <span
                        v-else
                        class="px-3 py-1.5 text-sm rounded-lg border border-[var(--card-border)] text-[var(--text-muted)] cursor-not-allowed"
                        v-html="link.label"
                    />

                </template>

            </div>

        </div>

    </div>

</div>

</template>