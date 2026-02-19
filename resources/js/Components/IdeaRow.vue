<script setup>
import { ref, computed } from 'vue'
import ScoreGauge from './ScoreGauge.vue'

const props = defineProps({
    idea: {
        type: Object,
        required: true,
    },
    expanded: {
        type: Boolean,
        required: true,
    },
    showSubreddit: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['toggle', 'star'])

// Star animation state
const starAnimating = ref(false)
// Copy feedback state
const copyFeedback = ref(null)

const prefersReducedMotion =
    typeof window !== 'undefined'
        ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
        : false

const handleStarClick = () => {
    if (!prefersReducedMotion) {
        starAnimating.value = true
        setTimeout(() => { starAnimating.value = false }, 200)
    }
    emit('star')
}

const copyToClipboard = async (text) => {
    try {
        await navigator.clipboard.writeText(text)
        copyFeedback.value = text
        setTimeout(() => { copyFeedback.value = null }, 1500)
    } catch {
        // Clipboard API unavailable (HTTP context) — silent fail
    }
}

const relativeDate = computed(() => {
    const date = new Date(props.idea.created_at)
    const now = new Date()
    const diffMs = now - date
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))
    if (diffDays === 0) return 'Today'
    if (diffDays === 1) return '1d ago'
    if (diffDays < 30) return `${diffDays}d ago`
    const diffMonths = Math.floor(diffDays / 30)
    if (diffMonths === 1) return '1mo ago'
    return `${diffMonths}mo ago`
})

const absoluteDate = computed(() => {
    return new Date(props.idea.created_at).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
})

const redditUrl = computed(() => {
    return props.idea.post ? `https://reddit.com${props.idea.post.permalink}` : null
})

const scoreEntries = [
    { key: 'score_overall', label: 'Overall' },
    { key: 'score_monetization', label: 'Monetization' },
    { key: 'score_saturation', label: 'Market Open' },
    { key: 'score_complexity', label: 'Buildability' },
    { key: 'score_demand', label: 'Demand' },
]
</script>

