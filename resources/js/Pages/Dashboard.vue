<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { compareDecimalStrings, formatUsd } from '@/utils/currency';

const props = defineProps({
    generations: {
        type: Array,
        default: () => [],
    },
    creditBalance: {
        type: [String, Number],
        default: '0.000000000',
    },
    pricing: {
        type: Object,
        default: () => ({}),
    },
    options: {
        type: Object,
        default: () => ({
            sizes: ['1024x1024', '1536x1024', '1024x1536'],
            qualities: ['low', 'medium', 'high'],
        }),
    },
});

const page = usePage();

const currentBalance = computed(
    () =>
        props.creditBalance ??
        page.props.auth?.user?.credit_balance ??
        '0.000000000',
);

const sizeOptions = [
    { value: '1024x1024', label: 'Square', ratio: '1:1', boxClass: 'h-5 w-5' },
    { value: '1536x1024', label: 'Landscape', ratio: '3:2', boxClass: 'h-4 w-6' },
    { value: '1024x1536', label: 'Portrait', ratio: '2:3', boxClass: 'h-6 w-4' },
];

const qualityOptions = [
    {
        value: 'low',
        label: 'Low',
        badge: 'Economical',
        reasoning: '0–500 reasoning tokens',
        output: '280 / 420 out',
    },
    {
        value: 'medium',
        label: 'Medium',
        badge: 'Default',
        reasoning: '200–2,000 reasoning tokens',
        output: '1,100 / 1,650 out',
    },
    {
        value: 'high',
        label: 'High',
        badge: 'High Detail',
        reasoning: '1,000–8,000 reasoning tokens',
        output: '4,300 / 6,450 out',
    },
];

const testChips = [
    { tag: '[slow]', hint: 'Slower processing (60–90s)' },
    { tag: '[fail]', hint: 'Accepted then fails during processing (free)' },
    { tag: '[duplicate]', hint: 'Every webhook event delivered twice' },
    { tag: '[blocked]', hint: 'Rejected with 400 content_policy_violation' },
    { tag: '[overloaded]', hint: 'Rejected with 503 overloaded' },
    { tag: '[succeed]', hint: 'Always succeeds (no random failures)' },
];

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_urls: [],
});

const estimate = ref(null);
const estimating = ref(false);
const estimateError = ref('');
const syncingIds = ref({});
const brokenImages = ref({});
const lightboxGeneration = ref(null);

let estimateTimer = null;
let pollInterval = null;
let isPollingTick = false;

const activeStatuses = ['pending', 'queued', 'processing'];

const activeGenerations = computed(() =>
    props.generations.filter((g) => activeStatuses.includes(g.status)),
);

const hasActiveGenerations = computed(() => activeGenerations.value.length > 0);

const hasInsufficientBalance = computed(() => {
    if (!estimate.value?.estimated_user_cost_max) return false;
    return (
        compareDecimalStrings(
            currentBalance.value,
            estimate.value.estimated_user_cost_max,
        ) < 0
    );
});

const cleanReferenceUrls = computed(() =>
    form.reference_urls.map((u) => u.trim()).filter((u) => u.length > 0),
);

const insertTestTag = (tag) => {
    const trimmed = form.prompt.trim();
    if (trimmed.includes(tag)) return;
    form.prompt = trimmed ? `${trimmed} ${tag}` : tag;
};

const addReferenceUrl = () => {
    if (form.reference_urls.length < 4) {
        form.reference_urls.push('');
    }
};

const removeReferenceUrl = (index) => {
    form.reference_urls.splice(index, 1);
};

const fetchEstimate = async () => {
    estimating.value = true;
    estimateError.value = '';

    const validUrls = cleanReferenceUrls.value.filter((url) => {
        try {
            const parsed = new URL(url);
            return parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch {
            return false;
        }
    });

    try {
        const { data } = await axios.post(route('generations.estimate'), {
            prompt: form.prompt,
            size: form.size,
            quality: form.quality,
            reference_urls: validUrls,
        });
        estimate.value = data;
    } catch (err) {
        estimateError.value =
            err?.response?.data?.message || 'Unable to calculate cost estimate.';
    } finally {
        estimating.value = false;
    }
};

const scheduleEstimate = () => {
    clearTimeout(estimateTimer);
    estimateTimer = setTimeout(() => {
        fetchEstimate();
    }, 250);
};

watch(
    () => [form.prompt, form.size, form.quality, [...form.reference_urls]],
    () => {
        scheduleEstimate();
    },
    { deep: true },
);

const submitGeneration = () => {
    const cleanedUrls = cleanReferenceUrls.value;

    form.transform((data) => ({
        ...data,
        reference_urls: cleanedUrls,
    })).post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.clearErrors();
        },
    });
};

