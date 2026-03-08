<script setup>
import { ref, watch, computed, onBeforeUnmount } from 'vue'
import BaseModal from './BaseModal.vue'
import ProviderBadge from './ProviderBadge.vue'
import { useProviderMetadata } from '../composables/useProviderMetadata'

const props = defineProps({
    open: {
        type: Boolean,
        required: true,
    },
    ideaId: {
        type: Number,
        default: null,
    },
    postTitle: {
        type: String,
        default: '',
    },
})

const emit = defineEmits(['close'])

const classification = ref(null)
const loading = ref(false)
const error = ref(null)
let abortController = null
let cachedIdeaId = null

const fetchClassification = async () => {
    if (!props.ideaId) return
    if (cachedIdeaId === props.ideaId && classification.value) return

    abortController?.abort()
    abortController = new AbortController()
    loading.value = true
    error.value = null

    try {
        const response = await fetch(`/ideas/${props.ideaId}`, {
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
        })
        if (!response.ok) throw new Error(`Failed to load (${response.status})`)
        const data = await response.json()
        classification.value = data.idea?.post?.classification ?? null
        cachedIdeaId = props.ideaId
    } catch (e) {
        if (e?.name !== 'AbortError') {
            error.value = e.message || 'Failed to load classification details'
        }
    } finally {
        loading.value = false
    }
}

watch(() => props.open, (isOpen) => {
    if (isOpen) fetchClassification()
    else {
        abortController?.abort()
        abortController = null
    }
})

onBeforeUnmount(() => {
    abortController?.abort()
})

const hasDisagreement = computed(() => {
    const providers = classification.value?.providers ?? []
    const completed = providers.filter(p => p.completed)
    if (completed.length < 2) return false
    const verdicts = new Set(completed.map(p => p.verdict))
    return verdicts.size > 1
})

