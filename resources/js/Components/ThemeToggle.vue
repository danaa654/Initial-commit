<script setup>
import { useAppShell } from '@/Composables/useAppShell'

// Same singleton store Sidebar.vue and Topbar.vue already read from —
// clicking this anywhere in the app flips the .dark class on <html>,
// persists the choice to localStorage, and every page (including
// Welcome/Login, which aren't behind AppLayout) picks it up because
// they also call useAppShell().
const { darkMode, toggleTheme } = useAppShell()

// Pill is 80px wide, knob is 22px with a 4px inset on each side.
const knobLeft = () => (darkMode.value ? '54px' : '4px')
</script>

<template>
    <button
        type="button"
        class="theme-pill"
        role="switch"
        :aria-checked="darkMode"
        :aria-label="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
        @click="toggleTheme"
    >
        <!-- Background: crossfades between the night-sky and day-sky art -->
        <span class="theme-pill-bg">
            <img
                src="/images/theme/bg-light.jpg"
                alt=""
                class="theme-pill-img"
                :style="{ opacity: darkMode ? 0 : 1 }"
            />
            <img
                src="/images/theme/bg-dark.jpg"
                alt=""
                class="theme-pill-img"
                :style="{ opacity: darkMode ? 1 : 0 }"
            />
        </span>

        <!-- Ring: subtle colored edge so the pill reads as interactive -->
        <span
            class="theme-pill-ring"
            :style="{
                boxShadow: darkMode
                    ? '0 0 0 1px rgba(99, 102, 241, 0.45) inset'
                    : '0 0 0 1px rgba(56, 189, 248, 0.45) inset',
            }"
        ></span>

        <!-- Knob: slides left/right, crossfades between moon and sun art -->
        <span class="theme-pill-knob" :style="{ left: knobLeft() }">
            <span
                class="theme-pill-glow"
                :style="{
                    boxShadow: darkMode
                        ? '0 0 10px 2px rgba(99, 102, 241, 0.55)'
                        : '0 0 10px 2px rgba(250, 204, 21, 0.55)',
                }"
            ></span>
            <img
                src="/images/theme/circle-light.jpg"
                alt=""
                :style="{ opacity: darkMode ? 0 : 1 }"
            />
            <img
                src="/images/theme/circle-dark.jpg"
                alt=""
                :style="{ opacity: darkMode ? 1 : 0 }"
            />
        </span>
    </button>
</template>