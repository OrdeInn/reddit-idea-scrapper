import { describe, it, expect, beforeEach, vi } from 'vitest'

describe('useTheme', () => {
    let useTheme

    beforeEach(async () => {
        // Reset modules to get fresh singleton state for each test
        vi.resetModules()
        localStorage.clear()
        // Ensure matchMedia returns dark: false by default
        window.matchMedia = vi.fn().mockImplementation((query) => ({
            matches: false,
            media: query,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            dispatchEvent: vi.fn(),
        }))
        const mod = await import('@/composables/useTheme.js')
        useTheme = mod.useTheme
    })

    it('toggleTheme flips isDark from false to true', () => {
        const { isDark, toggleTheme } = useTheme()

        const initial = isDark.value
        toggleTheme()

        expect(isDark.value).toBe(!initial)
    })

    it('toggleTheme flips isDark from true to false', () => {
        const { isDark, toggleTheme } = useTheme()

        // Set to dark first
        toggleTheme()
        const afterFirst = isDark.value

        toggleTheme()

        expect(isDark.value).toBe(!afterFirst)
    })

    it('toggleTheme saves dark preference to localStorage', () => {
        const { isDark, toggleTheme } = useTheme()

        // Ensure we go to dark mode
        if (!isDark.value) {
            toggleTheme()
        }

        expect(localStorage.getItem('theme')).toBe('dark')
    })

    it('toggleTheme saves light preference to localStorage', () => {
        const { isDark, toggleTheme } = useTheme()

        // Go to dark first, then back to light
        if (!isDark.value) {
            toggleTheme()
        }
        toggleTheme()

        expect(localStorage.getItem('theme')).toBe('light')
    })

    it('setTheme dark sets isDark=true and saves dark to localStorage', () => {
        const { isDark, setTheme } = useTheme()

        setTheme('dark')

        expect(isDark.value).toBe(true)
        expect(localStorage.getItem('theme')).toBe('dark')
    })

    it('setTheme light sets isDark=false and saves light to localStorage', () => {
        const { isDark, setTheme } = useTheme()

        setTheme('light')

        expect(isDark.value).toBe(false)
        expect(localStorage.getItem('theme')).toBe('light')
    })

    it('setTheme system removes localStorage preference', () => {
        const { setTheme } = useTheme()

        // Set explicit preference first
        setTheme('dark')
        expect(localStorage.getItem('theme')).toBe('dark')

        // Then reset to system
        setTheme('system')

        expect(localStorage.getItem('theme')).toBeNull()
    })
})
