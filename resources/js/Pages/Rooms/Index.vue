<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import Toast from '@/Components/Toast.vue'
import ManageSubjectsModal from '@/Components/Rooms/ManageSubjectsModal.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive, ref, watch } from 'vue'
import axios from 'axios'
import { useFlashToast } from '@/Composables/useFlashToast'
import {
    BuildingOffice2Icon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    Squares2X2Icon,
} from '@heroicons/vue/24/outline'

defineOptions({
    layout: DashboardLayout,
})

// Server-driven flashes (create/update/delete still redirect back here
// via Inertia, so those keep working as before) PLUS manual client-side
// flashes for the Manage Subjects modal below, which never redirects —
// show() is exactly what useFlashToast's docblock describes it for.
const { toast, show } = useFlashToast()

const props = defineProps({
    rooms: Array,
    roomGroupOptions: {
        type: Array,
        default: () => ['General', 'BSIT', 'BSED', 'BSHM', 'BSTM', 'BSCRIM'],
    },
    floorOptions: Array,
    filters: Object,
    weeklyCapacityHours: {
        type: Number,
        default: 60,
    },
    // Admin/Registrar only — see RoomController::middleware(). Drives
    // whether "+ New Room" and the per-row Edit/Delete buttons render
    // at all; Manage Subjects is unaffected, since Dean/Assistant
    // Dean/OIC still need it for their own department's preferences.
    canManageRooms: {
        type: Boolean,
        default: false,
    },
})

/*
|--------------------------------------------------------------------------
| Filter State
|--------------------------------------------------------------------------
|
| Seeded from the `filters` prop the controller echoes back, so a page
| refresh (or a bookmarked/shared URL) restores the exact same filtered
| view instead of resetting to "all rooms".
|
| room_group here filters against a single program at a time — it matches
| only rooms whose Available Programs includes that exact program, via
| the forRoomGroup scope server-side (General stays under General, not
| folded into every other filter too).
|
*/

const form = reactive({
    search: props.filters.search ?? '',
    room_type: props.filters.room_type ?? '',
    floor: props.filters.floor ?? '',
    room_group: props.filters.room_group ?? '',
})

function applyFilters() {
    const query = {}

    for (const key in form) {
        if (form[key] !== '' && form[key] !== null) {
            query[key] = form[key]
        }
    }

    router.get(route('rooms.index'), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['rooms', 'filters'],
    })
}

let searchTimeout = null

watch(() => form.search, () => {
    clearTimeout(searchTimeout)

    searchTimeout = setTimeout(() => {
        applyFilters()
    }, 350)
})

function resetFilters() {
    form.search = ''
    form.room_type = ''
    form.floor = ''
    form.room_group = ''

    applyFilters()
}

function destroyRoom(room) {
    // Mirrors RoomController::destroy()'s server-side block — this is
    // only a UX shortcut (instant feedback, no round trip) for what the
    // backend already enforces as the real source of truth; the button
    // itself is also disabled for these rooms (see the template), this
    // is just belt-and-suspenders in case it's ever triggered another way.
    if (room.scheduled_count > 0) {
        show(`${room.room_code} has ${room.scheduled_count} class(es) already scheduled via Master Grid and cannot be deleted. Reassign or delete those schedules first.`, 'error')
        return
    }

    if (!confirm(`Delete ${room.room_code}? This cannot be undone.`)) {
        return
    }

    router.delete(route('rooms.destroy', room.id), {
        preserveScroll: true,
    })
}

/*
|--------------------------------------------------------------------------
| Preferred Load
|--------------------------------------------------------------------------
|
| preferred_hours comes from RoomController::index()'s withSum — the
| total hours of this room's Preferred Subject Offerings for the ACTIVE
| Academic Term only (see Rooms/ManageSubjects.vue). null means either
| there's no active term or nothing has been preferred yet, so it's
| treated as 0 here.
*/

