<script setup>
import { computed, watch } from 'vue';

const props = defineProps({
    form: { type: Object, required: true },
    academicTerms: { type: Array, required: true },
    sections: { type: Array, required: true },
    curriculumItems: { type: Array, required: true },
    faculties: { type: Array, required: true },
    facultySubjects: { type: Array, required: true },
});

const selectedSection = computed(() =>
    props.sections.find((section) => section.id === props.form.section_id) ?? null
);

// Only curriculum items belonging to the selected section's curriculum
// are offered — this is the "Section -> Curriculum Item" cascade.
const availableCurriculumItems = computed(() => {
    if (!selectedSection.value) return [];

    return props.curriculumItems.filter(
        (item) => item.curriculum_id === selectedSection.value.curriculum_id
    );
});

const selectedCurriculumItem = computed(() =>
    props.curriculumItems.find((item) => item.id === props.form.curriculum_item_id) ?? null
);

// Only faculty qualified (via Faculty Subjects) to teach the selected
// curriculum item's subject are offered — the "Curriculum Item ->
// Faculty" cascade.
const availableFaculties = computed(() => {
    if (!selectedCurriculumItem.value) return [];

    const qualifiedFacultyIds = props.facultySubjects
        .filter((fs) => fs.subject_id === selectedCurriculumItem.value.subject_id)
        .map((fs) => fs.faculty_id);

    return props.faculties.filter((faculty) => qualifiedFacultyIds.includes(faculty.id));
});

// Resetting downstream selections whenever an upstream one changes
// prevents silently submitting a subject/faculty combo left over from
// a previously selected section or curriculum item.
watch(
    () => props.form.section_id,
    () => {
        props.form.curriculum_item_id = null;
        props.form.faculty_id = null;
    }
);

watch(
    () => props.form.curriculum_item_id,
    () => {
        props.form.faculty_id = null;
    }
);
</script>

<template>
    <div class="space-y-6">
        <!-- Academic Term -->
        <div>
            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">Academic Term</label>
            <select
                v-model="form.academic_term_id"
                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            >
                <option :value="null" disabled>Select an academic term</option>
                <option v-for="term in academicTerms" :key="term.id" :value="term.id">
                    {{ term.display_name }}
                </option>
            </select>
            <p v-if="form.errors.academic_term_id" class="mt-1 text-sm text-red-500">
                {{ form.errors.academic_term_id }}
            </p>
        </div>

        <!-- Section -->
        <div>
            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">Section</label>
            <select
                v-model="form.section_id"
                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            >
                <option :value="null" disabled>Select a section</option>
                <option v-for="section in sections" :key="section.id" :value="section.id">
                    {{ section.section_code }}
                    <template v-if="section.curriculum?.program">
                        — {{ section.curriculum.program.code }}
                    </template>
                </option>
            </select>
            <p v-if="form.errors.section_id" class="mt-1 text-sm text-red-500">
                {{ form.errors.section_id }}
            </p>
        </div>

        <!-- Curriculum Item -->
        <div>
            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">Curriculum Item</label>
            <select
                v-model="form.curriculum_item_id"
                :disabled="!selectedSection"
                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30 disabled:cursor-not-allowed disabled:bg-[var(--card-border)]/30 disabled:text-[var(--text-muted)]"
            >
                <option :value="null" disabled>
                    {{ selectedSection ? 'Select a curriculum item' : 'Select a section first' }}
                </option>
                <option v-for="item in availableCurriculumItems" :key="item.id" :value="item.id">
                    <template v-if="item.display_code">{{ item.display_code }} — </template>{{ item.display_title }}
                </option>
            </select>
            <p v-if="selectedSection && availableCurriculumItems.length === 0" class="mt-1 text-sm text-[var(--text-muted)]">
                This section's curriculum has no active subjects yet.
            </p>
            <p v-if="form.errors.curriculum_item_id" class="mt-1 text-sm text-red-500">
                {{ form.errors.curriculum_item_id }}
            </p>
        </div>

        <!-- Faculty -->
        <div>
            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">Assigned Faculty</label>
            <select
                v-model="form.faculty_id"
                :disabled="!selectedCurriculumItem"
                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30 disabled:cursor-not-allowed disabled:bg-[var(--card-border)]/30 disabled:text-[var(--text-muted)]"
            >
                <option :value="null" disabled>
                    {{ selectedCurriculumItem ? 'Select a faculty member' : 'Select a curriculum item first' }}
                </option>
                <option v-for="faculty in availableFaculties" :key="faculty.id" :value="faculty.id">
                    {{ faculty.full_name }}
                </option>
            </select>
            <p v-if="selectedCurriculumItem && availableFaculties.length === 0" class="mt-1 text-sm text-amber-600 dark:text-amber-400">
                No faculty is currently qualified to teach this subject. Add a qualification via Faculty Subjects first.
            </p>
            <p v-if="form.errors.faculty_id" class="mt-1 text-sm text-red-500">
                {{ form.errors.faculty_id }}
            </p>
        </div>

        <!-- Remarks -->
        <div>
            <label class="block font-medium mb-1.5 text-sm text-[var(--text-secondary)]">
                Remarks <span class="text-[var(--text-muted)]">(optional)</span>
            </label>
            <textarea
                v-model="form.remarks"
                rows="3"
                placeholder="e.g. Overload, temporary substitute, pending dean approval..."
                class="w-full rounded-xl border border-[var(--card-border)] bg-[var(--page-bg)] px-3 py-2.5 text-sm text-[var(--text-primary)] transition-all duration-200 focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
            ></textarea>
            <p v-if="form.errors.remarks" class="mt-1 text-sm text-red-500">
                {{ form.errors.remarks }}
            </p>
        </div>

        <!-- Active -->
        <label class="flex cursor-pointer items-center gap-3">
            <input
                v-model="form.active"
                type="checkbox"
                class="h-5 w-5 rounded border-[var(--card-border)] accent-[#D4A62A]"
            />
            <span class="text-sm text-[var(--text-primary)]">Active assignment</span>
        </label>
    </div>
</template>