<template>
    <div
        aria-hidden="true"
        class="rounded-lg border border-border-default bg-surface-secondary p-5 space-y-3"
    >
        <!-- Title placeholder: 60% width, 20px height -->
        <div
            class="h-5 rounded-md bg-surface-tertiary animate-pulse"
            style="width: 60%; animation-delay: 0ms"
        />

        <!-- Subtitle placeholder: 40% width, 14px height -->
        <div
            class="h-3.5 rounded bg-surface-tertiary animate-pulse"
            style="width: 40%; animation-delay: 50ms"
        />

        <!-- Stats 2×2 grid -->
        <div class="grid grid-cols-2 gap-4 pt-1">
            <div
                v-for="i in 4"
                :key="i"
                class="space-y-1.5"
            >
                <!-- Large number placeholder: 40px wide, 28px tall -->
                <div
                    class="h-7 w-10 rounded bg-surface-tertiary animate-pulse"
                    :style="{ animationDelay: `${100 + (i - 1) * 50}ms` }"
                />
                <!-- Label placeholder: 60px wide, 12px tall -->
                <div
                    class="h-3 w-14 rounded bg-surface-tertiary animate-pulse"
                    :style="{ animationDelay: `${125 + (i - 1) * 50}ms` }"
                />
            </div>
        </div>
    </div>
</template>
