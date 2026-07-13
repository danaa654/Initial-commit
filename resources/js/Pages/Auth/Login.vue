<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useAppShell } from '@/Composables/useAppShell';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import {
    UserGroupIcon,
    BuildingOffice2Icon,
    CalendarDaysIcon,
    ArrowPathIcon,
    ShieldCheckIcon,
    Squares2X2Icon,
    EyeIcon,
    EyeSlashIcon,
} from '@heroicons/vue/24/outline';

const capabilities = [
    {
        name: 'Faculty Assignment',
        description: 'Match faculty to subjects by expertise, load, and availability.',
        icon: UserGroupIcon,
    },
    {
        name: 'Room Allocation',
        description: 'Assign lecture and lab rooms with capacity and type checks built in.',
        icon: BuildingOffice2Icon,
    },
    {
        name: 'Block Scheduling',
        description: 'Build section schedules and catch overlaps before they happen.',
        icon: CalendarDaysIcon,
    },
    {
        name: 'Semester Lifecycle',
        description: 'Roll over, archive, and finalize terms without losing history.',
        icon: ArrowPathIcon,
    },
    {
        name: 'Conflict-Free Checks',
        description: 'Cross-check faculty, room, and section overlaps automatically.',
        icon: ShieldCheckIcon,
    },
    {
        name: 'Role Dashboards',
        description: 'Give Admins, Registrars, Deans, and OICs the view built for their job.',
        icon: Squares2X2Icon,
    },
];

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const showPassword = ref(false);
const { darkMode } = useAppShell();

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
];
</script>

