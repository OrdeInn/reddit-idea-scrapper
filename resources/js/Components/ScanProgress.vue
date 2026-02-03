<script setup>
import { computed } from 'vue'

const props = defineProps({
    scan: Object,
})

const progressPercent = computed(() => {
    const percent = Math.round((props.scan?.progress_percent || 0))
    return Math.max(0, Math.min(100, percent))
})

const getStatusColor = (status) => {
    const statusMap = {
        pending: 'bg-gray-100 text-gray-700',
        fetching: 'bg-blue-100 text-blue-700',
        classifying: 'bg-indigo-100 text-indigo-700',
        extracting: 'bg-purple-100 text-purple-700',
        completed: 'bg-green-100 text-green-700',
        failed: 'bg-red-100 text-red-700',
    }
    return statusMap[status] || 'bg-gray-100 text-gray-700'
}

const statusLabels = {
    pending: 'Pending',
    fetching: 'Fetching Posts',
    classifying: 'Classifying',
    extracting: 'Extracting Ideas',
    completed: 'Completed',
    failed: 'Failed',
}
</script>

<template>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="space-y-4">
            <!-- Status -->
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">Scan Progress</h3>
                <span :class="['px-3 py-1 rounded-full text-sm font-medium', getStatusColor(scan.status)]">
                    {{ statusLabels[scan.status] || scan.status }}
                </span>
            </div>

            <!-- Progress Bar -->
            <div>
                <div class="flex justify-between mb-2">
                    <p class="text-sm text-gray-600">{{ scan.status_message }}</p>
                    <p class="text-sm font-medium text-gray-900">{{ progressPercent }}%</p>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100">
                    <div
                        class="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                        :style="{ width: `${progressPercent}%` }"
                    />
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-900">{{ scan.posts_fetched }}</p>
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Posts Fetched</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-900">{{ scan.posts_classified }}</p>
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Classified</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-900">{{ scan.posts_extracted }}</p>
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Extracted</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-indigo-600">{{ scan.ideas_found }}</p>
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Ideas Found</p>
                </div>
            </div>
        </div>
    </div>
</template>
