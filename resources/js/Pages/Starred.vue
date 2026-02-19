<script setup>
import { Head } from '@inertiajs/vue3'
import IdeasTable from '@/Components/IdeasTable.vue'
</script>

<template>
    <div>
        <Head title="Starred Ideas" />

        <!-- Page header -->
        <div class="mb-8">
            <div class="flex items-center gap-3 mb-1">
                <!-- Star icon -->
                <svg
                    class="w-7 h-7 text-accent-500 flex-shrink-0"
                    fill="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                </svg>
                <h1 class="text-2xl font-bold font-display text-content-primary">Starred Ideas</h1>
            </div>
            <p class="text-sm text-content-secondary pl-10">
                Your favorite SaaS ideas from all subreddits
            </p>
        </div>

        <!-- Ideas table in starred mode (shows subreddit column) -->
        <IdeasTable :show-subreddit="true" mode="starred" />
    </div>
</template>
