<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
    filters: Object,
})

const emit = defineEmits(['change'])

const localFilters = ref({ ...props.filters })

watch(() => props.filters, (newFilters) => {
    localFilters.value = { ...newFilters }
}, { deep: true })

const handleFilterChange = (key, value) => {
    localFilters.value[key] = value
    emit('change', localFilters.value)
}

const resetFilters = () => {
    localFilters.value = {
        min_score: 1,
        min_complexity: 1,
        starred_only: false,
        include_borderline: true,
        sort_by: 'score_overall',
        sort_dir: 'desc',
    }
    emit('change', localFilters.value)
}
</script>

<template>
    <div class="px-6 py-4 space-y-4">
        <!-- First row: Score, Complexity, Starred -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Min Score -->
            <div>
                <label for="min-score" class="block text-sm font-medium text-gray-700 mb-1">Min Score</label>
                <select
                    id="min-score"
                    :value="localFilters.min_score"
                    @change="handleFilterChange('min_score', parseInt($event.target.value, 10))"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                >
                    <option value="1">1+</option>
                    <option value="2">2+</option>
                    <option value="3">3+</option>
                    <option value="4">4+</option>
                    <option value="5">5+</option>
                </select>
            </div>

            <!-- Min Complexity -->
            <div>
                <label for="min-complexity" class="block text-sm font-medium text-gray-700 mb-1">Min Complexity</label>
                <select
                    id="min-complexity"
                    :value="localFilters.min_complexity"
                    @change="handleFilterChange('min_complexity', parseInt($event.target.value, 10))"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                >
                    <option value="1">1+</option>
                    <option value="2">2+</option>
                    <option value="3">3+</option>
                    <option value="4">4+</option>
                    <option value="5">5+</option>
                </select>
            </div>

            <!-- Starred Only -->
            <div>
                <label class="flex items-center mt-6">
                    <input
                        type="checkbox"
                        :checked="localFilters.starred_only"
                        @change="handleFilterChange('starred_only', $event.target.checked)"
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <span class="ml-2 text-sm text-gray-700">Starred Only</span>
                </label>
            </div>

            <!-- Include Borderline -->
            <div>
                <label class="flex items-center mt-6">
                    <input
                        type="checkbox"
                        :checked="localFilters.include_borderline"
                        @change="handleFilterChange('include_borderline', $event.target.checked)"
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <span class="ml-2 text-sm text-gray-700">Include Borderline</span>
                </label>
            </div>
        </div>

        <!-- Second row: Sorting and Reset -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <!-- Sort By -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                <select
                    :value="localFilters.sort_by"
                    @change="handleFilterChange('sort_by', $event.target.value)"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                >
                    <option value="score_overall">Overall Score</option>
                    <option value="score_monetization">Monetization</option>
                    <option value="score_saturation">Market Open</option>
                    <option value="score_complexity">Buildability</option>
                    <option value="score_demand">Demand</option>
                    <option value="created_at">Date Posted</option>
                </select>
            </div>

            <!-- Sort Direction -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Direction</label>
                <select
                    :value="localFilters.sort_dir"
                    @change="handleFilterChange('sort_dir', $event.target.value)"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                >
                    <option value="desc">Descending</option>
                    <option value="asc">Ascending</option>
                </select>
            </div>

            <!-- Reset Button -->
            <div>
                <button
                    @click="resetFilters"
                    class="w-full px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                >
                    Reset Filters
                </button>
            </div>
        </div>
    </div>
</template>
