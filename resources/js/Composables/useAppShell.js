import { ref } from 'vue'

// Module-level refs = a tiny singleton store, shared by every component
// that calls useAppShell(). No Pinia needed for state this small.
const darkMode = ref(false)
const sidebarPinned = ref(false)
const mobileOpen = ref(false)

let bootstrapped = false

function bootstrap() {
    if (bootstrapped || typeof window === 'undefined') return
    bootstrapped = true

    darkMode.value = localStorage.getItem('theme') === 'dark'
    sidebarPinned.value = localStorage.getItem('sidebar-pinned') === 'true'
    document.documentElement.classList.toggle('dark', darkMode.value)
}

export function useAppShell() {
    bootstrap()

    function toggleTheme() {
        darkMode.value = !darkMode.value
        localStorage.setItem('theme', darkMode.value ? 'dark' : 'light')
        document.documentElement.classList.toggle('dark', darkMode.value)
    }

    function togglePin() {
        sidebarPinned.value = !sidebarPinned.value
        localStorage.setItem('sidebar-pinned', String(sidebarPinned.value))
    }

    function openMobile() {
        mobileOpen.value = true
    }

    function closeMobile() {
        mobileOpen.value = false
    }

    return {
        darkMode,
        sidebarPinned,
        mobileOpen,
        toggleTheme,
        togglePin,
        openMobile,
        closeMobile,
    }
}