<script setup>
import { computed } from 'vue'

const props = defineProps({
    scan: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['retry'])

const phases = [
    { key: 'fetching', label: 'Fetching', icon: 'download' },
    { key: 'classifying', label: 'Classifying', icon: 'filter' },
    { key: 'extracting', label: 'Extracting', icon: 'lightbulb' },
    { key: 'completed', label: 'Completed', icon: 'check' },
]

const currentPhaseIndex = computed(() => {
    const statusMap = {
        pending: -1,
        fetching: 0,
        classifying: 1,
        extracting: 2,
        completed: 3,
        failed: -1,
    }
    return statusMap[props.scan?.status] ?? -1
})

const progressPercent = computed(() => {
    const percent = Number(props.scan?.progress_percent ?? 0)
    return Math.min(100, Math.max(0, percent))
})

const isFailed = computed(() => props.scan?.is_failed === true || props.scan?.status === 'failed')
const isCompleted = computed(() => props.scan?.status === 'completed')
</script>

<template>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <!-- Failed State -->
        <div v-if="isFailed" class="text-center">
            <div class="mx-auto w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mb-4">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Scan Failed</h3>
            <p class="text-sm text-gray-500 mb-4">{{ scan.error_message || 'An unknown error occurred' }}</p>
            <button
                type="button"
                @click="emit('retry')"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Retry Scan
            </button>
        </div>

        <!-- Progress State -->
        <div v-else>
            <!-- Phase Indicators -->
            <div class="flex items-center justify-between mb-6">
                <template v-for="(phase, index) in phases" :key="phase.key">
                    <div class="flex flex-col items-center">
                        <div
                            :class="[
                                'relative w-10 h-10 rounded-full flex items-center justify-center transition-colors',
                                index < currentPhaseIndex ? 'bg-green-500 text-white' :
                                index === currentPhaseIndex ? 'bg-indigo-600 text-white' :
                                'bg-gray-200 text-gray-400'
                            ]"
                        >
                            <!-- Download icon -->
                            <svg v-if="phase.icon === 'download'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            <!-- Filter icon -->
                            <svg v-else-if="phase.icon === 'filter'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                            </svg>
                            <!-- Lightbulb icon -->
                            <svg v-else-if="phase.icon === 'lightbulb'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                            </svg>
                            <!-- Check icon -->
                            <svg v-else-if="phase.icon === 'check'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>

                            <!-- Spinner for current phase -->
                            <div
                                v-if="index === currentPhaseIndex && !isCompleted"
                                class="absolute inset-0 flex items-center justify-center pointer-events-none"
                            >
                                <svg class="animate-spin h-10 w-10 text-indigo-300" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                            </div>
                        </div>
                        <span
                            :class="[
                                'mt-2 text-xs font-medium',
                                index <= currentPhaseIndex ? 'text-gray-900' : 'text-gray-400'
                            ]"
                        >
                            {{ phase.label }}
                        </span>
                    </div>

                    <!-- Connector line -->
                    <div
                        v-if="index < phases.length - 1"
                        :class="[
                            'flex-1 h-1 mx-2 rounded',
                            index < currentPhaseIndex ? 'bg-green-500' : 'bg-gray-200'
                        ]"
                    />
                </template>
            </div>

            <!-- Progress Bar -->
            <div class="mb-4">
                <div class="h-2 bg-gray-200 rounded-full overflow-hidden" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100" aria-label="Scan progress">
                    <div
                        :style="{ width: `${progressPercent}%` }"
                        class="h-full bg-indigo-600 rounded-full transition-all duration-500"
                    />
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-4 gap-4 text-center">
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ scan.posts_fetched ?? 0 }}</p>
                    <p class="text-xs text-gray-500">Posts fetched</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ scan.posts_classified ?? 0 }}</p>
                    <p class="text-xs text-gray-500">Classified</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ scan.posts_extracted ?? 0 }}</p>
                    <p class="text-xs text-gray-500">Extracted</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-indigo-600">{{ scan.ideas_found ?? 0 }}</p>
                    <p class="text-xs text-gray-500">Ideas found</p>
                </div>
            </div>

            <!-- Status Message -->
            <p class="mt-4 text-sm text-center text-gray-500" aria-live="polite" role="status">
                {{ scan.status_message }}
            </p>
        </div>
    </div>
</template>
