<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import IdeaFilters from './IdeaFilters.vue'
import IdeaRow from './IdeaRow.vue'
import SkeletonRow from './SkeletonRow.vue'
import EmptyState from './EmptyState.vue'

const props = defineProps({
    subredditId: Number,
    showSubreddit: {
        type: Boolean,
        default: false,
    },
    mode: {
        type: String,
        validator: (v) => ['subreddit', 'starred'].includes(v),
        default: 'subreddit',
    },
})

const ideas = ref([])
const loading = ref(true)
const pagination = ref({})
const expandedId = ref(null)
const perPage = ref(20)
const tableRef = ref(null)
const abortController = ref(null)

let debounceTimer = null

const prefersReducedMotion =
    typeof window !== 'undefined'
        ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
        : false

const getDefaultFilters = () => ({
    min_score: 1,
    min_complexity: 1,
    starred_only: false,
    include_borderline: true,
    sort_by: props.mode === 'starred' ? 'starred_at' : 'score_overall',
    sort_dir: 'desc',
})

const filters = ref(getDefaultFilters())

// Default filters passed to IdeaFilters for "Clear all"
const modeDefaults = computed(() => getDefaultFilters())

// Whether any filter differs from defaults (excluding sort)
const hasActiveFilters = computed(() => {
    const d = modeDefaults.value
    const f = filters.value
    return (
        f.min_score !== d.min_score ||
        f.min_complexity !== d.min_complexity ||
        f.starred_only !== d.starred_only ||
        f.include_borderline !== d.include_borderline
    )
})

const fetchIdeas = async (page = 1) => {
    // Cancel any in-flight request
    abortController.value?.abort()
    const controller = new AbortController()
    abortController.value = controller

    loading.value = true

    const params = new URLSearchParams({
        ...filters.value,
        page,
        per_page: perPage.value,
    })

    const url =
        props.mode === 'starred'
            ? `/api/starred?${params}`
            : `/subreddits/${props.subredditId}/ideas?${params}`

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
        if (!response.ok) {
            throw new Error(`Failed to fetch ideas: ${response.status} ${response.statusText}`)
        }
        const data = await response.json()
        // Only commit data if this is still the current request
        if (abortController.value === controller) {
            ideas.value = data.ideas
            pagination.value = data.pagination
        }
    } catch (error) {
        if (error?.name !== 'AbortError') {
            console.error('Failed to fetch ideas:', error)
        }
    } finally {
        // Only update loading state if this request is still the current one
        if (abortController.value === controller) {
            loading.value = false
        }
    }
}

const scrollToTop = () => {
    if (tableRef.value) {
        tableRef.value.scrollIntoView({
            behavior: prefersReducedMotion ? 'auto' : 'smooth',
            block: 'start',
        })
    }
}

const toggleExpand = (id) => {
    expandedId.value = expandedId.value === id ? null : id
}

const handleFilterChange = (newFilters) => {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
        filters.value = { ...filters.value, ...newFilters }
        fetchIdeas(1)
    }, 300)
}

const clearFilters = () => {
    filters.value = getDefaultFilters()
    fetchIdeas(1)
}

const getCsrfToken = () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    if (!token) throw new Error('CSRF token not found. Please refresh the page.')
    return token
}

const handleStarToggle = async (idea) => {
    try {
        const response = await fetch(`/ideas/${idea.id}/star`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
        })
        if (!response.ok) throw new Error(`Failed to toggle star: ${response.status}`)
        const data = await response.json()
        idea.is_starred = data.is_starred
        idea.starred_at = data.starred_at

        if (props.mode === 'starred' && !idea.is_starred) {
            const currentPage = pagination.value.current_page || 1
            await fetchIdeas(currentPage)
            if (ideas.value.length === 0 && currentPage > 1) {
                await fetchIdeas(currentPage - 1)
            }
        }
    } catch (error) {
        console.error('Failed to toggle star:', error)
    }
}

const changePage = async (page) => {
    await fetchIdeas(page)
    scrollToTop()
}

const handlePerPageChange = async (newPerPage) => {
    perPage.value = newPerPage
    await fetchIdeas(1)
    scrollToTop()
}

// Page number generation: always show first, last, current ±1, with ellipsis
const pageNumbers = computed(() => {
    const total = pagination.value.last_page || 1
    const current = pagination.value.current_page || 1
    if (total <= 1) return []

    const pages = new Set([1, total, current])
    if (current > 1) pages.add(current - 1)
    if (current < total) pages.add(current + 1)

    const sorted = Array.from(pages).sort((a, b) => a - b)
    const result = []
    let prev = null
    for (const p of sorted) {
        if (prev !== null && p - prev > 1) {
            result.push('...')
        }
        result.push(p)
        prev = p
    }
    return result
})

onMounted(() => {
    fetchIdeas()
})

onBeforeUnmount(() => {
    clearTimeout(debounceTimer)
    abortController.value?.abort()
})

defineExpose({ refresh: () => fetchIdeas(1) })

watch(
    () => props.mode,
    () => {
        filters.value = getDefaultFilters()
        fetchIdeas()
    }
)

watch(
    () => props.subredditId,
    () => { fetchIdeas() }
)
</script>

