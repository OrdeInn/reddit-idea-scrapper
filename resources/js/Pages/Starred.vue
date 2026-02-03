<script setup>
import { Head } from '@inertiajs/vue3'
import IdeasTable from '@/Components/IdeasTable.vue'
</script>

<template>
    <div>
        <Head title="Starred Ideas" />

        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900">Starred Ideas</h1>
            <p class="mt-1 text-sm text-gray-500">
                Your favorite SaaS ideas from all subreddits
            </p>
        </div>

        <!-- Ideas Table in starred mode -->
        <IdeasTable :show-subreddit="true" mode="starred" />
    </div>
</template>
