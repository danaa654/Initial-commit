<script setup>
import { computed, ref } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { useAppShell } from '@/Composables/useAppShell'
import ThemeToggle from '@/Components/ThemeToggle.vue'

// mobileOpen is the same reactive flag Sidebar.vue reads to show/hide
// itself on small screens — flipping it here is what makes the
// hamburger button actually open the sidebar on mobile.
const { mobileOpen } = useAppShell()

const page = usePage()
const activeAcademicTerm = computed(() => page.props.activeAcademicTerm)

// The scheduling workspace's term — independent of activeAcademicTerm
// above. Shared to every authenticated user by HandleInertiaRequests,
// but the switcher UI (below) only ever renders for Admin/Registrar
// because academicTermsForSwitcher is only populated for them.
const workingAcademicTerm = computed(() => page.props.workingAcademicTerm)
const switcherOptions = computed(() => page.props.academicTermsForSwitcher ?? [])
const canSwitchWorkingTerm = computed(() => switcherOptions.value.length > 0)

const menuOpen = ref(false)

function toggleMenu() {
    if (!canSwitchWorkingTerm.value) return
    menuOpen.value = !menuOpen.value
}

function selectTerm(term) {
    menuOpen.value = false

    if (term.id === workingAcademicTerm.value?.id) return

    router.put(route('working-term.update'), { academic_term_id: term.id }, {
        preserveScroll: true,
        preserveState: true,
    })
}

function closeMenu() {
    menuOpen.value = false
}

/*
|--------------------------------------------------------------------------
| Notifications — Faculty Load Overload + College Finalization
|--------------------------------------------------------------------------
|
| Shared on every page by HandleInertiaRequests as
| page.props.overloadNotifications (unread only, current user only).
| Five shapes flow through this one list — distinguished by
| notification.type:
|
|   - ...FacultyLoadOverloadRequested        — sent to Admin/Registrar
|     when a Dean/Assistant Dean/OIC submits a new request. Clicking
|     one jumps to Faculty Loading so it can actually be
|     approved/declined there.
|   - ...FacultyLoadOverloadReviewed         — sent to the requester
|     once Admin/Registrar approves or declines. Read-only, dismiss
|     only.
|   - ...FacultyLoadOverloadAppliedByAdmin   — sent to a department's
|     Dean/OIC when Admin/Registrar directly adds overload units to
|     one of their faculty. Read-only, dismiss only — nothing to
|     approve, it's already applied.
|   - ...ScheduleFinalized                    — sent to Admin/
|     Registrar/Assistant Dean + that college's Dean/OIC (minus
|     whoever performed the action) when a college's schedule is
|     finalized. Read-only, dismiss only.
|   - ...ScheduleUnfinalized                  — same recipients as
|     above, for the schedule being reopened for editing. Read-only,
|     dismiss only.
|   - ...MasterGridScheduleSaved                — sent to Admin/
|     Registrar/Assistant Dean + that college's Dean/OIC (minus
|     whoever performed the action) when a Master Grid save actually
|     changes one or more of that college's Subject Offerings.
|     Batched per college per save. Read-only, dismiss only.
|   - ...SubjectOfferingsGenerated                — same recipients as
|     above, sent when new Subject Offerings are generated for a
|     college's curriculum. Read-only, dismiss only.
|   - ...SectionCreated                             — same recipients
|     as above, sent when a new Section is created under a college's
|     curriculum. Unlike the others, this one IS actionable — clicking
|     it dismisses and jumps to that Section's Edit page (see
|     goToSection() below), the same "dismiss + navigate" pattern as
|     the overload request notification.
|
| If other notification types get added later, this is the one place
| that needs to grow to merge them in.
*/

const notifications = computed(() => page.props.overloadNotifications ?? [])
const unreadNotifications = computed(() => notifications.value.length > 0)

function isRequestNotification(notification) {
    return notification.type?.endsWith('FacultyLoadOverloadRequested')
}

function isAppliedNotification(notification) {
    return notification.type?.endsWith('FacultyLoadOverloadAppliedByAdmin')
}

