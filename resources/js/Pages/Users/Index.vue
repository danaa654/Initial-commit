<script setup>
import { computed, ref } from 'vue'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import {
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    MagnifyingGlassIcon,
    UserGroupIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
    users: Array,
})

const search = ref('')

const filteredUsers = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.users
    return props.users.filter((u) =>
        u.name?.toLowerCase().includes(q) ||
        u.email?.toLowerCase().includes(q) ||
        u.roles[0]?.name?.toLowerCase().includes(q)
    )
})

function initials(name) {
    if (!name) return ''
    return name.trim().split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase()
}

// Consistent color per role — same idea as the sidebar's active-state accents,
// just spread across a small palette so roles are scannable at a glance.
const roleStyles = {
    Admin: 'bg-indigo-500/10 text-indigo-600 border-indigo-500/20 dark:text-indigo-300',
    Registrar: 'bg-blue-500/10 text-blue-600 border-blue-500/20 dark:text-blue-300',
    Dean: 'bg-purple-500/10 text-purple-600 border-purple-500/20 dark:text-purple-300',
    'Assistant Dean': 'bg-teal-500/10 text-teal-600 border-teal-500/20 dark:text-teal-300',
    OIC: 'bg-amber-500/10 text-amber-600 border-amber-500/20 dark:text-amber-300',
}
function roleBadgeClass(role) {
    return roleStyles[role] ?? 'bg-slate-500/10 text-slate-600 border-slate-500/20 dark:text-slate-300'
}

function destroy(id, name) {
    if (confirm(`Are you sure you want to delete ${name}? This can't be undone.`)) {
        router.delete(`/users/${id}`)
    }
}
</script>

<template>
    <DashboardLayout>

        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-[var(--text-primary)]">
                    Users
                </h1>
                <p class="text-sm text-[var(--text-secondary)] mt-1">
                    {{ users.length }} {{ users.length === 1 ? 'account' : 'accounts' }} across the system
                </p>
            </div>

            <Link
                href="/users/create"
                class="inline-flex items-center gap-2 rounded-full bg-[#D4A62A] px-5 py-2.5 text-sm font-semibold text-[#0B1220] shadow-lg shadow-[#D4A62A]/20 transition-all duration-200 hover:scale-105 hover:bg-[#E8C766]"
            >
                <PlusIcon class="h-4 w-4" />
                Add User
            </Link>
        </div>

        <!-- Search -->
        <div class="relative mb-4 max-w-sm">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]" />
            <input
                v-model="search"
                type="text"
                placeholder="Search by name, email, or role..."
                class="w-full rounded-full border border-[var(--card-border)] bg-[var(--card-bg)] py-2.5 pl-10 pr-4 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-colors duration-300 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            />
        </div>

        <!-- Table -->
        <div class="bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow overflow-hidden transition-colors duration-300">

            <table class="w-full">

                <thead>
                    <tr class="border-b border-[var(--card-border)]">
                        <th class="text-left p-4 text-xs font-bold uppercase tracking-wider text-[var(--text-secondary)]">Name</th>
                        <th class="text-left p-4 text-xs font-bold uppercase tracking-wider text-[var(--text-secondary)]">Email</th>
                        <th class="text-left p-4 text-xs font-bold uppercase tracking-wider text-[var(--text-secondary)]">Role</th>
                        <th class="text-left p-4 text-xs font-bold uppercase tracking-wider text-[var(--text-secondary)]">Department</th>
                        <th class="text-center p-4 text-xs font-bold uppercase tracking-wider text-[var(--text-secondary)]">Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <tr
                        v-for="user in filteredUsers"
                        :key="user.id"
                        class="border-t border-[var(--card-border)] transition-colors duration-150 hover:bg-[var(--page-bg)]"
                    >
                        <td class="p-4">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-indigo-700 text-[11px] font-black text-white">
                                    {{ initials(user.name) }}
                                </div>
                                <span class="font-medium text-[var(--text-primary)]">
                                    {{ user.name }}
                                </span>
                            </div>
                        </td>

                        <td class="p-4 text-[var(--text-secondary)]">
                            {{ user.email }}
                        </td>

                        <td class="p-4">
                            <span
                                class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold"
                                :class="roleBadgeClass(user.roles[0]?.name)"
                            >
                                {{ user.roles[0]?.name ?? '—' }}
                            </span>
                        </td>

                        <td class="p-4 text-[var(--text-secondary)]">
                            {{ user.department_name ?? '—' }}
                        </td>

                        <td class="p-4">
                            <div class="flex justify-center gap-1.5">

                                <Link
                                    :href="`/users/${user.id}/edit`"
                                    :aria-label="`Edit ${user.name}`"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600 transition-colors duration-150 hover:bg-blue-500/20 dark:text-blue-300"
                                >
                                    <PencilSquareIcon class="h-4 w-4" />
                                </Link>

                                <button
                                    v-if="!user.is_protected"
                                    @click="destroy(user.id, user.name)"
                                    :aria-label="`Delete ${user.name}`"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-500/10 text-rose-600 transition-colors duration-150 hover:bg-rose-500/20 dark:text-rose-300"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </button>

                                <span
                                    v-else
                                    :title="user.protected_reason"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--page-bg)] text-[var(--text-muted)] cursor-not-allowed"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </span>

                            </div>
                        </td>
                    </tr>

                    <tr v-if="filteredUsers.length === 0">
                        <td colspan="5" class="p-12">
                            <div class="flex flex-col items-center justify-center gap-2 text-[var(--text-muted)]">
                                <UserGroupIcon class="h-8 w-8" />
                                <p class="text-sm font-medium text-[var(--text-secondary)]">
                                    {{ search ? 'No users match your search.' : 'No users found.' }}
                                </p>
                            </div>
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </DashboardLayout>
</template>