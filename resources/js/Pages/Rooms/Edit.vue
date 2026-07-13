<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import { BuildingOffice2Icon, ArrowLeftIcon } from '@heroicons/vue/24/outline'

defineOptions({
    layout: DashboardLayout,
})

const props = defineProps({
    room: Object,
    roomGroupOptions: {
        type: Array,
        default: () => ['General', 'BSIT', 'BSED', 'BSHM', 'BSTM', 'BSCRIM'],
    },
})

const form = useForm({
    room_code: props.room.room_code,
    room_type: props.room.room_type,
    // Seeded from the room's current programs (room_group_codes is
    // appended server-side from the room_group_room pivot rows).
    room_groups: [...(props.room.room_group_codes ?? [])],
    building: props.room.building,
    floor: props.room.floor ?? '',
    capacity: props.room.capacity,
    active: props.room.active,
})

/*
|--------------------------------------------------------------------------
| Return Query
|--------------------------------------------------------------------------
|
| The Rooms index link that brought us here carries the current
| search/filter state as a query string. Captured once on load and reused
| for every "back to the list" trip below — the update submit, and the
| Cancel / Back links — so returning to the index always lands back on the
| same filtered view instead of resetting.
|
*/

const returnQuery = window.location.search

// "General" is a Lecture-only program — it's hidden from the checklist
// whenever Room Type is Laboratory (enforced server-side too, this is
// just UX).
const roomGroupChoices = computed(() => {
    if (form.room_type === 'Laboratory') {
        return props.roomGroupOptions.filter(option => option !== 'General')
    }

    return props.roomGroupOptions
})

function toggleRoomGroup(option) {
    const index = form.room_groups.indexOf(option)

    if (index === -1) {
        // General can't be combined with anything else, so selecting it
        // clears any other selection; selecting a department drops
        // General from whatever was already picked.
        if (option === 'General') {
            form.room_groups = ['General']
        } else {
            form.room_groups = form.room_groups.filter(o => o !== 'General')
            form.room_groups.push(option)
        }
    } else {
        form.room_groups.splice(index, 1)
    }
}

// Keeps the program selection in sync with Room Type. The backend
// enforces all of this too (a disabled/tampered field can't smuggle in a
// bad value), but mirroring it here keeps the form from ever showing/
// submitting a selection that doesn't make sense for the selected room
// type. immediate: true also runs this once on mount, so a Laboratory
// room loaded here with a stale "General" (from before this rule
// existed) gets cleaned up as soon as the form opens.
watch(() => form.room_type, (newType) => {
    if (newType === 'Laboratory') {
        form.room_groups = form.room_groups.filter(option => option !== 'General')
    }
}, { immediate: true })

function submit() {
    form.put(route('rooms.update', props.room.id) + returnQuery)
}
</script>

<template>

<Head title="Edit Room" />

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
                    Edit Room
                </h1>
                <p class="text-sm text-[var(--text-muted)]">
                    Update {{ room.room_code }}
                </p>
            </div>
        </div>

        <Link
            :href="route('rooms.index') + returnQuery"
            class="inline-flex items-center gap-1.5 text-sm text-[var(--text-secondary)] transition-colors duration-150 hover:text-[var(--text-primary)]"
        >
            <ArrowLeftIcon class="h-4 w-4" />
            Back to Rooms
        </Link>

    </div>

    <!-- Form -->

    <form
        @submit.prevent="submit"
        class="relative overflow-hidden mx-auto max-w-3xl bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-lg p-6 space-y-6 transition-colors duration-300"
    >

        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

        <!-- Room Code / Room Type -->

        <div class="grid grid-cols-2 gap-4">

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Room Code
                </label>

                <input
                    v-model="form.room_code"
                    type="text"
                    placeholder="e.g. Room 304 (ICT Workshop)"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />

                <p v-if="form.errors.room_code" class="text-red-500 text-sm mt-1">
                    {{ form.errors.room_code }}
                </p>

            </div>

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Room Type
                </label>

                <select
                    v-model="form.room_type"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                >
                    <option value="Lecture">Lecture</option>
                    <option value="Laboratory">Laboratory</option>
                </select>

                <p v-if="form.errors.room_type" class="text-red-500 text-sm mt-1">
                    {{ form.errors.room_type }}
                </p>

            </div>

        </div>

        <!-- Available Programs (Room Groups) -->
        <!--
            Multi-select: a room can be General (every department), Shared
            by several departments (e.g. BSHM + BSTM laboratory), or
            Exclusive to one. General can't be combined with anything else.
        -->

        <div>

            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                Available Programs
            </label>

            <p class="text-xs text-[var(--text-muted)] mb-2">
                Select General for a room usable by every department, or one
                or more specific programs for a Shared or Exclusive room.
            </p>

            <div class="flex flex-wrap gap-2 rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] p-3">

                <label
                    v-for="option in roomGroupChoices"
                    :key="option"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-full border text-sm cursor-pointer select-none transition-colors duration-150"
                    :class="form.room_groups.includes(option)
                        ? 'bg-blue-100 border-blue-300 text-blue-700'
                        : 'bg-[var(--card-bg)] border-[var(--card-border)] text-[var(--text-secondary)] hover:border-[#D4A62A]/40'"
                >
                    <input
                        type="checkbox"
                        class="rounded accent-[#D4A62A]"
                        :checked="form.room_groups.includes(option)"
                        @change="toggleRoomGroup(option)"
                    />
                    {{ option }}
                </label>

                <p v-if="form.room_type === 'Laboratory'" class="text-xs text-[var(--text-muted)] w-full">
                    Laboratory rooms must select one or more specific programs.
                </p>

            </div>

            <p v-if="form.errors.room_groups" class="text-red-500 text-sm mt-1">
                {{ form.errors.room_groups }}
            </p>

        </div>

        <!-- Location -->

        <div class="grid grid-cols-2 gap-4">

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Building
                </label>

                <input
                    v-model="form.building"
                    type="text"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />

                <p v-if="form.errors.building" class="text-red-500 text-sm mt-1">
                    {{ form.errors.building }}
                </p>

            </div>

            <div>

                <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                    Floor
                </label>

                <input
                    v-model="form.floor"
                    type="text"
                    placeholder="e.g. 2nd Floor"
                    class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                />

                <p v-if="form.errors.floor" class="text-red-500 text-sm mt-1">
                    {{ form.errors.floor }}
                </p>

            </div>

        </div>

        <!-- Capacity -->

        <div>

            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                Capacity
            </label>

            <input
                v-model.number="form.capacity"
                type="number"
                min="20"
                max="45"
                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            />

            <p class="text-xs text-[var(--text-muted)] mt-1">
                Must be between 20 and 45.
            </p>

            <p v-if="form.errors.capacity" class="text-red-500 text-sm mt-1">
                {{ form.errors.capacity }}
            </p>

        </div>

        <!-- Status -->

        <div>

            <label class="flex items-center gap-2">
                <input type="checkbox" v-model="form.active" class="rounded accent-[#D4A62A]" />
                <span class="text-sm text-[var(--text-primary)]">Active</span>
            </label>

        </div>

        <!-- Actions -->

        <div class="flex justify-end gap-3 pt-4 border-t border-[var(--card-border)]">

            <Link
                :href="route('rooms.index') + returnQuery"
                class="btn-neutral"
            >
                Cancel
            </Link>

            <button
                type="submit"
                :disabled="form.processing"
                class="btn-save"
            >
                {{ form.processing ? 'Updating...' : 'Update Room' }}
            </button>

        </div>

    </form>

</div>

</template>