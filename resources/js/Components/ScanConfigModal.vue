<script setup>
import { ref, computed, watch } from 'vue'
import BaseModal from '@/Components/BaseModal.vue'
import BaseButton from '@/Components/BaseButton.vue'

const props = defineProps({
    show: {
        type: Boolean,
        required: true,
    },
    isRescan: {
        type: Boolean,
        default: false,
    },
    scanHistory: {
        type: Array,
        default: () => [],
    },
    defaults: {
        type: Object,
        default: () => ({ default_timeframe_weeks: 1, rescan_timeframe_weeks: 2 }),
    },
    isSubmitting: {
        type: Boolean,
        default: false,
    },
    errorMessage: {
        type: String,
        default: null,
    },
})

const emit = defineEmits(['confirm', 'close'])

// --- Presets definition ---
const PRESETS = [
    { id: '6h',  label: '6 Hours',  hours: 6 },
    { id: '1d',  label: '1 Day',    hours: 24 },
    { id: '3d',  label: '3 Days',   hours: 72 },
    { id: '1w',  label: '1 Week',   hours: 24 * 7 },
    { id: '2w',  label: '2 Weeks',  hours: 24 * 14 },
    { id: '4w',  label: '4 Weeks',  hours: 24 * 28 },
]

// --- Internal state ---
const selectedPreset = ref(null)
const customDateFrom = ref('')
const customDateTo = ref('')
const validationError = ref(null)

// Derive default preset id from props
const defaultPresetId = computed(() => {
    const weeks = props.isRescan
        ? (props.defaults?.rescan_timeframe_weeks ?? 2)
        : (props.defaults?.default_timeframe_weeks ?? 1)
    if (weeks <= 0.25) return '6h'
    if (weeks <= 0.5) return '1d'
    if (weeks <= 0.75) return '3d'
    if (weeks <= 1) return '1w'
    if (weeks <= 2) return '2w'
    return '4w'
})

// Reset state when modal opens
watch(
    () => props.show,
    (isOpen) => {
        if (isOpen) {
            selectedPreset.value = defaultPresetId.value
            customDateFrom.value = ''
            customDateTo.value = toLocalDateTimeInput(new Date())
            validationError.value = null
        }
    },
    { immediate: true }
)

// --- Helper: format a Date to datetime-local string ---
function toLocalDateTimeInput(date) {
    const pad = (n) => String(n).padStart(2, '0')
    return (
        date.getFullYear() + '-' +
        pad(date.getMonth() + 1) + '-' +
        pad(date.getDate()) + 'T' +
        pad(date.getHours()) + ':' +
        pad(date.getMinutes())
    )
}

// --- Helper: format date range label for preset subtitle ---
function presetSubtitle(preset) {
    const now = new Date()
    const from = new Date(now.getTime() - preset.hours * 60 * 60 * 1000)
    return formatShortDate(from) + ' – ' + formatShortDate(now)
}

