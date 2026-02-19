<script setup>
import { ref, computed, watch } from 'vue'
import FilterChip from './FilterChip.vue'

const props = defineProps({
    filters: {
        type: Object,
        required: true,
    },
    defaults: {
        type: Object,
        default: null,
    },
})

const emit = defineEmits(['change'])

const localFilters = ref({ ...props.filters })
const showMoreFilters = ref(false)

// Sync local state when parent changes filters (e.g. mode switch)
watch(
    () => props.filters,
    (newFilters) => {
        localFilters.value = { ...newFilters }
    },
    { deep: true }
)

// Effective defaults — use provided defaults or fallback
const effectiveDefaults = computed(() => props.defaults ?? {
    min_score: 1,
    min_complexity: 1,
    starred_only: false,
    include_borderline: true,
    sort_by: 'score_overall',
    sort_dir: 'desc',
})

// Count active filters (excluding sort_by and sort_dir)
const activeFilterCount = computed(() => {
    const d = effectiveDefaults.value
    const f = localFilters.value
    let count = 0
    if (f.min_score !== d.min_score) count++
    if (f.min_complexity !== d.min_complexity) count++
    if (f.starred_only !== d.starred_only) count++
    if (f.include_borderline !== d.include_borderline) count++
    return count
})

// Chip active states derived from filter values
const isScore4Active = computed(() => localFilters.value.min_score >= 4)
const isScore3Active = computed(() => localFilters.value.min_score === 3)
const isStarredActive = computed(() => localFilters.value.starred_only === true)
const isEasyBuildActive = computed(() => localFilters.value.min_complexity >= 3)

const handleFilterChange = (updates) => {
    localFilters.value = { ...localFilters.value, ...updates }
    emit('change', { ...localFilters.value })
}

const toggleScore4 = () => {
    handleFilterChange({ min_score: isScore4Active.value ? 1 : 4 })
}

const toggleScore3 = () => {
    handleFilterChange({ min_score: isScore3Active.value ? 1 : 3 })
}

const toggleStarred = () => {
    handleFilterChange({ starred_only: !localFilters.value.starred_only })
}

const toggleEasyBuild = () => {
    handleFilterChange({ min_complexity: isEasyBuildActive.value ? 1 : 3 })
}

const toggleSortDir = () => {
    handleFilterChange({ sort_dir: localFilters.value.sort_dir === 'desc' ? 'asc' : 'desc' })
}

const clearAllFilters = () => {
    localFilters.value = { ...effectiveDefaults.value }
    emit('change', { ...localFilters.value })
}
</script>

