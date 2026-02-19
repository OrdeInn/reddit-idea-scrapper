import { ref } from 'vue'

// Module-level singleton — shared across all components
const toasts = ref([])
let nextId = 0
const MAX_TOASTS = 3

export function useToast() {
    const addToast = ({ message, type = 'success', duration = 5000, action = null }) => {
        // Enforce max 3 toasts: remove oldest when limit is reached
        if (toasts.value.length >= MAX_TOASTS) {
            toasts.value.shift()
        }

        toasts.value.push({
            id: ++nextId,
            message,
            type,
            duration,
            action,
            createdAt: Date.now(),
        })
    }

    const removeToast = (id) => {
        const index = toasts.value.findIndex((t) => t.id === id)
        if (index !== -1) {
            toasts.value.splice(index, 1)
        }
    }

    return { toasts, addToast, removeToast }
}
