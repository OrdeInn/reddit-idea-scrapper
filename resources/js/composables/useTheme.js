import { ref, watch } from 'vue'

// Module-level singleton — all components share the same reactive state
const isDark = ref(false)
let explicitPreference = false
let systemMediaQuery = null

// Initialize: read localStorage, fall back to system preference
if (typeof window !== 'undefined') {
    systemMediaQuery = window.matchMedia('(prefers-color-scheme: dark)')

    try {
        const saved = localStorage.getItem('theme')
        if (saved === 'dark') {
            isDark.value = true
            explicitPreference = true
        } else if (saved === 'light') {
            isDark.value = false
            explicitPreference = true
        } else {
            isDark.value = systemMediaQuery.matches
            explicitPreference = false
        }
    } catch {
        // localStorage unavailable (private browsing, storage full)
        isDark.value = systemMediaQuery.matches
        explicitPreference = false
    }

    // React to OS preference changes — only when no explicit user preference
    systemMediaQuery.addEventListener('change', (e) => {
        if (!explicitPreference) {
            isDark.value = e.matches
        }
    })
}

// Sync dark class on <html> whenever isDark changes
watch(
    isDark,
    (dark) => {
        if (dark) {
            document.documentElement.classList.add('dark')
        } else {
            document.documentElement.classList.remove('dark')
        }
    },
    { immediate: true }
)

export function useTheme() {
    const toggleTheme = () => {
        isDark.value = !isDark.value
        explicitPreference = true
        try {
            localStorage.setItem('theme', isDark.value ? 'dark' : 'light')
        } catch {
            // ignore
        }
    }

    const setTheme = (mode) => {
        if (mode === 'system') {
            explicitPreference = false
            try {
                localStorage.removeItem('theme')
            } catch {
                // ignore
            }
            isDark.value = systemMediaQuery?.matches ?? false
        } else {
            explicitPreference = true
            isDark.value = mode === 'dark'
            try {
                localStorage.setItem('theme', mode)
            } catch {
                // ignore
            }
        }
    }

    return { isDark, toggleTheme, setTheme }
}