<template>
    <div class="px-5 py-3 space-y-0">
        <!-- Quick filter chips row -->
        <div class="flex items-center gap-2 flex-nowrap overflow-x-auto py-1">
            <!-- Scroll gradient hints on mobile -->
            <div class="flex items-center gap-2 flex-nowrap min-w-0">
                <FilterChip
                    label="Score 4+"
                    :active="isScore4Active"
                    @toggle="toggleScore4"
                />
                <FilterChip
                    label="Score 3+"
                    :active="isScore3Active"
                    @toggle="toggleScore3"
                />
                <FilterChip
                    label="Starred"
                    :active="isStarredActive"
                    @toggle="toggleStarred"
                />
                <FilterChip
                    label="Easy to Build"
                    :active="isEasyBuildActive"
                    @toggle="toggleEasyBuild"
                />
            </div>

            <!-- Spacer -->
            <div class="flex-1 min-w-2" />

            <!-- More Filters toggle -->
            <button
                type="button"
                @click="showMoreFilters = !showMoreFilters"
                :aria-expanded="showMoreFilters"
                aria-controls="more-filters-panel"
                class="flex-shrink-0 inline-flex items-center gap-1.5 px-3 min-h-[44px] rounded-lg text-sm font-medium text-content-secondary hover:text-content-primary hover:bg-surface-tertiary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                </svg>
                Filters
                <span
                    v-if="activeFilterCount > 0"
                    class="inline-flex items-center justify-center rounded-full bg-brand-500 text-content-inverse text-xs font-semibold leading-none min-w-[1.25rem] h-5 px-1"
                    :aria-label="`${activeFilterCount} active filter${activeFilterCount !== 1 ? 's' : ''}`"
                >
                    {{ activeFilterCount }}
                </span>
                <svg
                    :class="['w-3.5 h-3.5 transition-transform duration-200', showMoreFilters ? 'rotate-180' : '']"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <!-- Clear all button -->
            <button
                v-if="activeFilterCount > 0"
                type="button"
                @click="clearAllFilters"
                class="flex-shrink-0 min-h-[44px] px-2 text-sm font-medium text-content-tertiary hover:text-status-error transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded-lg"
            >
                Clear all
            </button>
        </div>

        <!-- More Filters collapsible panel (CSS grid animation) -->
        <div
            id="more-filters-panel"
            class="grid transition-[grid-template-rows] duration-200 ease-out"
            :style="{ gridTemplateRows: showMoreFilters ? '1fr' : '0fr' }"
            :inert="!showMoreFilters || undefined"
        >
            <div class="overflow-hidden">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-3 pb-1">
                    <!-- Sort By -->
                    <div>
                        <label for="sort-by" class="block text-xs font-medium text-content-tertiary uppercase tracking-wide mb-1.5">
                            Sort by
                        </label>
                        <select
                            id="sort-by"
                            :value="localFilters.sort_by"
                            @change="handleFilterChange({ sort_by: $event.target.value })"
                            class="w-full px-3 py-2 text-sm border border-border-default rounded-lg bg-surface-secondary text-content-primary focus:outline-none focus:border-brand-500"
                        >
                            <option value="score_overall">Overall Score</option>
                            <option value="score_monetization">Monetization</option>
                            <option value="score_saturation">Market Open</option>
                            <option value="score_complexity">Buildability</option>
                            <option value="score_demand">Demand</option>
                            <option value="created_at">Date Posted</option>
                            <option v-if="filters.sort_by === 'starred_at'" value="starred_at">Starred At</option>
                        </select>
                    </div>

                    <!-- Sort Direction -->
                    <div>
                        <span class="block text-xs font-medium text-content-tertiary uppercase tracking-wide mb-1.5">Direction</span>
                        <button
                            type="button"
                            @click="toggleSortDir"
                            :aria-label="`Sort direction: ${localFilters.sort_dir === 'desc' ? 'descending' : 'ascending'}. Click to toggle.`"
                            :aria-pressed="localFilters.sort_dir === 'asc'"
                            class="flex items-center gap-2 px-3 py-2 min-h-[44px] w-full border border-border-default rounded-lg bg-surface-secondary text-sm text-content-primary hover:bg-surface-tertiary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        >
                            <svg
                                :class="['w-4 h-4 text-content-tertiary transition-transform duration-200', localFilters.sort_dir === 'asc' ? 'rotate-180' : '']"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                            {{ localFilters.sort_dir === 'desc' ? 'Descending' : 'Ascending' }}
                        </button>
                    </div>

                    <!-- Min Complexity -->
                    <div>
                        <label for="min-complexity" class="block text-xs font-medium text-content-tertiary uppercase tracking-wide mb-1.5">
                            Buildability
                        </label>
                        <select
                            id="min-complexity"
                            :value="localFilters.min_complexity"
                            @change="handleFilterChange({ min_complexity: parseInt($event.target.value, 10) })"
                            class="w-full px-3 py-2 text-sm border border-border-default rounded-lg bg-surface-secondary text-content-primary focus:outline-none focus:border-brand-500"
                        >
                            <option value="1">Any</option>
                            <option value="2">2+ Medium</option>
                            <option value="3">3+ Easy</option>
                            <option value="4">4+ Very Easy</option>
                        </select>
                    </div>

                    <!-- Include Borderline -->
                    <div>
                        <span class="block text-xs font-medium text-content-tertiary uppercase tracking-wide mb-1.5">Borderline</span>
                        <label class="flex items-center gap-2 min-h-[44px] cursor-pointer">
                            <input
                                type="checkbox"
                                :checked="localFilters.include_borderline"
                                @change="handleFilterChange({ include_borderline: $event.target.checked })"
                                class="rounded border-border-default"
                            />
                            <span class="text-sm text-content-primary">Include borderline</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