const syncGeneration = async (generation, silent = false) => {
    if (syncingIds.value[generation.id]) return;

    if (!silent) {
        syncingIds.value = { ...syncingIds.value, [generation.id]: true };
    }

    try {
        await axios.post(route('generations.sync', generation.id));
        if (!silent) {
            router.reload({
                only: ['generations', 'creditBalance', 'auth'],
                preserveScroll: true,
            });
        }
    } catch {
        // Ignore transient polling errors; next tick or manual sync will retry
    } finally {
        if (!silent) {
            const copy = { ...syncingIds.value };
            delete copy[generation.id];
            syncingIds.value = copy;
        }
    }
};

const runAutoPollTick = async () => {
    if (isPollingTick || !hasActiveGenerations.value) return;
    isPollingTick = true;

    try {
        const syncable = activeGenerations.value.filter((g) =>
            Boolean(g.pixelforge_id),
        );
        await Promise.allSettled(
            syncable.map((g) => syncGeneration(g, true)),
        );

        await new Promise((resolve) => {
            router.reload({
                only: ['generations', 'creditBalance', 'auth'],
                preserveScroll: true,
                onFinish: () => resolve(),
            });
        });
    } finally {
        isPollingTick = false;
    }
};

const startPolling = () => {
    if (pollInterval) return;
    pollInterval = setInterval(runAutoPollTick, 4000);
};

const stopPolling = () => {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
};

