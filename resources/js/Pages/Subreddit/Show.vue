<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { Head, router, Link } from '@inertiajs/vue3'
import IdeasTable from '@/Components/IdeasTable.vue'
import ScanProgress from '@/Components/ScanProgress.vue'

const props = defineProps({
    subreddit: {
        type: Object,
        required: true,
    },
    status: {
        type: Object,
        default: () => ({
            has_active_scan: false,
            active_scan: null,
            last_scan: null,
        }),
    },
})

// Scan status polling
const scanStatus = ref({
    has_active_scan: false,
    active_scan: null,
    last_scan: null,
})
const isPolling = ref(false)
const isStartingScan = ref(false)
const isCancellingScan = ref(false)
const errorMessage = ref(null)
const isPollRequestInFlight = ref(false)
let pollInterval = null
let pollAbortController = null

const isScanning = computed(() => !!scanStatus.value?.has_active_scan)
const activeScan = computed(() => scanStatus.value?.active_scan)
const lastScan = computed(() => scanStatus.value?.last_scan)

watch(
    () => props.status,
    (nextStatus) => {
        scanStatus.value = {
            has_active_scan: false,
            active_scan: null,
            last_scan: null,
            ...(nextStatus ?? {}),
        }
    },
    { immediate: true }
)

const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

const getErrorMessageFromResponse = async (response) => {
    try {
        const data = await response.json()
        if (typeof data?.message === 'string') return data.message
    } catch {
        // ignore
    }
    return `Request failed (${response.status})`
}

const startScan = async () => {
    if (isStartingScan.value || isScanning.value) return
    errorMessage.value = null
    isStartingScan.value = true

    try {
        const csrfToken = getCsrfToken()
        if (!csrfToken) {
            throw new Error('Missing CSRF token. Please refresh the page and try again.')
        }

        const response = await fetch(`/subreddits/${props.subreddit.id}/scan`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
        })
        if (!response.ok) {
            throw new Error(await getErrorMessageFromResponse(response))
        }
        const data = await response.json()
        scanStatus.value = {
            ...scanStatus.value,
            has_active_scan: true,
            active_scan: data.scan ?? null,
        }

        if (data.scan?.is_in_progress) {
            startPolling()
        }
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : 'Failed to start scan'
    }
    isStartingScan.value = false
}

const cancelScan = async () => {
    if (!activeScan.value) return
    if (isCancellingScan.value) return
    errorMessage.value = null
    isCancellingScan.value = true

    try {
        const csrfToken = getCsrfToken()
        if (!csrfToken) {
            throw new Error('Missing CSRF token. Please refresh the page and try again.')
        }

        const response = await fetch(`/scans/${activeScan.value.id}/cancel`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
        })

        if (!response.ok) {
            throw new Error(await getErrorMessageFromResponse(response))
        }

        const data = await response.json()
        stopPolling()
        scanStatus.value = {
            ...scanStatus.value,
            has_active_scan: false,
            active_scan: null,
            last_scan: scanStatus.value.last_scan,
        }
        router.reload({ only: ['status'] })
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : 'Failed to cancel scan'
    }
    isCancellingScan.value = false
}

const pollStatus = async () => {
    if (!activeScan.value) return
    if (isPollRequestInFlight.value) return

    try {
        isPollRequestInFlight.value = true
        pollAbortController?.abort()
        pollAbortController = new AbortController()

        const response = await fetch(`/scans/${activeScan.value.id}/status`, {
            signal: pollAbortController.signal,
        })
        if (!response.ok) {
            throw new Error(await getErrorMessageFromResponse(response))
        }
        const data = await response.json()
        errorMessage.value = null
        scanStatus.value = {
            ...scanStatus.value,
            active_scan: data.scan ?? null,
        }

        if (!data.scan?.is_in_progress || data.scan.is_completed || data.scan.is_failed) {
            stopPolling()
            scanStatus.value = {
                ...scanStatus.value,
                has_active_scan: false,
                // Keep failed scan for display/retry, only clear if completed
                active_scan: data.scan?.is_failed ? data.scan : null,
                last_scan: data.scan?.is_completed ? data.scan : scanStatus.value.last_scan,
            }
            router.reload({ only: ['status', 'subreddit'] })
        }
    } catch (error) {
        if (error?.name !== 'AbortError') {
            errorMessage.value = error instanceof Error ? error.message : 'Failed to poll status'
        }
    } finally {
        isPollRequestInFlight.value = false
    }
}

const startPolling = () => {
    if (isPolling.value) return
    isPolling.value = true
    pollStatus()
    pollInterval = setInterval(pollStatus, 3000)
}

const stopPolling = () => {
    isPolling.value = false
    if (pollInterval) {
        clearInterval(pollInterval)
        pollInterval = null
    }
    pollAbortController?.abort()
    pollAbortController = null
}

watch(
    () => [isScanning.value, activeScan.value?.id, activeScan.value?.is_in_progress],
    ([scanning, scanId, isInProgress]) => {
        if (scanning && scanId && isInProgress) {
            startPolling()
            return
        }
        stopPolling()
    },
    { immediate: true }
)

onBeforeUnmount(() => stopPolling())

const deleteSubreddit = () => {
    if (confirm(`Are you sure you want to remove r/${props.subreddit.name}? This will delete all associated data.`)) {
        router.delete(`/subreddits/${props.subreddit.id}`)
    }
}
</script>

<template>
    <div>
        <Head :title="subreddit.full_name" />
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <div class="flex items-center space-x-2">
                    <Link href="/" class="text-gray-400 hover:text-gray-600" aria-label="Back to dashboard">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <h1 class="text-2xl font-bold text-gray-900">{{ subreddit.full_name }}</h1>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    {{ subreddit.last_scanned_human ? `Last scanned ${subreddit.last_scanned_human}` : 'Never scanned' }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    v-if="!isScanning"
                    @click="startScan"
                    type="button"
                    :disabled="isStartingScan"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ lastScan ? 'Rescan' : 'Scan' }}
                </button>

                <button
                    v-else
                    @click="cancelScan"
                    type="button"
                    :disabled="isCancellingScan"
                    class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-red-500"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    Cancel Scan
                </button>

                <button
                    @click="deleteSubreddit"
                    type="button"
                    class="p-2 text-gray-400 hover:text-red-600 transition-colors"
                    aria-label="Remove subreddit"
                    title="Remove subreddit"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </button>
            </div>
        </div>

        <div
            v-if="errorMessage"
            class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            role="alert"
        >
            {{ errorMessage }}
        </div>

        <!-- Scan Progress (when scanning or failed) -->
        <ScanProgress
            v-if="(isScanning || activeScan?.is_failed) && activeScan"
            :scan="activeScan"
            @retry="startScan"
            class="mb-6"
        />

        <!-- Ideas Table -->
        <IdeasTable :subreddit-id="subreddit.id" />
    </div>
</template>
