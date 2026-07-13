<script setup>
import { ref, computed } from 'vue'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ArrowLeftIcon, EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    user: Object,
    roles: Array,
    departments: Array,
})

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    role: props.user.roles[0]?.name ?? '',
    department_id: props.user.department_id ?? '',
})

const showPassword = ref(false)
const showConfirmPassword = ref(false)

// Only relevant if they're actually changing the password — leaving both
// blank (the common case here) should never block the update.
const passwordsMismatch = computed(() =>
    form.password.length > 0 && form.password !== form.password_confirmation
)

function submit() {
    if (passwordsMismatch.value) return
    form.put(`/users/${props.user.id}`)
}

const inputClass = 'w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-4 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30'
const labelClass = 'mb-1.5 block text-sm font-medium text-[var(--text-secondary)]'
const errorClass = 'mt-1.5 text-xs text-rose-500'
</script>

<template>
    <DashboardLayout>

        <div class="mx-auto flex max-w-2xl flex-col">

            <Link
                href="/users"
                class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-[var(--text-secondary)] transition-colors duration-150 hover:text-[var(--text-primary)]"
            >
                <ArrowLeftIcon class="h-4 w-4" />
                Back to Users
            </Link>

            <h1 class="text-3xl font-bold mb-6 text-[var(--text-primary)]">
                Edit User
            </h1>

            <form
                @submit.prevent="submit"
                class="bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow p-6 space-y-5 transition-colors duration-300"
            >

                <!-- Name -->
                <div>
                    <label :class="labelClass">Name</label>
                    <input v-model="form.name" type="text" :class="inputClass" />
                    <p v-if="form.errors.name" :class="errorClass">{{ form.errors.name }}</p>
                </div>

                <!-- Email -->
                <div>
                    <label :class="labelClass">Email</label>
                    <input v-model="form.email" type="email" :class="inputClass" />
                    <p v-if="form.errors.email" :class="errorClass">{{ form.errors.email }}</p>
                </div>

                <!-- Password -->
                <div>
                    <label :class="labelClass">Password</label>
                    <div class="relative">
                        <input
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            placeholder="Leave blank to keep current password"
                            :class="inputClass"
                            class="pr-11"
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
                    <p v-if="form.errors.password" :class="errorClass">{{ form.errors.password }}</p>
                </div>

                <!-- Confirm Password -->
                <Transition
                    enter-active-class="transition-all duration-200 ease-out"
                    enter-from-class="opacity-0 -translate-y-1"
                    enter-to-class="opacity-100 translate-y-0"
                >
                    <div v-if="form.password.length > 0">
                        <label :class="labelClass">Confirm Password</label>
                        <div class="relative">
                            <input
                                v-model="form.password_confirmation"
                                :type="showConfirmPassword ? 'text' : 'password'"
                                :class="inputClass"
                                class="pr-11"
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
                        <p v-if="passwordsMismatch" :class="errorClass">Passwords do not match.</p>
                        <p v-else-if="form.errors.password_confirmation" :class="errorClass">{{ form.errors.password_confirmation }}</p>
                    </div>
                </Transition>

                <!-- Role -->
                <div>
                    <label :class="labelClass">Role</label>
                    <select v-model="form.role" :class="inputClass">
                        <option v-for="role in roles" :key="role.id" :value="role.name">
                            {{ role.name }}
                        </option>
                    </select>
                    <p v-if="form.errors.role" :class="errorClass">{{ form.errors.role }}</p>
                </div>

                <!-- Department -->
                <Transition
                    enter-active-class="transition-all duration-200 ease-out"
                    enter-from-class="opacity-0 -translate-y-1"
                    enter-to-class="opacity-100 translate-y-0"
                >
                    <div v-if="['Dean', 'OIC'].includes(form.role)">
                        <label :class="labelClass">Department</label>
                        <select v-model="form.department_id" :class="inputClass">
                            <option value="">Select Department</option>
                            <option v-for="department in departments" :key="department.id" :value="department.id">
                                {{ department.name }}
                            </option>
                        </select>
                        <p v-if="form.errors.department_id" :class="errorClass">{{ form.errors.department_id }}</p>
                    </div>
                </Transition>

                <!-- Actions -->
                <div class="flex items-center gap-3 pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing || passwordsMismatch"
                        class="rounded-full bg-[#D4A62A] px-6 py-2.5 text-sm font-semibold text-[#0B1220] shadow-lg shadow-[#D4A62A]/20 transition-all duration-200 hover:scale-[1.02] hover:bg-[#E8C766] disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:scale-100"
                    >
                        {{ form.processing ? 'Updating...' : 'Update User' }}
                    </button>

                    <Link
                        href="/users"
                        class="rounded-full border border-[var(--card-border)] px-6 py-2.5 text-sm font-semibold text-[var(--text-secondary)] transition-colors duration-150 hover:bg-[var(--page-bg)] hover:text-[var(--text-primary)]"
                    >
                        Cancel
                    </Link>
                </div>

            </form>

        </div>

    </DashboardLayout>
</template>