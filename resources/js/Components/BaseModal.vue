<script setup>
import { ref, watch, nextTick, onBeforeUnmount, getCurrentInstance } from 'vue'

const props = defineProps({
    open: {
        type: Boolean,
        required: true,
    },
    title: {
        type: String,
        default: '',
    },
    maxWidth: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg', 'xl'].includes(v),
    },
    closeable: {
        type: Boolean,
        default: true,
    },
})

const emit = defineEmits(['close'])

const dialogRef = ref(null)

// Unique title ID per instance to prevent collisions when multiple modals exist
const instance = getCurrentInstance()
const titleId = `modal-title-${instance?.uid ?? Math.floor(Math.random() * 1e6)}`

const maxWidthClass = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
}

const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not(:disabled)',
    'input:not(:disabled)',
    'select:not(:disabled)',
    'textarea:not(:disabled)',
    '[tabindex]:not([tabindex="-1"])',
].join(', ')

// Track previous focus target and prior overflow for restore on close
let previouslyFocusedElement = null
let previousOverflow = ''

const handleEscape = (event) => {
    if (props.closeable && event.key === 'Escape') {
        emit('close')
    }
}

const handleFocusTrap = (event) => {
    if (event.key !== 'Tab' || !dialogRef.value) return

    const focusable = Array.from(dialogRef.value.querySelectorAll(FOCUSABLE_SELECTOR))
    if (focusable.length === 0) return

    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    const active = document.activeElement

    if (event.shiftKey) {
        if (active === first) {
            event.preventDefault()
            last.focus()
        }
    } else {
        if (active === last) {
            event.preventDefault()
            first.focus()
        }
    }
}

const handleBackdropClick = () => {
    if (props.closeable) {
        emit('close')
    }
}

const lockBody = () => {
    previouslyFocusedElement = document.activeElement
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const appRoot = document.getElementById('app')
    if (appRoot) {
        appRoot.setAttribute('inert', '')
        appRoot.setAttribute('aria-hidden', 'true')
    }
}

const unlockBody = () => {
    document.body.style.overflow = previousOverflow
    const appRoot = document.getElementById('app')
    if (appRoot) {
        appRoot.removeAttribute('inert')
        appRoot.removeAttribute('aria-hidden')
    }
    // Restore focus to previously focused element for accessibility
    // Guard against detached nodes (e.g., after Inertia navigation or unmount)
    if (
        previouslyFocusedElement &&
        typeof previouslyFocusedElement.focus === 'function' &&
        document.contains(previouslyFocusedElement)
    ) {
        previouslyFocusedElement.focus()
    }
    previouslyFocusedElement = null
    previousOverflow = ''
}

// immediate: true ensures lock/focus runs even when open=true on first mount
watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            lockBody()
            document.addEventListener('keydown', handleEscape)
            document.addEventListener('keydown', handleFocusTrap)
            await nextTick()
            // Focus first focusable element inside dialog
            const focusable = dialogRef.value?.querySelectorAll(FOCUSABLE_SELECTOR)
            if (focusable?.length) {
                focusable[0].focus()
            }
        } else {
            unlockBody()
            document.removeEventListener('keydown', handleEscape)
            document.removeEventListener('keydown', handleFocusTrap)
        }
    },
    { immediate: true }
)

onBeforeUnmount(() => {
    if (props.open) {
        unlockBody()
    }
    document.removeEventListener('keydown', handleEscape)
    document.removeEventListener('keydown', handleFocusTrap)
})
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 overflow-y-auto"
                role="presentation"
            >
                <div class="flex min-h-full items-center justify-center p-4">
                    <!-- Scrim/backdrop: bg-surface-scrim stays dark in both light and dark mode -->
                    <div
                        class="fixed inset-0 bg-surface-scrim backdrop-blur-sm"
                        aria-hidden="true"
                        @click="handleBackdropClick"
                    />

                    <!-- Dialog panel -->
                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 scale-95"
                        enter-to-class="opacity-100 scale-100"
                        leave-active-class="transition ease-in duration-150"
                        leave-from-class="opacity-100 scale-100"
                        leave-to-class="opacity-0 scale-[0.98]"
                        appear
                    >
                        <div
                            v-if="open"
                            ref="dialogRef"
                            role="dialog"
                            aria-modal="true"
                            :aria-labelledby="title ? titleId : undefined"
                            :class="[
                                'relative bg-surface-elevated rounded-xl shadow-xl w-full',
                                maxWidthClass[maxWidth],
                            ]"
                        >
                            <!-- Header (only when title is provided) -->
                            <div v-if="title" class="flex items-center justify-between p-6 border-b border-border-subtle">
                                <h2 :id="titleId" class="text-lg font-semibold text-content-primary">
                                    {{ title }}
                                </h2>
                                <!-- Close button: min-h/min-w ensures 44×44px touch target (WCAG 2.1) -->
                                <button
                                    v-if="closeable"
                                    type="button"
                                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-content-tertiary hover:text-content-secondary hover:bg-surface-secondary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated"
                                    aria-label="Close modal"
                                    @click="emit('close')"
                                >
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Body -->
                            <div class="p-6">
                                <slot />
                            </div>

                            <!-- Footer -->
                            <div v-if="$slots.footer" class="px-6 pb-6">
                                <slot name="footer" />
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
