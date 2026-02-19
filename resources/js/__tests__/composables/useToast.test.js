import { describe, it, expect, beforeEach, vi } from 'vitest'

describe('useToast', () => {
    let useToast

    beforeEach(async () => {
        // Reset modules to get a fresh singleton for each test
        vi.resetModules()
        const mod = await import('@/composables/useToast.js')
        useToast = mod.useToast
    })

    it('addToast adds a toast with the correct message', () => {
        const { toasts, addToast } = useToast()

        addToast({ message: 'Hello world' })

        expect(toasts.value.some((t) => t.message === 'Hello world')).toBe(true)
    })

    it('addToast defaults type to success', () => {
        const { toasts, addToast } = useToast()

        addToast({ message: 'Done' })

        const toast = toasts.value.find((t) => t.message === 'Done')
        expect(toast.type).toBe('success')
    })

    it('addToast sets custom type', () => {
        const { toasts, addToast } = useToast()

        addToast({ message: 'Error occurred', type: 'error' })

        const toast = toasts.value.find((t) => t.message === 'Error occurred')
        expect(toast.type).toBe('error')
    })

    it('addToast increments id for each toast', () => {
        const { toasts, addToast } = useToast()

        addToast({ message: 'First' })
        addToast({ message: 'Second' })

        const ids = toasts.value.map((t) => t.id)
        const uniqueIds = new Set(ids)
        expect(uniqueIds.size).toBe(ids.length)
    })

    it('addToast sets createdAt timestamp', () => {
        const { toasts, addToast } = useToast()
        const before = Date.now()

        addToast({ message: 'Timed' })

        const toast = toasts.value.at(-1)
        expect(toast.createdAt).toBeGreaterThanOrEqual(before)
    })

    it('addToast enforces MAX_TOASTS=3 by removing oldest', () => {
        const { toasts, addToast } = useToast()

        addToast({ message: 'First' })
        addToast({ message: 'Second' })
        addToast({ message: 'Third' })
        addToast({ message: 'Fourth' })

        // Should still be 3, oldest removed
        expect(toasts.value).toHaveLength(3)
        const messages = toasts.value.map((t) => t.message)
        expect(messages).not.toContain('First')
        expect(messages).toContain('Fourth')
    })

    it('removeToast removes a toast by id', () => {
        const { toasts, addToast, removeToast } = useToast()

        addToast({ message: 'Remove me' })
        const id = toasts.value.at(-1).id

        removeToast(id)

        expect(toasts.value.find((t) => t.id === id)).toBeUndefined()
    })

    it('removeToast is a no-op for unknown id', () => {
        const { toasts, addToast, removeToast } = useToast()

        addToast({ message: 'Keep me' })
        const countBefore = toasts.value.length

        removeToast(99999)

        expect(toasts.value.length).toBe(countBefore)
    })
})
