<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
    score: {
        type: Number,
        required: true,
    },
    size: {
        type: Number,
        default: 36,
    },
    strokeWidth: {
        type: Number,
        default: 3,
    },
    animate: {
        type: Boolean,
        default: true,
    },
    label: {
        type: String,
        default: '',
    },
    delay: {
        type: Number,
        default: 0,
    },
})

const animated = ref(false)
let animateTimeout = null

const prefersReducedMotion =
    typeof window !== 'undefined'
        ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
        : false

const isEmpty = computed(() => props.score == null || props.score === 0)
const clampedScore = computed(() => {
    if (props.score == null) return 0
    return Math.min(5, Math.max(0, props.score))
})

const center = computed(() => props.size / 2)
const radius = computed(() => (props.size - props.strokeWidth) / 2)
const circumference = computed(() => 2 * Math.PI * radius.value)
const targetOffset = computed(() => circumference.value * (1 - clampedScore.value / 5))
const currentOffset = computed(() => {
    // Show final position immediately when: reduced motion, no animation requested, or already animated
    if (prefersReducedMotion || !props.animate || animated.value) {
        return targetOffset.value
    }
    // Start from full offset (empty ring) for initial animation
    return circumference.value
})

const fontSize = computed(() => Math.max(8, Math.round(props.size * 0.32)))

const scoreColorClass = computed(() => {
    if (isEmpty.value) return ''
    const s = clampedScore.value
    if (s >= 4) return 'stroke-score-excellent'
    if (s >= 3) return 'stroke-score-good'
    if (s >= 2) return 'stroke-score-average'
    return 'stroke-score-poor'
})

const triggerAnimation = () => {
    if (prefersReducedMotion) return
    clearTimeout(animateTimeout)
    animated.value = false
    animateTimeout = setTimeout(() => {
        animated.value = true
    }, props.delay)
}

// Animate on mount if animate=true from the start
onMounted(() => {
    if (props.animate) triggerAnimation()
})

// Re-animate when animate prop flips from false → true (e.g. IdeaRow expand)
watch(() => props.animate, (val) => {
    if (val) triggerAnimation()
})

onBeforeUnmount(() => {
    clearTimeout(animateTimeout)
})
</script>

<template>
    <div class="inline-flex flex-col items-center gap-1">
        <svg
            :width="size"
            :height="size"
            :viewBox="`0 0 ${size} ${size}`"
            role="img"
            :aria-label="isEmpty ? 'Score: not rated' : `Score: ${clampedScore} out of 5`"
        >
            <!-- Track (background ring) -->
            <circle
                :cx="center"
                :cy="center"
                :r="radius"
                fill="none"
                class="stroke-border-default"
                :stroke-width="strokeWidth"
            />
            <!-- Fill (progress arc), rotated so arc starts from 12 o'clock -->
            <circle
                v-if="!isEmpty"
                :cx="center"
                :cy="center"
                :r="radius"
                fill="none"
                :class="scoreColorClass"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="currentOffset"
                :transform="`rotate(-90, ${center}, ${center})`"
                :style="{
                    transition:
                        animate && !prefersReducedMotion
                            ? 'stroke-dashoffset 600ms ease-out'
                            : 'none',
                }"
            />
            <!-- Score number (centered, monospace) -->
            <text
                :x="center"
                :y="center"
                text-anchor="middle"
                dominant-baseline="central"
                :font-size="fontSize"
                font-family="JetBrains Mono, monospace"
                font-weight="700"
                class="fill-content-primary"
                aria-hidden="true"
            >{{ isEmpty ? '—' : clampedScore }}</text>
        </svg>

        <!-- Optional label below (hidden when size < 24) -->
        <span
            v-if="label && size >= 24"
            class="text-xs text-content-tertiary font-medium leading-none"
            aria-hidden="true"
        >{{ label }}</span>
    </div>
</template>
