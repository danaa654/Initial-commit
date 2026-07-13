<script setup>
defineProps({
    toast: {
        type: Object,
        default: null,
    },
})

const TYPE_CLASSES = {
    success: 'bg-green-500',
    warning: 'bg-amber-500',
    error: 'bg-red-500',

    // Same red as 'error' visually, but a separate key so the message
    // can read as "you did this on purpose and it worked" rather than
    // "something went wrong" — deliberate, not a failure.
    deleted: 'bg-red-500',
}

const TYPE_ICONS = {
    success: '✅',
    warning: '⚠️',
    error: '❌',
    deleted: '🗑️',
}
</script>

<template>
    <Transition name="toast-fade">
        <div
            v-if="toast"
            class="fixed top-6 left-4 right-4 sm:left-auto sm:right-6 z-50 sm:max-w-md rounded-xl shadow-lg px-5 py-4 text-base font-medium leading-snug text-white"
            :class="TYPE_CLASSES[toast.type] ?? TYPE_CLASSES.success"
        >
            {{ TYPE_ICONS[toast.type] ?? TYPE_ICONS.success }} {{ toast.message }}
        </div>
    </Transition>
</template>

<style scoped>
.toast-fade-enter-active,
.toast-fade-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-fade-enter-from,
.toast-fade-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>