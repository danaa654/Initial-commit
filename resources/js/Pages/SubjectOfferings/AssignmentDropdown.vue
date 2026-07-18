<script setup>
import { ref, computed, nextTick, onBeforeUnmount } from 'vue'
import { Teleport } from 'vue'
import { ChevronDownIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    // Currently selected id ('' / null means "Unassigned")
    modelValue: { type: [String, Number], default: '' },
    // [{ id, label }]
    options: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    // Extra classes applied to the trigger button (e.g. the amber/emerald badge classes)
    badgeClass: { type: String, default: '' },
    placeholder: { type: String, default: 'Unassigned' },
    maxWidthClass: { type: String, default: 'max-w-[180px]' },
    // Shows an "Override Eligibility" checkbox above the search box.
    // When checked, the dropdown swaps `options` for `overrideOptions`
    // (the full, unfiltered list) — same concept as Master Grid's
    // per-edit Override Eligibility checkbox. Off by default and left
    // to the parent to reset after each use (see Index.vue), so an
    // override is a deliberate choice made fresh each time, never an
    // accidentally-sticky setting.
    overridable: { type: Boolean, default: false },
    override: { type: Boolean, default: false },
    overrideOptions: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'update:override'])

const open = ref(false)
const triggerRef = ref(null)
const listRef = ref(null)
const searchInputRef = ref(null)
const menuStyle = ref({})
const query = ref('')

const activeOptions = computed(() => (props.overridable && props.override) ? props.overrideOptions : props.options)

const selectedLabel = computed(() => {
    const match = activeOptions.value.find(o => String(o.id) === String(props.modelValue))
    return match ? match.label : props.placeholder
})

// Simple case-insensitive substring match against the label — options
// lists here (Faculty, Rooms) are short enough that anything fancier
// (fuzzy matching, ranking) would be overkill.
const filteredOptions = computed(() => {
    const q = query.value.trim().toLowerCase()
    if (! q) return activeOptions.value

    return activeOptions.value.filter(o => o.label.toLowerCase().includes(q))
})

function positionMenu() {
    if (! triggerRef.value) return

    const rect = triggerRef.value.getBoundingClientRect()
    const viewportHeight = window.innerHeight
    const spaceBelow = viewportHeight - rect.bottom

    // Always prefer opening downward. Only flip up if there isn't even
    // enough room below for a reasonably short menu AND there's more
    // room above than below.
    const minMenuHeight = 200
    const openUpward = spaceBelow < minMenuHeight && rect.top > spaceBelow

    menuStyle.value = {
        position: 'fixed',
        left: `${rect.left}px`,
        width: `${Math.max(rect.width, 180)}px`,
        ...(openUpward
            ? { bottom: `${viewportHeight - rect.top + 4}px`, maxHeight: `${Math.min(rect.top - 12, 260)}px` }
            : { top: `${rect.bottom + 4}px`, maxHeight: `${Math.min(spaceBelow - 12, 260)}px` }),
    }
}

async function toggleOpen() {
    if (props.disabled) return

    open.value = ! open.value

    if (open.value) {
        query.value = ''
        await nextTick()
        positionMenu()
        searchInputRef.value?.focus()
        window.addEventListener('scroll', positionMenu, true)
        window.addEventListener('resize', positionMenu)
        document.addEventListener('mousedown', handleClickOutside)
    } else {
        cleanupListeners()
    }
}

function handleClickOutside(e) {
    if (triggerRef.value?.contains(e.target)) return
    if (listRef.value?.contains(e.target)) return
    closeMenu()
}

function closeMenu() {
    open.value = false
    cleanupListeners()
}

function cleanupListeners() {
    window.removeEventListener('scroll', positionMenu, true)
    window.removeEventListener('resize', positionMenu)
    document.removeEventListener('mousedown', handleClickOutside)
}

function select(id, option = null) {
    if (option?.disabled) return

    emit('update:modelValue', id)
    closeMenu()
}

onBeforeUnmount(cleanupListeners)
</script>

<template>
    <div class="relative inline-block w-full">
        <button
            ref="triggerRef"
            type="button"
            :disabled="disabled"
            class="flex w-full items-center justify-between gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold mx-auto disabled:cursor-not-allowed disabled:opacity-50"
            :class="[badgeClass, maxWidthClass]"
            @click="toggleOpen"
        >
            <span class="truncate">{{ selectedLabel }}</span>
            <ChevronDownIcon class="h-3 w-3 flex-shrink-0 transition-transform" :class="{ 'rotate-180': open }" />
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="listRef"
                :style="menuStyle"
                class="z-50 flex flex-col overflow-hidden rounded-xl border border-[var(--card-border)] bg-[var(--card-bg)] shadow-lg"
            >
                <label
                    v-if="overridable"
                    class="flex items-center gap-1.5 border-b border-[var(--card-border)] px-2.5 py-1.5 text-[11px] font-semibold cursor-pointer select-none"
                    :class="override ? 'text-amber-600 dark:text-amber-400' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'"
                >
                    <input
                        type="checkbox"
                        class="rounded"
                        :checked="override"
                        @change="emit('update:override', $event.target.checked)"
                    />
                    Override Eligibility
                </label>

                <div class="border-b border-[var(--card-border)] p-1.5">
                    <input
                        ref="searchInputRef"
                        v-model="query"
                        type="text"
                        placeholder="Search..."
                        class="w-full rounded-lg border border-[var(--card-border)] bg-[var(--page-bg)] px-2 py-1 text-xs text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[#D4A62A] focus:outline-none focus:ring-2 focus:ring-[#D4A62A]/30"
                        @keydown.escape="closeMenu"
                    />
                </div>

                <div class="assignment-dropdown-scroll min-h-0 flex-1 overflow-y-auto py-1">
                    <button
                        v-if="! query.trim()"
                        type="button"
                        class="block w-full truncate px-3 py-1.5 text-left text-xs font-medium text-[var(--text-muted)] hover:bg-[var(--page-bg)]"
                        @click="select('')"
                    >
                        {{ placeholder }}
                    </button>
                    <button
                        v-for="option in filteredOptions"
                        :key="option.id"
                        type="button"
                        class="block w-full truncate px-3 py-1.5 text-left text-xs font-medium"
                        :class="[
                            option.disabled
                                ? 'cursor-not-allowed text-[var(--text-muted)] opacity-60'
                                : 'text-[var(--text-primary)] hover:bg-[#D4A62A]/10',
                            ! option.disabled && String(option.id) === String(modelValue) ? 'bg-[#D4A62A]/10 text-[#D4A62A]' : '',
                        ]"
                        :disabled="option.disabled"
                        :title="option.disabled ? 'This faculty member is at their maximum teaching load' : ''"
                        :aria-disabled="option.disabled"
                        @click="select(option.id, option)"
                    >
                        {{ option.label }}
                    </button>
                    <div v-if="filteredOptions.length === 0" class="px-3 py-1.5 text-xs text-[var(--text-muted)]">
                        No matches found
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
/* Thin, styled scrollbar — this is the whole point: native <select>
   popups can't be styled at all, so this custom list is what makes a
   thin scrollbar (and forced downward opening) possible in the first
   place. */
.assignment-dropdown-scroll::-webkit-scrollbar {
    width: 6px;
}
.assignment-dropdown-scroll::-webkit-scrollbar-track {
    background: transparent;
}
.assignment-dropdown-scroll::-webkit-scrollbar-thumb {
    background-color: #D4A62A;
    border-radius: 9999px;
}
/* Firefox */
.assignment-dropdown-scroll {
    scrollbar-width: thin;
    scrollbar-color: #D4A62A transparent;
}
</style>