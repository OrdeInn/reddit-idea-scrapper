<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import ThemeToggle from '@/Components/ThemeToggle.vue'
import ToastContainer from '@/Components/ToastContainer.vue'
import { useSidebar } from '@/composables/useSidebar.js'
import { useToast } from '@/composables/useToast.js'

const page = usePage()
const { isCollapsed, toggleSidebar } = useSidebar()
const { addToast } = useToast()

// Mobile menu state
const mobileMenuOpen = ref(false)

const closeMobileMenu = () => {
    mobileMenuOpen.value = false
}

// Escape key closes mobile sidebar
const handleEscape = (e) => {
    if (e.key === 'Escape' && mobileMenuOpen.value) {
        closeMobileMenu()
    }
}

onMounted(() => document.addEventListener('keydown', handleEscape))
onBeforeUnmount(() => document.removeEventListener('keydown', handleEscape))

// Navigation items
const navigation = [
    { name: 'Dashboard', href: '/', icon: 'grid' },
    { name: 'Starred Ideas', href: '/starred', icon: 'star' },
]

const isActive = (href) => {
    const urlPath = page.url.split('?')[0]
    return urlPath === href || (href !== '/' && urlPath.startsWith(href + '/'))
}

// Convert flash messages to toast notifications
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) {
            addToast({ message: flash.success, type: 'success' })
        }
        if (flash?.error) {
            addToast({ message: flash.error, type: 'error' })
        }
    },
    { immediate: true }
)
</script>

