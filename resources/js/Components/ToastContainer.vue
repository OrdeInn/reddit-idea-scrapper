<script setup>
import ToastNotification from './ToastNotification.vue'
import { useToast } from '@/composables/useToast.js'

const { toasts, removeToast } = useToast()
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed bottom-4 right-4 left-4 sm:left-auto z-[9999] flex flex-col gap-2 sm:min-w-[320px] sm:max-w-[400px]"
            aria-live="polite"
            aria-label="Notifications"
            aria-atomic="false"
        >
            <TransitionGroup
                enter-active-class="transition ease-out duration-300"
                enter-from-class="opacity-0 translate-x-full"
                enter-to-class="opacity-100 translate-x-0"
                leave-active-class="transition ease-in duration-200 absolute w-full"
                leave-from-class="opacity-100 translate-x-0"
                leave-to-class="opacity-0 translate-x-full"
                move-class="transition-all duration-200"
            >
                <ToastNotification
                    v-for="toast in toasts"
                    :key="toast.id"
                    :toast="toast"
                    @dismiss="removeToast"
                />
            </TransitionGroup>
        </div>
    </Teleport>
</template>
