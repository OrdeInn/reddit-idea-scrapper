<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
    toast: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['dismiss'])

const paused = ref(false)
let timer = null
let remainingTime = props.toast.duration
let startTime = null

const variantConfig = computed(() => {
    switch (props.toast.type) {
        case 'success':
            return { border: 'border-status-success', icon: 'text-status-success', bar: 'bg-status-success' }
        case 'error':
            return { border: 'border-status-error', icon: 'text-status-error', bar: 'bg-status-error' }
        case 'warning':
            return { border: 'border-status-warning', icon: 'text-status-warning', bar: 'bg-status-warning' }
        default: // info
            return { border: 'border-status-info', icon: 'text-status-info', bar: 'bg-status-info' }
    }
})

const startTimer = () => {
    if (props.toast.duration === 0) return
    startTime = Date.now()
    timer = setTimeout(() => {
        emit('dismiss', props.toast.id)
    }, remainingTime)
}

const pauseTimer = () => {
    if (props.toast.duration === 0 || !timer) return
    clearTimeout(timer)
    remainingTime -= Date.now() - startTime
    paused.value = true
}

const resumeTimer = () => {
    if (props.toast.duration === 0) return
    paused.value = false
    startTimer()
}

onMounted(() => {
    startTimer()
})

onBeforeUnmount(() => {
    clearTimeout(timer)
})
</script>

<template>
    <div
        role="alert"
        :class="[
            'relative flex items-start gap-3 rounded-lg bg-surface-elevated shadow-lg overflow-hidden',
            'border-l-4 p-4 w-full',
            variantConfig.border,
        ]"
        @mouseenter="pauseTimer"
        @mouseleave="resumeTimer"
    >
        <!-- Variant icon -->
        <div :class="['flex-shrink-0 mt-0.5', variantConfig.icon]">
            <!-- Success: check circle -->
            <svg v-if="toast.type === 'success'" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            <!-- Error: X circle -->
            <svg v-else-if="toast.type === 'error'" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
            </svg>
            <!-- Warning: exclamation triangle -->
            <svg v-else-if="toast.type === 'warning'" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
            <!-- Info: info circle -->
            <svg v-else class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
            </svg>
        </div>

        <!-- Message + optional action -->
        <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-content-primary">{{ toast.message }}</p>
            <button
                v-if="toast.action"
                type="button"
                :class="['mt-1 text-sm underline font-medium', variantConfig.icon]"
                @click="toast.action.onClick"
            >
                {{ toast.action.label }}
            </button>
        </div>

        <!-- Close button -->
        <button
            type="button"
            aria-label="Dismiss notification"
            class="flex-shrink-0 min-h-[44px] min-w-[44px] flex items-center justify-center -mt-1 -mr-2 text-content-tertiary hover:text-content-secondary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated rounded"
            @click="emit('dismiss', toast.id)"
        >
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
        </button>

        <!-- Countdown bar (shrinks from full width to 0) -->
        <div
            v-if="toast.duration > 0"
            class="absolute bottom-0 left-0 right-0 h-0.5 overflow-hidden"
            aria-hidden="true"
        >
            <div
                :class="['h-full origin-left', variantConfig.bar]"
                :style="{
                    animation: `toast-countdown ${toast.duration}ms linear forwards`,
                    animationPlayState: paused ? 'paused' : 'running',
                }"
            />
        </div>
    </div>
</template>
