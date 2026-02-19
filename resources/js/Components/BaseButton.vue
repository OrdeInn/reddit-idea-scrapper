<script setup>
import { computed } from 'vue'

const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (v) => ['primary', 'secondary', 'danger', 'ghost'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
    loading: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: 'button',
    },
    as: {
        type: [String, Object],
        default: 'button',
    },
    href: {
        type: String,
        default: undefined,
    },
})

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'primary':
            return 'bg-brand-500 text-content-inverse hover:bg-brand-600 focus-visible:ring-brand-500'
        case 'secondary':
            return 'bg-transparent border border-border-default text-content-secondary hover:bg-surface-tertiary focus-visible:ring-brand-500'
        case 'danger':
            return 'bg-status-error text-content-inverse hover:brightness-90 focus-visible:ring-status-error'
        case 'ghost':
            return 'bg-transparent text-content-secondary hover:bg-surface-tertiary focus-visible:ring-brand-500'
        default:
            return ''
    }
})

const sizeClasses = computed(() => {
    switch (props.size) {
        case 'sm':
            // h-8 visual height; min-h-[44px] ensures 44×44px touch target (WCAG 2.1)
            return 'h-8 min-h-[44px] px-3 text-sm gap-1.5'
        case 'md':
            // h-10 visual height; min-h-[44px] ensures 44×44px touch target (WCAG 2.1)
            return 'h-10 min-h-[44px] px-5 text-sm gap-2'
        case 'lg':
            // h-12 = 48px, already exceeds 44px touch target
            return 'h-12 px-6 text-base gap-2.5'
        default:
            return ''
    }
})

const isDisabled = computed(() => props.disabled || props.loading)

// Build component tag and extra attrs
const tag = computed(() => props.as)

const tagProps = computed(() => {
    if (props.as === 'button') {
        return { type: props.type }
    }
    if (props.href !== undefined) {
        return { href: props.href }
    }
    return {}
})
</script>

<template>
    <component
        :is="tag"
        v-bind="{ ...tagProps, ...$attrs }"
        :disabled="as === 'button' ? (isDisabled || undefined) : undefined"
        :aria-disabled="isDisabled ? 'true' : undefined"
        :aria-busy="loading ? 'true' : undefined"
        :tabindex="isDisabled && as !== 'button' ? -1 : undefined"
        :class="[
            'inline-flex items-center justify-center font-medium rounded-lg',
            'min-w-[44px]',
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated',
            'transition-colors duration-150',
            variantClasses,
            sizeClasses,
            isDisabled ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'cursor-pointer',
        ]"
    >
        <!-- Loading spinner replaces all content -->
        <template v-if="loading">
            <svg
                class="animate-spin"
                :class="size === 'sm' ? 'h-3.5 w-3.5' : size === 'lg' ? 'h-5 w-5' : 'h-4 w-4'"
                fill="none"
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path
                    class="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                />
            </svg>
            <span class="sr-only">Loading…</span>
        </template>

        <!-- Normal content with optional icon slots -->
        <template v-else>
            <slot name="icon-left" />
            <slot />
            <slot name="icon-right" />
        </template>
    </component>
</template>
