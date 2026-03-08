<script setup>
import { computed } from 'vue'
import { useProviderMetadata } from '../composables/useProviderMetadata'

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
    modelId: {
        type: String,
        default: null,
    },
})

const { getProvider, getProviderColor } = useProviderMetadata()

const UNKNOWN_CONFIG = {
    display_name: 'Unknown',
    model: null,
    classes: 'bg-surface-tertiary text-content-tertiary ring-border-default',
    dot: 'bg-content-tertiary',
}

const metadata = computed(() => {
    if (!props.provider || props.provider === '') return null
    return getProvider(props.provider)
})

const colorSet = computed(() => {
    if (!props.provider || props.provider === '') return UNKNOWN_CONFIG
    return getProviderColor(props.provider) ?? UNKNOWN_CONFIG
})

const label = computed(() => {
    if (!props.provider || props.provider === '') return UNKNOWN_CONFIG.display_name
    return metadata.value?.display_name ?? props.provider
})

// When showModel is true: show the per-record modelId prop if provided,
// otherwise fall back to the current config model from metadata.
const tooltipModel = computed(() => {
    if (!props.showModel) return undefined
    return props.modelId || metadata.value?.model || undefined
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
        :title="tooltipModel"
        :class="[
            'inline-flex items-center rounded-full font-medium whitespace-nowrap ring-1',
            colorSet.classes,
            sizeClasses[size],
        ]"
    >
        <span :class="['rounded-full flex-shrink-0', colorSet.dot, dotSizeClasses[size]]" aria-hidden="true" />
        {{ label }}
    </span>
</template>