<template>
    <div ref="tableRef" class="bg-surface-elevated rounded-xl border border-border-default shadow-sm overflow-hidden">
        <!-- Filters toolbar -->
        <IdeaFilters
            :filters="filters"
            :defaults="modeDefaults"
            class="border-b border-border-subtle"
            @change="handleFilterChange"
        />

        <!-- Loading: skeleton rows -->
        <div
            v-if="loading"
            aria-busy="true"
            aria-label="Loading ideas"
        >
            <span class="sr-only" aria-live="polite">Loading ideas</span>
            <SkeletonRow
                v-for="i in 5"
                :key="i"
                :show-subreddit="showSubreddit"
                class="border-b border-border-subtle last:border-0"
            />
        </div>

        <template v-else>
            <!-- Empty state -->
            <div v-if="ideas.length === 0" class="p-8">
                <EmptyState
                    :title="
                        mode === 'starred'
                            ? (hasActiveFilters ? 'No starred ideas match your filters' : 'No starred ideas yet')
                            : (hasActiveFilters ? 'No ideas match your filters' : 'No ideas found')
                    "
                    :description="
                        mode === 'starred'
                            ? (hasActiveFilters ? 'Try adjusting your filters to see more results' : 'Star ideas from any subreddit to save them here')
                            : (hasActiveFilters ? 'Try adjusting your filters to see more results' : 'Scan this subreddit to discover SaaS opportunities')
                    "
                >
                    <template #icon>
                        <svg class="w-10 h-10 text-content-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                        </svg>
                    </template>
                    <template v-if="hasActiveFilters" #action>
                        <button
                            type="button"
                            @click="clearFilters"
                            class="inline-flex items-center px-4 py-2 text-sm font-medium text-brand-600 border border-brand-300 rounded-lg hover:bg-brand-50 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 min-h-[44px]"
                        >
                            Clear filters
                        </button>
                    </template>
                </EmptyState>
            </div>

            <div v-else>
                <!-- Sticky table header -->
                <div
                    :class="[
                        'sticky top-0 z-10 hidden md:grid gap-3 px-5 py-2.5',
                        'backdrop-blur-sm bg-surface-overlay border-b border-border-subtle',
                        'text-xs font-semibold text-content-tertiary uppercase tracking-wide',
                        showSubreddit
                            ? 'md:grid-cols-[40px_1fr_48px_minmax(0,200px)_auto_auto_auto]'
                            : 'md:grid-cols-[40px_1fr_48px_minmax(0,200px)_auto_auto]',
                    ]"
                >
                    <div class="flex items-center justify-center" aria-label="Starred">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                        </svg>
                    </div>
                    <div>Idea</div>
                    <div class="text-center">Score</div>
                    <div>Audience</div>
                    <div v-if="showSubreddit">Source</div>
                    <div class="text-right">Date</div>
                    <div></div>
                </div>

                <!-- Idea rows -->
                <div aria-live="polite" aria-label="Ideas list">
                    <IdeaRow
                        v-for="idea in ideas"
                        :key="idea.id"
                        :idea="idea"
                        :expanded="expandedId === idea.id"
                        :show-subreddit="showSubreddit"
                        @toggle="toggleExpand(idea.id)"
                        @star="handleStarToggle(idea)"
                    />
                </div>

                <!-- Pagination -->
                <div
                    v-if="pagination.last_page > 1"
                    class="flex flex-col sm:flex-row items-center justify-between gap-3 px-5 py-4 border-t border-border-subtle"
                >
                    <!-- Showing X–Y of Z -->
                    <p class="text-sm text-content-tertiary whitespace-nowrap">
                        Showing
                        {{ (pagination.current_page - 1) * pagination.per_page + 1 }}–{{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }}
                        of {{ pagination.total }} ideas
                    </p>

                    <!-- Page number buttons -->
                    <div class="flex items-center gap-1" role="navigation" aria-label="Pagination">
                        <!-- Previous -->
                        <button
                            @click="changePage(pagination.current_page - 1)"
                            :disabled="pagination.current_page === 1"
                            aria-label="Previous page"
                            class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg border border-border-default text-content-secondary hover:bg-surface-secondary disabled:opacity-40 disabled:cursor-not-allowed transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>

                        <!-- Page numbers with ellipsis -->
                        <template v-for="(page, i) in pageNumbers" :key="i">
                            <span
                                v-if="page === '...'"
                                class="min-h-[36px] min-w-[36px] flex items-center justify-center text-content-tertiary text-sm"
                                aria-hidden="true"
                            >…</span>
                            <button
                                v-else
                                @click="changePage(page)"
                                :aria-label="`Page ${page}`"
                                :aria-current="page === pagination.current_page ? 'page' : undefined"
                                :class="[
                                    'min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                                    page === pagination.current_page
                                        ? 'bg-brand-500 text-content-inverse border border-brand-500'
                                        : 'border border-border-default text-content-secondary hover:bg-surface-secondary',
                                ]"
                            >
                                {{ page }}
                            </button>
                        </template>

                        <!-- Next -->
                        <button
                            @click="changePage(pagination.current_page + 1)"
                            :disabled="pagination.current_page === pagination.last_page"
                            aria-label="Next page"
                            class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg border border-border-default text-content-secondary hover:bg-surface-secondary disabled:opacity-40 disabled:cursor-not-allowed transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>

                    <!-- Items per page -->
                    <div class="flex items-center gap-2 text-sm text-content-tertiary">
                        <label for="per-page-select" class="whitespace-nowrap">Per page:</label>
                        <select
                            id="per-page-select"
                            :value="perPage"
                            @change="handlePerPageChange(parseInt($event.target.value, 10))"
                            class="px-2 py-1 border border-border-default rounded-lg bg-surface-elevated text-content-primary text-sm focus:outline-none focus:border-brand-500 min-h-[44px]"
                        >
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
