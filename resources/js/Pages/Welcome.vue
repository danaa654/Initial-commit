<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useAppShell } from '@/Composables/useAppShell';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import {
    UserGroupIcon,
    BuildingOffice2Icon,
    CalendarDaysIcon,
    ArrowPathIcon,
    ShieldCheckIcon,
    Squares2X2Icon,
} from '@heroicons/vue/24/outline';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});

const { darkMode } = useAppShell();

const capabilities = [
    {
        key: 'faculty',
        name: 'Faculty Assignment',
        description: 'Match faculty to subjects by expertise, load, and availability.',
        icon: UserGroupIcon,
        color: '#D4A62A',
    },
    {
        key: 'room',
        name: 'Room Allocation',
        description: 'Assign lecture and lab rooms with capacity and type checks built in.',
        icon: BuildingOffice2Icon,
        color: '#3B5A8A',
    },
    {
        key: 'schedule',
        name: 'Block Scheduling',
        description: 'Build section schedules and catch overlaps before they happen.',
        icon: CalendarDaysIcon,
        color: '#2FB8A0',
    },
    {
        key: 'cycle',
        name: 'Semester Lifecycle',
        description: 'Roll over, archive, and finalize terms without losing history.',
        icon: ArrowPathIcon,
        color: '#C97B2E',
    },
    {
        key: 'shield',
        name: 'Conflict-Free Checks',
        description: 'Cross-check faculty, room, and section overlaps automatically.',
        icon: ShieldCheckIcon,
        color: '#7A2E3B',
    },
    {
        key: 'dashboard',
        name: 'Role Dashboards',
        description: 'Give Admins, Registrars, Deans, and OICs the view built for their job.',
        icon: Squares2X2Icon,
        color: '#A8425A',
    },
];

const activeFeature = ref(null);
function setActive(key) {
    activeFeature.value = key;
}
function clearActive() {
    activeFeature.value = null;
}

// ===================== NAV / SCROLL SPY =====================
const navItems = [
    { key: 'home', label: 'Home' },
    { key: 'about', label: 'About' },
    { key: 'features', label: 'Features' },
];
const activeSection = ref('home');
let observer = null;

function scrollToSection(key) {
    const el = document.getElementById(key);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

onMounted(() => {
    const sections = navItems.map((n) => document.getElementById(n.key)).filter(Boolean);
    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    activeSection.value = entry.target.id;
                }
            });
        },
        { root: null, threshold: 0.5 },
    );
    sections.forEach((s) => observer.observe(s));
});

onBeforeUnmount(() => {
    if (observer) observer.disconnect();
});

// Fixed positions/timings so the drift feels organic without being random on every render
const fireflies = [
    { top: '12%', left: '8%', size: '4px', duration: '9s', delay: '0s', variant: 'a' },
    { top: '22%', left: '18%', size: '3px', duration: '7.5s', delay: '1.2s', variant: 'b' },
    { top: '68%', left: '6%', size: '5px', duration: '10.5s', delay: '2.4s', variant: 'c' },
    { top: '78%', left: '15%', size: '3px', duration: '8s', delay: '0.6s', variant: 'a' },
    { top: '15%', left: '85%', size: '4px', duration: '9.5s', delay: '1.8s', variant: 'b' },
    { top: '30%', left: '92%', size: '3px', duration: '7s', delay: '3s', variant: 'c' },
    { top: '72%', left: '88%', size: '5px', duration: '11s', delay: '0.9s', variant: 'a' },
    { top: '85%', left: '78%', size: '3px', duration: '8.5s', delay: '2.1s', variant: 'b' },
    { top: '8%', left: '45%', size: '3px', duration: '9s', delay: '2.7s', variant: 'c' },
    { top: '92%', left: '48%', size: '4px', duration: '10s', delay: '1.5s', variant: 'a' },
    { top: '45%', left: '4%', size: '3px', duration: '7.8s', delay: '0.3s', variant: 'b' },
    { top: '50%', left: '95%', size: '4px', duration: '8.2s', delay: '3.3s', variant: 'c' },
    { top: '38%', left: '60%', size: '3px', duration: '9.8s', delay: '1s', variant: 'a' },
    { top: '60%', left: '35%', size: '3px', duration: '8.8s', delay: '2.6s', variant: 'b' },
];

// Light-mode counterpart to the fireflies — soft rising bubbles.
const bubbles = [
    { left: '6%', size: '14px', duration: '12s', delay: '0s', variant: 'a' },
    { left: '14%', size: '9px', duration: '9.5s', delay: '1.5s', variant: 'b' },
    { left: '22%', size: '18px', duration: '14s', delay: '3s', variant: 'c' },
    { left: '30%', size: '10px', duration: '10.5s', delay: '0.8s', variant: 'a' },
    { left: '40%', size: '13px', duration: '11.5s', delay: '2.2s', variant: 'b' },
    { left: '48%', size: '8px', duration: '9s', delay: '4s', variant: 'c' },
    { left: '58%', size: '16px', duration: '13s', delay: '1.1s', variant: 'a' },
    { left: '66%', size: '11px', duration: '10s', delay: '2.8s', variant: 'b' },
    { left: '74%', size: '9px', duration: '9.8s', delay: '0.4s', variant: 'c' },
    { left: '82%', size: '15px', duration: '12.5s', delay: '3.4s', variant: 'a' },
    { left: '90%', size: '10px', duration: '10.8s', delay: '1.7s', variant: 'b' },
    { left: '95%', size: '12px', duration: '11s', delay: '2.5s', variant: 'c' },
    { left: '52%', size: '20px', duration: '15s', delay: '0.6s', variant: 'a' },
    { left: '18%', size: '11px', duration: '10.2s', delay: '3.8s', variant: 'b' },
];
</script>

