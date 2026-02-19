<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import BaseButton from './BaseButton.vue'

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

// Animated counter state
const prefersReducedMotion =
    typeof window !== 'undefined'
        ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
        : false

const displayedStats = ref({
    posts_fetched: props.scan?.posts_fetched ?? 0,
    posts_classified: props.scan?.posts_classified ?? 0,
    posts_extracted: props.scan?.posts_extracted ?? 0,
    ideas_found: props.scan?.ideas_found ?? 0,
})

let animationFrameId = null

const animateCounters = (fromStats, toStats) => {
    if (prefersReducedMotion) {
        displayedStats.value = { ...toStats }
        return
    }

    const duration = 500
    const startTime = performance.now()
    const keys = ['posts_fetched', 'posts_classified', 'posts_extracted', 'ideas_found']

    const step = (currentTime) => {
        const elapsed = currentTime - startTime
        const progress = Math.min(elapsed / duration, 1)
        // Ease-out cubic
        const eased = 1 - Math.pow(1 - progress, 3)

        for (const key of keys) {
            const from = fromStats[key] ?? 0
            const to = toStats[key] ?? 0
            if (to >= from) {
                displayedStats.value[key] = Math.round(from + (to - from) * eased)
            } else {
                // Snap decreases immediately
                displayedStats.value[key] = to
            }
        }

        if (progress < 1) {
            animationFrameId = requestAnimationFrame(step)
        }
    }

    if (animationFrameId) cancelAnimationFrame(animationFrameId)
    animationFrameId = requestAnimationFrame(step)
}

watch(
    () => props.scan,
    (newScan, oldScan) => {
        if (!newScan) return
        const fromStats = {
            posts_fetched: oldScan?.posts_fetched ?? 0,
            posts_classified: oldScan?.posts_classified ?? 0,
            posts_extracted: oldScan?.posts_extracted ?? 0,
            ideas_found: oldScan?.ideas_found ?? 0,
        }
        const toStats = {
            posts_fetched: newScan.posts_fetched ?? 0,
            posts_classified: newScan.posts_classified ?? 0,
            posts_extracted: newScan.posts_extracted ?? 0,
            ideas_found: newScan.ideas_found ?? 0,
        }
        animateCounters(fromStats, toStats)
    },
    { deep: true }
)

onBeforeUnmount(() => {
    if (animationFrameId) cancelAnimationFrame(animationFrameId)
})
</script>

