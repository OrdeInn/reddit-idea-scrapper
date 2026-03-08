import { ref } from 'vue'

// Module-level singleton — fetched once, shared across all component instances
const providers = ref([])
const classificationProviders = ref([])
const extractionProvider = ref(null)
const extractionFilterProviders = ref([])
const isLoaded = ref(false)
let fetchPromise = null

/**
 * Full color map for all allowed color values in the provider config.
 * Full literal class strings are required for Tailwind JIT compilation —
 * class names must NOT be dynamically constructed.
 */
export const COLOR_MAP = {
    amber: {
        classes: 'bg-amber-50 text-amber-700 ring-amber-200/60 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40',
        dot: 'bg-amber-400 dark:bg-amber-500',
        border: 'border-amber-400 dark:border-amber-600',
    },
    purple: {
        classes: 'bg-purple-50 text-purple-700 ring-purple-200/60 dark:bg-purple-900/20 dark:text-purple-300 dark:ring-purple-700/40',
        dot: 'bg-purple-400 dark:bg-purple-500',
        border: 'border-purple-400 dark:border-purple-600',
    },
    red: {
        classes: 'bg-red-50 text-red-700 ring-red-200/60 dark:bg-red-900/20 dark:text-red-300 dark:ring-red-700/40',
        dot: 'bg-red-400 dark:bg-red-500',
        border: 'border-red-400 dark:border-red-600',
    },
    emerald: {
        classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200/60 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40',
        dot: 'bg-emerald-400 dark:bg-emerald-500',
        border: 'border-emerald-400 dark:border-emerald-600',
    },
    green: {
        classes: 'bg-green-50 text-green-700 ring-green-200/60 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-700/40',
        dot: 'bg-green-400 dark:bg-green-500',
        border: 'border-green-400 dark:border-green-600',
    },
    blue: {
        classes: 'bg-blue-50 text-blue-700 ring-blue-200/60 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40',
        dot: 'bg-blue-400 dark:bg-blue-500',
        border: 'border-blue-400 dark:border-blue-600',
    },
    rose: {
        classes: 'bg-rose-50 text-rose-700 ring-rose-200/60 dark:bg-rose-900/20 dark:text-rose-300 dark:ring-rose-700/40',
        dot: 'bg-rose-400 dark:bg-rose-500',
        border: 'border-rose-400 dark:border-rose-600',
    },
    cyan: {
        classes: 'bg-cyan-50 text-cyan-700 ring-cyan-200/60 dark:bg-cyan-900/20 dark:text-cyan-300 dark:ring-cyan-700/40',
        dot: 'bg-cyan-400 dark:bg-cyan-500',
        border: 'border-cyan-400 dark:border-cyan-600',
    },
    orange: {
        classes: 'bg-orange-50 text-orange-700 ring-orange-200/60 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-700/40',
        dot: 'bg-orange-400 dark:bg-orange-500',
        border: 'border-orange-400 dark:border-orange-600',
    },
    indigo: {
        classes: 'bg-indigo-50 text-indigo-700 ring-indigo-200/60 dark:bg-indigo-900/20 dark:text-indigo-300 dark:ring-indigo-700/40',
        dot: 'bg-indigo-400 dark:bg-indigo-500',
        border: 'border-indigo-400 dark:border-indigo-600',
    },
    teal: {
        classes: 'bg-teal-50 text-teal-700 ring-teal-200/60 dark:bg-teal-900/20 dark:text-teal-300 dark:ring-teal-700/40',
        dot: 'bg-teal-400 dark:bg-teal-500',
        border: 'border-teal-400 dark:border-teal-600',
    },
    pink: {
        classes: 'bg-pink-50 text-pink-700 ring-pink-200/60 dark:bg-pink-900/20 dark:text-pink-300 dark:ring-pink-700/40',
        dot: 'bg-pink-400 dark:bg-pink-500',
        border: 'border-pink-400 dark:border-pink-600',
    },
    lime: {
        classes: 'bg-lime-50 text-lime-700 ring-lime-200/60 dark:bg-lime-900/20 dark:text-lime-300 dark:ring-lime-700/40',
        dot: 'bg-lime-400 dark:bg-lime-500',
        border: 'border-lime-400 dark:border-lime-600',
    },
}

/**
 * Fallback auto-color palette for unknown providers (not in COLOR_MAP).
 * Full literal strings required for Tailwind JIT.
 */
