<script setup>
import { useTheme } from '@/composables/useTheme.js'

defineProps({
    collapsed: {
        type: Boolean,
        default: false,
    },
})

const { isDark, toggleTheme } = useTheme()
</script>

<template>
    <button
        type="button"
        @click="toggleTheme"
        :aria-label="`Switch to ${isDark ? 'light' : 'dark'} mode`"
        :title="collapsed ? `Switch to ${isDark ? 'light' : 'dark'} mode` : undefined"
        :class="[
            'flex items-center w-full text-sm font-medium rounded-lg transition-colors',
            'text-content-secondary hover:text-content-primary hover:bg-surface-tertiary',
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 focus-visible:ring-offset-surface-secondary',
            collapsed
                ? 'justify-center p-2 min-h-[44px] min-w-[44px]'
                : 'gap-3 px-3 py-2 min-h-[44px]',
        ]"
    >
        <!-- Icon: sun (dark mode active, click → light) or moon (light mode active, click → dark) -->
        <span class="relative flex-shrink-0 w-5 h-5">
            <Transition
                enter-active-class="transition-all duration-200"
                enter-from-class="opacity-0 rotate-90 scale-50"
                enter-to-class="opacity-100 rotate-0 scale-100"
                leave-active-class="transition-all duration-200 absolute inset-0"
                leave-from-class="opacity-100 rotate-0 scale-100"
                leave-to-class="opacity-0 -rotate-90 scale-50"
                mode="out-in"
            >
                <!-- Sun icon shown when dark mode is active (clicking goes to light) -->
                <svg
                    v-if="isDark"
                    key="sun"
                    class="w-5 h-5"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <!-- Moon icon shown when light mode is active (clicking goes to dark) -->
                <svg
                    v-else
                    key="moon"
                    class="w-5 h-5"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                </svg>
            </Transition>
        </span>

        <!-- Label (visible when not collapsed) -->
        <span v-if="!collapsed" class="truncate">
            {{ isDark ? 'Light mode' : 'Dark mode' }}
        </span>
    </button>
</template>