<template>
    <Head title="Welcome">
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link
            href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap"
            rel="stylesheet"
        />
    </Head>

    <div
        class="relative flex h-screen w-full flex-col overflow-hidden bg-[#EAF6FF] text-[#16213E] antialiased transition-colors duration-500 dark:bg-[#0B1220] dark:text-[#F3EFE6] [font-family:'IBM_Plex_Sans',sans-serif]"
    >
        <!-- ===================== BACKGROUND TEXTURE ===================== -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div
                class="absolute inset-0 opacity-[0.07] dark:hidden"
                style="
                    background-image:
                        linear-gradient(#1e3a5f 1px, transparent 1px),
                        linear-gradient(90deg, #1e3a5f 1px, transparent 1px);
                    background-size: 56px 56px;
                "
            ></div>
            <div
                class="absolute inset-0 hidden opacity-[0.06] dark:block"
                style="
                    background-image:
                        linear-gradient(#3ee8c9 1px, transparent 1px),
                        linear-gradient(90deg, #3ee8c9 1px, transparent 1px);
                    background-size: 56px 56px;
                "
            ></div>
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
            <div class="absolute right-0 top-1/4 h-[28rem] w-[28rem] rounded-full bg-[#3ee8c9]/10 blur-3xl dark:bg-[#3ee8c9]/15"></div>
            <div class="absolute -bottom-32 left-1/4 h-96 w-96 rounded-full bg-[#D4A62A]/5 blur-3xl"></div>

            <span
                v-if="darkMode"
                v-for="(f, i) in fireflies"
                :key="`f-${i}`"
                class="firefly"
                :class="`firefly-${f.variant}`"
                :style="{
                    top: f.top,
                    left: f.left,
                    width: f.size,
                    height: f.size,
                    animationDuration: f.duration,
                    animationDelay: f.delay,
                }"
            ></span>

            <span
                v-if="!darkMode"
                v-for="(b, i) in bubbles"
                :key="`b-${i}`"
                class="bubble"
                :class="`bubble-${b.variant}`"
                :style="{
                    left: b.left,
                    width: b.size,
                    height: b.size,
                    animationDuration: b.duration,
                    animationDelay: b.delay,
                }"
            ></span>
        </div>

        <!-- ===================== NAVBAR ===================== -->
        <header class="relative z-30 flex w-full items-center justify-between px-6 py-4 lg:px-12">
            <button type="button" class="flex items-center gap-2" @click="scrollToSection('home')">
                <img src="/logo.png" alt="CLASSLY logo" class="h-8 w-8 rounded-full object-contain" />
                <span class="text-lg font-semibold tracking-tight text-[#16213E] [font-family:'Fraunces',serif] dark:text-[#F3EFE6]">
                    CLASSLY
                </span>
            </button>

            <nav class="hidden items-center gap-8 rounded-full border border-[#16213E]/10 bg-white/60 px-8 py-2.5 backdrop-blur-xl dark:border-[#F3EFE6]/10 dark:bg-[#151B2E]/60 md:flex">
                <button
                    v-for="item in navItems"
                    :key="item.key"
                    type="button"
                    class="relative text-sm font-medium transition-colors duration-300"
                    :class="activeSection === item.key
                        ? 'text-[#A8790E] dark:text-[#3ee8c9]'
                        : 'text-[#16213E]/70 hover:text-[#16213E] dark:text-[#F3EFE6]/70 dark:hover:text-[#F3EFE6]'"
                    @click="scrollToSection(item.key)"
                >
                    {{ item.label }}
                    <span
                        class="absolute -bottom-1 left-0 h-0.5 w-full origin-left scale-x-0 rounded-full bg-[#D4A62A] transition-transform duration-300 dark:bg-[#3ee8c9]"
                        :class="activeSection === item.key && 'scale-x-100'"
                    ></span>
                </button>
            </nav>

            <div class="flex items-center gap-3">
                <ThemeToggle />
                <Link
                    v-if="canLogin && $page.props.auth.user"
                    :href="route('dashboard')"
                    class="firefly-btn rounded-full bg-[#D4A62A] px-6 py-2 text-sm font-semibold text-[#0B1220] shadow-lg shadow-[#D4A62A]/20 transition-all duration-300 hover:scale-105 hover:bg-[#E8C766] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#D4A62A]"
                >
                    Dashboard
                </Link>
                <Link
                    v-else-if="canLogin"
                    :href="route('login')"
                    class="firefly-btn group relative inline-flex items-center gap-2 overflow-hidden rounded-full bg-gradient-to-r from-[#D4A62A] via-[#E8C766] to-[#D4A62A] bg-[length:200%_auto] px-6 py-2 text-sm font-semibold text-[#0B1220] shadow-lg shadow-[#D4A62A]/30 transition-all duration-300 hover:scale-105 hover:bg-right hover:shadow-xl hover:shadow-[#D4A62A]/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#D4A62A]"
                >
                    <span class="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-700 group-hover:translate-x-full"></span>
                    <span class="relative">Login</span>
                    <svg class="relative h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14" />
                        <path d="M13 6l6 6-6 6" />
                    </svg>
                </Link>
            </div>
        </header>

        <!-- ===================== SNAP-SCROLL SECTIONS ===================== -->
        <main class="thin-scrollbar relative z-10 flex-1 snap-y snap-mandatory overflow-y-auto scroll-smooth" style="scrollbar-gutter: stable both-edges;">
            <!-- ===================== HOME ===================== -->
            <section id="home" class="flex min-h-full w-full snap-start flex-col items-center justify-start px-6 py-10 lg:px-16">
                <div class="flex w-full flex-1 flex-col items-center justify-center">
                <div class="mx-auto flex w-full max-w-6xl flex-col items-center gap-10 lg:flex-row lg:gap-6">
                    <!-- Text -->
                    <div class="flex w-full flex-col items-center text-center lg:w-1/2 lg:items-start lg:text-left animate-[entrance_0.8s_cubic-bezier(0.16,1,0.3,1)_both]">
                        <p class="mb-2 text-sm font-medium uppercase tracking-[0.2em] text-[#A8790E] dark:text-[#3ee8c9]">
                            Your Friendly Class Scheduler
                        </p>
                        <h1 class="wave-text text-5xl font-semibold tracking-tight text-[#16213E] [font-family:'Fraunces',serif] dark:text-[#F3EFE6] sm:text-6xl lg:text-7xl">
                            <span v-for="(letter, i) in 'Welcome'.split('')" :key="i" :style="{ animationDelay: `${0.2 + i * 0.06}s` }">{{ letter }}</span>
                        </h1>
                        <p class="mt-4 max-w-md text-base leading-relaxed text-[#16213E]/75 dark:text-[#F3EFE6]/75 sm:text-lg">
                            CLASSLY brings faculty loading, room allocation, and block scheduling into one
                            conflict-free workspace built for PAP's academic terms.
                        </p>

                        <div class="mt-8 flex items-center gap-4">
                            <Link
                                v-if="canLogin"
                                :href="route('login')"
                                class="firefly-btn group relative inline-flex items-center gap-2 overflow-hidden rounded-full bg-gradient-to-r from-[#D4A62A] via-[#E8C766] to-[#D4A62A] bg-[length:200%_auto] px-10 py-3 text-sm font-semibold text-[#0B1220] shadow-lg shadow-[#D4A62A]/30 transition-all duration-300 hover:scale-105 hover:bg-right hover:shadow-xl hover:shadow-[#D4A62A]/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#D4A62A]"
                            >
                                <span class="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-700 group-hover:translate-x-full"></span>
                                <span class="relative">Login</span>
                                <svg class="relative h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 12h14" />
                                    <path d="M13 6l6 6-6 6" />
                                </svg>
                            </Link>
                            <button
                                type="button"
                                class="text-sm font-semibold text-[#16213E]/70 underline-offset-4 transition-colors hover:text-[#16213E] hover:underline dark:text-[#F3EFE6]/70 dark:hover:text-[#F3EFE6]"
                                @click="scrollToSection('about')"
                            >
                                Learn more &darr;
                            </button>
                        </div>
                    </div>

                    <!-- Scheduling illustration -->
                    <div class="illu-float-slow relative flex w-full items-center justify-center lg:w-1/2 animate-[entrance_0.8s_cubic-bezier(0.16,1,0.3,1)_0.15s_both]">
                        <img
                            src="/welcome.png"
                            alt="Classly dashboard illustration"
                            class="h-auto w-full max-w-lg select-none drop-shadow-xl"
                            draggable="false"
                        />
                    </div>
                </div>
                </div>

                <!-- ===================== FOOTER ===================== -->
                <footer class="relative z-10 mt-10 w-full border-t border-[#16213E]/10 px-6 py-2.5 text-center dark:border-[#F3EFE6]/10">
                    <p class="text-[10px] text-slate-500 dark:text-[#8B93A7] sm:text-xs">
                        &copy; 2025 Professional Academy of the Philippines &ndash; Naga Cebu &mdash;
                        CLASSLY. All Rights Reserved.
                    </p>
                    <p class="mt-0.5 text-[9px] text-slate-500/70 dark:text-[#8B93A7]/70 sm:text-[10px]">
                        Developed by &ndash; DJS
                    </p>
                </footer>
            </section>

            <!-- ===================== ABOUT ===================== -->
            <section id="about" class="flex min-h-full w-full snap-start flex-col items-center justify-start px-6 py-10 lg:px-16">
                <div class="flex w-full flex-1 flex-col items-center justify-center">
                <div class="mx-auto w-full max-w-4xl">
                    <div
                        class="holo-panel relative flex w-full flex-col items-center gap-4 overflow-hidden rounded-3xl border border-[#3ee8c9]/30 bg-white/50 p-10 text-center shadow-lg backdrop-blur-xl dark:border-[#3ee8c9]/25 dark:bg-[#151B2E]/60 lg:p-14 animate-[entrance_0.8s_cubic-bezier(0.16,1,0.3,1)_both]"
                    >
                        <!-- scanline texture + sweeping beam -->
                        <div class="holo-scan-line pointer-events-none"></div>
                        <!-- soft corner glows -->
                        <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-[#3ee8c9]/20 blur-3xl"></div>
                        <div class="pointer-events-none absolute -bottom-10 -left-10 h-32 w-32 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
                        <!-- inset ring for glass depth -->
                        <span class="pointer-events-none absolute inset-0 rounded-3xl ring-1 ring-inset ring-white/40 dark:ring-white/5"></span>

                        <div class="relative z-10 flex flex-col items-center">
                            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl border border-[#3ee8c9]/40 bg-[#3ee8c9]/10 text-[#1D9E75] dark:text-[#3ee8c9]">
                                <Squares2X2Icon class="h-7 w-7" />
                            </div>
                            <h2 class="text-2xl font-semibold text-[#16213E] [font-family:'Fraunces',serif] dark:text-[#F3EFE6] sm:text-3xl">
                                About the System
                            </h2>
                            <p class="mt-1 text-sm italic text-[#D4A62A] dark:text-[#3ee8c9] sm:text-base">
                                A Capstone Project in Class Scheduling.
                            </p>
                            <p class="mt-5 max-w-2xl text-sm leading-relaxed text-[#16213E]/80 dark:text-[#F3EFE6]/80 sm:text-base">
                                CLASSLY is a capstone system for class scheduling management, developed to help
                                academic institutions replace manual, spreadsheet-based scheduling with a single
                                automated workspace. It handles faculty assignment, room allocation, and block
                                scheduling while automatically checking for conflicts, giving Admins, Registrars,
                                Deans, and OICs a faster, more reliable way to plan every term.
                            </p>
                        </div>
                    </div>
                </div>
                </div>

                <!-- ===================== FOOTER ===================== -->
                <footer class="relative z-10 mt-10 w-full border-t border-[#16213E]/10 px-6 py-2.5 text-center dark:border-[#F3EFE6]/10">
                    <p class="text-[10px] text-slate-500 dark:text-[#8B93A7] sm:text-xs">
                        &copy; 2025 Professional Academy of the Philippines &ndash; Naga Cebu &mdash;
                        CLASSLY. All Rights Reserved.
                    </p>
                    <p class="mt-0.5 text-[9px] text-slate-500/70 dark:text-[#8B93A7]/70 sm:text-[10px]">
                        Developed by &ndash; DJS
                    </p>
                </footer>
            </section>

            <!-- ===================== FEATURES ===================== -->
            <section id="features" class="flex min-h-full w-full snap-start flex-col items-center justify-start px-6 py-10 lg:px-16">
                <div class="flex w-full flex-1 flex-col items-center justify-start">
                <div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
                    <div class="text-left animate-[entrance_0.8s_cubic-bezier(0.16,1,0.3,1)_both]">
                        <h2 class="text-2xl font-semibold text-[#16213E] [font-family:'Fraunces',serif] dark:text-[#F3EFE6] sm:text-3xl">
                            Classly Features
                        </h2>
                        <p class="mt-1 text-sm italic text-[#D4A62A] dark:text-[#3ee8c9] sm:text-base">
                            Smart Scheduling Made Simple.
                        </p>
                    </div>

                    <div class="grid grid-cols-3 gap-4 sm:gap-5 animate-[entrance_0.8s_cubic-bezier(0.16,1,0.3,1)_0.1s_both]">
                        <div
                            v-for="(capability, i) in capabilities"
                            :key="capability.name"
                            class="holo-card group relative h-44 w-full cursor-default overflow-hidden rounded-3xl border border-[#16213E]/10 bg-white/60 p-5 shadow-lg backdrop-blur-xl transition-all duration-300 hover:-translate-y-1 hover:border-[--hc]/50 hover:bg-white hover:shadow-[0_14px_34px_-10px_var(--hc)] dark:border-[#F3EFE6]/10 dark:bg-[#151B2E]/60 dark:hover:bg-[#151B2E] sm:h-52 lg:h-60"
                            :style="{ '--hc': capability.color + '66', animationDelay: `${0.15 + i * 0.06}s` }"
                            @mouseenter="setActive(capability.key)"
                            @mouseleave="clearActive"
                        >
                            <!-- soft corner wash in the capability color -->
                            <div
                                class="pointer-events-none absolute -right-8 -top-8 h-28 w-28 rounded-full opacity-[0.35] blur-2xl transition-opacity duration-300 group-hover:opacity-50"
                                :style="{ backgroundColor: capability.color }"
                            ></div>
                            <!-- faint watermark icon -->
                            <component
                                :is="capability.icon"
                                class="pointer-events-none absolute -bottom-5 -right-5 h-20 w-20 opacity-[0.18] transition-transform duration-500 group-hover:scale-110 group-hover:opacity-[0.25] sm:h-24 sm:w-24"
                                :style="{ color: capability.color }"
                                stroke-width="1"
                            />
                            <!-- colored top accent -->
                            <span
                                class="absolute inset-x-0 top-0 h-1.5 rounded-t-3xl transition-all duration-300 group-hover:h-2"
                                :style="{ backgroundColor: capability.color }"
                            ></span>
                            <!-- fine inset ring for depth -->
                            <span class="pointer-events-none absolute inset-0 rounded-3xl ring-1 ring-inset ring-white/40 dark:ring-white/5"></span>
                            <!-- default state: icon + name -->
                            <div
                                class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-3 text-center transition-all duration-300"
                                :class="activeFeature === capability.key ? 'opacity-0 scale-90' : 'opacity-100 scale-100'"
                            >
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-xl border transition-all duration-300 group-hover:scale-110 group-hover:shadow-[0_0_0_4px_var(--hc)] sm:h-14 sm:w-14"
                                    :style="{
                                        borderColor: capability.color + '40',
                                        backgroundColor: capability.color + '1A',
                                        color: capability.color,
                                    }"
                                >
                                    <component :is="capability.icon" class="h-6 w-6 sm:h-7 sm:w-7" />
                                </div>
                                <p class="text-sm font-semibold leading-tight text-[#16213E] dark:text-[#F3EFE6] sm:text-base">
                                    {{ capability.name }}
                                </p>
                            </div>

                            <!-- hover state: hologram + name -->
                            <div
                                class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 px-3 text-center transition-all duration-300"
                                :class="activeFeature === capability.key ? 'opacity-100 scale-100' : 'opacity-0 scale-90 pointer-events-none'"
                            >
                                <div class="relative h-16 w-16 opacity-90 sm:h-20 sm:w-20">
                                    <div class="holo-stage relative h-full w-full">
                                        <div class="holo-scan"></div>

                                        <!-- Faculty -->
                                        <svg v-if="capability.key === 'faculty'" viewBox="0 0 120 120" class="h-full w-full">
                                            <defs>
                                                <linearGradient :id="`hg-faculty-${capability.key}`" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stop-color="#5DCAA5" stop-opacity="0.9" />
                                                    <stop offset="100%" stop-color="#5DCAA5" stop-opacity="0.15" />
                                                </linearGradient>
                                            </defs>
                                            <ellipse cx="60" cy="104" rx="38" ry="6" fill="#5DCAA5" opacity="0.15" />
                                            <g opacity="0.45">
                                                <circle cx="30" cy="42" r="10" :fill="`url(#hg-faculty-${capability.key})`" stroke="#1D9E75" stroke-width="0.5" />
                                                <path d="M14 84 Q14 56 30 52 Q46 56 46 84" :fill="`url(#hg-faculty-${capability.key})`" stroke="#1D9E75" stroke-width="0.5" />
                                            </g>
                                            <g opacity="0.45">
                                                <circle cx="90" cy="42" r="10" :fill="`url(#hg-faculty-${capability.key})`" stroke="#1D9E75" stroke-width="0.5" />
                                                <path d="M74 84 Q74 56 90 52 Q106 56 106 84" :fill="`url(#hg-faculty-${capability.key})`" stroke="#1D9E75" stroke-width="0.5" />
                                            </g>
                                            <circle cx="60" cy="44" r="15" :fill="`url(#hg-faculty-${capability.key})`" stroke="#1D9E75" stroke-width="0.6" />
                                            <path d="M38 102 Q38 62 60 57 Q82 62 82 102" :fill="`url(#hg-faculty-${capability.key})`" stroke="#1D9E75" stroke-width="0.6" />
                                            <line x1="10" y1="60" x2="110" y2="60" stroke="#5DCAA5" stroke-width="0.5" opacity="0.4" />
                                            <line x1="10" y1="76" x2="110" y2="76" stroke="#5DCAA5" stroke-width="0.5" opacity="0.25" />
                                        </svg>

                                        <!-- Room -->
                                        <svg v-else-if="capability.key === 'room'" viewBox="0 0 120 120" class="h-full w-full">
                                            <defs>
                                                <linearGradient :id="`hg-room-${capability.key}`" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stop-color="#378ADD" stop-opacity="0.9" />
                                                    <stop offset="100%" stop-color="#378ADD" stop-opacity="0.15" />
                                                </linearGradient>
                                            </defs>
                                            <ellipse cx="60" cy="104" rx="38" ry="6" fill="#378ADD" opacity="0.15" />
                                            <rect x="14" y="50" width="24" height="42" :fill="`url(#hg-room-${capability.key})`" stroke="#185FA5" stroke-width="0.5" opacity="0.4" />
                                            <rect x="82" y="50" width="24" height="42" :fill="`url(#hg-room-${capability.key})`" stroke="#185FA5" stroke-width="0.5" opacity="0.4" />
                                            <rect x="36" y="36" width="48" height="66" :fill="`url(#hg-room-${capability.key})`" stroke="#185FA5" stroke-width="0.6" />
                                            <rect x="44" y="46" width="9" height="9" fill="#185FA5" opacity="0.6" />
                                            <rect x="66" y="46" width="9" height="9" fill="#185FA5" opacity="0.6" />
                                            <rect x="44" y="63" width="9" height="9" fill="#185FA5" opacity="0.6" />
                                            <rect x="66" y="63" width="9" height="9" fill="#185FA5" opacity="0.6" />
                                            <rect x="52" y="82" width="16" height="20" fill="#185FA5" opacity="0.3" />
                                            <line x1="8" y1="58" x2="112" y2="58" stroke="#378ADD" stroke-width="0.5" opacity="0.35" />
                                        </svg>

                                        <!-- Block schedule -->
                                        <svg v-else-if="capability.key === 'schedule'" viewBox="0 0 120 120" class="h-full w-full">
                                            <defs>
                                                <linearGradient :id="`hg-sched-${capability.key}`" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stop-color="#7F77DD" stop-opacity="0.9" />
                                                    <stop offset="100%" stop-color="#7F77DD" stop-opacity="0.15" />
                                                </linearGradient>
                                            </defs>
                                            <ellipse cx="60" cy="104" rx="38" ry="6" fill="#7F77DD" opacity="0.15" />
                                            <rect x="22" y="26" width="76" height="72" rx="4" :fill="`url(#hg-sched-${capability.key})`" stroke="#534AB7" stroke-width="0.5" opacity="0.85" />
                                            <line x1="22" y1="42" x2="98" y2="42" stroke="#534AB7" stroke-width="0.5" />
                                            <rect x="30" y="50" width="16" height="11" fill="#534AB7" opacity="0.5" />
                                            <rect x="52" y="50" width="16" height="11" fill="#534AB7" opacity="0.5" />
                                            <rect x="74" y="50" width="16" height="11" fill="#534AB7" opacity="0.5" />
                                            <rect x="30" y="66" width="16" height="11" fill="#534AB7" opacity="0.7" />
                                            <rect x="52" y="66" width="16" height="11" fill="#534AB7" opacity="0.5" />
                                            <rect x="74" y="66" width="16" height="11" fill="#534AB7" opacity="0.3" />
                                            <rect x="30" y="82" width="16" height="11" fill="#534AB7" opacity="0.35" />
                                            <rect x="52" y="82" width="16" height="11" fill="#534AB7" opacity="0.6" />
                                        </svg>

                                        <!-- Semester cycle -->
                                        <svg v-else-if="capability.key === 'cycle'" viewBox="0 0 120 120" class="h-full w-full">
                                            <ellipse cx="60" cy="104" rx="38" ry="6" fill="#D85A30" opacity="0.12" />
                                            <circle cx="60" cy="52" r="26" fill="#5DCAA5" opacity="0.3" />
                                            <circle cx="60" cy="52" r="30" fill="none" stroke="#D85A30" stroke-width="2" stroke-dasharray="6 5" opacity="0.9" class="holo-spin" />
                                            <path d="M83 47 L94 52 L83 57 Z" fill="#D85A30" opacity="0.9" />
                                        </svg>

                                        <!-- Conflict-free shield -->
                                        <svg v-else-if="capability.key === 'shield'" viewBox="0 0 120 120" class="h-full w-full">
                                            <defs>
                                                <linearGradient :id="`hg-shield-${capability.key}`" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stop-color="#97C459" stop-opacity="0.9" />
                                                    <stop offset="100%" stop-color="#97C459" stop-opacity="0.15" />
                                                </linearGradient>
                                            </defs>
                                            <ellipse cx="60" cy="104" rx="38" ry="6" fill="#639922" opacity="0.15" />
                                            <path d="M60 24 L92 38 V70 Q92 94 60 106 Q28 94 28 70 V38 Z" :fill="`url(#hg-shield-${capability.key})`" stroke="#3B6D11" stroke-width="0.6" opacity="0.85" />
                                            <path d="M44 66 L54 76 L78 48" fill="none" stroke="#173404" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>

                                        <!-- Role dashboard -->
                                        <svg v-else-if="capability.key === 'dashboard'" viewBox="0 0 120 120" class="h-full w-full">
                                            <defs>
                                                <linearGradient :id="`hg-dash-${capability.key}`" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stop-color="#D4537E" stop-opacity="0.9" />
                                                    <stop offset="100%" stop-color="#D4537E" stop-opacity="0.15" />
                                                </linearGradient>
                                            </defs>
                                            <ellipse cx="60" cy="104" rx="38" ry="6" fill="#D4537E" opacity="0.15" />
                                            <rect x="18" y="28" width="84" height="66" rx="4" :fill="`url(#hg-dash-${capability.key})`" stroke="#993556" stroke-width="0.5" opacity="0.85" />
                                            <rect x="28" y="38" width="24" height="30" fill="#993556" opacity="0.4" />
                                            <rect x="58" y="38" width="34" height="14" fill="#993556" opacity="0.6" />
                                            <rect x="58" y="56" width="34" height="12" fill="#993556" opacity="0.3" />
                                            <rect x="28" y="74" width="64" height="9" fill="#993556" opacity="0.5" />
                                            <rect x="28" y="87" width="36" height="9" fill="#993556" opacity="0.35" />
                                        </svg>
                                    </div>
                                </div>
                                <p class="text-sm font-semibold leading-tight text-[#16213E] dark:text-[#F3EFE6] sm:text-base">
                                    {{ capability.name }}
                                </p>
                                <p class="hidden text-xs leading-snug text-[#16213E]/70 dark:text-[#F3EFE6]/70 sm:block">
                                    {{ capability.description }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                </div>

                <!-- ===================== FOOTER ===================== -->
                <footer class="relative z-10 mt-10 w-full border-t border-[#16213E]/10 px-6 py-2.5 text-center dark:border-[#F3EFE6]/10">
                    <p class="text-[10px] text-slate-500 dark:text-[#8B93A7] sm:text-xs">
                        &copy; 2025 Professional Academy of the Philippines &ndash; Naga Cebu &mdash;
                        CLASSLY. All Rights Reserved.
                    </p>
                    <p class="mt-0.5 text-[9px] text-slate-500/70 dark:text-[#8B93A7]/70 sm:text-[10px]">
                        Developed by &ndash; DJS
                    </p>
                </footer>
            </section>
        </main>
    </div>
</template>

<style scoped>
/* ===================== THIN SCROLLBAR ===================== */
.thin-scrollbar {
    scrollbar-width: thin;
    scrollbar-color: rgba(212, 166, 42, 0.5) transparent;
}
.thin-scrollbar::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
.thin-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.thin-scrollbar::-webkit-scrollbar-thumb {
    background-color: rgba(212, 166, 42, 0.5);
    border-radius: 9999px;
}
.thin-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: rgba(212, 166, 42, 0.75);
}
.dark .thin-scrollbar {
    scrollbar-color: rgba(62, 232, 201, 0.4) transparent;
}
.dark .thin-scrollbar::-webkit-scrollbar-thumb {
    background-color: rgba(62, 232, 201, 0.4);
}
.dark .thin-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: rgba(62, 232, 201, 0.65);
}

@keyframes float {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-8px);
    }
}

