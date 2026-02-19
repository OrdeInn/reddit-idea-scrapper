<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import IdeasTable from '@/Components/IdeasTable.vue'
import ScanProgress from '@/Components/ScanProgress.vue'
import Breadcrumb from '@/Components/Breadcrumb.vue'
import BaseButton from '@/Components/BaseButton.vue'
import BaseModal from '@/Components/BaseModal.vue'
import StatCard from '@/Components/StatCard.vue'
import ScanConfigModal from '@/Components/ScanConfigModal.vue'

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
    scan_history: {
        type: Array,
        default: () => [],
    },
    scan_defaults: {
        type: Object,
        default: () => ({ default_timeframe_weeks: 1, rescan_timeframe_weeks: 2 }),
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
const showDeleteModal = ref(false)
const isDeleting = ref(false)
let pollInterval = null
let pollAbortController = null

const showConfigModal = ref(false)
const modalErrorMessage = ref(null)
const ideasTable = ref(null)

const isScanning = computed(() => !!scanStatus.value?.has_active_scan)
const activeScan = computed(() => scanStatus.value?.active_scan)
const lastScan = computed(() => scanStatus.value?.last_scan)
const isRescan = computed(() => !!lastScan.value)

const breadcrumbItems = computed(() => [
    { label: 'Dashboard', href: '/' },
    { label: props.subreddit.full_name },
])

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

const getCsrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

const getErrorMessageFromResponse = async (response) => {
    try {
        const data = await response.json()
        // Prefer first field-level validation error from Laravel's errors bag
        if (data?.errors) {
            const firstKey = Object.keys(data.errors)[0]
            if (firstKey && Array.isArray(data.errors[firstKey]) && data.errors[firstKey].length) {
                return data.errors[firstKey][0]
            }
        }
        if (typeof data?.message === 'string') return data.message
    } catch { /* ignore */ }
    return `Request failed (${response.status})`
}

const startScan = async (dateFrom, dateTo) => {
    if (isStartingScan.value || isScanning.value) return
    modalErrorMessage.value = null
    isStartingScan.value = true

    try {
        const csrfToken = getCsrfToken()
        if (!csrfToken) throw new Error('Missing CSRF token. Please refresh the page and try again.')

        const response = await fetch(`/subreddits/${props.subreddit.id}/scan`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ date_from: dateFrom, date_to: dateTo }),
        })
        if (!response.ok) throw new Error(await getErrorMessageFromResponse(response))

        const data = await response.json()
        showConfigModal.value = false
        scanStatus.value = {
            ...scanStatus.value,
            has_active_scan: true,
            active_scan: data.scan ?? null,
        }

        if (data.scan?.is_in_progress) startPolling()
    } catch (error) {
        modalErrorMessage.value = error instanceof Error ? error.message : 'Failed to start scan'
    }
    isStartingScan.value = false
}

const handleScanConfirm = ({ date_from, date_to }) => {
    startScan(date_from, date_to)
}

const handleModalClose = () => {
    showConfigModal.value = false
    modalErrorMessage.value = null
}

const cancelScan = async () => {
    if (!activeScan.value || isCancellingScan.value) return
    errorMessage.value = null
    isCancellingScan.value = true

    try {
        const csrfToken = getCsrfToken()
        if (!csrfToken) throw new Error('Missing CSRF token. Please refresh the page and try again.')

        const response = await fetch(`/scans/${activeScan.value.id}/cancel`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        })
        if (!response.ok) throw new Error(await getErrorMessageFromResponse(response))

        stopPolling()
        scanStatus.value = {
            ...scanStatus.value,
            has_active_scan: false,
            active_scan: null,
        }
        router.reload({ only: ['status'] })
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : 'Failed to cancel scan'
    }
    isCancellingScan.value = false
}

