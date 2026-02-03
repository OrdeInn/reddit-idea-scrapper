<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()

// Mobile menu state
const mobileMenuOpen = ref(false)

// Flash message handling
const flash = ref(null)
const showFlash = ref(false)
let flashTimeout = null

const showFlashMessage = () => {
    // Clear any existing timeout
    if (flashTimeout) {
        clearTimeout(flashTimeout)
        flashTimeout = null
    }

    if (page.props.flash?.success || page.props.flash?.error) {
        flash.value = {
            type: page.props.flash.success ? 'success' : 'error',
            message: page.props.flash.success || page.props.flash.error,
        }
        showFlash.value = true

        // Auto-dismiss after 5 seconds
        flashTimeout = setTimeout(() => {
            showFlash.value = false
        }, 5000)
    } else {
        showFlash.value = false
    }
}

// Watch for flash message changes on navigation
watch(() => page.props.flash, showFlashMessage, { immediate: true })

// Clean up timeout when component unmounts
onBeforeUnmount(() => {
    if (flashTimeout) {
        clearTimeout(flashTimeout)
    }
})

const dismissFlash = () => {
    showFlash.value = false
    if (flashTimeout) {
        clearTimeout(flashTimeout)
        flashTimeout = null
    }
}

const closeMobileMenu = () => {
    mobileMenuOpen.value = false
}

// Navigation items
const navigation = [
    { name: 'Dashboard', href: '/', icon: 'home' },
    { name: 'Starred Ideas', href: '/starred', icon: 'star' },
]

const isActive = (href) => {
    const urlPath = page.url.split('?')[0]
    return urlPath === href || (href !== '/' && urlPath.startsWith(href + '/'))
}
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <!-- Mobile menu button -->
        <div class="lg:hidden fixed top-4 left-4 z-50">
            <button
                @click="mobileMenuOpen = !mobileMenuOpen"
                :aria-label="mobileMenuOpen ? 'Close menu' : 'Open menu'"
                :aria-expanded="mobileMenuOpen"
                class="p-2 rounded-md bg-white shadow-md text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path
                        v-if="!mobileMenuOpen"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M4 6h16M4 12h16M4 18h16"
                    />
                    <path
                        v-else
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M6 18L18 6M6 6l12 12"
                    />
                </svg>
            </button>
        </div>

        <!-- Sidebar overlay (mobile) -->
        <div
            v-if="mobileMenuOpen"
            @click="closeMobileMenu"
            class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"
        />

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 w-64 bg-white shadow-lg transform transition-transform duration-200 ease-in-out lg:translate-x-0',
                mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <div class="h-full flex flex-col">
                <!-- Logo -->
                <div class="h-16 flex items-center px-6 border-b border-gray-200">
                    <Link href="/" class="flex items-center space-x-2">
                        <span class="text-xl font-bold text-indigo-600">SaaS Scanner</span>
                    </Link>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 px-4 py-4 space-y-1">
                    <Link
                        v-for="item in navigation"
                        :key="item.name"
                        :href="item.href"
                        @click="closeMobileMenu"
                        :class="[
                            'flex items-center px-4 py-2 text-sm font-medium rounded-lg transition-colors',
                            isActive(item.href)
                                ? 'bg-indigo-50 text-indigo-700'
                                : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                        ]"
                    >
                        <!-- Home icon -->
                        <svg
                            v-if="item.icon === 'home'"
                            class="w-5 h-5 mr-3"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
                            />
                        </svg>
                        <!-- Star icon -->
                        <svg
                            v-if="item.icon === 'star'"
                            class="w-5 h-5 mr-3"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"
                            />
                        </svg>
                        {{ item.name }}
                    </Link>
                </nav>

                <!-- Footer -->
                <div class="p-4 border-t border-gray-200">
                    <p class="text-xs text-gray-500 text-center">
                        Reddit SaaS Idea Scanner
                    </p>
                </div>
            </div>
        </aside>

        <!-- Main content -->
        <main class="lg:pl-64">
            <div class="min-h-screen">
                <!-- Flash messages -->
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 -translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-2"
                >
                    <div
                        v-if="showFlash && flash"
                        role="alert"
                        aria-live="polite"
                        :class="[
                            'fixed top-4 right-4 z-50 max-w-sm w-full shadow-lg rounded-lg p-4',
                            flash.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'
                        ]"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <svg
                                    v-if="flash.type === 'success'"
                                    class="w-5 h-5 mr-2 text-green-400"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                                <svg
                                    v-else
                                    class="w-5 h-5 mr-2 text-red-400"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                                <span class="text-sm font-medium">{{ flash.message }}</span>
                            </div>
                            <button
                                @click="dismissFlash"
                                aria-label="Dismiss notification"
                                class="text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-400 rounded"
                            >
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path
                                        fill-rule="evenodd"
                                        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </Transition>

                <!-- Page content -->
                <div class="p-6 lg:p-8">
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
