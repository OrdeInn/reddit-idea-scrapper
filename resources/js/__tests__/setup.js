// Global test setup for Vitest
// Reset module state between tests to avoid singleton bleed
import { afterEach, vi } from 'vitest'

// Mock matchMedia (not available in jsdom)
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })),
})

afterEach(() => {
    localStorage.clear()
})