const humanizeCategory = (slug) => {
    if (!slug) return '—'
    return slug.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

const confidencePercent = (val) => Math.round((val ?? 0) * 100)

const confidenceBarColor = (val) => {
    if (val >= 0.7) return 'from-emerald-400 to-emerald-500'
    if (val >= 0.4) return 'from-amber-400 to-amber-500'
    return 'from-red-400 to-red-500'
}

const { getProviderBorderColor } = useProviderMetadata()

const providerTopBorder = (name) => getProviderBorderColor(name)

const verdictClasses = (verdict) => verdict === 'keep'
    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
    : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'

const decisionClasses = (decision) => {
    if (decision === 'keep') return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
    if (decision === 'discard') return 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
    return 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'
}
</script>

<template>
    <BaseModal
        :open="open"
        title="Classification Details"
        max-width="lg"
        @close="emit('close')"
    >
        <!-- Post context -->
        <p
            v-if="postTitle"
            class="text-xs text-content-tertiary line-clamp-2 -mt-2 mb-5 pb-4 border-b border-border-subtle"
        >
            {{ postTitle }}
        </p>

        <!-- Loading skeleton -->
        <div v-if="loading" class="space-y-4" aria-busy="true" aria-label="Loading classification details">
            <div class="grid md:grid-cols-2 gap-3">
                <div
                    v-for="i in 2"
                    :key="i"
                    class="border border-border-default rounded-lg p-4 space-y-3 animate-pulse"
                >
                    <div class="h-4 bg-surface-tertiary rounded w-1/3" />
                    <div class="h-3 bg-surface-tertiary rounded w-1/2" />
                    <div class="h-2 bg-surface-tertiary rounded-full w-full" />
                    <div class="h-16 bg-surface-tertiary rounded" />
                </div>
            </div>
            <div class="h-20 bg-surface-tertiary rounded animate-pulse" />
        </div>

        <!-- Error state -->
        <div
            v-else-if="error"
            class="py-8 text-center space-y-2"
            role="alert"
        >
            <svg class="w-8 h-8 text-status-error mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
            <p class="text-sm text-status-error">{{ error }}</p>
            <button
                type="button"
                @click="cachedIdeaId = null; fetchClassification()"
                class="text-xs text-brand-500 hover:text-brand-600 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded"
            >
                Retry
            </button>
        </div>

        <!-- No classification -->
        <div
            v-else-if="!classification"
            class="py-8 text-center text-sm text-content-tertiary"
        >
            No classification data available.
        </div>

        <!-- Classification content -->
        <div v-else class="space-y-5">

            <!-- Disagreement banner -->
            <div
                v-if="hasDisagreement"
                class="flex items-center gap-2 px-3 py-2 rounded-lg bg-amber-50 dark:bg-amber-900/15 border border-amber-200 dark:border-amber-800"
                role="note"
            >
                <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="text-xs font-medium text-amber-700 dark:text-amber-300">
                    Providers disagreed — consensus score was used to determine the final decision.
                </span>
            </div>

            <!-- Provider cards -->
            <div class="grid md:grid-cols-2 gap-3">
                <div
                    v-for="provider in (classification.providers ?? [])"
                    :key="provider.name"
                    :class="[
                        'border-t-[3px] border border-border-default rounded-lg bg-surface-secondary overflow-hidden',
                        providerTopBorder(provider.name),
                    ]"
                >
                    <!-- Card header -->
                    <div class="flex items-center justify-between px-4 pt-4 pb-3 border-b border-border-subtle">
                        <ProviderBadge :provider="provider.name" size="sm" :show-model="true" :model-id="provider.model_id" />
                        <span
                            v-if="provider.completed"
                            :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold', verdictClasses(provider.verdict)]"
                        >
                            {{ provider.verdict === 'keep' ? '✓ Keep' : '✕ Skip' }}
                        </span>
                        <span v-else class="text-xs text-content-tertiary italic">Pending</span>
                    </div>

                    <!-- Card body -->
                    <div class="p-4 space-y-3.5">
                        <!-- Confidence bar -->
                        <div v-if="provider.completed">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary">Confidence</span>
                                <span class="text-xs font-semibold tabular-nums text-content-primary">
                                    {{ confidencePercent(provider.confidence) }}%
                                </span>
                            </div>
                            <div class="h-1.5 rounded-full bg-surface-tertiary overflow-hidden">
                                <div
                                    class="h-full rounded-full bg-gradient-to-r transition-[width] duration-700 ease-out"
                                    :class="confidenceBarColor(provider.confidence)"
                                    :style="{ width: `${confidencePercent(provider.confidence)}%` }"
                                />
                            </div>
                        </div>

                        <!-- Category -->
                        <div v-if="provider.completed && provider.category">
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary block mb-1">Category</span>
                            <span class="text-xs text-content-primary font-medium">{{ humanizeCategory(provider.category) }}</span>
                        </div>

                        <!-- Reasoning -->
                        <div v-if="provider.completed && provider.reasoning">
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary block mb-1.5">Reasoning</span>
                            <div class="text-xs text-content-secondary bg-surface-tertiary rounded-lg p-3 max-h-32 overflow-y-auto leading-relaxed">
                                {{ provider.reasoning }}
                            </div>
                        </div>

                        <!-- Pending state -->
                        <div v-if="!provider.completed" class="py-4 text-center text-xs text-content-tertiary italic">
                            Classification pending…
                        </div>
                    </div>
                </div>
            </div>

            <!-- Consensus section -->
            <div class="border-t border-border-default pt-4 space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-content-tertiary">Consensus Result</h3>

                <div class="flex flex-wrap items-center gap-4">
                    <!-- Combined score bar -->
                    <div class="flex-1 min-w-40">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary">Combined Score</span>
                            <span class="text-xs font-semibold tabular-nums text-content-primary">
                                {{ confidencePercent(classification.combined_score) }}%
                            </span>
                        </div>
                        <div class="h-2 rounded-full bg-surface-tertiary overflow-hidden">
                            <div
                                class="h-full rounded-full bg-gradient-to-r transition-[width] duration-700 ease-out delay-300"
                                :class="confidenceBarColor(classification.combined_score)"
                                :style="{ width: `${confidencePercent(classification.combined_score)}%` }"
                            />
                        </div>
                    </div>

                    <!-- Final decision badge -->
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary">Decision</span>
                        <span
                            :class="['inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold capitalize', decisionClasses(classification.final_decision)]"
                        >
                            {{ classification.final_decision ?? '—' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end">
                <button
                    type="button"
                    @click="emit('close')"
                    class="px-4 py-2 text-sm font-medium text-content-secondary border border-border-default rounded-lg hover:bg-surface-secondary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 min-h-[44px]"
                >
                    Close
                </button>
            </div>
        </template>
    </BaseModal>
</template>
