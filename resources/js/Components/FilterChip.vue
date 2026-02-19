<script setup>
defineProps({
    label: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
        default: false,
    },
    count: {
        type: Number,
        default: null,
    },
})

const emit = defineEmits(['toggle'])
</script>

<template>
    <button
        type="button"
        :aria-pressed="active"
        :class="[
            'inline-flex items-center gap-1.5 rounded-full px-3',
            'min-h-[44px] min-w-[44px] text-sm font-medium max-w-[160px]',
            'transition-colors duration-150',
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
            'focus-visible:ring-offset-1 focus-visible:ring-offset-surface-primary',
            'active:scale-95',
            active
                ? 'bg-brand-100 text-brand-700 border border-brand-300'
                : 'bg-surface-tertiary text-content-secondary border border-transparent hover:bg-surface-secondary',
        ]"
        @click="emit('toggle')"
    >
        <!-- Label with truncation for long text -->
        <span class="truncate">{{ label }}</span>

        <!-- Count badge -->
        <span
            v-if="count !== null"
            :aria-label="`${count} items`"
            :class="[
                'inline-flex items-center justify-center rounded-full text-xs font-semibold leading-none',
                'min-w-[1.25rem] h-5 px-1 flex-shrink-0',
                active
                    ? 'bg-brand-500 text-content-inverse'
                    : 'bg-surface-secondary text-content-tertiary',
            ]"
        >
            {{ count }}
        </span>
    </button>
</template>