<template>
    <Head title="Log in" />

    <div
        class="relative flex h-screen w-screen flex-col overflow-hidden bg-[#EAF6FF] text-[#16213E] antialiased transition-colors duration-500 dark:bg-[#0B1220] dark:text-[#F3EFE6] [font-family:'IBM_Plex_Sans',sans-serif]"
    >
        <!-- Theme toggle -->
        <div class="absolute right-5 top-5 z-20">
            <ThemeToggle />
        </div>

        <!-- ===================== TIMETABLE GRID TEXTURE ===================== -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <!-- Light mode grid -->
            <div
                class="absolute inset-0 opacity-[0.07] dark:hidden"
                style="
                    background-image:
                        linear-gradient(#1e3a5f 1px, transparent 1px),
                        linear-gradient(90deg, #1e3a5f 1px, transparent 1px);
                    background-size: 56px 56px;
                "
            ></div>
            <!-- Dark mode grid -->
            <div
                class="absolute inset-0 hidden opacity-[0.05] dark:block"
                style="
                    background-image:
                        linear-gradient(#f3efe6 1px, transparent 1px),
                        linear-gradient(90deg, #f3efe6 1px, transparent 1px);
                    background-size: 56px 56px;
                "
            ></div>
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-[#D4A62A]/10 blur-3xl"></div>
            <div class="absolute right-0 top-1/4 h-[28rem] w-[28rem] rounded-full bg-[#7A2E3B]/10 blur-3xl dark:bg-[#7A2E3B]/20"></div>
            <div class="absolute -bottom-32 left-1/4 h-96 w-96 rounded-full bg-[#D4A62A]/5 blur-3xl"></div>

            <!-- Fireflies — night-sky detail, dark mode only -->
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

            <!-- Bubbles — day-sky detail, light mode only -->
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

        <!-- ===================== MAIN ===================== -->
        <main class="relative z-10 flex flex-1 flex-col items-center justify-center gap-4 overflow-hidden px-6" style="perspective: 1400px">
            <!-- Status message -->
            <div
                v-if="status"
                class="w-full max-w-4xl rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-2.5 text-center text-sm font-medium text-emerald-300"
            >
                {{ status }}
            </div>

            <!-- Brand: centered above the card, shared across all breakpoints -->
            <Link
                href="/"
                class="flex animate-[fadein_0.6s_ease-out] items-center justify-center gap-3 transition-transform duration-300 hover:scale-[1.03]"
            >
                <div
                    class="flex h-12 w-12 animate-[float_4s_ease-in-out_infinite] items-center justify-center rounded-full border border-[#D4A62A]/40 bg-white p-1.5 shadow-lg shadow-black/10 dark:bg-[#151B2E] dark:shadow-black/40"
                >
                    <img src="/logo.png" alt="PAP logo" class="h-full w-full rounded-full object-contain" />
                </div>
                <span class="text-2xl font-semibold tracking-tight text-[#16213E] [font-family:'Fraunces',serif] dark:text-[#F3EFE6]">
                    CLASSLY
                </span>
            </Link>
            <p
                class="-mt-2 animate-[fadein_0.6s_ease-out] text-center text-xs text-slate-500 [font-family:'IBM_Plex_Mono',monospace] dark:text-[#8B93A7]"
            >
                Academic scheduling for the Professional Academy of the Philippines
            </p>

            <!-- Split panel -->
            <div class="card-open relative w-full max-w-4xl">
                <!-- Ambient glow so the card doesn't float in flat space -->
                <div
                    class="pointer-events-none absolute -inset-6 -z-10 rounded-[2.5rem] bg-[#D4A62A]/10 blur-3xl"
                ></div>

                <div
                    class="card-open-inner relative grid grid-cols-1 overflow-hidden rounded-3xl border border-[#16213E]/10 shadow-2xl shadow-black/10 ring-1 ring-black/5 dark:border-[#F3EFE6]/10 dark:shadow-black/50 dark:ring-white/5 lg:grid-cols-[1.1fr_1fr]"
                >
                    <!-- Top accent line -->
                    <div
                        class="pointer-events-none absolute inset-x-0 top-0 z-10 h-px bg-gradient-to-r from-transparent via-[#D4A62A]/70 to-transparent"
                    ></div>

                    <!-- Left: capabilities -->
                    <div
                        class="relative hidden flex-col justify-center gap-6 bg-white/70 px-9 py-9 backdrop-blur-xl dark:bg-[#10182B]/70 lg:flex"
                    >
                        <!-- Corner glow accent -->
                        <div
                            class="pointer-events-none absolute -left-10 -top-10 h-40 w-40 rounded-full bg-[#D4A62A]/10 blur-3xl"
                        ></div>

                        <div class="relative">
                            <p
                                class="mb-4 text-[10px] uppercase tracking-[0.3em] text-slate-500 [font-family:'IBM_Plex_Mono',monospace] dark:text-[#8B93A7]"
                            >
                                What Classly Handles
                            </p>
                            <ul class="flex flex-col gap-3.5">
                                <li v-for="capability in capabilities" :key="capability.name" class="group flex items-start gap-3">
                                    <div
                                        class="flex h-8 w-8 flex-none items-center justify-center rounded-lg border border-[#D4A62A]/20 bg-[#D4A62A]/10 text-[#D4A62A] transition-all duration-300 group-hover:scale-110 group-hover:bg-[#D4A62A]/20"
                                    >
                                        <component :is="capability.icon" class="h-4 w-4" />
                                    </div>
                                    <div class="min-w-0 cursor-default">
                                        <p
                                            class="text-sm font-semibold text-[#16213E] transition-colors duration-200 group-hover:text-[#A8790E] dark:text-[#F3EFE6] dark:group-hover:text-[#E8C766]"
                                        >
                                            {{ capability.name }}
                                        </p>
                                        <p
                                            class="max-h-0 overflow-hidden text-xs leading-relaxed text-slate-500 opacity-0 transition-all duration-300 ease-out group-hover:mt-1 group-hover:max-h-16 group-hover:opacity-100 dark:text-[#8B93A7]"
                                        >
                                            {{ capability.description }}
                                        </p>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <!-- Divider fading into the right panel -->
                        <div
                            class="pointer-events-none absolute inset-y-8 right-0 hidden w-px bg-gradient-to-b from-transparent via-[#D4A62A]/25 to-transparent lg:block"
                        ></div>
                    </div>

                    <!-- Right: login form -->
                    <div class="flex flex-col justify-center bg-white/80 px-7 py-9 backdrop-blur-xl dark:bg-[#151B2E]/80 sm:px-9">
                        <p
                            class="mb-6 text-center text-[10px] uppercase tracking-[0.3em] text-slate-500 [font-family:'IBM_Plex_Mono',monospace] dark:text-[#8B93A7] lg:text-left"
                        >
                            Sign in to continue
                        </p>

                        <form @submit.prevent="submit" class="flex flex-col gap-4">
                        <div>
                            <label for="email" class="mb-1.5 block text-xs font-medium text-[#16213E]/80 dark:text-[#F3EFE6]/80">Email</label>
                            <input
                                id="email"
                                type="email"
                                v-model="form.email"
                                required
                                autofocus
                                autocomplete="username"
                                class="w-full rounded-xl border border-[#16213E]/15 bg-white/70 px-4 py-2.5 text-sm text-[#16213E] placeholder-slate-400 transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/40 dark:border-[#F3EFE6]/15 dark:bg-[#0B1220]/60 dark:text-[#F3EFE6] dark:placeholder-[#8B93A7]"
                                placeholder="you@pap.edu.ph"
                            />
                            <p v-if="form.errors.email" class="mt-1.5 text-xs text-rose-600 dark:text-[#E8828C]">
                                {{ form.errors.email }}
                            </p>
                        </div>

                        <div>
                            <label for="password" class="mb-1.5 block text-xs font-medium text-[#16213E]/80 dark:text-[#F3EFE6]/80">Password</label>
                            <div class="relative">
                                <input
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    v-model="form.password"
                                    required
                                    autocomplete="current-password"
                                    class="w-full rounded-xl border border-[#16213E]/15 bg-white/70 px-4 py-2.5 pr-11 text-sm text-[#16213E] placeholder-slate-400 transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/40 dark:border-[#F3EFE6]/15 dark:bg-[#0B1220]/60 dark:text-[#F3EFE6] dark:placeholder-[#8B93A7]"
                                    placeholder="••••••••"
                                />
                                <button
                                    type="button"
                                    tabindex="-1"
                                    @click="showPassword = !showPassword"
                                    :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                    class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 transition-colors duration-200 hover:text-[#A8790E] focus:outline-none focus-visible:text-[#D4A62A] dark:text-[#8B93A7] dark:hover:text-[#E8C766]"
                                >
                                    <EyeSlashIcon v-if="showPassword" class="h-4 w-4" />
                                    <EyeIcon v-else class="h-4 w-4" />
                                </button>
                            </div>
                            <p v-if="form.errors.password" class="mt-1.5 text-xs text-rose-600 dark:text-[#E8828C]">
                                {{ form.errors.password }}
                            </p>
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="flex cursor-pointer items-center gap-2">
                                <input
                                    type="checkbox"
                                    name="remember"
                                    v-model="form.remember"
                                    class="h-4 w-4 cursor-pointer rounded border-[#16213E]/25 bg-white/70 text-[#D4A62A] accent-[#D4A62A] focus:ring-2 focus:ring-[#D4A62A]/40 focus:ring-offset-0 dark:border-[#F3EFE6]/25 dark:bg-[#0B1220]/60"
                                />
                                <span class="text-xs text-slate-500 dark:text-[#8B93A7]">Remember me</span>
                            </label>

                            <Link
                                v-if="canResetPassword"
                                :href="route('password.request')"
                                class="text-xs text-slate-500 underline decoration-slate-400/40 underline-offset-2 transition-colors duration-200 hover:text-[#A8790E] hover:decoration-[#A8790E] focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#D4A62A] dark:text-[#8B93A7] dark:decoration-[#8B93A7]/40 dark:hover:text-[#E8C766] dark:hover:decoration-[#E8C766]"
                            >
                                Forgot your password?
                            </Link>
                        </div>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            :class="{ 'opacity-50': form.processing }"
                            class="firefly-btn mt-2 w-full rounded-full bg-[#D4A62A] px-6 py-2.5 text-sm font-semibold text-[#0B1220] shadow-lg shadow-[#D4A62A]/20 transition-all duration-300 hover:scale-[1.02] hover:bg-[#E8C766] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#D4A62A] disabled:cursor-not-allowed disabled:hover:scale-100"
                        >
                            Log In
                        </button>
                    </form>
                    </div>
                </div>
            </div>
        </main>

        <!-- ===================== FOOTER ===================== -->
        <footer class="relative z-10 border-t border-[#16213E]/10 px-6 py-2.5 text-center dark:border-[#F3EFE6]/10">
            <p class="text-[10px] text-slate-500 dark:text-[#8B93A7] sm:text-xs">
                &copy; 2025 Professional Academy of the Philippines &ndash; Naga Cebu &mdash;
                CLASSLY. All Rights Reserved.
            </p>
            <p class="mt-0.5 text-[9px] text-slate-500/70 dark:text-[#8B93A7]/70 sm:text-[10px]">
                Developed by &ndash; DJS
            </p>
        </footer>
    </div>
</template>

<style scoped>
@keyframes fadein {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ===================== CARD OPEN (login panel entrance) ===================== */
.card-open {
    transform-origin: center top;
    transform-style: preserve-3d;
    animation: card-open 0.75s cubic-bezier(0.16, 1, 0.3, 1) 0.15s both;
}

@keyframes card-open {
    0% {
        opacity: 0;
        transform: scale(0.82) rotateX(18deg) translateY(24px);
        filter: blur(4px);
    }
    55% {
        opacity: 1;
        filter: blur(0);
    }
    100% {
        opacity: 1;
        transform: scale(1) rotateX(0deg) translateY(0);
        filter: blur(0);
    }
}

.card-open-inner {
    animation: card-open-inner 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.4s both;
}

@keyframes card-open-inner {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
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
        0 0 0 4px rgba(212, 166, 42, 0.15),
        0 10px 30px -8px rgba(212, 166, 42, 0.4);
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