function isFinalizedNotification(notification) {
    return notification.type?.endsWith('ScheduleFinalized')
}

function isUnfinalizedNotification(notification) {
    return notification.type?.endsWith('ScheduleUnfinalized')
}

function isSavedNotification(notification) {
    return notification.type?.endsWith('MasterGridScheduleSaved')
}

function isGeneratedNotification(notification) {
    return notification.type?.endsWith('SubjectOfferingsGenerated')
}

function isSectionCreatedNotification(notification) {
    return notification.type?.endsWith('SectionCreated')
}

const notificationsOpen = ref(false)

function toggleNotifications() {
    notificationsOpen.value = !notificationsOpen.value
}

function closeNotifications() {
    notificationsOpen.value = false
}

function dismissNotification(notification) {
    router.post(route('faculty-load-overloads.notifications.read', notification.id), {}, {
        preserveScroll: true,
        preserveState: true,
    })
}

function markAllNotificationsRead() {
    router.post(route('faculty-load-overloads.notifications.read-all'), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { notificationsOpen.value = false },
    })
}

// A pending-request notification is actionable — clicking it dismisses
// it and takes the reviewer straight to Faculty Loading, where the
// PendingOverloadsPanel actually has the Approve/Decline controls.
function goToFacultyLoading(notification) {
    dismissNotification(notification)
    notificationsOpen.value = false
    router.visit(route('teaching-assignments.index'))
}

// A new-Section notification is actionable — clicking it dismisses it
// and takes the recipient straight to that Section's Edit page.
function goToSection(notification) {
    dismissNotification(notification)
    notificationsOpen.value = false
    router.visit(route('sections.edit', notification.data.section_id))
}

// "Just now" / "5m ago" / "3h ago" / "2d ago" — small and dependency-free
// rather than pulling in a date library for one relative-time string.
function timeAgo(isoString) {
    const seconds = Math.floor((Date.now() - new Date(isoString).getTime()) / 1000)
    if (seconds < 60) return 'Just now'
    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `${minutes}m ago`
    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `${hours}h ago`
    const days = Math.floor(hours / 24)
    return `${days}d ago`
}
</script>

