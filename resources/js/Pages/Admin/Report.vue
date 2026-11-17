<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    absDiffDecimalStrings,
    compareDecimalStrings,
    formatUsd,
} from '@/utils/currency';

const props = defineProps({
    summary: {
        type: Object,
        required: true,
    },
    daily: {
        type: Array,
        default: () => [],
    },
    by_user: {
        type: Array,
        default: () => [],
    },
    pixelforge_usage: {
        type: Object,
        default: null,
    },
    pixelforge_usage_error: {
        type: String,
        default: null,
    },
});

const dateFrom = ref('');
const dateTo = ref('');
const filtering = ref(false);

const remoteProviderCost = computed(() => {
    const usage = props.pixelforge_usage;
    if (!usage || typeof usage !== 'object') return null;

    const candidates = [
        usage.total?.cost_usd,
        usage.total_cost,
        usage.total_cost_usd,
        usage.cost_usd,
        usage.cost,
        usage.provider_cost,
        usage.summary?.total_cost,
        usage.data?.total_cost,
        usage.totals?.cost,
    ];

    for (const candidate of candidates) {
        if (candidate !== undefined && candidate !== null && candidate !== '') {
            return String(candidate);
        }
    }

    return null;
});

const reconciliationStatus = computed(() => {
    if (props.pixelforge_usage_error || remoteProviderCost.value === null) {
        return {
            state: 'unavailable',
            label: 'Usage API Data Unavailable',
            badgeClass: 'bg-amber-500/15 text-amber-300 ring-amber-500/30',
            diff: null,
        };
    }

    const localCost = props.summary?.total_provider_cost ?? '0.000000000';
    const diff = absDiffDecimalStrings(localCost, remoteProviderCost.value);
    const matched = compareDecimalStrings(diff, '0.000000000') === 0;

    return {
        state: matched ? 'matched' : 'mismatch',
        label: matched ? 'Matched (100% Reconciled)' : 'Discrepancy Detected',
        badgeClass: matched
            ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30'
            : 'bg-rose-500/15 text-rose-300 ring-rose-500/30',
        diff,
    };
});

const applyFilter = () => {
    filtering.value = true;
    const params = {};
    if (dateFrom.value) params.start_date = dateFrom.value;
    if (dateTo.value) params.end_date = dateTo.value;

    router.get(route('admin.report'), params, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            filtering.value = false;
        },
    });
};

const resetFilter = () => {
    dateFrom.value = '';
    dateTo.value = '';
    applyFilter();
};
</script>