function preferredHours(room) {
    return room.preferred_hours ?? 0
}

function preferredCount(room) {
    return room.preferred_count ?? 0
}

// scheduled_hours/scheduled_count come from RoomController::index() too,
// but are sourced from the real `schedules` table (Master Grid's actual
// generated/saved classes) — NOT the preference pivot above. Kept as
// separate fields so "preferred" and "actually scheduled" never get
// blended into one misleading number. See RoomController::index() and
// MasterGridDataService::presentRoom() for the same distinction there.
function scheduledHours(room) {
    return room.scheduled_hours ?? 0
}

function scheduledCount(room) {
    return room.scheduled_count ?? 0
}

// The bar/percent should always match what Master Grid's Room Sidebar
// shows for the SAME room (see MasterGridDataService::presentRoom()) —
// real scheduled hours take priority once any exist. Preferred Hours is
// only a pre-scheduling wishlist and stays purely informational (the
// small line beneath) once real classes exist; it's the primary number
// ONLY for rooms nothing has been scheduled to yet.
function primaryHours(room) {
    return scheduledCount(room) > 0 ? scheduledHours(room) : preferredHours(room)
}

function utilizationPercent(room) {
    if (!props.weeklyCapacityHours) return 0
    return Math.min(100, Math.round((primaryHours(room) / props.weeklyCapacityHours) * 100))
}

function isOverCapacity(room) {
    return primaryHours(room) > props.weeklyCapacityHours
}

function remainingHours(room) {
    return Math.max(0, props.weeklyCapacityHours - primaryHours(room))
}

/*
|--------------------------------------------------------------------------
| Manage Subjects Modal
|--------------------------------------------------------------------------
|
| Opening/saving/closing this modal is entirely client-side — no
| Inertia visit happens at any point in this flow, so Index.vue itself
| never re-renders. That's what keeps filters, scroll position, and
| everything else on this page exactly as the user left it.
*/

const modalData = ref(null)   // set once the fetch below resolves; null = closed
const modalLoading = ref(false)

function openManageSubjects(room) {
    modalLoading.value = true

    axios.get(route('rooms.manage-subjects', room.id))
        .then(response => {
            modalData.value = response.data
        })
        .catch(() => {
            show('Could not load Manage Subjects for this room. Please try again.', 'error')
        })
        .finally(() => {
            modalLoading.value = false
        })
}

function closeManageSubjects() {
    modalData.value = null
}

// The modal emits the server's fresh preferred_hours/preferred_count
// for its one room — find that room in the local list and patch just
// those two fields in place. This is a deliberate, narrow mutation of a
// prop's nested object (not a reassignment of the `rooms` prop itself),
// which is how this page gets its "update only the affected row, no
// reload" behavior — Vue does not warn about this, only about
// reassigning the prop reference.
function onSubjectsSaved(payload) {
    const room = props.rooms.find(r => r.id === payload.room_id)

    if (room) {
        room.preferred_hours = payload.preferred_hours
        room.preferred_count = payload.preferred_count
    }

    show(payload.message, 'success')
    closeManageSubjects()
}
</script>

<template>

<Head title="Rooms" />

