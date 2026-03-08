<script setup>
import { computed } from 'vue'

const props = defineProps({
    provider: {
        type: String,
        default: null,
    },
    size: {
        type: String,
        default: 'sm',
        validator: (v) => ['xs', 'sm'].includes(v),
    },
    showModel: {
        type: Boolean,
        default: false,
    },
})

const PROVIDER_CONFIG = {
    'anthropic-sonnet': {
        label: 'Sonnet',
        model: 'claude-sonnet-4-5',
        classes: 'bg-amber-50 text-amber-700 ring-amber-200/60 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40',
        dot: 'bg-amber-400 dark:bg-amber-500',
    },
    'anthropic-haiku': {
        label: 'Haiku',
        model: 'claude-haiku-4-5',
        classes: 'bg-purple-50 text-purple-700 ring-purple-200/60 dark:bg-purple-900/20 dark:text-purple-300 dark:ring-purple-700/40',
        dot: 'bg-purple-400 dark:bg-purple-500',
    },
    'openai': {
        label: 'GPT',
        model: 'gpt-5-mini',
        classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200/60 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40',
        dot: 'bg-emerald-400 dark:bg-emerald-500',
    },
}

const UNKNOWN_CONFIG = {
    label: 'Unknown',
    model: null,
    classes: 'bg-surface-tertiary text-content-tertiary ring-border-default',
    dot: 'bg-content-tertiary',
}

const current = computed(() => {
    const key = props.provider
    if (!key || key === '') return UNKNOWN_CONFIG
    return PROVIDER_CONFIG[key] ?? { ...UNKNOWN_CONFIG, label: key, model: null }
})

const sizeClasses = {
    xs: 'text-[10px] px-1.5 py-0.5 gap-1',
    sm: 'text-xs px-2 py-0.5 gap-1',
}

const dotSizeClasses = {
    xs: 'w-1 h-1',
    sm: 'w-1.5 h-1.5',
}
</script>

<template>
    <span
        :title="showModel && current.model ? current.model : undefined"
        :class="[
            'inline-flex items-center rounded-full font-medium whitespace-nowrap ring-1',
            current.classes,
            sizeClasses[size],
        ]"
    >
        <span :class="['rounded-full flex-shrink-0', current.dot, dotSizeClasses[size]]" aria-hidden="true" />
        {{ current.label }}
    </span>
</template>