<template>
    <div class="min-h-screen bg-surface-primary">
        <!-- Skip to main content (accessibility) -->
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[100] focus:px-4 focus:py-2 focus:bg-surface-elevated focus:text-content-primary focus:rounded-lg focus:shadow-lg focus:border focus:border-border-default focus:outline-none"
        >
            Skip to main content
        </a>

        <!-- Mobile menu button -->
        <div class="lg:hidden fixed top-3 left-3 z-50">
            <button
                type="button"
                @click="mobileMenuOpen = !mobileMenuOpen"
                :aria-label="mobileMenuOpen ? 'Close navigation' : 'Open navigation'"
                :aria-expanded="mobileMenuOpen"
                :aria-controls="'sidebar'"
                class="p-2 min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg bg-surface-elevated shadow-md border border-border-default text-content-secondary hover:text-content-primary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path v-if="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Mobile sidebar backdrop -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="mobileMenuOpen"
                @click="closeMobileMenu"
                class="fixed inset-0 bg-surface-scrim backdrop-blur-sm z-40 lg:hidden"
                aria-hidden="true"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            id="sidebar"
            :class="[
                'fixed inset-y-0 left-0 z-50 flex flex-col bg-surface-secondary border-r border-border-default',
                'transform transition-all duration-200 ease-in-out w-[260px]',
                // Desktop: always visible, width controlled by isCollapsed
                'lg:translate-x-0',
                isCollapsed ? 'lg:w-[72px]' : 'lg:w-[260px]',
                // Mobile: slides in from left when open
                mobileMenuOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ]"
            :aria-label="isCollapsed && !mobileMenuOpen ? 'Main navigation (collapsed)' : 'Main navigation'"
        >
            <!-- Logo area -->
            <div class="h-16 flex items-center border-b border-border-default px-4 flex-shrink-0 overflow-hidden">
                <Link
                    href="/"
                    class="flex items-center gap-3 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 min-h-[44px]"
                    @click="closeMobileMenu"
                >
                    <!-- App icon (always visible) -->
                    <div class="w-8 h-8 flex-shrink-0 rounded-lg bg-brand-500 flex items-center justify-center shadow-sm">
                        <svg class="w-4 h-4 text-content-inverse" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                        </svg>
                    </div>
                    <!-- App name (hidden on desktop when collapsed) -->
                    <span
                        :class="[
                            'font-display font-bold text-content-primary text-sm whitespace-nowrap transition-opacity duration-200',
                            isCollapsed && !mobileMenuOpen ? 'lg:opacity-0 lg:pointer-events-none lg:w-0' : '',
                        ]"
                    >
                        SaaS Scanner
                    </span>
                </Link>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-2 py-3 space-y-0.5 overflow-y-auto" aria-label="Main">
                <Link
                    v-for="item in navigation"
                    :key="item.name"
                    :href="item.href"
                    @click="closeMobileMenu"
                    :title="isCollapsed && !mobileMenuOpen ? item.name : undefined"
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                    :class="[
                        'group flex items-center gap-3 rounded-lg text-sm font-medium transition-colors',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1 focus-visible:ring-offset-surface-secondary',
                        'min-h-[44px] overflow-hidden',
                        isCollapsed && !mobileMenuOpen ? 'lg:justify-center lg:px-2' : 'px-3',
                        isActive(item.href)
                            ? 'bg-brand-50 text-brand-700 border-l-[3px] border-brand-500 pl-[calc(0.75rem-3px)]'
                            : 'text-content-secondary hover:text-content-primary hover:bg-surface-tertiary',
                    ]"
                >
                    <!-- Grid icon (Dashboard) -->
                    <svg
                        v-if="item.icon === 'grid'"
                        class="w-5 h-5 flex-shrink-0"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                    </svg>
                    <!-- Star icon (Starred Ideas) -->
                    <svg
                        v-else-if="item.icon === 'star'"
                        class="w-5 h-5 flex-shrink-0"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                    </svg>

                    <!-- Label (hidden on desktop when collapsed) -->
                    <span
                        :class="[
                            'truncate transition-opacity duration-200',
                            isCollapsed && !mobileMenuOpen ? 'lg:opacity-0 lg:pointer-events-none lg:w-0' : '',
                        ]"
                    >
                        {{ item.name }}
                    </span>
                </Link>
            </nav>

            <!-- Divider -->
            <div class="mx-2 border-t border-border-subtle" aria-hidden="true" />

            <!-- Footer: ThemeToggle + collapse toggle -->
            <div class="px-2 py-3 flex-shrink-0 space-y-0.5">
                <ThemeToggle :collapsed="isCollapsed && !mobileMenuOpen" />

                <!-- Collapse toggle — desktop only -->
                <button
                    type="button"
                    @click="toggleSidebar"
                    :aria-label="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    :title="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    :class="[
                        'hidden lg:flex items-center w-full rounded-lg text-sm font-medium transition-colors min-h-[44px]',
                        'text-content-secondary hover:text-content-primary hover:bg-surface-tertiary',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1 focus-visible:ring-offset-surface-secondary',
                        isCollapsed ? 'justify-center px-2 min-w-[44px]' : 'gap-3 px-3',
                    ]"
                >
                    <svg
                        :class="['w-5 h-5 flex-shrink-0 transition-transform duration-200', isCollapsed ? 'rotate-180' : '']"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                    </svg>
                    <span
                        v-show="!isCollapsed"
                        class="truncate"
                    >Collapse</span>
                </button>
            </div>
        </aside>

        <!-- Main content area -->
        <main
            id="main-content"
            :class="[
                'min-h-screen transition-all duration-200 ease-in-out bg-surface-primary',
                isCollapsed ? 'lg:pl-[72px]' : 'lg:pl-[260px]',
            ]"
        >
            <!-- Page content with top padding on mobile to clear hamburger -->
            <div class="px-4 pb-6 pt-16 sm:px-6 lg:px-8 lg:pt-8 lg:pb-8">
                <Transition
                    enter-active-class="transition ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0"
                    mode="out-in"
                >
                    <div :key="page.url">
                        <slot />
                    </div>
                </Transition>
            </div>
        </main>

        <!-- Toast notifications (rendered via Teleport) -->
        <ToastContainer />
    </div>
</template>