/* ===================== ILLUSTRATION FLOAT ===================== */
.illu-float-slow {
    animation: illu-float 5s ease-in-out infinite;
    transform-origin: center;
}
.illu-float-fast {
    animation: illu-float 3.4s ease-in-out infinite;
    transform-origin: center;
}
@keyframes illu-float {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-10px);
    }
}

/* ===================== WAVE TEXT (title / tagline reveal) ===================== */
.wave-text {
    display: block;
}
.wave-text span {
    display: inline-block;
    opacity: 0;
    animation: wave-in 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes wave-in {
    0% {
        opacity: 0;
        transform: translateY(16px) rotate(6deg);
    }
    60% {
        opacity: 1;
    }
    100% {
        opacity: 1;
        transform: translateY(0) rotate(0deg);
    }
}
@keyframes entrance {
    from {
        opacity: 0;
        transform: translateY(18px) scale(0.97);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* ===================== HOLO CARDS ===================== */
.holo-card {
    border-radius: 1.5rem;
}

/* ===================== HOLOGRAPHIC ABOUT PANEL ===================== */
.holo-panel {
    animation: holo-flicker 3.6s ease-in-out infinite;
    filter: drop-shadow(0 0 14px rgba(62, 232, 201, 0.18));
}

.holo-scan-line {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
    background-image: repeating-linear-gradient(
        to bottom,
        rgba(62, 232, 201, 0.07) 0px,
        rgba(62, 232, 201, 0.07) 1px,
        transparent 1px,
        transparent 3px
    );
}

.holo-scan-line::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    height: 45%;
    background: linear-gradient(to bottom, transparent, rgba(62, 232, 201, 0.22), transparent);
    animation: holo-sweep-full 4.5s linear infinite;
}

@keyframes holo-sweep-full {
    0% {
        top: -45%;
    }
    100% {
        top: 100%;
    }
}

/* ===================== HOLOGRAM STAGE ===================== */
.holo-stage {
    filter: drop-shadow(0 0 10px rgba(62, 232, 201, 0.25));
    animation: holo-flicker 2.4s ease-in-out infinite;
}

.holo-scan {
    position: absolute;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, rgba(62, 232, 201, 0.65), transparent);
    animation: holo-sweep 2.2s linear infinite;
    pointer-events: none;
}

@keyframes holo-sweep {
    0% {
        top: 0%;
        opacity: 0;
    }
    10% {
        opacity: 1;
    }
    90% {
        opacity: 1;
    }
    100% {
        top: 100%;
        opacity: 0;
    }
}

@keyframes holo-flicker {
    0%,
    100% {
        opacity: 1;
    }
    92% {
        opacity: 1;
    }
    93% {
        opacity: 0.7;
    }
    94% {
        opacity: 1;
    }
    97% {
        opacity: 0.85;
    }
    98% {
        opacity: 1;
    }
}

.holo-spin {
    transform-origin: 60px 52px;
    animation: holo-rotate 3s linear infinite;
}

@keyframes holo-rotate {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

/* ===================== FIREFLIES ===================== */
.firefly {
    position: absolute;
    border-radius: 9999px;
    background: #e8c766;
    box-shadow:
        0 0 6px 2px rgba(212, 166, 42, 0.7),
        0 0 12px 4px rgba(212, 166, 42, 0.3);
    opacity: 0;
    animation-timing-function: ease-in-out;
    animation-iteration-count: infinite;
    will-change: transform, opacity;
}

.firefly-a {
    animation-name: firefly-a;
}
.firefly-b {
    animation-name: firefly-b;
}
.firefly-c {
    animation-name: firefly-c;
}

@keyframes firefly-a {
    0% {
        opacity: 0;
        transform: translate(0, 0);
    }
    15% {
        opacity: 0.9;
    }
    50% {
        opacity: 1;
        transform: translate(18px, -26px);
    }
    85% {
        opacity: 0.5;
    }
    100% {
        opacity: 0;
        transform: translate(30px, -44px);
    }
}

@keyframes firefly-b {
    0% {
        opacity: 0;
        transform: translate(0, 0);
    }
    15% {
        opacity: 0.8;
    }
    50% {
        opacity: 1;
        transform: translate(-22px, -18px);
    }
    85% {
        opacity: 0.4;
    }
    100% {
        opacity: 0;
        transform: translate(-36px, -32px);
    }
}

@keyframes firefly-c {
    0% {
        opacity: 0;
        transform: translate(0, 0);
    }
    15% {
        opacity: 0.9;
    }
    50% {
        opacity: 1;
        transform: translate(12px, 24px);
    }
    85% {
        opacity: 0.5;
    }
    100% {
        opacity: 0;
        transform: translate(20px, 40px);
    }
}

.firefly-btn {
    position: relative;
}
.firefly-btn:hover {
    box-shadow:
        0 0 0 5px rgba(212, 166, 42, 0.18),
        0 12px 34px -8px rgba(212, 166, 42, 0.5);
}

@media (prefers-reduced-motion: reduce) {
    * {
        animation: none !important;
        transition: none !important;
    }
    .firefly,
    .bubble {
        display: none;
    }
}

/* ===================== BUBBLES (light mode) ===================== */
.bubble {
    position: absolute;
    bottom: -40px;
    border-radius: 9999px;
    background: radial-gradient(
        circle at 30% 25%,
        #ffffff 0%,
        rgba(255, 255, 255, 0.9) 12%,
        rgba(219, 234, 254, 0.6) 40%,
        rgba(96, 165, 250, 0.35) 72%,
        rgba(37, 99, 235, 0.12) 100%
    );
    border: 1.5px solid rgba(255, 255, 255, 0.95);
    box-shadow:
        0 0 10px 2px rgba(37, 99, 235, 0.4),
        inset 0 0 6px rgba(255, 255, 255, 0.7);
    opacity: 0;
    animation-timing-function: ease-in-out;
    animation-iteration-count: infinite;
    will-change: transform, opacity;
}

.bubble-a {
    animation-name: bubble-a;
}
.bubble-b {
    animation-name: bubble-b;
}
.bubble-c {
    animation-name: bubble-c;
}

@keyframes bubble-a {
    0% {
        opacity: 0;
        transform: translate(0, 0) scale(0.8);
    }
    12% {
        opacity: 1;
    }
    50% {
        transform: translate(14px, -55vh) scale(1);
    }
    88% {
        opacity: 0.6;
    }
    100% {
        opacity: 0;
        transform: translate(24px, -100vh) scale(1.05);
    }
}

@keyframes bubble-b {
    0% {
        opacity: 0;
        transform: translate(0, 0) scale(0.85);
    }
    12% {
        opacity: 0.95;
    }
    50% {
        transform: translate(-16px, -55vh) scale(1);
    }
    88% {
        opacity: 0.55;
    }
    100% {
        opacity: 0;
        transform: translate(-26px, -100vh) scale(1.05);
    }
}

@keyframes bubble-c {
    0% {
        opacity: 0;
        transform: translate(0, 0) scale(0.75);
    }
    12% {
        opacity: 1;
    }
    50% {
        transform: translate(10px, -55vh) scale(1);
    }
    88% {
        opacity: 0.6;
    }
    100% {
        opacity: 0;
        transform: translate(18px, -100vh) scale(1.05);
    }
}
</style>