<Toast :toast="toast" />

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
                <BuildingOffice2Icon class="h-5.5 w-5.5" />
            </div>
            <div>
                <h1 class="text-3xl font-bold [font-family:'Fraunces',serif] text-[var(--text-primary)]">
                    Rooms
                </h1>
                <p class="text-sm text-[var(--text-muted)]">
                    {{ rooms.length }} {{ rooms.length === 1 ? 'room' : 'rooms' }} on record
                </p>
            </div>
        </div>

        <Link
            v-if="canManageRooms"
            :href="route('rooms.create')"
            class="btn-save inline-flex items-center gap-1.5"
        >
            <PlusIcon class="h-4 w-4" />
            Add Room
        </Link>

    </div>

    <!-- Search & Filters -->

    <div class="bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow p-4 mb-6">

        <div class="flex flex-col lg:flex-row lg:items-center gap-3">

            <!-- Search -->

            <input
                v-model="form.search"
                type="text"
                placeholder="Search by room code or building..."
                class="w-full lg:flex-1 border-[var(--card-border)] bg-[var(--page-bg)] text-[var(--text-primary)] rounded-lg text-sm focus:border-[#D4A62A] focus:ring-[#D4A62A]/30"
            />

            <!-- Room Type -->

            <select
                v-model="form.room_type"
                @change="applyFilters()"
                class="w-full lg:w-44 border-[var(--card-border)] bg-[var(--page-bg)] text-[var(--text-primary)] rounded-lg text-sm focus:border-[#D4A62A] focus:ring-[#D4A62A]/30"
            >
                <option value="">All Room Types</option>
                <option value="Lecture">Lecture</option>
                <option value="Laboratory">Laboratory</option>
            </select>

            <!-- Floor -->

            <select
                v-model="form.floor"
                @change="applyFilters()"
                class="w-full lg:w-40 border-[var(--card-border)] bg-[var(--page-bg)] text-[var(--text-primary)] rounded-lg text-sm focus:border-[#D4A62A] focus:ring-[#D4A62A]/30"
            >
                <option value="">All Floors</option>
                <option v-for="floor in floorOptions" :key="floor" :value="floor">
                    {{ floor }}
                </option>
            </select>

            <!-- Available Programs -->

            <select
                v-model="form.room_group"
                @change="applyFilters()"
                class="w-full lg:w-40 border-[var(--card-border)] bg-[var(--page-bg)] text-[var(--text-primary)] rounded-lg text-sm focus:border-[#D4A62A] focus:ring-[#D4A62A]/30"
            >
                <option value="">All Programs</option>
                <option v-for="option in roomGroupOptions" :key="option" :value="option">
                    {{ option }}
                </option>
            </select>

            <!-- Reset -->

            <button
                @click="resetFilters"
                type="button"
                class="w-full lg:w-auto px-4 py-2 text-sm rounded-lg border border-[var(--card-border)] text-[var(--text-secondary)] hover:bg-[var(--page-bg)] hover:text-[var(--text-primary)] transition-colors duration-150 whitespace-nowrap"
            >
                Reset Filters
            </button>

        </div>

    </div>

    <!-- Table -->

    <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-lg transition-colors duration-300">

        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

        <table class="min-w-full">

            <thead class="bg-[var(--page-bg)] border-b border-[var(--card-border)]">

                <tr>

                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Room Code
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Room Type
                    </th>

                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Available Programs
                    </th>

                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Building
                    </th>

                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Floor
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Capacity
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Status
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Room Load
                    </th>

                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                        Actions
                    </th>

                </tr>

            </thead>

            <tbody>

                <tr
                    v-for="room in rooms"
                    :key="room.id"
                    class="border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                >

                    <td class="px-4 py-3 font-medium text-[var(--text-primary)]">
                        {{ room.room_code }}
                    </td>

                    <td class="px-4 py-3 text-center text-[var(--text-secondary)]">
                        {{ room.room_type }}
                    </td>

                    <!--
                        A room can carry several programs (e.g. a
                        laboratory Shared by BSHM + BSTM), so this renders
                        one badge per assigned program instead of a single
                        value.
                    -->
                    <td class="px-4 py-3">

                        <div
                            v-if="room.room_group_codes && room.room_group_codes.length"
                            class="flex flex-wrap gap-1"
                        >
                            <span
                                v-for="group in room.room_group_codes"
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

                    <td class="px-4 py-3 text-[var(--text-secondary)]">
                        {{ room.building }}
                    </td>

                    <td class="px-4 py-3 text-[var(--text-secondary)]">
                        {{ room.floor ?? '—' }}
                    </td>

                    <td class="px-4 py-3 text-center text-[var(--text-secondary)]">
                        {{ room.capacity }}
                    </td>

                    <td class="px-4 py-3 text-center">

                        <span
                            v-if="room.active"
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

                    <td class="px-4 py-3">

                        <div class="flex flex-col items-center gap-1">

                            <!--
                                Same wording/format as the Master Grid
                                Room Sidebar: a dot + "X hrs scheduled ·
                                Y classes" once anything real exists.
                                This — not Preferred Hours — is what
                                drives the big number and bar below.
                            -->
                            <span
                                v-if="scheduledCount(room) > 0"
                                class="inline-flex items-center gap-1 text-[10px] font-semibold text-green-600 dark:text-green-400 whitespace-nowrap"
                            >
                                ● {{ scheduledHours(room) }} hrs scheduled · {{ scheduledCount(room) }} class{{ scheduledCount(room) === 1 ? '' : 'es' }}
                            </span>

                            <span
                                class="text-xs font-semibold whitespace-nowrap"
                                :class="isOverCapacity(room) ? 'text-red-500' : 'text-[var(--text-primary)]'"
                            >
                                {{ primaryHours(room) }} / {{ weeklyCapacityHours }} hrs · {{ utilizationPercent(room) }}%
                            </span>

                            <span class="text-[10px] text-[var(--text-muted)] whitespace-nowrap">
                                Remaining: {{ remainingHours(room) }} hrs
                            </span>

                            <span
                                v-if="preferredCount(room) > 0"
                                class="text-[10px] text-[var(--text-muted)] whitespace-nowrap"
                            >
                                {{ preferredHours(room) }} hrs / {{ preferredCount(room) }} subject{{ preferredCount(room) === 1 ? '' : 's' }} preferred
                            </span>

                            <div class="w-20 h-1.5 rounded-full bg-[var(--page-bg)] overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    :class="isOverCapacity(room) ? 'bg-red-500' : (scheduledCount(room) > 0 ? 'bg-green-500' : 'bg-[#D4A62A]')"
                                    :style="{ width: utilizationPercent(room) + '%' }"
                                />
                            </div>

                        </div>

                    </td>

                    <td class="px-4 py-3 text-center whitespace-nowrap">

                        <div class="flex justify-center gap-2">

                            <button
                                @click="openManageSubjects(room)"
                                type="button"
                                :disabled="modalLoading"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-[#D4A62A] text-white shadow-sm hover:bg-[#b8901f] transition-colors duration-150 whitespace-nowrap disabled:opacity-50"
                            >
                                <Squares2X2Icon class="h-3.5 w-3.5" />
                                Manage Subjects
                            </button>

                            <template v-if="canManageRooms">
                                <Link
                                    :href="route('rooms.edit', room.id)"
                                    class="btn-edit inline-flex items-center gap-1.5"
                                >
                                    <PencilSquareIcon class="h-3.5 w-3.5" />
                                    Edit
                                </Link>

                                <button
                                    @click="destroyRoom(room)"
                                    :disabled="room.scheduled_count > 0"
                                    :title="room.scheduled_count > 0
                                        ? `${room.room_code} has ${room.scheduled_count} class(es) already scheduled via Master Grid — reassign or delete those schedules first.`
                                        : null"
                                    class="btn-delete inline-flex items-center gap-1.5 disabled:opacity-40 disabled:cursor-not-allowed"
                                >
                                    <TrashIcon class="h-3.5 w-3.5" />
                                    Delete
                                </button>
                            </template>

                        </div>

                    </td>

                </tr>

                <tr v-if="rooms.length === 0">

                    <td
                        colspan="9"
                        class="text-center py-8 text-[var(--text-muted)]"
                    >
                        No rooms found.
                    </td>

                </tr>

            </tbody>

        </table>

    </div>

    <ManageSubjectsModal
        v-if="modalData"
        :initial-data="modalData"
        @close="closeManageSubjects"
        @saved="onSubjectsSaved"
    />

</div>

</template>