<script setup>

import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import DashboardBackgroundFX from '@/Components/DashboardBackgroundFX.vue'

</script>

<template>

<DashboardLayout>
  <DashboardBackgroundFX>

    <h1 class="text-3xl font-bold mb-6 text-[var(--text-primary)]">
        Dashboard
    </h1>

    <div class="grid grid-cols-4 gap-5">

        <div class="bg-[var(--card-bg)] shadow rounded-lg p-6 border border-[var(--card-border)] transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:border-indigo-300 dark:hover:border-indigo-500 text-[var(--text-primary)]">
            Users
        </div>

        <div class="bg-[var(--card-bg)] shadow rounded-lg p-6 border border-[var(--card-border)] transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:border-indigo-300 dark:hover:border-indigo-500 text-[var(--text-primary)]">
            Departments
        </div>

        <div class="bg-[var(--card-bg)] shadow rounded-lg p-6 border border-[var(--card-border)] transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:border-indigo-300 dark:hover:border-indigo-500 text-[var(--text-primary)]">
            Faculty
        </div>

        <div class="bg-[var(--card-bg)] shadow rounded-lg p-6 border border-[var(--card-border)] transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:border-indigo-300 dark:hover:border-indigo-500 text-[var(--text-primary)]">
            Schedules
        </div>

    </div>

  </DashboardBackgroundFX>
</DashboardLayout>

</template>