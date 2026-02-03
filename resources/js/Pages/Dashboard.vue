<script setup>
import { ref, nextTick, watch, onBeforeUnmount } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

defineProps({
    subreddits: {
        type: Array,
        default: () => [],
    },
})

// Add subreddit modal
const showModal = ref(false)
const inputRef = ref(null)
const dialogRef = ref(null)
const form = useForm({
    name: '',
})

const submitForm = () => {
    form.post('/subreddits', {
        onSuccess: () => {
            showModal.value = false
            form.reset()
            form.clearErrors()
        },
    })
}

const openModal = () => {
    showModal.value = true
    form.clearErrors()
    nextTick(() => inputRef.value?.focus())
}

const closeModal = () => {
    showModal.value = false
    form.reset()
    form.clearErrors()
}

const handleDocumentEscapeKey = (event) => {
    if (showModal.value && event.key === 'Escape' && !form.processing) {
        closeModal()
    }
}

const handleFocusTrap = (event) => {
    // Only trap Tab and Shift+Tab keys
    if (event.key !== 'Tab') return
    if (!showModal.value || !dialogRef.value) return

    // Find focusable elements within the dialog only
    const focusableElements = dialogRef.value.querySelectorAll(
        'button:not(:disabled), [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])'
    )

    const focusedElement = document.activeElement
    const focusableArray = Array.from(focusableElements)
    const isFirstFocusable = focusedElement === focusableArray[0]
    const isLastFocusable = focusedElement === focusableArray[focusableArray.length - 1]

    if (event.shiftKey) {
        // Shift+Tab on first focusable - wrap to last
        if (isFirstFocusable) {
            event.preventDefault()
            focusableArray[focusableArray.length - 1]?.focus()
        }
    } else {
        // Tab on last focusable - wrap to first
        if (isLastFocusable) {
            event.preventDefault()
            focusableArray[0]?.focus()
        }
    }
}

// Manage modal overlay and focus
watch(showModal, (isOpen) => {
    if (isOpen) {
        document.addEventListener('keydown', handleDocumentEscapeKey)
        document.addEventListener('keydown', handleFocusTrap)
        const appRoot = document.getElementById('app')
        if (appRoot) {
            appRoot.setAttribute('inert', '')
            appRoot.setAttribute('aria-hidden', 'true')
        }
        document.body.style.overflow = 'hidden'
    } else {
        document.removeEventListener('keydown', handleDocumentEscapeKey)
        document.removeEventListener('keydown', handleFocusTrap)
        const appRoot = document.getElementById('app')
        if (appRoot) {
            appRoot.removeAttribute('inert')
            appRoot.removeAttribute('aria-hidden')
        }
        document.body.style.overflow = ''
    }
})

// Cleanup on unmount
onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleDocumentEscapeKey)
    document.removeEventListener('keydown', handleFocusTrap)
    const appRoot = document.getElementById('app')
    if (appRoot) {
        appRoot.removeAttribute('inert')
        appRoot.removeAttribute('aria-hidden')
    }
    document.body.style.overflow = ''
})
</script>

<template>
    <div>
        <Head title="Dashboard" />
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Track subreddits to discover SaaS opportunities
                </p>
            </div>
            <button
                type="button"
                @click="openModal"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Subreddit
            </button>
        </div>

        <!-- Empty state -->
        <div
            v-if="subreddits.length === 0"
            class="text-center py-12 bg-white rounded-lg border-2 border-dashed border-gray-300"
        >
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No subreddits</h3>
            <p class="mt-1 text-sm text-gray-500">Get started by adding a subreddit to scan.</p>
            <button
                type="button"
                @click="openModal"
                class="mt-4 inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
                Add your first subreddit
            </button>
        </div>

        <!-- Subreddit cards grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <Link
                v-for="subreddit in subreddits"
                :key="subreddit.id"
                :href="`/subreddits/${subreddit.id}`"
                class="block bg-white rounded-lg shadow-sm border border-gray-200 hover:shadow-md hover:border-indigo-300 transition-all p-6 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            {{ subreddit.full_name }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ subreddit.last_scanned_human || 'Never scanned' }}
                        </p>
                    </div>
                    <div v-if="subreddit.has_active_scan" class="flex items-center text-indigo-600" role="status" aria-live="polite">
                        <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                        <span class="sr-only">Scanning in progress</span>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-2xl font-bold text-gray-900">{{ subreddit.idea_count || 0 }}</p>
                        <p class="text-xs text-gray-500">Ideas found</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold" :class="(subreddit.top_score ?? 0) >= 4 ? 'text-green-600' : 'text-gray-900'">
                            {{ subreddit.top_score ?? '-' }}
                        </p>
                        <p class="text-xs text-gray-500">Top score</p>
                    </div>
                </div>
            </Link>
        </div>

        <!-- Add Subreddit Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center p-4">
                        <div
                            class="fixed inset-0 bg-black bg-opacity-25"
                            @click="!form.processing && closeModal()"
                            aria-hidden="true"
                        />

                        <div
                            ref="dialogRef"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="modal-title"
                            class="relative bg-white rounded-xl shadow-xl max-w-md w-full p-6"
                        >
                            <h2 id="modal-title" class="text-lg font-semibold text-gray-900 mb-4">Add Subreddit</h2>

                            <form @submit.prevent="submitForm">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                                        Subreddit name
                                    </label>
                                    <div class="flex">
                                        <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 text-gray-500 text-sm">
                                            r/
                                        </span>
                                        <input
                                            ref="inputRef"
                                            id="name"
                                            v-model="form.name"
                                            type="text"
                                            placeholder="startups"
                                            class="flex-1 rounded-r-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                            :class="{ 'border-red-500': form.errors.name }"
                                            :aria-invalid="!!form.errors.name"
                                            :aria-describedby="form.errors.name ? 'name-error' : undefined"
                                        />
                                    </div>
                                    <p v-if="form.errors.name" id="name-error" class="mt-1 text-sm text-red-600">
                                        {{ form.errors.name }}
                                    </p>
                                </div>

                                <div class="mt-6 flex justify-end space-x-3">
                                    <button
                                        type="button"
                                        @click="closeModal"
                                        :disabled="form.processing"
                                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="form.processing"
                                        class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        {{ form.processing ? 'Adding...' : 'Add Subreddit' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
