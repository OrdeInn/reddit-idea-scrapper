<script setup>
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import BaseButton from '@/Components/BaseButton.vue'
import BaseModal from '@/Components/BaseModal.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatCard from '@/Components/StatCard.vue'
import ScoreGauge from '@/Components/ScoreGauge.vue'

const props = defineProps({
    subreddits: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
})

const showModal = ref(false)
const form = useForm({ name: '' })

const openModal = () => {
    form.clearErrors()
    showModal.value = true
}

const closeModal = () => {
    if (form.processing) return
    showModal.value = false
    form.reset()
    form.clearErrors()
}

const submitForm = () => {
    form.post('/subreddits', {
        onSuccess: () => {
            showModal.value = false
            form.reset()
            form.clearErrors()
        },
    })
}

const formattedAvgScore = (score) => {
    if (score == null) return null
    return Number(score).toFixed(1)
}
</script>

<template>
    <div>
        <Head title="Dashboard" />

        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
            <div>
                <h1 class="text-2xl font-bold font-display text-content-primary">Dashboard</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Track subreddits to discover SaaS opportunities
                </p>
            </div>
            <BaseButton
                variant="primary"
                @click="openModal"
            >
                <template #icon-left>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                </template>
                Add Subreddit
            </BaseButton>
        </div>

        <!-- Aggregate stats bar (only shown when subreddits exist) -->
        <div
            v-if="subreddits.length > 0"
            class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8"
        >
            <StatCard
                :value="stats.total_subreddits ?? subreddits.length"
                label="Subreddits"
            />
            <StatCard
                :value="stats.total_ideas ?? 0"
                label="Ideas found"
            />
            <StatCard
                :value="formattedAvgScore(stats.avg_score)"
                label="Avg score"
                :highlight="(stats.avg_score ?? 0) >= 4"
            />
            <StatCard
                :value="stats.starred_count ?? 0"
                label="Ideas starred"
            />
        </div>

        <!-- Empty state -->
        <EmptyState
            v-if="subreddits.length === 0"
            title="No signals detected yet"
            description="Add a subreddit to start discovering SaaS opportunities from Reddit"
        >
            <template #icon>
                <svg class="w-12 h-12 text-content-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.14 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                </svg>
            </template>
            <template #action>
                <BaseButton variant="primary" size="lg" @click="openModal">
                    Add your first subreddit
                </BaseButton>
            </template>
        </EmptyState>

        <!-- Subreddit cards grid -->
        <div
            v-else
            class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5"
        >
            <Link
                v-for="(subreddit, index) in subreddits"
                :key="subreddit.id"
                :href="`/subreddits/${subreddit.id}`"
                :style="{ '--stagger-delay': `${Math.min(index * 60, 600)}ms` }"
                class="group block rounded-lg border border-border-default bg-surface-secondary p-5 transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md hover:border-brand-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 focus-visible:ring-offset-surface-primary stagger-card"
            >
                <!-- Card header: name + scan indicator -->
                <div class="flex items-start justify-between gap-3 mb-4">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold font-display text-content-primary truncate">
                            {{ subreddit.full_name }}
                        </h3>
                        <p class="mt-0.5 text-xs text-content-tertiary">
                            {{ subreddit.last_scanned_human ? `Last scanned ${subreddit.last_scanned_human}` : 'Never scanned' }}
                        </p>
                    </div>
                    <!-- Active scan pulse indicator -->
                    <div
                        v-if="subreddit.has_active_scan"
                        class="flex-shrink-0 flex items-center gap-1.5 text-status-scanning text-xs font-medium"
                        role="status"
                        aria-live="polite"
                    >
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-status-scanning opacity-75" aria-hidden="true" />
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-status-scanning" aria-hidden="true" />
                        </span>
                        <span class="sr-only">Scanning in progress</span>
                        <span aria-hidden="true">Scanning</span>
                    </div>
                </div>

                <!-- Stats 2×2 grid -->
                <div class="grid grid-cols-2 gap-4">
                    <!-- Ideas count -->
                    <div>
                        <p class="font-mono text-xl font-black text-content-primary tabular-nums">
                            {{ subreddit.idea_count ?? 0 }}
                        </p>
                        <p class="text-xs text-content-tertiary mt-0.5">Ideas</p>
                    </div>
                    <!-- Top score gauge -->
                    <div class="flex items-center gap-2">
                        <ScoreGauge
                            v-if="subreddit.top_score != null"
                            :score="subreddit.top_score"
                            :size="36"
                            :animate="false"
                        />
                        <span
                            v-else
                            class="font-mono text-xl font-black text-content-tertiary"
                        >—</span>
                        <div>
                            <p class="text-xs text-content-tertiary">Top Score</p>
                        </div>
                    </div>
                    <!-- Last scanned -->
                    <div>
                        <p class="font-mono text-sm font-bold text-content-primary">
                            {{ subreddit.scans_count ?? 0 }}
                        </p>
                        <p class="text-xs text-content-tertiary mt-0.5">Scans</p>
                    </div>
                    <!-- Status -->
                    <div>
                        <p class="text-sm font-medium" :class="subreddit.has_active_scan ? 'text-status-scanning' : 'text-content-tertiary'">
                            {{ subreddit.has_active_scan ? 'Scanning…' : 'Idle' }}
                        </p>
                        <p class="text-xs text-content-tertiary mt-0.5">Status</p>
                    </div>
                </div>
            </Link>
        </div>

        <!-- Add Subreddit Modal -->
        <BaseModal
            :open="showModal"
            title="Add Subreddit"
            max-width="md"
            :closeable="!form.processing"
            @close="closeModal"
        >
            <form @submit.prevent="submitForm">
                <div>
                    <label for="subreddit-name" class="block text-sm font-medium text-content-primary mb-1.5">
                        Subreddit name
                    </label>
                    <div class="flex">
                        <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-border-default bg-surface-secondary text-content-tertiary text-sm font-mono">
                            r/
                        </span>
                        <input
                            id="subreddit-name"
                            v-model="form.name"
                            type="text"
                            placeholder="startups"
                            class="flex-1 rounded-r-lg border border-border-default bg-surface-elevated text-content-primary text-sm px-3 py-2 focus:outline-none focus:border-brand-500 focus:ring-0"
                            :class="{ 'border-status-error': form.errors.name }"
                            :aria-invalid="!!form.errors.name"
                            :aria-describedby="form.errors.name ? 'subreddit-name-error' : undefined"
                            autofocus
                        />
                    </div>
                    <p
                        v-if="form.errors.name"
                        id="subreddit-name-error"
                        class="mt-1.5 text-sm text-status-error"
                        role="alert"
                    >
                        {{ form.errors.name }}
                    </p>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <BaseButton
                        variant="secondary"
                        :disabled="form.processing"
                        @click="closeModal"
                    >
                        Cancel
                    </BaseButton>
                    <BaseButton
                        variant="primary"
                        :loading="form.processing"
                        @click="submitForm"
                    >
                        Add Subreddit
                    </BaseButton>
                </div>
            </template>
        </BaseModal>
    </div>
</template>
