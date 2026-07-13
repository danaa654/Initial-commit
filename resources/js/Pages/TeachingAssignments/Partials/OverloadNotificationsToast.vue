<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';

// Reads directly from the shared Inertia prop — no props needed, so
// this can be dropped into any layout/page and it just works.
const notifications = computed(() => usePage().props.overloadNotifications ?? []);

function dismiss(notification) {
    router.post(
        route('faculty-load-overloads.notifications.read', notification.id),
        {},
        { preserveScroll: true, preserveState: true }
    );
}
</script>

<template>
    <div v-if="notifications.length" class="fixed bottom-4 right-4 z-[60] flex w-full max-w-sm flex-col gap-2">
        <div
            v-for="notification in notifications"
            :key="notification.id"
            class="rounded-xl border p-4 shadow-lg"
            :class="
                notification.data.status === 'approved'
                    ? 'border-emerald-300/50 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-950/40'
                    : 'border-red-300/50 bg-red-50 dark:border-red-500/30 dark:bg-red-950/40'
            "
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p
                        class="text-sm font-semibold"
                        :class="notification.data.status === 'approved' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'"
                    >
                        Overload Request {{ notification.data.status === 'approved' ? 'Approved' : 'Declined' }}
                    </p>
                    <p class="mt-1 text-xs text-[var(--text-secondary)]">{{ notification.data.message }}</p>
                    <p v-if="notification.data.decline_reason" class="mt-1 text-xs italic text-[var(--text-muted)]">
                        "{{ notification.data.decline_reason }}"
                    </p>
                    <p v-if="notification.data.reviewed_by_name" class="mt-1 text-xs text-[var(--text-muted)]">
                        Reviewed by {{ notification.data.reviewed_by_name }}
                    </p>
                </div>
                <button
                    type="button"
                    class="flex-shrink-0 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                    aria-label="Dismiss"
                    @click="dismiss(notification)"
                >
                    <XMarkIcon class="h-4 w-4" />
                </button>
            </div>
        </div>
    </div>
</template>