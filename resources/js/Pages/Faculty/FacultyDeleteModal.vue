<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

const props = defineProps({
    show: { type: Boolean, default: false },
    faculty: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const loading = ref(false)
const deleting = ref(false)
const previewError = ref(null)
const deleteError = ref(null)
const schedules = ref([])

watch(() => props.show, (visible) => {
    if (visible && props.faculty) {
        loadPreview()
    } else {
        schedules.value = []
        previewError.value = null
        deleteError.value = null
    }
})

function loadPreview() {
    loading.value = true
    previewError.value = null

    axios.get(route('faculty.delete-preview', props.faculty.id))
        .then(({ data }) => {
            schedules.value = data.schedules
        })
        .catch(() => {
            previewError.value = 'Failed to load this faculty member\'s current schedule.'
        })
        .finally(() => {
            loading.value = false
        })
}

// Minutes-since-midnight -> "7:30 AM" — same shape Schedule stores
// start_minutes/end_minutes in, mirrored here purely for display.
function formatTime(minutes) {
    if (minutes == null) return ''

    const hours24 = Math.floor(minutes / 60)
    const mins = minutes % 60
    const period = hours24 >= 12 ? 'PM' : 'AM'
    const hours12 = hours24 % 12 === 0 ? 12 : hours24 % 12

    return `${hours12}:${String(mins).padStart(2, '0')} ${period}`
}

function confirmDelete() {
    deleting.value = true
    deleteError.value = null

    router.delete(route('faculty.destroy', props.faculty.id), {
        data: { confirmed: true },
        onSuccess: () => emit('close'),
        onError: () => {
            deleteError.value = 'Failed to delete this faculty member. Please try again.'
        },
        onFinish: () => {
            deleting.value = false
        },
    })
}

function close() {
    if (deleting.value) return
    emit('close')
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="relative overflow-hidden bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow-xl w-full max-w-lg max-h-[85vh] flex flex-col">

            <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-[#D4A62A] to-transparent"></div>

            <div class="flex items-center justify-between px-5 py-4 border-b border-[var(--card-border)]">
                <div>
                    <h3 class="font-semibold text-lg text-[var(--text-primary)]">Delete Faculty Member</h3>
                    <p class="text-xs text-[var(--text-muted)]">{{ faculty?.full_name }}</p>
                </div>
                <button type="button" class="text-[var(--text-muted)] hover:text-[var(--text-primary)]" @click="close">✕</button>
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-4">

                <p v-if="loading" class="py-10 text-center text-sm text-[var(--text-muted)]">
                    Checking this faculty member's current schedule…
                </p>

                <p v-else-if="previewError" class="text-sm text-red-500">
                    Couldn't confirm whether this faculty member has scheduled classes. Please try again
                    before deleting — proceeding blind risks orphaning a class on the Master Grid.
                </p>

                <template v-else>

                    <p v-if="schedules.length === 0" class="text-sm text-[var(--text-secondary)]">
                        This faculty member has no scheduled classes. They can be safely deleted.
                    </p>

                    <div v-else>

                        <div class="rounded-xl bg-amber-500/10 border border-amber-500/30 px-3 py-2.5 mb-4">
                            <p class="text-sm font-semibold text-amber-600 dark:text-amber-300">
                                This faculty member currently has {{ schedules.length }} scheduled
                                {{ schedules.length === 1 ? 'class' : 'classes' }}.
                            </p>
                            <p class="text-xs text-amber-600/80 dark:text-amber-300/80 mt-0.5">
                                Deleting them will not automatically remove these classes from the Master Grid.
                                Review the list below before continuing.
                            </p>
                        </div>

                        <ul class="space-y-1.5">
                            <li
                                v-for="schedule in schedules"
                                :key="schedule.id"
                                class="rounded-xl border border-[var(--card-border)] px-3 py-2"
                            >
                                <p class="text-sm font-semibold text-[var(--text-primary)]">
                                    {{ schedule.subject_code }} — {{ schedule.subject_title }}
                                </p>
                                <p class="text-xs text-[var(--text-muted)]">
                                    {{ schedule.section_code }} ·
                                    {{ schedule.day }}
                                    {{ formatTime(schedule.start_minutes) }}–{{ formatTime(schedule.end_minutes) }} ·
                                    {{ schedule.room_code }}
                                    <template v-if="schedule.academic_term"> · {{ schedule.academic_term }}</template>
                                </p>
                            </li>
                        </ul>

                    </div>

                </template>

            </div>

            <div class="flex items-center justify-between gap-3 px-5 py-4 border-t border-[var(--card-border)]">
                <p v-if="deleteError" class="text-xs font-medium text-red-500">{{ deleteError }}</p>
                <div v-else></div>

                <div class="flex gap-2">
                    <button type="button" class="btn-neutral" :disabled="deleting" @click="close">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn-delete"
                        :disabled="deleting || loading || !!previewError"
                        @click="confirmDelete"
                    >
                        {{ deleting ? 'Deleting…' : (schedules.length > 0 ? 'Delete Anyway' : 'Delete') }}
                    </button>
                </div>
            </div>

        </div>
    </div>
</template>