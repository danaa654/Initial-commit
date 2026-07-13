<script setup>
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Link } from '@inertiajs/vue3'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue'
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue'

defineProps({
    mustVerifyEmail: {
        type: Boolean,
        default: false,
    },
    status: {
        type: String,
        default: null,
    },
})

const cardClass = 'bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow p-6 transition-colors duration-300'
</script>

<template>
    <DashboardLayout>

        <div class="mx-auto flex max-w-2xl flex-col">

            <Link
                href="/dashboard"
                class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-[var(--text-secondary)] transition-colors duration-150 hover:text-[var(--text-primary)]"
            >
                <ArrowLeftIcon class="h-4 w-4" />
                Back to Dashboard
            </Link>

            <div class="mb-6">
                <h1 class="text-3xl font-bold text-[var(--text-primary)]">
                    My Account
                </h1>
                <p class="mt-1 text-sm text-[var(--text-secondary)]">
                    Manage your profile information, password, and account.
                </p>
            </div>

            <div class="space-y-6">

                <div :class="cardClass">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                    />
                </div>

                <div :class="cardClass">
                    <UpdatePasswordForm />
                </div>

            </div>

        </div>

    </DashboardLayout>
</template>