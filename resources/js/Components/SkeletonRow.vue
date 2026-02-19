<script setup>
defineProps({
    showSubreddit: {
        type: Boolean,
        default: false,
    },
})
</script>

<template>
    <div
        aria-hidden="true"
        :class="[
            'grid gap-3 items-center px-5 py-3.5',
            showSubreddit
                ? 'grid-cols-[auto_1fr_auto_auto_auto_auto] md:grid-cols-[40px_1fr_48px_minmax(0,200px)_auto_auto_auto]'
                : 'grid-cols-[auto_1fr_auto_auto_auto] md:grid-cols-[40px_1fr_48px_minmax(0,200px)_auto_auto]',
        ]"
    >
        <!-- Star: 20×20 circle -->
        <div
            class="w-5 h-5 rounded-full bg-surface-tertiary animate-pulse"
            style="animation-delay: 0ms"
        />

        <!-- Title: ~70% width, 16px height -->
        <div
            class="h-4 rounded-md bg-surface-tertiary animate-pulse"
            style="width: 70%; animation-delay: 50ms"
        />

        <!-- Score: 36×36 circle -->
        <div
            class="w-9 h-9 rounded-full bg-surface-tertiary animate-pulse mx-auto"
            style="animation-delay: 100ms"
        />

        <!-- Audience: 70% width, 14px height -->
        <div
            class="h-3.5 rounded bg-surface-tertiary animate-pulse"
            style="width: 70%; animation-delay: 150ms"
        />

        <!-- Subreddit (conditional): 50% width -->
        <div
            v-if="showSubreddit"
            class="h-3.5 rounded bg-surface-tertiary animate-pulse"
            style="width: 50%; animation-delay: 200ms"
        />

        <!-- Date + Expand: flex row aligned to end -->
        <div class="flex items-center justify-end gap-2" :style="{ animationDelay: showSubreddit ? '250ms' : '200ms' }">
            <div class="h-3.5 w-16 rounded bg-surface-tertiary animate-pulse" />
            <div class="w-4 h-4 rounded bg-surface-tertiary animate-pulse" />
        </div>
    </div>
</template>