<template>
    <div class="bg-surface-elevated rounded-xl border border-border-default shadow-sm p-6">
        <!-- Failed state -->
        <div
            v-if="isFailed"
            class="flex flex-col items-center text-center p-4 rounded-lg border-l-4 border-status-error bg-surface-secondary"
        >
            <div class="w-12 h-12 rounded-full bg-surface-tertiary flex items-center justify-center mb-4">
                <svg class="w-6 h-6 text-status-error" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold text-content-primary mb-1">Scan Failed</h3>
            <p class="text-sm text-content-secondary mb-4">{{ scan.error_message || 'An unknown error occurred' }}</p>
            <BaseButton variant="primary" @click="emit('retry')">
                <template #icon-left>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </template>
                Retry Scan
            </BaseButton>
        </div>

        <!-- Progress state -->
        <div v-else>
            <!-- Pipeline phase nodes -->
            <div class="flex items-center mb-6" role="list" aria-label="Scan pipeline phases">
                <template v-for="(phase, index) in phases" :key="phase.key">
                    <!-- Phase node -->
                    <div
                        class="flex flex-col items-center"
                        role="listitem"
                        :aria-label="`${phase.label}: ${index < currentPhaseIndex ? 'completed' : index === currentPhaseIndex ? 'active' : 'pending'}`"
                    >
                        <!-- Circle with icon -->
                        <div class="relative">
                            <div
                                :class="[
                                    'w-10 h-10 rounded-full flex items-center justify-center transition-colors duration-300',
                                    index < currentPhaseIndex
                                        ? 'bg-status-success text-content-inverse'
                                        : index === currentPhaseIndex
                                            ? 'bg-status-scanning text-content-inverse'
                                            : 'bg-surface-tertiary border-2 border-border-default text-content-tertiary',
                                ]"
                            >
                                <!-- Completed phases: always show checkmark -->
                                <svg v-if="index < currentPhaseIndex" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <!-- Active/pending phases: show phase-specific icon -->
                                <template v-else>
                                    <!-- Download icon -->
                                    <svg v-if="phase.icon === 'download'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                    <!-- Filter icon -->
                                    <svg v-else-if="phase.icon === 'filter'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                    </svg>
                                    <!-- Lightbulb icon -->
                                    <svg v-else-if="phase.icon === 'lightbulb'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                    </svg>
                                    <!-- Check icon (for the Completed phase node) -->
                                    <svg v-else-if="phase.icon === 'check'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                </template>
                            </div>

                            <!-- Pulse ring on active node -->
                            <span
                                v-if="index === currentPhaseIndex && !isCompleted"
                                class="absolute inset-0 rounded-full bg-status-scanning opacity-30 animate-ping"
                                aria-hidden="true"
                            />
                        </div>

                        <!-- Phase label -->
                        <span
                            :class="[
                                'mt-2 text-xs font-medium',
                                index <= currentPhaseIndex ? 'text-content-primary' : 'text-content-tertiary',
                            ]"
                        >
                            {{ phase.label }}
                        </span>
                    </div>

                    <!-- Connector line between nodes -->
                    <div
                        v-if="index < phases.length - 1"
                        :class="[
                            'flex-1 mx-2 transition-colors duration-300',
                            index < currentPhaseIndex
                                ? 'h-0.5 rounded-full bg-status-success'
                                : index === currentPhaseIndex
                                    ? 'h-0 border-t-2 border-dashed border-status-scanning'
                                    : 'h-0.5 rounded-full bg-border-default',
                        ]"
                        aria-hidden="true"
                    />
                </template>
            </div>

            <!-- Progress bar with shimmer -->
            <div class="mb-5">
                <div
                    class="h-2 bg-surface-tertiary rounded-full overflow-hidden"
                    role="progressbar"
                    :aria-valuenow="progressPercent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-label="Scan progress"
                >
                    <div
                        :style="{ width: `${progressPercent}%` }"
                        class="relative h-full bg-gradient-to-r from-brand-400 to-brand-600 rounded-full transition-all duration-500 ease-out overflow-hidden"
                    >
                        <!-- Shimmer overlay -->
                        <div
                            class="absolute inset-0 bg-shimmer opacity-40"
                            aria-hidden="true"
                        />
                    </div>
                </div>
            </div>

            <!-- Stats grid -->
            <div class="grid grid-cols-4 gap-4 text-center mb-4">
                <div>
                    <p class="font-mono text-2xl font-black text-content-primary tabular-nums">{{ displayedStats.posts_fetched }}</p>
                    <p class="text-xs text-content-tertiary mt-0.5">Fetched</p>
                </div>
                <div>
                    <p class="font-mono text-2xl font-black text-content-primary tabular-nums">{{ displayedStats.posts_classified }}</p>
                    <p class="text-xs text-content-tertiary mt-0.5">Classified</p>
                </div>
                <div>
                    <p class="font-mono text-2xl font-black text-content-primary tabular-nums">{{ displayedStats.posts_extracted }}</p>
                    <p class="text-xs text-content-tertiary mt-0.5">Extracted</p>
                </div>
                <div>
                    <p class="font-mono text-2xl font-black text-brand-500 tabular-nums">{{ displayedStats.ideas_found }}</p>
                    <p class="text-xs text-content-tertiary mt-0.5">Ideas found</p>
                </div>
            </div>

            <!-- Status message -->
            <p
                class="text-sm text-center text-content-tertiary"
                aria-live="polite"
                role="status"
            >
                {{ scan.status_message }}
            </p>
        </div>
    </div>
</template>
