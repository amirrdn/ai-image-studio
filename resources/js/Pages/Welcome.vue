<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

const features = [
    {
        title: 'Transparent Token Pricing',
        description:
            'Real-time cost estimation across Low, Medium, and High quality tiers before you generate.',
        icon: 'calculator',
    },
    {
        title: 'Text-to-Image & Image-to-Image',
        description:
            'Support for multiple aspect ratios (1024x1024, 1536x1024, 1024x1536) and reference image URLs.',
        icon: 'layers',
    },
    {
        title: 'Instant Credit Wallet & Live Sync',
        description:
            'Start with $10.00 in free credits, instant top-ups, automatic status updates, and zero charge on failed generations.',
        icon: 'wallet',
    },
];
</script>

<template>
    <Head title="Pixelforge Studio — AI Image Generation" />

    <div class="relative min-h-screen overflow-hidden bg-slate-950 text-slate-100">
        <!-- Subtle Ambient Studio Glow -->
        <div
            class="pointer-events-none absolute -top-40 left-1/2 h-[480px] w-[780px] -translate-x-1/2 rounded-full bg-indigo-600/15 blur-3xl"
            aria-hidden="true"
        />

        <!-- Minimalist Navbar -->
        <header class="relative z-10 border-b border-slate-800/80 bg-slate-950/80 backdrop-blur-md">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <Link :href="route('home')" class="flex items-center gap-2.5 text-white">
                    <span
                        class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 shadow-md shadow-indigo-500/25"
                    >
                        <svg
                            class="h-5 w-5 text-white"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"
                            />
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z"
                            />
                        </svg>
                    </span>
                    <div class="leading-tight">
                        <span class="block text-sm font-bold tracking-tight text-white">
                            Pixelforge Studio
                        </span>
                        <span class="hidden text-[11px] font-medium text-slate-400 sm:block">
                            AI Image Workspace
                        </span>
                    </div>
                </Link>

                <nav class="flex items-center gap-3">
                    <Link
                        v-if="isAuthenticated"
                        :href="route('dashboard')"
                        class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm shadow-indigo-500/25 transition hover:bg-indigo-500 sm:text-sm"
                    >
                        <span>Go to Studio</span>
                        <span aria-hidden="true">&rarr;</span>
                    </Link>

                    <template v-else>
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="rounded-xl px-3.5 py-2 text-xs font-medium text-slate-300 transition hover:bg-slate-900 hover:text-white sm:text-sm"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm shadow-indigo-500/25 transition hover:bg-indigo-500 sm:text-sm"
                        >
                            Get Started
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <!-- Hero Section -->
        <main class="relative z-10">
            <section class="mx-auto max-w-7xl px-4 pb-16 pt-14 sm:px-6 sm:pt-20 lg:px-8">
                <div class="grid grid-cols-1 items-center gap-12 lg:grid-cols-12">
                    <!-- Hero Copy & CTAs -->
                    <div class="lg:col-span-6">
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-indigo-500/30 bg-indigo-500/10 px-3 py-1 text-xs font-medium text-indigo-300"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                            <span>9-Decimal Precision Billing &amp; Live Webhook Sync</span>
                        </div>

                        <h1 class="mt-5 text-4xl font-bold tracking-tight text-white sm:text-5xl">
                            AI Image Generation with
                            <span class="bg-gradient-to-r from-indigo-400 to-violet-400 bg-clip-text text-transparent">
                                Transparent Real-Time Pricing
                            </span>
                        </h1>

                        <p class="mt-5 max-w-xl text-base leading-relaxed text-slate-300">
                            Preview exact token estimates before every render, manage your credits in a real-time wallet, and track asynchronous image jobs automatically from queue to completion.
                        </p>

                        <div class="mt-8 flex flex-wrap items-center gap-3.5">
                            <Link
                                v-if="isAuthenticated"
                                :href="route('dashboard')"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:from-indigo-500 hover:to-violet-500"
                            >
                                <span>Go to Studio</span>
                                <span aria-hidden="true">&rarr;</span>
                            </Link>

                            <template v-else>
                                <Link
                                    v-if="canRegister"
                                    :href="route('register')"
                                    class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:from-indigo-500 hover:to-violet-500"
                                >
                                    Start Creating &mdash; $10 Free Credits
                                </Link>
                                <Link
                                    v-if="canLogin"
                                    :href="route('login')"
                                    class="inline-flex items-center justify-center rounded-xl border border-slate-700 bg-slate-900/90 px-6 py-3 text-sm font-semibold text-slate-200 transition hover:border-slate-600 hover:bg-slate-800 hover:text-white"
                                >
                                    Sign In
                                </Link>
                            </template>
                        </div>

                        <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-slate-400">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-indigo-400" />
                                $10.00 free starter credits
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                                $0.00 charged on failed jobs
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-violet-400" />
                                Low, Medium &amp; High tiers
                            </span>
                        </div>
                    </div>

                    <!-- Studio Visual Preview Card -->
                    <div class="lg:col-span-6">
                        <div
                            class="rounded-2xl border border-slate-800 bg-slate-900/90 p-5 shadow-2xl shadow-black/40"
                        >
                            <!-- Card Window Header -->
                            <div class="flex items-center justify-between border-b border-slate-800 pb-3.5">
                                <div class="flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 rounded-full bg-slate-700" />
                                    <span class="h-2.5 w-2.5 rounded-full bg-slate-700" />
                                    <span class="h-2.5 w-2.5 rounded-full bg-slate-700" />
                                    <span class="ml-2 font-mono text-xs text-slate-400">
                                        studio.pixelforge
                                    </span>
                                </div>
                                <div
                                    class="flex items-center gap-2 rounded-lg border border-slate-800 bg-slate-950 px-2.5 py-1 text-xs"
                                >
                                    <span class="text-slate-400">Credits</span>
                                    <span class="font-mono font-semibold text-emerald-400">$10.00</span>
                                </div>
                            </div>

                            <!-- Stylized Artwork Preview -->
                            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-12">
                                <div
                                    class="relative flex min-h-[210px] items-end overflow-hidden rounded-xl border border-slate-800 bg-gradient-to-br from-indigo-950 via-violet-900/70 to-slate-950 p-4 sm:col-span-6"
                                >
                                    <div
                                        class="absolute -right-8 -top-8 h-36 w-36 rounded-full bg-indigo-500/30 blur-2xl"
                                        aria-hidden="true"
                                    />
                                    <div
                                        class="absolute bottom-8 left-8 h-28 w-28 rounded-full bg-violet-500/25 blur-xl"
                                        aria-hidden="true"
                                    />
                                    <div class="relative z-10 w-full">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-300 ring-1 ring-emerald-500/30"
                                        >
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                                            Succeeded
                                        </span>
                                        <p class="mt-2 text-xs font-medium leading-relaxed text-slate-200">
                                            "A futuristic glass observatory overlooking nebula clouds at twilight"
                                        </p>
                                    </div>
                                </div>

                                <!-- Live Estimation & Settlement Summary -->
                                <div class="flex flex-col justify-between gap-3 sm:col-span-6">
                                    <div class="rounded-xl border border-slate-800 bg-slate-950/80 p-3.5">
                                        <div class="flex items-center justify-between text-[11px] text-slate-400">
                                            <span>Estimated Range</span>
                                            <span class="font-mono text-indigo-300">Medium · 1536x1024</span>
                                        </div>
                                        <p class="mt-1 font-mono text-base font-bold text-white">
                                            $0.0856 &ndash; $0.1138
                                        </p>
                                        <div class="mt-2.5 space-y-1 border-t border-slate-800 pt-2 text-[11px] text-slate-400">
                                            <div class="flex justify-between">
                                                <span>Output tokens</span>
                                                <span class="font-mono text-slate-200">1,650</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span>Reasoning range</span>
                                                <span class="font-mono text-slate-200">200&ndash;2,000</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-emerald-500/25 bg-emerald-500/10 p-3.5">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-medium text-emerald-200">
                                                Actual Billed
                                            </span>
                                            <span class="font-mono text-sm font-bold text-emerald-300">
                                                $0.0941500
                                            </span>
                                        </div>
                                        <p class="mt-1 text-[11px] text-emerald-200/75">
                                            Deducted only after completion via signed webhook settlement.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Feature Highlights (3 concise cards) -->
            <section class="mx-auto max-w-7xl px-4 pb-20 pt-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="rounded-2xl border border-slate-800 bg-slate-900/75 p-6 transition hover:border-slate-700"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500/15 text-indigo-400 ring-1 ring-indigo-500/30"
                        >
                            <!-- Calculator Icon -->
                            <svg
                                v-if="feature.icon === 'calculator'"
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>

                            <!-- Layers Icon -->
                            <svg
                                v-else-if="feature.icon === 'layers'"
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z"
                                />
                            </svg>

                            <!-- Wallet Icon -->
                            <svg
                                v-else
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3"
                                />
                            </svg>
                        </div>

                        <h2 class="mt-4 text-base font-semibold text-white">
                            {{ feature.title }}
                        </h2>
                        <p class="mt-2 text-sm leading-relaxed text-slate-400">
                            {{ feature.description }}
                        </p>
                    </div>
                </div>
            </section>
        </main>

        <!-- Clean Footer -->
        <footer class="relative z-10 border-t border-slate-800/80 bg-slate-950/90">
            <div
                class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-4 py-6 text-xs text-slate-500 sm:flex-row sm:px-6 lg:px-8"
            >
                <p>&copy; {{ new Date().getFullYear() }} Pixelforge Studio. All rights reserved.</p>
                <div class="flex items-center gap-4">
                    <Link
                        v-if="isAuthenticated"
                        :href="route('dashboard')"
                        class="transition hover:text-slate-300"
                    >
                        Studio
                    </Link>
                    <template v-else>
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="transition hover:text-slate-300"
                        >
                            Sign In
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="transition hover:text-slate-300"
                        >
                            Create Account
                        </Link>
                    </template>
                </div>
            </div>
        </footer>
    </div>
</template>
