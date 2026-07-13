<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ChevronDownIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    requests: { type: Array, required: true },
});

const open = ref(false);
const decliningId = ref(null); // id of the request currently showing its decline-reason field
const declineReason = ref('');
const busyId = ref(null); // id currently in-flight, to disable its buttons

function approve(request) {
    if (busyId.value) return;
    busyId.value = request.id;

    router.post(
        route('faculty-load-overloads.approve', request.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busyId.value = null;
            },
        }
    );
}

function startDecline(request) {
    decliningId.value = request.id;
    declineReason.value = '';
}

function cancelDecline() {
    decliningId.value = null;
    declineReason.value = '';
}

function confirmDecline(request) {
    if (busyId.value || !declineReason.value.trim()) return;
    busyId.value = request.id;

    router.post(
        route('faculty-load-overloads.decline', request.id),
        { decline_reason: declineReason.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => {
                decliningId.value = null;
                declineReason.value = '';
            },
            onFinish: () => {
                busyId.value = null;
            },
        }
    );
}
</script>

<template>
    <div v-if="requests.length" class="border-b border-[var(--card-border)] px-4 py-3">
        <button
            type="button"
            class="flex w-full items-center justify-between rounded-lg bg-amber-500/10 px-3 py-2 text-left"
            @click="open = !open"
        >
            <span class="text-xs font-semibold text-amber-600 dark:text-amber-400">
                {{ requests.length }} Overload Request{{ requests.length === 1 ? '' : 's' }} Pending
            </span>
            <ChevronDownIcon class="h-3.5 w-3.5 text-amber-600 transition-transform dark:text-amber-400" :class="{ 'rotate-180': open }" />
        </button>

        <div v-if="open" class="mt-2 max-h-72 space-y-2 overflow-y-auto custom-scrollbar-theme">
            <div
                v-for="request in requests"
                :key="request.id"
                class="rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] p-3"
            >
                <p class="text-sm font-semibold text-[var(--text-primary)]">
                    {{ request.faculty?.full_name }}
                    <span class="font-normal text-[var(--text-muted)]">+{{ request.units }} units</span>
                </p>
                <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                    Requested by {{ request.requested_by?.name ?? '—' }}
                </p>
                <p v-if="request.reason" class="mt-1 text-xs italic text-[var(--text-secondary)]">
                    "{{ request.reason }}"
                </p>

                <template v-if="decliningId === request.id">
                    <textarea
                        v-model="declineReason"
                        rows="2"
                        placeholder="Reason for declining..."
                        class="mt-2 w-full rounded-lg border border-[var(--card-border)] bg-[var(--card-bg)] px-2.5 py-1.5 text-xs text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                    ></textarea>
                    <div class="mt-2 flex justify-end gap-2">
                        <button type="button" class="btn-cancel !px-2.5 !py-1 !text-xs" @click="cancelDecline">Cancel</button>
                        <button
                            type="button"
                            class="btn-delete !px-2.5 !py-1 !text-xs disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="busyId === request.id || !declineReason.trim()"
                            @click="confirmDecline(request)"
                        >
                            Confirm Decline
                        </button>
                    </div>
                </template>
                <div v-else class="mt-2 flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn-delete !px-2.5 !py-1 !text-xs disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="busyId === request.id"
                        @click="startDecline(request)"
                    >
                        Decline
                    </button>
                    <button
                        type="button"
                        class="btn-save !px-2.5 !py-1 !text-xs disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="busyId === request.id"
                        @click="approve(request)"
                    >
                        Approve
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>