export const AUTO_COLOR_PALETTE = [
    {
        classes: 'bg-blue-50 text-blue-700 ring-blue-200/60 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40',
        dot: 'bg-blue-400 dark:bg-blue-500',
        border: 'border-blue-400 dark:border-blue-600',
    },
    {
        classes: 'bg-rose-50 text-rose-700 ring-rose-200/60 dark:bg-rose-900/20 dark:text-rose-300 dark:ring-rose-700/40',
        dot: 'bg-rose-400 dark:bg-rose-500',
        border: 'border-rose-400 dark:border-rose-600',
    },
    {
        classes: 'bg-cyan-50 text-cyan-700 ring-cyan-200/60 dark:bg-cyan-900/20 dark:text-cyan-300 dark:ring-cyan-700/40',
        dot: 'bg-cyan-400 dark:bg-cyan-500',
        border: 'border-cyan-400 dark:border-cyan-600',
    },
    {
        classes: 'bg-orange-50 text-orange-700 ring-orange-200/60 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-700/40',
        dot: 'bg-orange-400 dark:bg-orange-500',
        border: 'border-orange-400 dark:border-orange-600',
    },
    {
        classes: 'bg-indigo-50 text-indigo-700 ring-indigo-200/60 dark:bg-indigo-900/20 dark:text-indigo-300 dark:ring-indigo-700/40',
        dot: 'bg-indigo-400 dark:bg-indigo-500',
        border: 'border-indigo-400 dark:border-indigo-600',
    },
    {
        classes: 'bg-teal-50 text-teal-700 ring-teal-200/60 dark:bg-teal-900/20 dark:text-teal-300 dark:ring-teal-700/40',
        dot: 'bg-teal-400 dark:bg-teal-500',
        border: 'border-teal-400 dark:border-teal-600',
    },
    {
        classes: 'bg-pink-50 text-pink-700 ring-pink-200/60 dark:bg-pink-900/20 dark:text-pink-300 dark:ring-pink-700/40',
        dot: 'bg-pink-400 dark:bg-pink-500',
        border: 'border-pink-400 dark:border-pink-600',
    },
    {
        classes: 'bg-lime-50 text-lime-700 ring-lime-200/60 dark:bg-lime-900/20 dark:text-lime-300 dark:ring-lime-700/40',
        dot: 'bg-lime-400 dark:bg-lime-500',
        border: 'border-lime-400 dark:border-lime-600',
    },
]

const hashProvider = (name) => {
    let hash = 0
    for (let i = 0; i < name.length; i++) hash += name.charCodeAt(i)
    return hash % AUTO_COLOR_PALETTE.length
}

const labelFromKey = (key) => {
    return key.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')
}

async function loadMetadata() {
    try {
        const response = await fetch('/api/provider-metadata', {
            headers: { Accept: 'application/json' },
        })
        if (!response.ok) {
            // Reset so retry is possible on next component mount
            fetchPromise = null
            return
        }

        const data = await response.json()
        providers.value = data.providers ?? []
        classificationProviders.value = data.classification_providers ?? []
        extractionProvider.value = data.extraction_provider ?? null
        extractionFilterProviders.value = data.extraction_filter_providers ?? []
        isLoaded.value = true
    } catch {
        // Reset so retry is possible on next component mount
        fetchPromise = null
        // Gracefully degrade — providers will fall back to label-from-key
    }
}

export function useProviderMetadata() {
    // Trigger fetch on first call — subsequent calls reuse the singleton promise
    if (!fetchPromise) {
        fetchPromise = loadMetadata()
    }

    /**
     * Get provider metadata object for a given config key.
     * Returns a fallback shape for unknown providers.
     */
    function getProvider(configKey) {
        if (!configKey) return null

        const found = providers.value.find(p => p.config_key === configKey)
        if (found) return found

        return {
            config_key: configKey,
            display_name: labelFromKey(configKey),
            model: null,
            vendor: null,
            color: null,
            capabilities: [],
        }
    }

    /**
     * Get the full color set { classes, dot, border } for a provider.
     * Uses COLOR_MAP if the provider has a known color, otherwise falls back to auto-palette.
     */
    function getProviderColor(configKey) {
        if (!configKey) return null

        const metadata = getProvider(configKey)
        if (metadata?.color && COLOR_MAP[metadata.color]) {
            return COLOR_MAP[metadata.color]
        }

        return AUTO_COLOR_PALETTE[hashProvider(configKey)]
    }

    /**
     * Convenience helper that returns only the border class string for a provider.
     */
    function getProviderBorderColor(configKey) {
        if (!configKey) return 'border-border-default'
        return getProviderColor(configKey)?.border ?? 'border-border-default'
    }

    return {
        providers,
        classificationProviders,
        extractionProvider,
        extractionFilterProviders,
        isLoaded,
        getProvider,
        getProviderColor,
        getProviderBorderColor,
    }
}
