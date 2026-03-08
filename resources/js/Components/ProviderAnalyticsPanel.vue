<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import ProviderBadge from './ProviderBadge.vue'
import { useProviderMetadata } from '../composables/useProviderMetadata'

const props = defineProps({
    subredditId: {
        type: Number,
        required: true,
    },
})

const analytics = ref(null)
const loading = ref(false)
const error = ref(null)
const expanded = ref(false)
let fetched = false
let abortController = null

const fetchAnalytics = async () => {
    if (fetched && analytics.value) return

    abortController?.abort()
    abortController = new AbortController()
    loading.value = true
    error.value = null

    try {
        const response = await fetch(`/subreddits/${props.subredditId}/provider-analytics`, {
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
        })
        if (!response.ok) throw new Error(`Failed to load analytics (${response.status})`)
        analytics.value = await response.json()
        fetched = true
    } catch (e) {
        if (e?.name !== 'AbortError') {
            error.value = e.message || 'Failed to load provider analytics'
        }
    } finally {
        loading.value = false
    }
}

watch(expanded, (isOpen) => {
    if (isOpen) fetchAnalytics()
})

onBeforeUnmount(() => {
    abortController?.abort()
})

const retry = () => {
    fetched = false
    analytics.value = null
    fetchAnalytics()
}

// -- Computed helpers --

const agreementPercent = computed(() => {
    const rate = analytics.value?.classification?.agreement?.agreement_rate ?? 0
    return Math.round(rate * 100)
})

const totalClassified = computed(() => analytics.value?.classification?.total_classified ?? 0)
const bothDisagree = computed(() => analytics.value?.classification?.agreement?.both_disagree ?? 0)
const providers = computed(() => analytics.value?.classification?.providers ?? [])
const classificationIsEmpty = computed(() => totalClassified.value === 0)

const extractionProviders = computed(() => {
    const dist = analytics.value?.extraction?.provider_distribution ?? []
    const total = analytics.value?.extraction?.total_extracted ?? 0
    return dist.map(entry => ({
        provider: entry.name,
        display_name: entry.display_name,
        count: entry.count,
        percent: total > 0 ? Math.round((entry.count / total) * 100) : 0,
    }))
})

const totalExtracted = computed(() => analytics.value?.extraction?.total_extracted ?? 0)

const topCategories = (categoryDist) => {
    return Object.entries(categoryDist ?? {})
        .sort((a, b) => b[1] - a[1])
        .slice(0, 3)
        .map(([cat, count]) => ({
            label: cat.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
            count,
        }))
}

const verdictBarWidth = (provider, verdict) => {
    const total = (provider.verdict_distribution?.keep ?? 0) + (provider.verdict_distribution?.skip ?? 0)
    if (total === 0) return '0%'
    const count = provider.verdict_distribution?.[verdict] ?? 0
    return `${Math.round((count / total) * 100)}%`
}

const confidencePercent = (val) => Math.round((val ?? 0) * 100)

const { getProviderBorderColor } = useProviderMetadata()

const providerTopBorder = (name) => getProviderBorderColor(name)
</script>