<template>
    <Head title="Admin Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-white">
                        Operator Financial &amp; Usage Report
                    </h1>
                    <p class="mt-0.5 text-xs text-slate-400">
                        BCMath 9-decimal aggregation &amp; real-time reconciliation against Pixelforge
                        <span class="font-mono text-slate-300">/v1/usage</span>.
                    </p>
                </div>

                <Link
                    :href="route('dashboard')"
                    class="rounded-xl border border-slate-700 bg-slate-800 px-3.5 py-2 text-xs font-semibold text-slate-200 transition hover:bg-slate-700"
                >
                    &larr; Back to Studio
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <!-- KPI Cards -->
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-slate-800 bg-slate-900/90 p-5">
                    <span class="text-xs font-medium uppercase tracking-wider text-slate-400">
                        Total User Revenue (Credit Spend)
                    </span>
                    <p class="mt-2 font-mono text-2xl font-bold text-white">
                        {{ formatUsd(summary.total_user_cost) }}
                    </p>
                    <p class="mt-1 font-mono text-[11px] text-slate-500">
                        Exact: ${{ summary.total_user_cost }}
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-800 bg-slate-900/90 p-5">
                    <span class="text-xs font-medium uppercase tracking-wider text-slate-400">
                        Total Provider Cost (Pixelforge)
                    </span>
                    <p class="mt-2 font-mono text-2xl font-bold text-sky-300">
                        {{ formatUsd(summary.total_provider_cost) }}
                    </p>
                    <p class="mt-1 font-mono text-[11px] text-slate-500">
                        Exact: ${{ summary.total_provider_cost }}
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-800 bg-slate-900/90 p-5">
                    <span class="text-xs font-medium uppercase tracking-wider text-slate-400">
                        Net Profit
                    </span>
                    <p class="mt-2 font-mono text-2xl font-bold text-emerald-400">
                        {{ formatUsd(summary.total_profit) }}
                    </p>
                    <p class="mt-1 font-mono text-[11px] text-slate-500">
                        Exact: ${{ summary.total_profit }} (25% Markup)
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-800 bg-slate-900/90 p-5">
                    <span class="text-xs font-medium uppercase tracking-wider text-slate-400">
                        Total Generations
                    </span>
                    <p class="mt-2 font-mono text-2xl font-bold text-white">
                        {{ summary.total_generations }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        <span class="text-emerald-400">{{ summary.succeeded_generations }} succeeded</span>
                        &middot;
                        <span class="text-rose-400">{{ summary.failed_generations }} failed</span>
                    </p>
                </div>
            </section>

            <!-- Reconciliation Panel -->
            <section class="rounded-2xl border border-slate-800 bg-slate-900/90 p-6">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-800 pb-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h2 class="text-base font-semibold text-white">
                                Provider Cost Reconciliation (Local DB vs Pixelforge API)
                            </h2>
                            <span
                                :class="[
                                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1',
                                    reconciliationStatus.badgeClass,
                                ]"
                            >
                                {{ reconciliationStatus.label }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">
                            Compares total provider cost from the local
                            <span class="font-mono text-slate-300">generations</span>
                            table against the official
                            <span class="font-mono text-slate-300">GET /v1/usage</span>
                            endpoint response.
                        </p>
                    </div>

                    <!-- Date Range Filter for /v1/usage -->
                    <form @submit.prevent="applyFilter" class="flex flex-wrap items-center gap-2">
                        <input
                            v-model="dateFrom"
                            type="date"
                            class="rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-xs text-slate-200 focus:border-indigo-500 focus:ring-indigo-500"
                            aria-label="Start date"
                        />
                        <span class="text-xs text-slate-500">to</span>
                        <input
                            v-model="dateTo"
                            type="date"
                            class="rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-xs text-slate-200 focus:border-indigo-500 focus:ring-indigo-500"
                            aria-label="End date"
                        />
                        <button
                            type="submit"
                            :disabled="filtering"
                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {{ filtering ? 'Loading...' : 'Filter Usage' }}
                        </button>
                        <button
                            v-if="dateFrom || dateTo"
                            type="button"
                            @click="resetFilter"
                            class="rounded-lg border border-slate-700 px-2.5 py-1.5 text-xs text-slate-300 hover:bg-slate-800"
                        >
                            Reset
                        </button>
                    </form>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                        <span class="text-xs text-slate-400">Local Recorded Cost</span>
                        <p class="mt-1 font-mono text-lg font-bold text-white">
                            {{ formatUsd(summary.total_provider_cost) }}
                        </p>
                        <p class="mt-0.5 font-mono text-[11px] text-slate-500">
                            ${{ summary.total_provider_cost }}
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                        <span class="text-xs text-slate-400">Official Pixelforge Usage (/v1/usage)</span>
                        <p
                            v-if="remoteProviderCost !== null"
                            class="mt-1 font-mono text-lg font-bold text-sky-300"
                        >
                            {{ formatUsd(remoteProviderCost) }}
                        </p>
                        <p v-else class="mt-1 text-sm font-medium text-amber-300">
                            Unavailable
                        </p>
                        <p v-if="remoteProviderCost !== null" class="mt-0.5 font-mono text-[11px] text-slate-500">
                            ${{ remoteProviderCost }}
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
                        <span class="text-xs text-slate-400">Discrepancy (Delta)</span>
                        <p
                            v-if="reconciliationStatus.diff !== null"
                            :class="[
                                'mt-1 font-mono text-lg font-bold',
                                reconciliationStatus.state === 'matched'
                                    ? 'text-emerald-400'
                                    : 'text-rose-400',
                            ]"
                        >
                            {{ formatUsd(reconciliationStatus.diff) }}
                        </p>
                        <p v-else class="mt-1 text-sm text-slate-500">
                            &mdash;
                        </p>
                        <p
                            v-if="reconciliationStatus.diff !== null"
                            class="mt-0.5 font-mono text-[11px] text-slate-500"
                        >
                            Exact diff: ${{ reconciliationStatus.diff }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="pixelforge_usage_error"
                    class="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200"
                >
                    <span class="font-semibold">Pixelforge Usage API Notice:</span>
                    {{ pixelforge_usage_error }}
                </div>

                <details v-if="pixelforge_usage" class="mt-4">
                    <summary class="cursor-pointer text-xs font-medium text-indigo-400 hover:text-indigo-300">
                        View full GET /v1/usage payload
                    </summary>
                    <pre class="mt-2 overflow-x-auto rounded-xl border border-slate-800 bg-slate-950 p-3 font-mono text-[11px] text-slate-300">{{ JSON.stringify(pixelforge_usage, null, 2) }}</pre>
                </details>
            </section>

            <!-- Daily Breakdown Table -->
            <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/90">
                <div class="border-b border-slate-800 px-6 py-4">
                    <h2 class="text-base font-semibold text-white">
                        Daily Report
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-400">
                        Summary of generations, user charges, provider costs, and profit per day.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
                        <thead class="bg-slate-950/60 text-xs uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="px-6 py-3 font-medium">Date</th>
                                <th class="px-6 py-3 font-medium">Generations</th>
                                <th class="px-6 py-3 font-medium">Succeeded / Failed</th>
                                <th class="px-6 py-3 font-medium text-right">User Spend</th>
                                <th class="px-6 py-3 font-medium text-right">Provider Cost</th>
                                <th class="px-6 py-3 font-medium text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/80 text-slate-200">
                            <tr v-if="daily.length === 0">
                                <td colspan="6" class="px-6 py-8 text-center text-xs text-slate-500">
                                    No generation transactions recorded yet.
                                </td>
                            </tr>
                            <tr v-for="row in daily" :key="row.date" class="hover:bg-slate-800/40">
                                <td class="whitespace-nowrap px-6 py-3.5 font-mono text-xs font-medium text-white">
                                    {{ row.date }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 font-mono text-xs">
                                    {{ row.generations_count }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-xs">
                                    <span class="text-emerald-400">{{ row.succeeded_count }}</span>
                                    /
                                    <span class="text-rose-400">{{ row.failed_count }}</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-mono text-xs">
                                    {{ formatUsd(row.total_user_cost) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-mono text-xs text-sky-300">
                                    {{ formatUsd(row.total_provider_cost) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-mono text-xs font-semibold text-emerald-400">
                                    {{ formatUsd(row.profit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Per-User Breakdown Table -->
            <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/90">
                <div class="border-b border-slate-800 px-6 py-4">
                    <h2 class="text-base font-semibold text-white">
                        Per-User Report
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-400">
                        Total credit spend, provider cost, and profit contribution for each user.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
                        <thead class="bg-slate-950/60 text-xs uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="px-6 py-3 font-medium">User</th>
                                <th class="px-6 py-3 font-medium">Generations</th>
                                <th class="px-6 py-3 font-medium">Succeeded / Failed</th>
                                <th class="px-6 py-3 font-medium text-right">User Spend</th>
                                <th class="px-6 py-3 font-medium text-right">Provider Cost</th>
                                <th class="px-6 py-3 font-medium text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/80 text-slate-200">
                            <tr v-if="by_user.length === 0">
                                <td colspan="6" class="px-6 py-8 text-center text-xs text-slate-500">
                                    No users with generation history yet.
                                </td>
                            </tr>
                            <tr v-for="row in by_user" :key="row.user_id" class="hover:bg-slate-800/40">
                                <td class="px-6 py-3.5">
                                    <div class="text-xs font-semibold text-white">{{ row.name }}</div>
                                    <div class="text-[11px] text-slate-400">{{ row.email }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 font-mono text-xs">
                                    {{ row.generations_count }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-xs">
                                    <span class="text-emerald-400">{{ row.succeeded_count }}</span>
                                    /
                                    <span class="text-rose-400">{{ row.failed_count }}</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-mono text-xs">
                                    {{ formatUsd(row.total_user_cost) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-mono text-xs text-sky-300">
                                    {{ formatUsd(row.total_provider_cost) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right font-mono text-xs font-semibold text-emerald-400">
                                    {{ formatUsd(row.profit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
