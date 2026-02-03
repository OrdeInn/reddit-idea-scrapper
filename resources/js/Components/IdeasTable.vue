<script setup>
import { ref, watch, onMounted } from 'vue'
import IdeaFilters from './IdeaFilters.vue'
import IdeaRow from './IdeaRow.vue'

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

const getDefaultFilters = () => ({
    min_score: 1,
    min_complexity: 1,
    starred_only: false,
    include_borderline: true,
    sort_by: props.mode === 'starred' ? 'starred_at' : 'score_overall',
    sort_dir: 'desc',
})

const filters = ref(getDefaultFilters())

const fetchIdeas = async (page = 1) => {
    loading.value = true

    const params = new URLSearchParams({
        ...filters.value,
        page,
        per_page: 20,
    })

    const url = props.mode === 'starred'
        ? `/api/starred?${params}`
        : `/subreddits/${props.subredditId}/ideas?${params}`

    try {
        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
            },
        })
        if (!response.ok) {
            throw new Error(`Failed to fetch ideas: ${response.status} ${response.statusText}`)
        }
        const data = await response.json()
        ideas.value = data.ideas
        pagination.value = data.pagination
    } catch (error) {
        console.error('Failed to fetch ideas:', error)
    } finally {
        loading.value = false
    }
}

const toggleExpand = (id) => {
    expandedId.value = expandedId.value === id ? null : id
}

const handleFilterChange = (newFilters) => {
    filters.value = { ...filters.value, ...newFilters }
    fetchIdeas(1)
}

const getCsrfToken = () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    if (!token) {
        throw new Error('CSRF token not found. Please refresh the page.')
    }
    return token
}

const handleStarToggle = async (idea) => {
    try {
        const response = await fetch(`/ideas/${idea.id}/star`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
        })
        if (!response.ok) {
            throw new Error(`Failed to toggle star: ${response.status}`)
        }
        const data = await response.json()
        idea.is_starred = data.is_starred
        idea.starred_at = data.starred_at

        // Refetch on starred page when unstarring to ensure pagination stays correct
        if (props.mode === 'starred' && !idea.is_starred) {
            const currentPage = pagination.value.current_page || 1
            await fetchIdeas(currentPage)
            // If we land on an empty page and not on page 1, try the previous page
            if (ideas.value.length === 0 && currentPage > 1) {
                await fetchIdeas(currentPage - 1)
            }
        }
    } catch (error) {
        console.error('Failed to toggle star:', error)
    }
}

const changePage = (page) => {
    fetchIdeas(page)
}

onMounted(() => {
    fetchIdeas()
})

// Reset filters and refetch when mode changes
watch(() => props.mode, () => {
    filters.value = getDefaultFilters()
    fetchIdeas()
})

// Refetch when subredditId changes
watch(() => props.subredditId, () => {
    fetchIdeas()
})
</script>

<template>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <!-- Filters -->
        <IdeaFilters
            :filters="filters"
            @change="handleFilterChange"
            class="border-b border-gray-200"
        />

        <!-- Loading -->
        <div v-if="loading" class="p-8 text-center">
            <svg class="animate-spin h-8 w-8 text-indigo-600 mx-auto" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <p class="mt-2 text-sm text-gray-500">Loading ideas...</p>
        </div>

        <!-- Empty state -->
        <div v-else-if="ideas.length === 0" class="p-8 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No ideas found</h3>
            <p class="mt-1 text-sm text-gray-500">
                Try adjusting your filters or scan more subreddits.
            </p>
        </div>

        <!-- Table -->
        <div v-else>
            <!-- Header -->
            <div class="hidden md:grid md:grid-cols-12 gap-4 px-6 py-3 bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                <div class="col-span-1">Star</div>
                <div class="col-span-4">Idea</div>
                <div class="col-span-1 text-center">Score</div>
                <div class="col-span-2">Audience</div>
                <div class="col-span-1 text-center">Complexity</div>
                <div v-if="showSubreddit" class="col-span-2">Source</div>
                <div :class="showSubreddit ? 'col-span-1' : 'col-span-3'" class="text-right">Date</div>
            </div>

            <!-- Rows -->
            <div class="divide-y divide-gray-200">
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
            <div v-if="pagination.last_page > 1" class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                <p class="text-sm text-gray-500">
                    Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to
                    {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }}
                    of {{ pagination.total }} ideas
                </p>
                <div class="flex space-x-2">
                    <button
                        @click="changePage(pagination.current_page - 1)"
                        :disabled="pagination.current_page === 1"
                        class="px-3 py-1 text-sm border rounded disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                    >
                        Previous
                    </button>
                    <button
                        @click="changePage(pagination.current_page + 1)"
                        :disabled="pagination.current_page === pagination.last_page"
                        class="px-3 py-1 text-sm border rounded disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