const pollStatus = async () => {
    if (!activeScan.value || isPollRequestInFlight.value) return

    try {
        isPollRequestInFlight.value = true
        pollAbortController?.abort()
        pollAbortController = new AbortController()

        const response = await fetch(`/scans/${activeScan.value.id}/status`, {
            signal: pollAbortController.signal,
        })
        if (!response.ok) throw new Error(await getErrorMessageFromResponse(response))

        const data = await response.json()
        errorMessage.value = null
        scanStatus.value = { ...scanStatus.value, active_scan: data.scan ?? null }

        if (!data.scan?.is_in_progress || data.scan.is_completed || data.scan.is_failed) {
            stopPolling()
            scanStatus.value = {
                ...scanStatus.value,
                has_active_scan: false,
                active_scan: data.scan?.is_failed ? data.scan : null,
                last_scan: data.scan?.is_completed ? data.scan : scanStatus.value.last_scan,
            }
            if (data.scan?.is_completed) {
                ideasTable.value?.refresh()
            }
            router.reload({ only: ['status', 'subreddit', 'scan_history'] })
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
    if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
    pollAbortController?.abort()
    pollAbortController = null
}

watch(
    () => [isScanning.value, activeScan.value?.id, activeScan.value?.is_in_progress],
    ([scanning, scanId, isInProgress]) => {
        if (scanning && scanId && isInProgress) { startPolling(); return }
        stopPolling()
    },
    { immediate: true }
)

onBeforeUnmount(() => stopPolling())

const confirmDelete = async () => {
    isDeleting.value = true
    router.delete(`/subreddits/${props.subreddit.id}`, {
        onFinish: () => { isDeleting.value = false },
    })
}
</script>

<template>
    <div>
        <Head :title="subreddit.full_name" />

        <!-- Breadcrumb navigation -->
        <Breadcrumb :items="breadcrumbItems" class="mb-5" />

        <!-- Header card -->
        <div class="bg-surface-secondary border border-border-default rounded-xl shadow-sm p-6 mb-6">
            <!-- Top row: name + actions -->
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5">
                <div class="min-w-0">
                    <h1 class="text-xl font-bold font-display text-content-primary truncate">
                        {{ subreddit.full_name }}
                    </h1>
                    <p class="mt-1 text-sm text-content-secondary">
                        {{ subreddit.last_scanned_human ? `Last scanned ${subreddit.last_scanned_human}` : 'Never scanned' }}
                    </p>
                </div>

                <!-- Action buttons -->
                <div class="flex items-center gap-2 flex-shrink-0">
                    <BaseButton
                        v-if="!isScanning"
                        variant="primary"
                        :loading="isStartingScan"
                        @click="showConfigModal = true"
                    >
                        <template #icon-left>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </template>
                        {{ lastScan ? 'Rescan' : 'Scan' }}
                    </BaseButton>

                    <BaseButton
                        v-else
                        variant="danger"
                        :loading="isCancellingScan"
                        @click="cancelScan"
                    >
                        <template #icon-left>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </template>
                        Cancel Scan
                    </BaseButton>

                    <!-- Delete button (icon only) -->
                    <button
                        type="button"
                        @click="showDeleteModal = true"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg border border-border-default text-content-tertiary hover:text-status-error hover:border-status-error hover:bg-surface-tertiary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        aria-label="Remove subreddit"
                        title="Remove subreddit"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Quick stats row -->
            <div class="grid grid-cols-3 gap-4">
                <StatCard :value="subreddit.idea_count ?? 0" label="Ideas found" />
                <StatCard
                    :value="subreddit.avg_score != null ? Number(subreddit.avg_score).toFixed(1) : null"
                    label="Avg score"
                    :highlight="(subreddit.avg_score ?? 0) >= 4"
                />
                <StatCard
                    :value="subreddit.last_scanned_human"
                    label="Last scanned"
                />
            </div>
        </div>

        <!-- Error message -->
        <div
            v-if="errorMessage"
            class="mb-6 flex items-start gap-3 rounded-lg border-l-4 border-status-error bg-surface-secondary px-4 py-3"
            role="alert"
        >
            <svg class="w-5 h-5 text-status-error flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
            </svg>
            <p class="text-sm text-status-error">{{ errorMessage }}</p>
        </div>

        <!-- Scan progress (when scanning or failed) -->
        <ScanProgress
            v-if="(isScanning || activeScan?.is_failed) && activeScan"
            :scan="activeScan"
            @retry="showConfigModal = true"
            class="mb-6"
        />

        <!-- Ideas table -->
        <IdeasTable ref="ideasTable" :subreddit-id="subreddit.id" />

        <!-- Scan configuration modal -->
        <ScanConfigModal
            :show="showConfigModal"
            :is-rescan="isRescan"
            :scan-history="scan_history"
            :defaults="scan_defaults"
            :is-submitting="isStartingScan"
            :error-message="modalErrorMessage"
            @confirm="handleScanConfirm"
            @close="handleModalClose"
        />

        <!-- Delete confirmation modal -->
        <BaseModal
            :open="showDeleteModal"
            title="Remove subreddit"
            max-width="sm"
            @close="showDeleteModal = false"
        >
            <p class="text-sm text-content-secondary leading-relaxed">
                Are you sure you want to remove <strong class="text-content-primary font-semibold">{{ subreddit.full_name }}</strong>?
                This will delete all associated scans and ideas.
                <span class="font-semibold text-status-error">This action cannot be undone.</span>
            </p>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <BaseButton
                        variant="secondary"
                        :disabled="isDeleting"
                        @click="showDeleteModal = false"
                    >
                        Cancel
                    </BaseButton>
                    <BaseButton
                        variant="danger"
                        :loading="isDeleting"
                        @click="confirmDelete"
                    >
                        Delete
                    </BaseButton>
                </div>
            </template>
        </BaseModal>
    </div>
</template>
