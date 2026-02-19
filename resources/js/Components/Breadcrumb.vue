<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    items: {
        type: Array,
        required: true,
        // Each item: { label: String, href?: String }
    },
})
</script>

<template>
    <nav aria-label="Breadcrumb">
        <ol class="flex items-center gap-0.5 text-sm flex-wrap">
            <li
                v-for="(item, index) in items"
                :key="`${item.label}-${index}`"
                class="flex items-center gap-0.5"
            >
                <!-- Chevron separator (not before first item) -->
                <svg
                    v-if="index > 0"
                    class="h-3.5 w-3.5 text-content-tertiary flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>

                <!-- Last item — current page, plain text -->
                <span
                    v-if="index === items.length - 1"
                    class="font-semibold text-content-primary px-1"
                    aria-current="page"
                >
                    {{ item.label }}
                </span>

                <!-- Other items — Inertia Link with 44px touch target height -->
                <Link
                    v-else-if="item.href"
                    :href="item.href"
                    class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-1 text-content-secondary hover:text-content-primary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded"
                >
                    {{ item.label }}
                </Link>

                <!-- Fallback for items without href that are not last -->
                <span v-else class="px-1 text-content-secondary">
                    {{ item.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