<template>
    <div class="border-b border-border-subtle last:border-0">
        <!-- Collapsed row -->
        <div
            :id="`idea-row-${idea.id}`"
            :class="[
                'grid gap-3 items-center px-5 py-3.5 hover:bg-surface-secondary transition-colors cursor-pointer',
                showSubreddit
                    ? 'grid-cols-[auto_1fr_auto_auto_auto_auto] md:grid-cols-[40px_1fr_48px_minmax(0,200px)_auto_auto_auto]'
                    : 'grid-cols-[auto_1fr_auto_auto_auto] md:grid-cols-[40px_1fr_48px_minmax(0,200px)_auto_auto]',
            ]"
            @click="emit('toggle')"
        >
            <!-- Star button -->
            <div class="flex items-center justify-center" @click.stop>
                <button
                    type="button"
                    @click="handleStarClick"
                    :aria-label="`${idea.is_starred ? 'Remove star from' : 'Star'} idea: ${idea.idea_title}`"
                    :aria-pressed="idea.is_starred"
                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1 focus-visible:ring-offset-surface-primary"
                    :class="idea.is_starred ? 'text-accent-500' : 'text-content-tertiary hover:text-accent-400'"
                >
                    <svg
                        class="w-5 h-5 transition-transform"
                        :class="{ 'scale-125': starAnimating }"
                        :fill="idea.is_starred ? 'currentColor' : 'none'"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                    </svg>
                </button>
            </div>

            <!-- Title + borderline dot -->
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-content-primary text-sm leading-snug line-clamp-2">
                        {{ idea.idea_title }}
                    </span>
                    <span
                        v-if="idea.classification_status === 'borderline'"
                        role="img"
                        class="flex-shrink-0 w-2 h-2 rounded-full bg-accent-500"
                        title="Borderline idea"
                        aria-label="Borderline idea"
                    />
                </div>
            </div>

            <!-- Score gauge -->
            <div class="flex items-center justify-center">
                <ScoreGauge
                    :score="idea.score_overall ?? 0"
                    :size="36"
                    :animate="false"
                />
            </div>

            <!-- Audience chip -->
            <div class="hidden md:block min-w-0">
                <span class="inline-block max-w-full px-2 py-1 text-xs text-content-secondary bg-surface-tertiary rounded-full truncate">
                    {{ idea.target_audience || '—' }}
                </span>
            </div>

            <!-- Subreddit (conditional) -->
            <div v-if="showSubreddit" class="hidden md:block min-w-0">
                <span class="text-xs font-medium text-brand-600 truncate">
                    r/{{ idea.post?.subreddit?.name }}
                </span>
            </div>

            <!-- Date + expand -->
            <div class="flex items-center justify-end gap-1" @click.stop>
                <span
                    class="hidden md:block text-xs text-content-tertiary whitespace-nowrap"
                    :title="absoluteDate"
                >
                    {{ relativeDate }}
                </span>
                <button
                    type="button"
                    @click="emit('toggle')"
                    :aria-expanded="expanded"
                    :aria-controls="`idea-details-${idea.id}`"
                    :aria-label="`${expanded ? 'Collapse' : 'Expand'} idea: ${idea.idea_title}`"
                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-content-tertiary hover:text-content-secondary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                >
                    <svg
                        :class="['w-4 h-4 transition-transform duration-200', expanded ? 'rotate-180' : '']"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Expanded detail section (CSS grid animation) -->
        <div
            :id="`idea-details-${idea.id}`"
            class="grid transition-[grid-template-rows] duration-200 ease-out"
            :style="{ gridTemplateRows: expanded ? '1fr' : '0fr' }"
            :aria-hidden="!expanded"
            :inert="!expanded || undefined"
        >
            <div class="overflow-hidden">
                <div class="mx-5 mb-4 rounded-r-lg border-l-4 border-brand-500 bg-surface-tertiary">
                    <div class="p-5 space-y-5">
                        <!-- Problem & Solution -->
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <h4 class="flex items-center gap-1.5 text-xs font-semibold text-content-tertiary uppercase tracking-wide mb-2">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                    </svg>
                                    Problem
                                </h4>
                                <p class="text-sm text-content-secondary leading-relaxed">{{ idea.problem_statement }}</p>
                            </div>
                            <div>
                                <h4 class="flex items-center gap-1.5 text-xs font-semibold text-content-tertiary uppercase tracking-wide mb-2">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    Solution
                                </h4>
                                <p class="text-sm text-content-secondary leading-relaxed">{{ idea.proposed_solution }}</p>
                            </div>
                        </div>

                        <!-- Score breakdown (5 gauges with staggered animation) -->
                        <div>
                            <h4 class="text-xs font-semibold text-content-tertiary uppercase tracking-wide mb-3">Score breakdown</h4>
                            <div class="flex flex-wrap gap-6 justify-center md:justify-start">
                                <ScoreGauge
                                    v-for="(entry, i) in scoreEntries"
                                    :key="entry.key"
                                    :score="idea[entry.key] ?? 0"
                                    :size="48"
                                    :stroke-width="3.5"
                                    :animate="expanded"
                                    :label="entry.label"
                                    :delay="i * 80"
                                />
                            </div>
                        </div>

                        <!-- Branding section -->
                        <div v-if="idea.branding_suggestions">
                            <h4 class="text-xs font-semibold text-content-tertiary uppercase tracking-wide mb-2">Branding ideas</h4>
                            <!-- Name chips -->
                            <div class="flex flex-wrap gap-2 mb-3">
                                <div
                                    v-for="name in (idea.branding_suggestions?.name_ideas ?? [])"
                                    :key="name"
                                    class="relative"
                                >
                                    <button
                                        type="button"
                                        @click="copyToClipboard(name)"
                                        class="px-2.5 py-1 text-sm font-medium bg-brand-50 text-brand-700 border border-brand-200 rounded-full hover:bg-brand-100 transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                        :title="`Copy '${name}' to clipboard`"
                                        :aria-label="`Copy ${name} to clipboard`"
                                    >
                                        {{ name }}
                                    </button>
                                    <!-- Copied feedback tooltip -->
                                    <Transition
                                        enter-active-class="transition ease-out duration-150"
                                        enter-from-class="opacity-0 -translate-y-1"
                                        enter-to-class="opacity-100 translate-y-0"
                                        leave-active-class="transition ease-in duration-100"
                                        leave-from-class="opacity-100 translate-y-0"
                                        leave-to-class="opacity-0 -translate-y-1"
                                    >
                                        <span
                                            v-if="copyFeedback === name"
                                            class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-0.5 text-xs bg-content-primary text-content-inverse rounded whitespace-nowrap pointer-events-none z-10"
                                            aria-live="polite"
                                        >
                                            Copied!
                                        </span>
                                    </Transition>
                                </div>
                            </div>
                            <!-- Tagline -->
                            <blockquote
                                v-if="idea.branding_suggestions.tagline"
                                class="relative pl-4 text-sm text-content-secondary italic border-l-2 border-brand-300"
                            >
                                "{{ idea.branding_suggestions.tagline }}"
                            </blockquote>
                        </div>

                        <!-- Marketing & Competitors -->
                        <div class="grid md:grid-cols-2 gap-4">
                            <div v-if="(idea.marketing_channels ?? []).length">
                                <h4 class="text-xs font-semibold text-content-tertiary uppercase tracking-wide mb-2">Marketing channels</h4>
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="channel in idea.marketing_channels"
                                        :key="channel"
                                        class="px-2 py-0.5 text-xs bg-surface-secondary text-content-secondary rounded-full border border-border-default"
                                    >
                                        {{ channel }}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <h4 class="text-xs font-semibold text-content-tertiary uppercase tracking-wide mb-2">Competitors</h4>
                                <p v-if="!idea.existing_competitors?.length" class="text-sm text-content-tertiary italic">None identified</p>
                                <div v-else class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="comp in idea.existing_competitors"
                                        :key="comp"
                                        class="px-2 py-0.5 text-xs rounded-full bg-status-error/10 text-status-error"
                                    >
                                        {{ comp }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Source quote -->
                        <div v-if="idea.source_quote" class="pt-4 border-t border-border-subtle">
                            <h4 class="text-xs font-semibold text-content-tertiary uppercase tracking-wide mb-2">Source quote</h4>
                            <blockquote class="text-sm text-content-secondary italic border-l-4 border-reddit-500 pl-4 leading-relaxed">
                                "{{ idea.source_quote }}"
                            </blockquote>
                            <a
                                v-if="redditUrl"
                                :href="redditUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 mt-3 text-sm font-medium text-brand-600 hover:text-brand-700 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded"
                                aria-label="View original post on Reddit (opens in new tab)"
                            >
                                View on Reddit
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