watch(
    hasActiveGenerations,
    (active) => {
        if (active) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

onMounted(() => {
    fetchEstimate();
});

onUnmounted(() => {
    clearTimeout(estimateTimer);
    stopPolling();
});

const openBuyCredits = () => {
    window.dispatchEvent(new CustomEvent('open-buy-credits'));
};

const markImageBroken = (id) => {
    brokenImages.value = { ...brokenImages.value, [id]: true };
};

const isImageExpired = (generation) => {
    if (!generation.image_expires_at) return false;
    return new Date(generation.image_expires_at).getTime() <= Date.now();
};

const shouldShowImageFallback = (generation) =>
    !generation.image_url ||
    Boolean(brokenImages.value[generation.id]) ||
    isImageExpired(generation);

const statusBadgeMeta = (status) => {
    switch (status) {
        case 'pending':
            return {
                label: 'Pending',
                classes: 'bg-amber-500/15 text-amber-300 ring-amber-500/30',
                dot: 'bg-amber-400 animate-pulse',
            };
        case 'queued':
            return {
                label: 'Queued',
                classes: 'bg-sky-500/15 text-sky-300 ring-sky-500/30',
                dot: 'bg-sky-400 animate-pulse',
            };
        case 'processing':
            return {
                label: 'Processing',
                classes: 'bg-indigo-500/20 text-indigo-300 ring-indigo-500/40',
                dot: 'bg-indigo-400 animate-ping',
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                classes: 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
                dot: 'bg-emerald-400',
            };
        case 'failed':
            return {
                label: 'Failed',
                classes: 'bg-rose-500/15 text-rose-300 ring-rose-500/30',
                dot: 'bg-rose-400',
            };
        default:
            return {
                label: status,
                classes: 'bg-slate-700 text-slate-300 ring-slate-600',
                dot: 'bg-slate-400',
            };
    }
};

const formatDateTime = (isoString) => {
    if (!isoString) return '-';
    return new Date(isoString).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
};
</script>

<template>
    <Head title="Studio" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                <!-- Left Column: Image Generator Form -->
                <section class="lg:col-span-5">
                    <div
                        class="sticky top-24 rounded-2xl border border-slate-800 bg-slate-900/90 p-6 shadow-xl shadow-black/20"
                    >
                        <div class="flex items-center justify-between gap-3 border-b border-slate-800 pb-4">
                            <div>
                                <h1 class="text-lg font-semibold text-white">
                                    Create Image
                                </h1>
                                <p class="mt-0.5 text-xs text-slate-400">
                                    Real-time cost estimation with 9-decimal precision.
                                </p>
                            </div>
                            <span
                                class="rounded-full border border-indigo-500/30 bg-indigo-500/10 px-2.5 py-1 text-[11px] font-medium text-indigo-300"
                            >
                                Pixelforge AI
                            </span>
                        </div>

                        <form @submit.prevent="submitGeneration" class="mt-5 space-y-5">
                            <!-- Prompt Input + Quick Test Chips -->
                            <div>
                                <div class="flex items-center justify-between">
                                    <label
                                        for="prompt"
                                        class="block text-sm font-medium text-slate-200"
                                    >
                                        Prompt
                                    </label>
                                    <span class="font-mono text-xs text-slate-400">
                                        {{ form.prompt.length }} / 4000
                                    </span>
                                </div>

                                <textarea
                                    id="prompt"
                                    v-model="form.prompt"
                                    rows="4"
                                    maxlength="4000"
                                    required
                                    placeholder="Describe the image you want to generate, e.g.: A neon-lit cyberpunk Tokyo alley after rain, cinematic lighting..."
                                    class="mt-2 block w-full rounded-xl border border-slate-700 bg-slate-950/90 px-3.5 py-2.5 text-sm text-slate-100 placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500"
                                />

                                <!-- Test Chips -->
                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <span class="text-[11px] text-slate-400">
                                        Quick test tags:
                                    </span>
                                    <button
                                        v-for="chip in testChips"
                                        :key="chip.tag"
                                        type="button"
                                        :title="chip.hint"
                                        @click="insertTestTag(chip.tag)"
                                        class="rounded-md border border-slate-700 bg-slate-800/80 px-2 py-0.5 font-mono text-[11px] text-slate-300 transition hover:border-indigo-500/50 hover:bg-indigo-500/15 hover:text-indigo-200"
                                    >
                                        {{ chip.tag }}
                                    </button>
                                </div>

                                <p v-if="form.errors.prompt" class="mt-1.5 text-xs text-rose-400">
                                    {{ form.errors.prompt }}
                                </p>
                            </div>

                            <!-- Size Selector Cards -->
                            <div>
                                <label class="block text-sm font-medium text-slate-200">
                                    Image Size
                                </label>
                                <div class="mt-2 grid grid-cols-3 gap-2.5">
                                    <button
                                        v-for="item in sizeOptions"
                                        :key="item.value"
                                        type="button"
                                        @click="form.size = item.value"
                                        :class="[
                                            'flex flex-col items-center justify-center rounded-xl border px-2.5 py-3 text-center transition',
                                            form.size === item.value
                                                ? 'border-indigo-500 bg-indigo-500/15 text-white ring-1 ring-indigo-500/40'
                                                : 'border-slate-800 bg-slate-950/70 text-slate-300 hover:border-slate-700 hover:bg-slate-800/60',
                                        ]"
                                    >
                                        <span
                                            :class="[
                                                'mb-1.5 rounded-sm border-2',
                                                item.boxClass,
                                                form.size === item.value
                                                    ? 'border-indigo-400 bg-indigo-400/20'
                                                    : 'border-slate-500',
                                            ]"
                                        />
                                        <span class="text-xs font-semibold">{{ item.label }}</span>
                                        <span class="mt-0.5 font-mono text-[11px] text-slate-400">
                                            {{ item.value }}
                                        </span>
                                    </button>
                                </div>
                                <p v-if="form.errors.size" class="mt-1.5 text-xs text-rose-400">
                                    {{ form.errors.size }}
                                </p>
                            </div>

                            <!-- Quality Selector Cards (low / medium / high) -->
                            <div>
                                <label class="block text-sm font-medium text-slate-200">
                                    Quality
                                </label>
                                <div class="mt-2 grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                                    <button
                                        v-for="item in qualityOptions"
                                        :key="item.value"
                                        type="button"
                                        @click="form.quality = item.value"
                                        :class="[
                                            'rounded-xl border p-3 text-left transition',
                                            form.quality === item.value
                                                ? 'border-indigo-500 bg-indigo-500/15 text-white ring-1 ring-indigo-500/40'
                                                : 'border-slate-800 bg-slate-950/70 text-slate-300 hover:border-slate-700 hover:bg-slate-800/60',
                                        ]"
                                    >
                                        <div class="flex items-center justify-between gap-1">
                                            <span class="text-sm font-semibold">{{ item.label }}</span>
                                            <span
                                                v-if="form.quality === item.value"
                                                class="h-2 w-2 shrink-0 rounded-full bg-indigo-400"
                                            />
                                        </div>
                                        <span class="mt-0.5 inline-block text-[10px] font-medium uppercase tracking-wide text-indigo-300">
                                            {{ item.badge }}
                                        </span>
                                        <p class="mt-1 text-[11px] leading-snug text-slate-400">
                                            {{ item.reasoning }}
                                        </p>
                                        <p class="mt-0.5 font-mono text-[10px] text-slate-500">
                                            {{ item.output }}
                                        </p>
                                    </button>
                                </div>
                                <p v-if="form.errors.quality" class="mt-1.5 text-xs text-rose-400">
                                    {{ form.errors.quality }}
                                </p>
                            </div>

                            <!-- Dynamic Reference URLs (max 4) -->
                            <div>
                                <div class="flex items-center justify-between">
                                    <label class="block text-sm font-medium text-slate-200">
                                        Reference Image URLs
                                        <span class="text-xs font-normal text-slate-400">(optional, max 4)</span>
                                    </label>
                                    <button
                                        v-if="form.reference_urls.length < 4"
                                        type="button"
                                        @click="addReferenceUrl"
                                        class="text-xs font-medium text-indigo-400 transition hover:text-indigo-300"
                                    >
                                        + Add URL
                                    </button>
                                </div>

                                <div v-if="form.reference_urls.length > 0" class="mt-2 space-y-2">
                                    <div
                                        v-for="(url, index) in form.reference_urls"
                                        :key="index"
                                        class="flex items-center gap-2"
                                    >
                                        <input
                                            v-model="form.reference_urls[index]"
                                            type="url"
                                            placeholder="https://example.com/reference.png"
                                            class="block w-full rounded-xl border border-slate-700 bg-slate-950/90 px-3 py-2 text-xs text-slate-100 placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                        <button
                                            type="button"
                                            @click="removeReferenceUrl(index)"
                                            class="rounded-lg border border-slate-700 bg-slate-800 px-2.5 py-2 text-xs text-slate-300 transition hover:border-rose-500/40 hover:bg-rose-500/15 hover:text-rose-300"
                                            title="Remove URL"
                                        >
                                            &times;
                                        </button>
                                    </div>
                                </div>
                                <p v-else class="mt-1.5 text-xs text-slate-500">
                                    Add optional reference image URLs to guide the style (765 tokens/image).
                                </p>

                                <p
                                    v-if="form.errors.reference_urls || form.errors['reference_urls.0']"
                                    class="mt-1.5 text-xs text-rose-400"
                                >
                                    {{ form.errors.reference_urls || form.errors['reference_urls.0'] }}
                                </p>
                            </div>

                            <!-- Real-Time Cost Breakdown Card -->
                            <div
                                class="rounded-xl border border-slate-800 bg-slate-950/80 p-4"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                                        Estimated User Cost
                                    </span>
                                    <span
                                        v-if="estimating"
                                        class="text-[11px] text-indigo-400"
                                    >
                                        Calculating...
                                    </span>
                                </div>

                                <div v-if="estimate" class="mt-2">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="font-mono text-lg font-bold text-white">
                                            {{ formatUsd(estimate.estimated_user_cost_min) }}
                                            &ndash;
                                            {{ formatUsd(estimate.estimated_user_cost_max) }}
                                        </span>
                                        <span class="text-[11px] text-slate-400">
                                            Includes 25% markup
                                        </span>
                                    </div>

                                    <div
                                        class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 border-t border-slate-800/80 pt-3 text-[11px] text-slate-400"
                                    >
                                        <div class="flex justify-between">
                                            <span>Input text:</span>
                                            <span class="font-mono text-slate-200">
                                                <template
                                                    v-if="
                                                        estimate.tokens.input_text_tokens_min !== undefined &&
                                                        estimate.tokens.input_text_tokens_min !== estimate.tokens.input_text_tokens_max
                                                    "
                                                >
                                                    {{ estimate.tokens.input_text_tokens_min }}&ndash;{{ estimate.tokens.input_text_tokens_max }} tok
                                                </template>
                                                <template v-else>
                                                    {{ estimate.tokens.input_text_tokens }} tok
                                                </template>
                                            </span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Ref images:</span>
                                            <span class="font-mono text-slate-200">
                                                {{ estimate.tokens.input_image_tokens }} tok
                                            </span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Output image:</span>
                                            <span class="font-mono text-slate-200">
                                                {{ estimate.tokens.output_tokens }} tok
                                            </span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Reasoning:</span>
                                            <span class="font-mono text-slate-200">
                                                {{ estimate.tokens.reasoning_tokens_min }}&ndash;{{ estimate.tokens.reasoning_tokens_max }} tok
                                            </span>
                                        </div>
                                    </div>

                                    <p class="mt-2.5 text-[11px] text-slate-500">
                                        Price includes a 25% service markup and estimated reasoning token range. Credits are only deducted based on actual usage when generation succeeds.
                                    </p>
                                </div>

                                <p v-else-if="estimateError" class="mt-2 text-xs text-rose-400">
                                    {{ estimateError }}
                                </p>
                            </div>

                            <!-- Insufficient Balance Warning -->
                            <div
                                v-if="hasInsufficientBalance"
                                class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-3.5 text-xs text-amber-200"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-semibold text-amber-300">
                                            Insufficient credit balance
                                        </p>
                                        <p class="mt-1 text-amber-200/80">
                                            Your balance is {{ formatUsd(currentBalance) }}, while the maximum estimate is {{ formatUsd(estimate.estimated_user_cost_max) }}.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        @click="openBuyCredits"
                                        class="shrink-0 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-slate-950 transition hover:bg-amber-400"
                                    >
                                        Buy Credits
                                    </button>
                                </div>
                            </div>

                            <!-- Server/API Errors -->
                            <div
                                v-if="form.errors.credit_balance || form.errors.generation"
                                class="rounded-xl border border-rose-500/40 bg-rose-500/10 p-3.5 text-xs text-rose-300"
                            >
                                {{ form.errors.credit_balance || form.errors.generation }}
                            </div>

                            <button
                                type="submit"
                                :disabled="form.processing || hasInsufficientBalance"
                                class="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:from-indigo-500 hover:to-violet-500 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <svg
                                    v-if="form.processing"
                                    class="h-4 w-4 animate-spin"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                                    />
                                </svg>
                                <span>
                                    {{ form.processing ? 'Submitting to Pixelforge...' : 'Generate Image' }}
                                </span>
                            </button>
                        </form>
                    </div>
                </section>

                <!-- Right Column: Generation History Gallery -->
                <section class="lg:col-span-7">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-white">
                                Generation History
                            </h2>
                            <p class="text-xs text-slate-400">
                                {{ generations.length }} total {{ generations.length === 1 ? 'generation' : 'generations' }}
                            </p>
                        </div>

                        <div
                            v-if="hasActiveGenerations"
                            class="inline-flex items-center gap-2 rounded-full border border-indigo-500/30 bg-indigo-500/10 px-3 py-1 text-xs font-medium text-indigo-300"
                        >
                            <span class="h-2 w-2 animate-ping rounded-full bg-indigo-400" />
                            <span>
                                Live sync active ({{ activeGenerations.length }} in progress)
                            </span>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div
                        v-if="generations.length === 0"
                        class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-800 bg-slate-900/40 px-6 py-16 text-center"
                    >
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-800 text-slate-400"
                        >
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.5"
                                    d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"
                                />
                            </svg>
                        </div>
                        <h3 class="mt-4 text-sm font-semibold text-white">
                            No generations yet
                        </h3>
                        <p class="mt-1 max-w-sm text-xs text-slate-400">
                            Enter a prompt on the left panel, review the real-time cost estimate, and click Generate Image.
                        </p>
                    </div>

                    <!-- History Cards -->
                    <div v-else class="space-y-4">
                        <article
                            v-for="gen in generations"
                            :key="gen.id"
                            class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 shadow-md transition hover:border-slate-700"
                        >
                            <div class="grid grid-cols-1 sm:grid-cols-12">
                                <!-- Visual / Image Preview -->
                                <div
                                    class="relative flex min-h-[210px] items-center justify-center bg-slate-950 sm:col-span-5"
                                >
                                    <!-- Active Spinner / Skeleton -->
                                    <div
                                        v-if="activeStatuses.includes(gen.status)"
                                        class="flex flex-col items-center justify-center p-6 text-center"
                                    >
                                        <div
                                            class="h-10 w-10 animate-spin rounded-full border-2 border-slate-700 border-t-indigo-400"
                                        />
                                        <p class="mt-3 text-xs font-medium text-slate-300">
                                            Status: {{ statusBadgeMeta(gen.status).label }}
                                        </p>
                                        <p class="mt-1 text-[11px] text-slate-500">
                                            Waiting for Pixelforge result...
                                        </p>
                                    </div>

                                    <!-- Succeeded Image -->
                                    <template v-else-if="gen.status === 'succeeded'">
                                        <button
                                            v-if="!shouldShowImageFallback(gen)"
                                            type="button"
                                            @click="lightboxGeneration = gen"
                                            class="group relative h-full w-full overflow-hidden focus:outline-none"
                                        >
                                            <img
                                                :src="gen.image_url"
                                                :alt="gen.prompt"
                                                @error="markImageBroken(gen.id)"
                                                class="h-full max-h-72 w-full object-cover transition duration-300 group-hover:scale-105"
                                            />
                                            <span
                                                class="absolute bottom-2 right-2 rounded-lg bg-slate-950/80 px-2 py-1 text-[10px] font-medium text-slate-200 opacity-0 backdrop-blur transition group-hover:opacity-100"
                                            >
                                                Enlarge
                                            </span>
                                        </button>

                                        <!-- Expired or Unloadable Fallback -->
                                        <div
                                            v-else
                                            class="flex flex-col items-center justify-center p-6 text-center"
                                        >
                                            <svg
                                                class="h-8 w-8 text-slate-600"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="1.5"
                                                    d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"
                                                />
                                            </svg>
                                            <p class="mt-2 text-xs font-medium text-slate-300">
                                                Image unavailable or expired
                                            </p>
                                            <p class="mt-0.5 text-[11px] text-slate-500">
                                                The temporary Pixelforge URL is no longer active.
                                            </p>
                                        </div>
                                    </template>

                                    <!-- Failed Placeholder -->
                                    <div
                                        v-else
                                        class="flex flex-col items-center justify-center p-6 text-center"
                                    >
                                        <div
                                            class="flex h-10 w-10 items-center justify-center rounded-full bg-rose-500/15 text-rose-400"
                                        >
                                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                        </div>
                                        <p class="mt-2 text-xs font-medium text-rose-300">
                                            Generation Failed
                                        </p>
                                        <p class="mt-0.5 text-[11px] text-slate-400">
                                            Cost: $0.00 (Free)
                                        </p>
                                    </div>
                                </div>

                                <!-- Details Panel -->
                                <div class="flex flex-col justify-between p-5 sm:col-span-7">
                                    <div>
                                        <!-- Status + Timestamp + Manual Sync -->
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <span
                                                :class="[
                                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1',
                                                    statusBadgeMeta(gen.status).classes,
                                                ]"
                                            >
                                                <span
                                                    :class="[
                                                        'h-1.5 w-1.5 rounded-full',
                                                        statusBadgeMeta(gen.status).dot,
                                                    ]"
                                                />
                                                {{ statusBadgeMeta(gen.status).label }}
                                            </span>

                                            <div class="flex items-center gap-2">
                                                <button
                                                    v-if="activeStatuses.includes(gen.status)"
                                                    type="button"
                                                    :disabled="Boolean(syncingIds[gen.id])"
                                                    @click="syncGeneration(gen)"
                                                    class="rounded-lg border border-slate-700 bg-slate-800 px-2.5 py-1 text-[11px] font-medium text-slate-200 transition hover:border-indigo-500/50 hover:bg-slate-700 disabled:opacity-50"
                                                >
                                                    {{ syncingIds[gen.id] ? 'Syncing...' : 'Sync Status' }}
                                                </button>
                                                <span class="text-[11px] text-slate-500">
                                                    {{ formatDateTime(gen.created_at) }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Prompt -->
                                        <p class="mt-3 text-sm leading-relaxed text-slate-100">
                                            {{ gen.prompt }}
                                        </p>

                                        <!-- Parameter Badges -->
                                        <div class="mt-3 flex flex-wrap gap-1.5">
                                            <span
                                                class="rounded-md bg-slate-800 px-2 py-0.5 font-mono text-[11px] text-slate-300"
                                            >
                                                {{ gen.size }}
                                            </span>
                                            <span
                                                class="rounded-md bg-slate-800 px-2 py-0.5 text-[11px] font-medium uppercase text-slate-300"
                                            >
                                                {{ gen.quality }}
                                            </span>
                                            <span
                                                v-if="gen.reference_urls?.length"
                                                class="rounded-md bg-slate-800 px-2 py-0.5 text-[11px] text-slate-300"
                                            >
                                                {{ gen.reference_urls.length }} ref image(s)
                                            </span>
                                            <span
                                                v-if="gen.pixelforge_id"
                                                class="rounded-md bg-slate-800/60 px-2 py-0.5 font-mono text-[11px] text-slate-400"
                                            >
                                                {{ gen.pixelforge_id }}
                                            </span>
                                        </div>

                                        <!-- Failure Error Box -->
                                        <div
                                            v-if="gen.status === 'failed' && (gen.error_message || gen.error_code)"
                                            class="mt-3 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-200"
                                        >
                                            <p v-if="gen.error_code" class="font-mono text-[11px] font-semibold text-rose-300">
                                                {{ gen.error_code }}
                                            </p>
                                            <p class="mt-0.5 text-rose-200/90">
                                                {{ gen.error_message || 'Generation failed on the provider side.' }}
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Cost Comparison Footer -->
                                    <div
                                        class="mt-4 grid grid-cols-2 gap-3 rounded-xl border border-slate-800 bg-slate-950/70 p-3 text-xs"
                                    >
                                        <div>
                                            <span class="block text-[11px] text-slate-400">
                                                Estimated Cost
                                            </span>
                                            <span class="mt-0.5 block font-mono font-medium text-slate-300">
                                                {{ formatUsd(gen.estimated_user_cost_min) }}
                                                &ndash;
                                                {{ formatUsd(gen.estimated_user_cost_max) }}
                                            </span>
                                        </div>

                                        <div>
                                            <span class="block text-[11px] text-slate-400">
                                                Actual Cost
                                            </span>
                                            <span
                                                v-if="gen.status === 'succeeded'"
                                                class="mt-0.5 block font-mono font-semibold text-emerald-400"
                                                :title="`Provider cost: $${gen.actual_provider_cost}`"
                                            >
                                                {{ formatUsd(gen.actual_user_cost) }}
                                            </span>
                                            <span
                                                v-else-if="gen.status === 'failed'"
                                                class="mt-0.5 block font-mono font-semibold text-emerald-400"
                                            >
                                                $0.00 (Free)
                                            </span>
                                            <span
                                                v-else
                                                class="mt-0.5 block font-mono text-slate-500"
                                            >
                                                Pending completion...
                                            </span>
                                        </div>

                                        <div
                                            v-if="gen.status === 'succeeded' && gen.token_usage"
                                            class="col-span-2 border-t border-slate-800 pt-2 font-mono text-[10px] text-slate-400"
                                        >
                                            Tokens: text {{ gen.token_usage.input_text_tokens ?? 0 }} ·
                                            img {{ gen.token_usage.input_image_tokens ?? 0 }} ·
                                            out {{ gen.token_usage.output_tokens ?? 0 }} ·
                                            reason {{ gen.token_usage.reasoning_tokens ?? 0 }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </div>

        <!-- Image Lightbox Modal -->
        <div
            v-if="lightboxGeneration"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4 backdrop-blur-sm"
            @click.self="lightboxGeneration = null"
        >
            <div class="relative max-h-[90vh] max-w-4xl overflow-hidden rounded-2xl border border-slate-800 bg-slate-900 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-800 px-4 py-3">
                    <p class="truncate pr-4 text-xs font-medium text-slate-200">
                        {{ lightboxGeneration.prompt }}
                    </p>
                    <div class="flex items-center gap-2">
                        <a
                            :href="lightboxGeneration.image_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded-lg bg-slate-800 px-3 py-1 text-xs font-medium text-indigo-300 hover:bg-slate-700"
                        >
                            Open in New Tab
                        </a>
                        <button
                            type="button"
                            @click="lightboxGeneration = null"
                            class="rounded-lg px-2 py-1 text-sm text-slate-400 hover:bg-slate-800 hover:text-white"
                        >
                            &times;
                        </button>
                    </div>
                </div>
                <img
                    :src="lightboxGeneration.image_url"
                    :alt="lightboxGeneration.prompt"
                    class="max-h-[78vh] w-auto object-contain"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
