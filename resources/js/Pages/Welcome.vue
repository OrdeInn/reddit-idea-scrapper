<script setup>
import { Head } from "@inertiajs/vue3";

defineProps({
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Head title="Welcome" />
    <div
        class="min-h-screen bg-gray-100 flex items-center justify-center selection:bg-red-500 selection:text-white"
    >
        <div class="max-w-7xl mx-auto p-6 lg:p-8">
            <div class="flex justify-center">
                <h1 class="text-4xl font-bold text-gray-900">
                    Welcome to Your Vue + Laravel App
                </h1>
            </div>

            <div class="mt-8 text-center text-gray-600">
                <p>Laravel v{{ laravelVersion }} (PHP v{{ phpVersion }})</p>
            </div>
        </div>
    </div>
</template>
