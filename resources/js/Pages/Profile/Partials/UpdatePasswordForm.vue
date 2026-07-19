<script setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'
import { useFlashToast } from '@/Composables/useFlashToast'

const { show: showToast } = useFlashToast()

const passwordInput = ref(null)
const currentPasswordInput = ref(null)

const showCurrentPassword = ref(false)
const showPassword = ref(false)
const showConfirmPassword = ref(false)

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})

// Client-side-only heuristic, purely to give visual feedback while
// typing — the server's Password::defaults() rule (min 8, mixed case,
// numbers) is still the actual source of truth and gets the final say
// via form.errors.password.
const passwordStrength = computed(() => {
    const value = form.password

    if (! value) {
        return null
    }

    let score = 0

    if (value.length >= 8) score++
    if (value.length >= 12) score++
    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++
    if (/\d/.test(value)) score++
    if (/[^A-Za-z0-9]/.test(value)) score++

    if (score <= 1) return { label: 'Weak', level: 1, color: 'bg-rose-500', textColor: 'text-rose-500' }
    if (score <= 3) return { label: 'Medium', level: 2, color: 'bg-amber-500', textColor: 'text-amber-500' }
    return { label: 'Strong', level: 3, color: 'bg-emerald-500', textColor: 'text-emerald-500' }
})

function submit() {
    form.put('/password', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            showToast('Password updated successfully.', 'success')
        },
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation')
                passwordInput.value?.focus()
            }
            if (form.errors.current_password) {
                form.reset('current_password')
                currentPasswordInput.value?.focus()
            }
        },
    })
}

const inputClass = 'w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-4 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30'
const labelClass = 'mb-1.5 block text-sm font-medium text-[var(--text-secondary)]'
const errorClass = 'mt-1.5 text-xs text-rose-500'
</script>

<template>
    <section>
        <header class="mb-5">
            <h2 class="text-lg font-bold text-[var(--text-primary)]">
                Update Password
            </h2>
            <p class="mt-1 text-sm text-[var(--text-secondary)]">
                Use a long, random password to keep your account secure.
            </p>
        </header>

        <form @submit.prevent="submit" class="space-y-5">

            <!-- Current Password -->
            <div>
                <label :class="labelClass">Current Password</label>
                <div class="relative">
                    <input
                        ref="currentPasswordInput"
                        v-model="form.current_password"
                        :type="showCurrentPassword ? 'text' : 'password'"
                        :class="inputClass"
                        class="pr-11"
                        autocomplete="current-password"
                    />
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showCurrentPassword = !showCurrentPassword"
                        :aria-label="showCurrentPassword ? 'Hide password' : 'Show password'"
                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-[var(--text-muted)] transition-colors duration-150 hover:text-[var(--text-primary)]"
                    >
                        <EyeSlashIcon v-if="showCurrentPassword" class="h-4 w-4" />
                        <EyeIcon v-else class="h-4 w-4" />
                    </button>
                </div>
                <p v-if="form.errors.current_password" :class="errorClass">{{ form.errors.current_password }}</p>
            </div>

            <!-- New Password -->
            <div>
                <label :class="labelClass">New Password</label>
                <div class="relative">
                    <input
                        ref="passwordInput"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        :class="inputClass"
                        class="pr-11"
                        autocomplete="new-password"
                    />
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showPassword = !showPassword"
                        :aria-label="showPassword ? 'Hide password' : 'Show password'"
                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-[var(--text-muted)] transition-colors duration-150 hover:text-[var(--text-primary)]"
                    >
                        <EyeSlashIcon v-if="showPassword" class="h-4 w-4" />
                        <EyeIcon v-else class="h-4 w-4" />
                    </button>
                </div>
                <!-- Mirrors the server rule (Password::defaults() in
                     AppServiceProvider) — shown up front so a weak
                     password is caught here, not after a round trip
                     to the server. -->
                <p class="mt-1.5 text-xs text-[var(--text-muted)]">
                    At least 8 characters, with an uppercase letter, a lowercase letter, and a number.
                </p>

                <!-- Client-side strength indicator — purely visual feedback
                     while typing, the server rule above is still what's
                     actually enforced. -->
                <div v-if="passwordStrength" class="mt-2">
                    <div class="flex gap-1">
                        <span
                            v-for="bar in 3"
                            :key="bar"
                            class="h-1.5 flex-1 rounded-full transition-colors duration-200"
                            :class="bar <= passwordStrength.level ? passwordStrength.color : 'bg-[var(--card-border)]'"
                        ></span>
                    </div>
                    <p class="mt-1 text-xs font-medium" :class="passwordStrength.textColor">
                        {{ passwordStrength.label }} password
                    </p>
                </div>

                <p v-if="form.errors.password" :class="errorClass">{{ form.errors.password }}</p>
            </div>

            <!-- Confirm New Password -->
            <div>
                <label :class="labelClass">Confirm New Password</label>
                <div class="relative">
                    <input
                        v-model="form.password_confirmation"
                        :type="showConfirmPassword ? 'text' : 'password'"
                        :class="inputClass"
                        class="pr-11"
                        autocomplete="new-password"
                    />
                    <button
                        type="button"
                        tabindex="-1"
                        @click="showConfirmPassword = !showConfirmPassword"
                        :aria-label="showConfirmPassword ? 'Hide password' : 'Show password'"
                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-[var(--text-muted)] transition-colors duration-150 hover:text-[var(--text-primary)]"
                    >
                        <EyeSlashIcon v-if="showConfirmPassword" class="h-4 w-4" />
                        <EyeIcon v-else class="h-4 w-4" />
                    </button>
                </div>
                <p v-if="form.errors.password_confirmation" :class="errorClass">{{ form.errors.password_confirmation }}</p>
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