function formatShortDate(date) {
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function formatFullDate(isoString) {
    if (!isoString) return 'N/A'
    const d = new Date(isoString)
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

// --- Preset selection ---
function selectPreset(id) {
    selectedPreset.value = id
    customDateFrom.value = ''
    customDateTo.value = toLocalDateTimeInput(new Date())
    validationError.value = null
}

// --- Custom input changes clear preset ---
function onCustomFromInput() {
    selectedPreset.value = null
    validationError.value = null
}

function onCustomToInput() {
    selectedPreset.value = null
    validationError.value = null
}

// --- Helper: safely convert a datetime-local string to ISO8601 UTC ---
function toISOStringSafe(localValue) {
    if (!localValue) return null
    const d = new Date(localValue)
    if (isNaN(d.getTime())) return null
    return d.toISOString()
}

// --- Computed: resolved date range (ISO8601 UTC strings) ---
const resolvedDateRange = computed(() => {
    if (selectedPreset.value) {
        const preset = PRESETS.find((p) => p.id === selectedPreset.value)
        if (!preset) return null
        const now = new Date()
        const from = new Date(now.getTime() - preset.hours * 60 * 60 * 1000)
        return { date_from: from.toISOString(), date_to: now.toISOString() }
    }
    if (customDateFrom.value && customDateTo.value) {
        const from = toISOStringSafe(customDateFrom.value)
        const to = toISOStringSafe(customDateTo.value)
        if (!from || !to) return null
        return { date_from: from, date_to: to }
    }
    return null
})

// --- Computed: summary text ---
const summaryText = computed(() => {
    const range = resolvedDateRange.value
    if (!range) return null
    const from = new Date(range.date_from)
    const to = new Date(range.date_to)
    return `Scanning posts from ${formatShortDate(from)} to ${formatShortDate(to)}`
})

// --- Computed: is submit enabled ---
const canSubmit = computed(() => {
    return !!resolvedDateRange.value && !props.isSubmitting
})

// --- Validation ---
function validate() {
    const range = resolvedDateRange.value
    if (!range) {
        // Detect case where user entered custom dates but they failed to parse
        if (!selectedPreset.value && (customDateFrom.value || customDateTo.value)) {
            validationError.value = 'Invalid date/time range. Please check your inputs.'
        } else {
            validationError.value = 'Please select a time range.'
        }
        return false
    }
    const from = new Date(range.date_from)
    const to = new Date(range.date_to)
    const now = new Date()

    if (from > now) {
        validationError.value = 'Start date cannot be in the future.'
        return false
    }
    if (to > now) {
        validationError.value = 'End date cannot be in the future.'
        return false
    }
    if (from >= to) {
        validationError.value = 'Start date must be before end date.'
        return false
    }
    const diffDays = (to - from) / (1000 * 60 * 60 * 24)
    if (diffDays > 84) {
        validationError.value = 'Date range cannot exceed 12 weeks.'
        return false
    }
    validationError.value = null
    return true
}

// --- Submit ---
function handleConfirm() {
    if (!validate()) return
    emit('confirm', resolvedDateRange.value)
}

function handleClose() {
    if (props.isSubmitting) return
    emit('close')
}

// --- Visible history (max 5 rows) ---
const visibleHistory = computed(() => props.scanHistory.slice(0, 5))
const hiddenHistoryCount = computed(() => Math.max(0, props.scanHistory.length - 5))
</script>

<template>
    <BaseModal
        :open="show"
        title="Configure Scan"
        max-width="lg"
        :closeable="!isSubmitting"
        @close="handleClose"
    >
        <div class="space-y-5">
            <!-- Scan history (rescans only) -->
            <div
                v-if="isRescan && scanHistory.length > 0"
                class="rounded-lg bg-surface-secondary border border-border-subtle p-4"
            >
                <h3 class="text-sm font-semibold text-content-primary mb-3">Previous Scans</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead>
                            <tr class="text-content-tertiary border-b border-border-subtle">
                                <th class="pb-2 pr-4 font-medium">Date</th>
                                <th class="pb-2 pr-4 font-medium">Range</th>
                                <th class="pb-2 pr-4 font-medium text-right">Posts</th>
                                <th class="pb-2 font-medium text-right">Ideas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="scan in visibleHistory"
                                :key="scan.id"
                                class="border-b border-border-subtle last:border-0"
                            >
                                <td class="py-1.5 pr-4 text-content-secondary whitespace-nowrap">
                                    {{ scan.completed_at_human ?? 'N/A' }}
                                </td>
                                <td class="py-1.5 pr-4 text-content-secondary whitespace-nowrap">
                                    <span v-if="scan.date_from && scan.date_to">
                                        {{ formatFullDate(scan.date_from) }} – {{ formatFullDate(scan.date_to) }}
                                    </span>
                                    <span v-else class="text-content-tertiary">N/A</span>
                                </td>
                                <td class="py-1.5 pr-4 text-content-secondary text-right tabular-nums">
                                    {{ scan.posts_fetched ?? 0 }}
                                </td>
                                <td class="py-1.5 text-content-secondary text-right tabular-nums">
                                    {{ scan.ideas_found ?? 0 }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="hiddenHistoryCount > 0" class="mt-2 text-xs text-content-tertiary">
                        and {{ hiddenHistoryCount }} more
                    </p>
                </div>
            </div>

            <!-- Preset time range chips -->
            <div>
                <h3 class="text-sm font-semibold text-content-primary mb-3">Select Time Range</h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2" role="group" aria-label="Preset time ranges">
                    <button
                        v-for="preset in PRESETS"
                        :key="preset.id"
                        type="button"
                        :aria-pressed="selectedPreset === preset.id"
                        :class="[
                            'rounded-lg border p-3 text-left cursor-pointer transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                            selectedPreset === preset.id
                                ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500'
                                : 'border-border-default hover:bg-surface-secondary',
                        ]"
                        @click="selectPreset(preset.id)"
                    >
                        <span class="block text-sm font-semibold text-content-primary">{{ preset.label }}</span>
                        <span class="block text-xs text-content-tertiary mt-0.5 truncate">{{ presetSubtitle(preset) }}</span>
                    </button>
                </div>
            </div>

            <!-- Divider -->
            <div class="flex items-center gap-3">
                <div class="flex-1 border-t border-border-subtle" />
                <span class="text-xs text-content-tertiary font-medium">or set a custom range</span>
                <div class="flex-1 border-t border-border-subtle" />
            </div>

            <!-- Custom date range inputs -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="scan-date-from" class="block text-sm font-medium text-content-primary mb-1.5">
                        From
                    </label>
                    <input
                        id="scan-date-from"
                        v-model="customDateFrom"
                        type="datetime-local"
                        class="w-full rounded-lg border border-border-default bg-surface-elevated text-content-primary text-sm px-3 py-2 focus:outline-none focus:border-brand-500 focus:ring-0"
                        :max="customDateTo || toLocalDateTimeInput(new Date())"
                        @input="onCustomFromInput"
                    />
                </div>
                <div>
                    <label for="scan-date-to" class="block text-sm font-medium text-content-primary mb-1.5">
                        To
                    </label>
                    <input
                        id="scan-date-to"
                        v-model="customDateTo"
                        type="datetime-local"
                        class="w-full rounded-lg border border-border-default bg-surface-elevated text-content-primary text-sm px-3 py-2 focus:outline-none focus:border-brand-500 focus:ring-0"
                        :max="toLocalDateTimeInput(new Date())"
                        @input="onCustomToInput"
                    />
                </div>
            </div>

            <!-- Summary line -->
            <p v-if="summaryText" class="text-sm text-content-secondary">
                {{ summaryText }}
            </p>

            <!-- Validation error -->
            <div
                v-if="validationError"
                class="rounded-lg border border-status-error bg-surface-secondary px-4 py-3 text-sm text-status-error"
                role="alert"
            >
                {{ validationError }}
            </div>

            <!-- Server error -->
            <div
                v-if="errorMessage"
                class="rounded-lg border border-status-error bg-surface-secondary px-4 py-3 text-sm text-status-error"
                role="alert"
            >
                {{ errorMessage }}
            </div>
        </div>

        <template #footer>
            <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3">
                <BaseButton
                    variant="secondary"
                    :disabled="isSubmitting"
                    @click="handleClose"
                >
                    Cancel
                </BaseButton>
                <BaseButton
                    variant="primary"
                    :loading="isSubmitting"
                    :disabled="!canSubmit"
                    @click="handleConfirm"
                >
                    {{ isSubmitting ? 'Starting…' : 'Start Scan' }}
                </BaseButton>
            </div>
        </template>
    </BaseModal>
</template>
