import { ref } from 'vue'

// Module-level singleton — sidebar state persists across navigations
const isCollapsed = ref(false)

// Initialize from localStorage
try {
    isCollapsed.value = localStorage.getItem('sidebar-collapsed') === 'true'
} catch {
    // localStorage unavailable — keep default (expanded)
}

export function useSidebar() {
    const toggleSidebar = () => {
        isCollapsed.value = !isCollapsed.value
        try {
            localStorage.setItem('sidebar-collapsed', String(isCollapsed.value))
        } catch {
            // ignore
        }
    }

    return { isCollapsed, toggleSidebar }
}