<template>
    <header
        class="relative h-16 px-5 flex items-center justify-between shrink-0 border-b border-black/10"
        style="background: var(--sidebar-bg)"
    >
        <!-- Subtle bottom glow so the bar reads as elevated, not flat -->
        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-white/15 to-transparent"></div>

        <!-- School / system title -->
        <div class="flex items-center gap-3 min-w-0 brand-font">
            <button
                type="button"
                class="md:hidden shrink-0 text-white/80 hover:text-white"
                @click="mobileOpen = true"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <div class="flex flex-col leading-none min-w-0">
                <span class="text-white font-extrabold text-[14px] sm:text-[15px] tracking-wide uppercase truncate">
                    Professional Academy of the Philippines
                </span>
                <span class="text-blue-400 font-bold text-[9px] sm:text-[10px] tracking-[0.15em] uppercase mt-1 truncate">
                    Classly &middot; Class Scheduling Management System
                </span>
            </div>
        </div>

        <!-- Right controls -->
        <div class="flex items-center gap-3 sm:gap-4">

            <!-- Active Academic Term (unchanged — Enrollment, Grades,
                 Attendance, Reports, dashboard stats, and the Student
                 Portal all key off this one, never the Working Term). -->
            <div
                class="flex items-center gap-1.5 sm:gap-2 rounded-full border px-2.5 sm:px-3 py-1 sm:py-1.5 brand-font"
                :style="activeAcademicTerm
                    ? 'background: rgba(16, 185, 129, 0.08); border-color: rgba(16, 185, 129, 0.25)'
                    : 'background: rgba(255, 255, 255, 0.04); border-color: rgba(255, 255, 255, 0.1)'"
            >
                <span
                    class="w-1.5 h-1.5 rounded-full shrink-0"
                    :class="activeAcademicTerm ? 'bg-emerald-400' : 'bg-slate-500'"
                    :style="activeAcademicTerm ? 'box-shadow: 0 0 6px rgba(52, 211, 153, 0.8)' : ''"
                ></span>

                <div class="flex flex-col leading-tight">
                    <span
                        class="hidden sm:block text-[9px] font-bold uppercase tracking-widest"
                        :class="activeAcademicTerm ? 'text-emerald-300/80' : 'text-slate-400'"
                    >
                        {{ activeAcademicTerm ? 'Active Term' : 'No Active Academic Term' }}
                    </span>
                    <span
                        v-if="activeAcademicTerm"
                        class="text-[11px] sm:text-[12px] font-semibold text-white truncate max-w-[110px] sm:max-w-none"
                    >
                        {{ activeAcademicTerm.semester_label }} &bull; SY {{ activeAcademicTerm.academic_year }}
                    </span>
                    <span v-else class="sm:hidden text-[11px] font-semibold text-slate-300">
                        No Active Term
                    </span>
                </div>
            </div>

            <!-- Working Academic Term — the scheduling workspace switcher. -->
            <div class="relative">
                <button
                    type="button"
                    class="flex items-center gap-1.5 sm:gap-2 rounded-full border px-2.5 sm:px-3 py-1 sm:py-1.5 brand-font"
                    style="background: rgba(99, 102, 241, 0.08); border-color: rgba(99, 102, 241, 0.25)"
                    @click="toggleMenu"
                >
                    <span
                        class="w-1.5 h-1.5 rounded-full shrink-0 bg-indigo-400"
                        style="box-shadow: 0 0 6px rgba(129, 140, 248, 0.8)"
                    ></span>

                    <div class="flex flex-col leading-tight text-left">
                        <span class="hidden sm:block text-[9px] font-bold uppercase tracking-widest text-indigo-300/80">
                            Working Term
                        </span>
                        <span
                            v-if="workingAcademicTerm"
                            class="text-[11px] sm:text-[12px] font-semibold text-white truncate max-w-[110px] sm:max-w-none"
                        >
                            {{ workingAcademicTerm.semester_label }} &bull; SY {{ workingAcademicTerm.academic_year }}
                        </span>
                        <span v-else class="text-[11px] font-semibold text-slate-300">
                            No Working Term
                        </span>
                    </div>

                    <span
                        v-if="workingAcademicTerm"
                        class="hidden sm:inline text-[9px] font-bold uppercase tracking-wide text-indigo-200/80 border border-indigo-400/30 rounded px-1.5 py-0.5 whitespace-nowrap"
                    >
                        {{ workingAcademicTerm.scheduling_status }}
                    </span>

                    <svg
                        v-if="canSwitchWorkingTerm"
                        class="w-3.5 h-3.5 text-indigo-200/70 transition-transform"
                        :class="menuOpen ? 'rotate-180' : ''"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <!-- Backdrop to close the menu on outside click -->
                <div v-if="menuOpen" class="fixed inset-0 z-40" @click="closeMenu"></div>

                <div
                    v-if="menuOpen"
                    class="absolute right-0 mt-2 w-64 rounded-lg border border-white/10 shadow-xl z-50 overflow-hidden"
                    style="background: var(--sidebar-bg)"
                >
                    <div class="px-3 py-2 text-[9px] font-bold uppercase tracking-widest text-white/40 border-b border-white/10">
                        Switch Working Term
                    </div>
                    <button
                        v-for="term in switcherOptions"
                        :key="term.id"
                        type="button"
                        class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left text-[12px] hover:bg-white/10 transition-colors"
                        :class="term.id === workingAcademicTerm?.id ? 'text-indigo-300 font-semibold bg-indigo-500/10' : 'text-white/80'"
                        @click="selectTerm(term)"
                    >
                        <span class="truncate">{{ term.semester_label }} &bull; SY {{ term.academic_year }}</span>
                        <span class="text-[9px] uppercase text-white/40 shrink-0">{{ term.scheduling_status }}</span>
                    </button>
                </div>
            </div>

            <!-- Theme toggle -->
            <ThemeToggle />

            <!-- Notifications -->
            <div class="relative">
                <button
                    type="button"
                    class="relative flex h-9 w-9 items-center justify-center rounded-full text-white/80 transition-colors duration-150 hover:text-white hover:bg-white/10"
                    aria-label="Notifications"
                    @click="toggleNotifications"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                        />
                    </svg>
                    <span
                        v-if="unreadNotifications"
                        class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-rose-500 ring-2"
                        style="--tw-ring-color: var(--sidebar-bg)"
                    ></span>
                </button>

                <!-- Backdrop to close the dropdown on outside click -->
                <div v-if="notificationsOpen" class="fixed inset-0 z-40" @click="closeNotifications"></div>

                <div
                    v-if="notificationsOpen"
                    class="absolute right-0 mt-2 w-80 max-h-[26rem] flex flex-col rounded-lg border border-white/10 shadow-xl z-50 overflow-hidden"
                    style="background: var(--sidebar-bg)"
                >
                    <div class="flex items-center justify-between px-3 py-2 border-b border-white/10">
                        <span class="text-[9px] font-bold uppercase tracking-widest text-white/40">
                            Notifications
                        </span>
                        <button
                            v-if="notifications.length"
                            type="button"
                            class="text-[10px] font-semibold text-indigo-300/80 hover:text-indigo-200"
                            @click="markAllNotificationsRead"
                        >
                            Mark all as read
                        </button>
                    </div>

                    <div class="overflow-y-auto notif-scroll">
                        <p v-if="!notifications.length" class="px-3 py-6 text-center text-[12px] text-white/40">
                            You're all caught up.
                        </p>

                        <div
                            v-for="notification in notifications"
                            :key="notification.id"
                            class="flex items-start gap-2 px-3 py-3 border-b border-white/5 last:border-b-0 hover:bg-white/5"
                        >
                            <!-- New request awaiting Admin/Registrar review -->
                            <template v-if="isRequestNotification(notification)">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400"></span>

                                <div class="min-w-0 flex-1 cursor-pointer" @click="goToFacultyLoading(notification)">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-amber-300">
                                        Overload Request Pending
                                    </p>
                                    <p class="mt-0.5 text-[12px] leading-snug text-white/80">
                                        {{ notification.data.message }}
                                    </p>
                                    <p v-if="notification.data.reason" class="mt-1 text-[11px] italic leading-snug text-white/50">
                                        "{{ notification.data.reason }}"
                                    </p>
                                    <div class="mt-1 text-[10px] text-white/40">
                                        {{ timeAgo(notification.created_at) }}
                                    </div>
                                </div>
                            </template>

                            <!-- Admin/Registrar directly added overload units to a department faculty member -->
                            <template v-else-if="isAppliedNotification(notification)">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-sky-400"></span>

                                <div class="min-w-0 flex-1 cursor-pointer" @click="goToFacultyLoading(notification)">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-sky-300">
                                        Overload Units Added
                                    </p>
                                    <p class="mt-0.5 text-[12px] leading-snug text-white/80">
                                        {{ notification.data.message }}
                                    </p>
                                    <div class="mt-1 text-[10px] text-white/40">
                                        {{ timeAgo(notification.created_at) }}
                                    </div>
                                </div>
                            </template>

                            <!-- A college's schedule was finalized (now read-only) -->
                            <template v-else-if="isFinalizedNotification(notification)">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-violet-400"></span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-violet-300">
                                        Schedule Finalized
                                    </p>
                                    <p class="mt-0.5 text-[12px] leading-snug text-white/80">
                                        {{ notification.data.message }}
                                    </p>
                                    <div class="mt-1 text-[10px] text-white/40">
                                        {{ timeAgo(notification.created_at) }}
                                    </div>
                                </div>
                            </template>

                            <!-- A college's schedule was reopened for editing -->
                            <template v-else-if="isUnfinalizedNotification(notification)">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-400"></span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-teal-300">
                                        Schedule Reopened
                                    </p>
                                    <p class="mt-0.5 text-[12px] leading-snug text-white/80">
                                        {{ notification.data.message }}
                                    </p>
                                    <div class="mt-1 text-[10px] text-white/40">
                                        {{ timeAgo(notification.created_at) }}
                                    </div>
                                </div>
                            </template>

                            <!-- Master Grid save changed one or more of this college's offerings -->
                            <template v-else-if="isSavedNotification(notification)">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-400"></span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-indigo-300">
                                        Master Grid Saved
                                    </p>
                                    <p class="mt-0.5 text-[12px] leading-snug text-white/80">
                                        {{ notification.data.message }}
                                    </p>
                                    <div class="mt-1 text-[10px] text-white/40">
                                        {{ timeAgo(notification.created_at) }}
                                    </div>
                                </div>
                            </template>

                            <!-- New Subject Offerings generated for a college's curriculum -->
                            <template v-else-if="isGeneratedNotification(notification)">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-lime-400"></span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-lime-300">
                                        Subject Offerings Generated
                                    </p>
                                    <p class="mt-0.5 text-[12px] leading-snug text-white/80">
                                        {{ notification.data.message }}
                                    </p>
                                    <div class="mt-1 text-[10px] text-white/40">
                                        {{ timeAgo(notification.created_at) }}
                                    </div>
                                </div>
                            </template>

                            <!-- New Section created under a college's curriculum -->
                            <template v-else-if="isSectionCreatedNotification(notification)">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-fuchsia-400"></span>

                                <div class="min-w-0 flex-1 cursor-pointer" @click="goToSection(notification)">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-fuchsia-300">
                                        Section Created
                                    </p>
                                    <p class="mt-0.5 text-[12px] leading-snug text-white/80">
                                        {{ notification.data.message }}
                                    </p>
                                    <div class="mt-1 text-[10px] text-white/40">
                                        {{ timeAgo(notification.created_at) }}
                                    </div>
                                </div>
                            </template>

                            <!-- Request reviewed (approved/declined) -->
                            <template v-else>
                                <span
                                    class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full"
                                    :class="notification.data.status === 'approved' ? 'bg-emerald-400' : 'bg-rose-400'"
                                ></span>

                                <div class="min-w-0 flex-1">
                                    <p
                                        class="text-[11px] font-bold uppercase tracking-wide"
                                        :class="notification.data.status === 'approved' ? 'text-emerald-300' : 'text-rose-300'"
                                    >
                                        Overload {{ notification.data.status === 'approved' ? 'Approved' : 'Declined' }}
                                    </p>
                                    <p class="mt-0.5 text-[12px] leading-snug text-white/80">
                                        {{ notification.data.message }}
                                    </p>
                                    <p v-if="notification.data.decline_reason" class="mt-1 text-[11px] italic leading-snug text-white/50">
                                        "{{ notification.data.decline_reason }}"
                                    </p>
                                    <div class="mt-1 flex items-center gap-1.5 text-[10px] text-white/40">
                                        <span v-if="notification.data.reviewed_by_name">{{ notification.data.reviewed_by_name }} &bull;</span>
                                        <span>{{ timeAgo(notification.created_at) }}</span>
                                    </div>
                                </div>
                            </template>

                            <button
                                type="button"
                                class="shrink-0 text-white/30 hover:text-white/70"
                                aria-label="Dismiss"
                                @click="dismissNotification(notification)"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>

<style scoped>
/* Thin scrollbar for the notifications dropdown list — Firefox */
.notif-scroll {
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.25) transparent;
}

/* Thin scrollbar — Chrome / Safari / Edge */
.notif-scroll::-webkit-scrollbar {
    width: 6px;
}

.notif-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.notif-scroll::-webkit-scrollbar-thumb {
    background-color: rgba(255, 255, 255, 0.25);
    border-radius: 9999px;
}

.notif-scroll::-webkit-scrollbar-thumb:hover {
    background-color: rgba(255, 255, 255, 0.4);
}
</style>