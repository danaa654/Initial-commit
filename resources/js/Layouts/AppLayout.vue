<script setup>
import Sidebar from '@/Components/Sidebar.vue';
import Topbar from '@/Components/Topbar.vue';
import Toast from '@/Components/Toast.vue';
import SemesterTransitionBanner from '@/Components/SemesterTransitionBanner.vue';
import { useFlashToast } from '@/Composables/useFlashToast';

const { toast } = useFlashToast();
</script>

<template>
    <div id="layout-shell">
        <Sidebar />

        <div id="layout-content">
            <Topbar />

            <!-- Admin/Registrar-only "Semester Ended" prompt — renders
                 nothing (v-if inside the component) unless the Active
                 Academic Term has actually passed its Class End date.
                 See SemesterTransitionService / HandleInertiaRequests'
                 'semesterTransition' shared prop. -->
            <SemesterTransitionBanner />

            <!-- Themed scrollable content area — everything the page
                 passes into the default slot renders here, below the dark
                 sidebar/topbar shell. Switches with light/dark mode via
                 the --page-bg token (see app-shell.css), same palette
                 Welcome.vue uses on the public landing page. -->
            <main
                class="flex-1 overflow-y-auto custom-scrollbar p-6 transition-colors duration-300"
                style="background: var(--page-bg); color: var(--text-primary)"
            >
                <slot />
            </main>
        </div>

        <Toast :toast="toast" />
    </div>
</template>