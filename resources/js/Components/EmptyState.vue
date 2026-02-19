<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
})
</script>

<template>
    <div class="flex flex-col items-center justify-center py-16 px-6 rounded-xl border-2 border-dashed border-border-default bg-surface-secondary text-center">
        <!-- Icon slot -->
        <div v-if="$slots.icon" class="mb-4 text-content-tertiary">
            <slot name="icon" />
        </div>

        <!-- Title -->
        <h3 class="text-base font-semibold text-content-primary">
            {{ title }}
        </h3>

        <!-- Description -->
        <p v-if="description" class="mt-1.5 text-sm text-content-secondary max-w-sm">
            {{ description }}
        </p>

        <!-- Action slot -->
        <div v-if="$slots.action" class="mt-6">
            <slot name="action" />
        </div>
    </div>
</template>
