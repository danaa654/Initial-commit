<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3'

const props = defineProps({
    mustVerifyEmail: {
        type: Boolean,
        default: false,
    },
    status: {
        type: String,
        default: null,
    },
})

const user = usePage().props.auth.user

const form = useForm({
    name: user.name,
    email: user.email,
})

function submit() {
    form.patch('/profile')
}

const inputClass = 'w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-4 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30'
const labelClass = 'mb-1.5 block text-sm font-medium text-[var(--text-secondary)]'
const errorClass = 'mt-1.5 text-xs text-rose-500'
</script>

<template>
    <section>
        <header class="mb-5">
            <h2 class="text-lg font-bold text-[var(--text-primary)]">
                Profile Information
            </h2>
            <p class="mt-1 text-sm text-[var(--text-secondary)]">
                Update your account's name and email address.
            </p>
        </header>

        <form @submit.prevent="submit" class="space-y-5">

            <!-- Name -->
            <div>
                <label :class="labelClass">Name</label>
                <input v-model="form.name" type="text" :class="inputClass" autocomplete="name" />
                <p v-if="form.errors.name" :class="errorClass">{{ form.errors.name }}</p>
            </div>

            <!-- Email -->
            <div>
                <label :class="labelClass">Email</label>
                <input v-model="form.email" type="email" :class="inputClass" autocomplete="username" />
                <p v-if="form.errors.email" :class="errorClass">{{ form.errors.email }}</p>
            </div>

            <!-- Email verification notice -->
            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="text-sm text-[var(--text-secondary)]">
                    Your email address is unverified.
                    <Link
                        href="/email/verification-notification"
                        method="post"
                        as="button"
                        class="font-medium text-[#D4A62A] underline decoration-[#D4A62A]/40 underline-offset-2 transition-colors duration-150 hover:text-[#E8C766]"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <p
                    v-if="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-emerald-600 dark:text-emerald-400"
                >
                    A new verification link has been sent to your email address.
                </p>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3 pt-1">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-full bg-[#D4A62A] px-6 py-2.5 text-sm font-semibold text-[#0B1220] shadow-lg shadow-[#D4A62A]/20 transition-all duration-200 hover:scale-[1.02] hover:bg-[#E8C766] disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:scale-100"
                >
                    {{ form.processing ? 'Saving...' : 'Save' }}
                </button>

                <Transition
                    enter-active-class="transition-all duration-200 ease-out"
                    leave-active-class="transition-all duration-1000 ease-in"
                    enter-from-class="opacity-0"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-[var(--text-secondary)]">
                        Saved.
                    </p>
                </Transition>
            </div>

        </form>
    </section>
</template>