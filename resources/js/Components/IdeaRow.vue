<script setup>
import { computed } from 'vue'

const props = defineProps({
    idea: Object,
    expanded: Boolean,
    showSubreddit: Boolean,
})

const emit = defineEmits(['toggle', 'star'])

const scoreColor = (score) => {
    if (score >= 4) return 'text-green-600 bg-green-100'
    if (score >= 3) return 'text-yellow-600 bg-yellow-100'
    return 'text-gray-600 bg-gray-100'
}

const formattedDate = computed(() => {
    const date = new Date(props.idea.created_at)
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
})

const redditUrl = computed(() => {
    return props.idea.post ? `https://reddit.com${props.idea.post.permalink}` : null
})

const scoreLabels = {
    score_overall: 'Overall',
    score_monetization: 'Monetization',
    score_saturation: 'Market Open',
    score_complexity: 'Buildability',
    score_demand: 'Demand',
}
</script>

<template>
    <div class="hover:bg-gray-50 transition-colors">
        <!-- Collapsed row -->
        <div
            class="hover:bg-gray-50 transition-colors grid grid-cols-1 md:grid-cols-12 gap-4 px-6 py-4 items-center"
            :id="`idea-row-${idea.id}`"
        >
            <!-- Star -->
            <div class="md:col-span-1">
                <button
                    type="button"
                    @click.stop="emit('star')"
                    :aria-label="`${idea.is_starred ? 'Remove' : 'Add'} star`"
                    :aria-pressed="idea.is_starred"
                    :title="`${idea.is_starred ? 'Remove' : 'Add'} star`"
                    class="text-gray-400 hover:text-yellow-500 transition-colors"
                >
                    <svg
                        class="w-5 h-5"
                        :fill="idea.is_starred ? 'currentColor' : 'none'"
                        :class="{ 'text-yellow-500': idea.is_starred }"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                    </svg>
                </button>
            </div>

            <!-- Title -->
            <div class="md:col-span-4">
                <div class="flex items-center">
                    <span class="font-medium text-gray-900">{{ idea.idea_title }}</span>
                    <span v-if="idea.classification_status === 'borderline'" class="ml-2 px-1.5 py-0.5 text-xs bg-yellow-100 text-yellow-700 rounded">
                        Borderline
                    </span>
                </div>
            </div>

            <!-- Score -->
            <div class="md:col-span-1 text-center">
                <span :class="scoreColor(idea.score_overall)" class="inline-flex items-center justify-center w-8 h-8 rounded-full font-bold text-sm">
                    {{ idea.score_overall }}
                </span>
            </div>

            <!-- Audience -->
            <div class="md:col-span-2">
                <p class="text-sm text-gray-600 truncate">{{ idea.target_audience }}</p>
            </div>

            <!-- Complexity -->
            <div class="md:col-span-1 text-center">
                <span class="text-sm text-gray-600">{{ idea.score_complexity }}/5</span>
            </div>

            <!-- Subreddit (optional) -->
            <div v-if="showSubreddit" class="md:col-span-2">
                <span class="text-sm text-indigo-600">r/{{ idea.post?.subreddit?.name }}</span>
            </div>

            <!-- Date & Expand -->
            <div :class="showSubreddit ? 'md:col-span-1' : 'md:col-span-3'" class="flex items-center justify-end gap-2">
                <span class="text-sm text-gray-500">{{ formattedDate }}</span>
                <button
                    type="button"
                    @click="emit('toggle')"
                    :aria-expanded="expanded"
                    :aria-controls="`idea-details-${idea.id}`"
                    :aria-label="`${expanded ? 'Collapse' : 'Expand'} idea details`"
                    class="p-1 text-gray-400 hover:text-gray-600 transition-colors"
                >
                    <svg :class="['w-4 h-4 transition-transform', expanded ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Expanded details -->
        <Transition
            enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0 max-h-0"
            enter-to-class="opacity-100 max-h-[2000px]"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100 max-h-[2000px]"
            leave-to-class="opacity-0 max-h-0"
        >
            <div v-if="expanded" :id="`idea-details-${idea.id}`" class="px-6 pb-6 overflow-hidden">
                <div class="bg-gray-50 rounded-lg p-6 space-y-6">
                    <!-- Problem & Solution -->
                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <h4 class="text-sm font-medium text-gray-900 mb-2">Problem Statement</h4>
                            <p class="text-sm text-gray-600">{{ idea.problem_statement }}</p>
                        </div>
                        <div>
                            <h4 class="text-sm font-medium text-gray-900 mb-2">Proposed Solution</h4>
                            <p class="text-sm text-gray-600">{{ idea.proposed_solution }}</p>
                        </div>
                    </div>

                    <!-- Scores -->
                    <div>
                        <h4 class="text-sm font-medium text-gray-900 mb-3">Scores</h4>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                            <div v-for="(label, key) in scoreLabels" :key="key" class="text-center">
                                <div :class="scoreColor(idea[key])" class="inline-flex items-center justify-center w-10 h-10 rounded-full font-bold mb-1">
                                    {{ idea[key] }}
                                </div>
                                <p class="text-xs text-gray-500">{{ label }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Branding -->
                    <div v-if="idea.branding_suggestions">
                        <h4 class="text-sm font-medium text-gray-900 mb-2">Branding Ideas</h4>
                        <div class="flex flex-wrap gap-2 mb-2">
                            <span v-for="name in (idea.branding_suggestions?.name_ideas ?? [])" :key="name" class="px-2 py-1 bg-indigo-100 text-indigo-700 text-sm rounded">
                                {{ name }}
                            </span>
                        </div>
                        <p v-if="idea.branding_suggestions.tagline" class="text-sm text-gray-600 italic">"{{ idea.branding_suggestions.tagline }}"</p>
                    </div>

                    <!-- Marketing & Competitors -->
                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <h4 class="text-sm font-medium text-gray-900 mb-2">Marketing Channels</h4>
                            <div class="flex flex-wrap gap-2">
                                <span v-for="channel in (idea.marketing_channels ?? [])" :key="channel" class="px-2 py-1 bg-gray-200 text-gray-700 text-sm rounded">
                                    {{ channel }}
                                </span>
                            </div>
                        </div>
                        <div>
                            <h4 class="text-sm font-medium text-gray-900 mb-2">Existing Competitors</h4>
                            <p v-if="!idea.existing_competitors?.length" class="text-sm text-gray-500 italic">None identified</p>
                            <div v-else class="flex flex-wrap gap-2">
                                <span v-for="comp in idea.existing_competitors" :key="comp" class="px-2 py-1 bg-red-100 text-red-700 text-sm rounded">
                                    {{ comp }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Source -->
                    <div class="pt-4 border-t border-gray-200">
                        <h4 class="text-sm font-medium text-gray-900 mb-2">Source Quote</h4>
                        <blockquote class="text-sm text-gray-600 italic border-l-4 border-indigo-300 pl-4">
                            "{{ idea.source_quote }}"
                        </blockquote>
                        <a v-if="redditUrl" :href="redditUrl" target="_blank" rel="noopener noreferrer" class="inline-flex items-center mt-2 text-sm text-indigo-600 hover:text-indigo-800">
                            View on Reddit
                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>
