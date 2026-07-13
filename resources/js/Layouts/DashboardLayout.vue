<script setup>
import Sidebar from '@/Components/Sidebar.vue'
import Topbar from '@/Components/Topbar.vue'
import AppFooter from '@/Components/AppFooter.vue'
import SemesterTransitionBanner from '@/Components/SemesterTransitionBanner.vue'
import Toast from '@/Components/Toast.vue'
import { useFlashToast } from '@/Composables/useFlashToast'

const { toast } = useFlashToast()
</script>

<template>

<div
    class="flex h-screen transition-colors duration-300"
    style="background: var(--page-bg)"
>

    <Sidebar />

    <div class="flex-1 flex flex-col">

        <Topbar />

        <SemesterTransitionBanner />

        <main class="flex-1 overflow-y-auto p-6">
            <slot />
        </main>

        <AppFooter />

    </div>

    <Toast :toast="toast" />

</div>

</template>