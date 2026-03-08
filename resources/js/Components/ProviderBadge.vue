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
    'anthropic-opus': {
        label: 'Opus',
        model: 'claude-opus-4-6',
        classes: 'bg-red-50 text-red-700 ring-red-200/60 dark:bg-red-900/20 dark:text-red-300 dark:ring-red-700/40',
        dot: 'bg-red-400 dark:bg-red-500',
    },
    'openai-gpt5-mini': {
        label: 'GPT Mini',
        model: 'gpt-5-mini',
        classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200/60 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40',
        dot: 'bg-emerald-400 dark:bg-emerald-500',
    },
    'openai-gpt5-2': {
        label: 'GPT-5.2',
        model: 'gpt-5.2',
        classes: 'bg-green-50 text-green-700 ring-green-200/60 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-700/40',
        dot: 'bg-green-400 dark:bg-green-500',
    },
}

// Pre-defined palette for auto-color assignment to unknown providers.
// Full literal class strings are required so Tailwind's JIT compiler includes them.
const AUTO_COLOR_PALETTE = [
    {
        classes: 'bg-blue-50 text-blue-700 ring-blue-200/60 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40',
        dot: 'bg-blue-400 dark:bg-blue-500',
    },
    {
        classes: 'bg-rose-50 text-rose-700 ring-rose-200/60 dark:bg-rose-900/20 dark:text-rose-300 dark:ring-rose-700/40',
        dot: 'bg-rose-400 dark:bg-rose-500',
    },
    {
        classes: 'bg-cyan-50 text-cyan-700 ring-cyan-200/60 dark:bg-cyan-900/20 dark:text-cyan-300 dark:ring-cyan-700/40',
        dot: 'bg-cyan-400 dark:bg-cyan-500',
    },
    {
        classes: 'bg-orange-50 text-orange-700 ring-orange-200/60 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-700/40',
        dot: 'bg-orange-400 dark:bg-orange-500',
    },
    {
        classes: 'bg-indigo-50 text-indigo-700 ring-indigo-200/60 dark:bg-indigo-900/20 dark:text-indigo-300 dark:ring-indigo-700/40',
        dot: 'bg-indigo-400 dark:bg-indigo-500',
    },
    {
        classes: 'bg-teal-50 text-teal-700 ring-teal-200/60 dark:bg-teal-900/20 dark:text-teal-300 dark:ring-teal-700/40',
        dot: 'bg-teal-400 dark:bg-teal-500',
    },
    {
        classes: 'bg-pink-50 text-pink-700 ring-pink-200/60 dark:bg-pink-900/20 dark:text-pink-300 dark:ring-pink-700/40',
        dot: 'bg-pink-400 dark:bg-pink-500',
    },
    {
        classes: 'bg-lime-50 text-lime-700 ring-lime-200/60 dark:bg-lime-900/20 dark:text-lime-300 dark:ring-lime-700/40',
        dot: 'bg-lime-400 dark:bg-lime-500',
    },
]

const UNKNOWN_CONFIG = {
    label: 'Unknown',
    model: null,
    classes: 'bg-surface-tertiary text-content-tertiary ring-border-default',
    dot: 'bg-content-tertiary',
}

const hashProvider = (name) => {
    let hash = 0
    for (let i = 0; i < name.length; i++) hash += name.charCodeAt(i)
    return hash % AUTO_COLOR_PALETTE.length
}

const labelFromKey = (key) => {
    return key.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')
}

const current = computed(() => {
    const key = props.provider
    if (!key || key === '') return UNKNOWN_CONFIG
    if (PROVIDER_CONFIG[key]) return PROVIDER_CONFIG[key]
    const palette = AUTO_COLOR_PALETTE[hashProvider(key)]
    return { ...palette, label: labelFromKey(key), model: null }
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