<template>
    <div class="border border-border-default rounded-xl bg-surface-secondary overflow-hidden">
        <!-- Panel header toggle -->
        <button
            type="button"
            :aria-expanded="expanded"
            aria-controls="provider-analytics-content"
            @click="expanded = !expanded"
            class="w-full flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-tertiary/50 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-inset rounded-xl"
        >
            <div class="flex items-center gap-2.5">
                <!-- Sparkle icon -->
                <svg class="w-4 h-4 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                </svg>
                <span class="text-sm font-semibold text-content-primary">Provider Analytics</span>
                <span
                    v-if="totalClassified > 0 && !expanded"
                    class="text-xs text-content-tertiary tabular-nums"
                >
                    — {{ agreementPercent }}% agreement
                </span>
            </div>
            <svg
                :class="['w-4 h-4 text-content-tertiary transition-transform duration-200', expanded ? 'rotate-180' : '']"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Collapsible content (CSS grid animation) -->
        <div
            id="provider-analytics-content"
            class="grid transition-[grid-template-rows] duration-200 ease-out"
            :style="{ gridTemplateRows: expanded ? '1fr' : '0fr' }"
        >
            <div class="overflow-hidden">
                <div class="border-t border-border-subtle px-5 pb-5 pt-4 space-y-5">

                    <!-- Loading skeleton -->
                    <div v-if="loading" class="space-y-4 animate-pulse" aria-busy="true" aria-label="Loading analytics">
                        <div class="grid grid-cols-3 gap-4">
                            <div v-for="i in 3" :key="i" class="h-16 bg-surface-tertiary rounded-lg" />
                        </div>
                        <div class="grid md:grid-cols-2 gap-4">
                            <div v-for="i in 2" :key="i" class="h-40 bg-surface-tertiary rounded-lg" />
                        </div>
                    </div>

                    <!-- Error state -->
                    <div v-else-if="error" class="py-6 text-center space-y-2" role="alert">
                        <p class="text-sm text-status-error">{{ error }}</p>
                        <button
                            type="button"
                            @click="retry"
                            class="text-xs text-brand-500 hover:text-brand-600 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded"
                        >
                            Retry
                        </button>
                    </div>

                    <!-- Analytics content -->
                    <template v-else-if="analytics">

                        <!-- Classification section -->
                        <div v-if="!classificationIsEmpty">
                        <!-- Agreement overview stat cards -->
                        <div class="grid grid-cols-3 gap-3">
                            <!-- Total classified -->
                            <div class="bg-surface-tertiary rounded-lg px-4 py-3 text-center">
                                <div class="text-2xl font-bold text-content-primary tabular-nums">{{ totalClassified.toLocaleString() }}</div>
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary mt-1">Classified</div>
                            </div>
                            <!-- Agreement rate -->
                            <div class="bg-surface-tertiary rounded-lg px-4 py-3 text-center">
                                <div
                                    class="text-2xl font-bold tabular-nums"
                                    :class="agreementPercent >= 70 ? 'text-emerald-600 dark:text-emerald-400' : agreementPercent >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'"
                                >
                                    {{ agreementPercent }}%
                                </div>
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary mt-1">Agreement</div>
                            </div>
                            <!-- Disagreements -->
                            <div class="bg-surface-tertiary rounded-lg px-4 py-3 text-center">
                                <div class="text-2xl font-bold text-content-primary tabular-nums">{{ bothDisagree.toLocaleString() }}</div>
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary mt-1">Disagreements</div>
                            </div>
                        </div>

                        <!-- Per-provider breakdown cards -->
                        <div class="mt-5">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-content-tertiary mb-3">Classification Providers</h3>
                            <div class="grid md:grid-cols-2 gap-3">
                                <div
                                    v-for="provider in providers"
                                    :key="provider.name"
                                    :class="[
                                        'border-t-[3px] border border-border-default rounded-lg bg-surface-tertiary p-4 space-y-3',
                                        providerTopBorder(provider.name),
                                    ]"
                                >
                                    <!-- Provider header -->
                                    <div class="flex items-center justify-between">
                                        <ProviderBadge :provider="provider.name" size="sm" :show-model="true" />
                                        <span class="text-xs text-content-tertiary tabular-nums">
                                            {{ provider.total_completed.toLocaleString() }} classified
                                        </span>
                                    </div>

                                    <!-- Avg confidence bar -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary">Avg Confidence</span>
                                            <span class="text-xs font-semibold tabular-nums text-content-primary">
                                                {{ confidencePercent(provider.avg_confidence) }}%
                                            </span>
                                        </div>
                                        <div class="h-1.5 rounded-full bg-surface-secondary overflow-hidden">
                                            <div
                                                class="h-full rounded-full bg-gradient-to-r from-brand-400 to-brand-500 transition-[width] duration-700 ease-out"
                                                :style="{ width: `${confidencePercent(provider.avg_confidence)}%` }"
                                            />
                                        </div>
                                    </div>

                                    <!-- Verdict distribution stacked bar -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary">Verdicts</span>
                                            <div class="flex items-center gap-2 text-[10px] text-content-tertiary">
                                                <span class="flex items-center gap-1">
                                                    <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block" />
                                                    Keep {{ provider.verdict_distribution?.keep ?? 0 }}
                                                </span>
                                                <span class="flex items-center gap-1">
                                                    <span class="w-2 h-2 rounded-full bg-surface-secondary border border-border-default inline-block" />
                                                    Skip {{ provider.verdict_distribution?.skip ?? 0 }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="h-2.5 rounded-full bg-surface-secondary overflow-hidden flex">
                                            <div
                                                class="h-full bg-emerald-400 dark:bg-emerald-500 transition-[width] duration-700 ease-out"
                                                :style="{ width: verdictBarWidth(provider, 'keep') }"
                                            />
                                            <div
                                                class="h-full bg-content-tertiary/20 transition-[width] duration-700 ease-out"
                                                :style="{ width: verdictBarWidth(provider, 'skip') }"
                                            />
                                        </div>
                                    </div>

                                    <!-- Top categories -->
                                    <div v-if="Object.keys(provider.category_distribution ?? {}).length > 0">
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-content-tertiary block mb-1.5">Top Categories</span>
                                        <div class="space-y-1">
                                            <div
                                                v-for="cat in topCategories(provider.category_distribution)"
                                                :key="cat.label"
                                                class="flex items-center justify-between"
                                            >
                                                <span class="text-xs text-content-secondary truncate">{{ cat.label }}</span>
                                                <span class="text-xs font-medium text-content-primary tabular-nums ml-2">{{ cat.count }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        </div><!-- /classification section -->

                        <!-- Classification empty state (shown independently of extraction) -->
                        <div v-if="classificationIsEmpty" class="py-4 text-center">
                            <p class="text-sm text-content-tertiary">No classification data yet.</p>
                        </div>

                        <!-- Extraction provider distribution -->
                        <div v-if="totalExtracted > 0">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-content-tertiary mb-3">
                                Extraction Providers
                                <span class="normal-case font-normal text-content-tertiary">— {{ totalExtracted.toLocaleString() }} total</span>
                            </h3>
                            <div class="space-y-2.5">
                                <div
                                    v-for="ep in extractionProviders"
                                    :key="ep.provider"
                                    class="flex items-center gap-3"
                                >
                                    <ProviderBadge :provider="ep.provider !== 'unknown' ? ep.provider : null" size="xs" />
                                    <div class="flex-1 h-2 rounded-full bg-surface-tertiary overflow-hidden">
                                        <div
                                            class="h-full rounded-full bg-brand-400/70 transition-[width] duration-700 ease-out"
                                            :style="{ width: `${ep.percent}%` }"
                                        />
                                    </div>
                                    <span class="text-xs text-content-tertiary tabular-nums w-10 text-right">{{ ep.count }}</span>
                                    <span class="text-[10px] text-content-tertiary tabular-nums w-8 text-right">{{ ep.percent }}%</span>
                                </div>
                            </div>
                        </div>

                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
