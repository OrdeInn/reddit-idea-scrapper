<script setup>
defineProps({
    value: {
        type: [String, Number],
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    highlight: {
        type: Boolean,
        default: false,
    },
})
</script>

<template>
    <div class="flex flex-col items-start gap-0.5 rounded-lg border border-border-default bg-surface-elevated px-4 py-3">
        <span
            class="font-mono text-2xl font-bold leading-none tabular-nums"
            :class="highlight ? 'text-brand-500' : 'text-content-primary'"
        >
            <!-- Display "0" explicitly rather than falsy empty -->
            {{ value === null || value === undefined ? '—' : value }}
        </span>
        <span class="text-xs text-content-tertiary leading-snug">
            {{ label }}
        </span>
    </div>
</template>
