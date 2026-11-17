<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import BuyCreditsModal from '@/Components/BuyCreditsModal.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { formatUsd } from '@/utils/currency';

const page = usePage();
const showingNavigationDropdown = ref(false);
const showBuyCreditsModal = ref(false);
const topupBanner = ref('');

const user = computed(() => page.props.auth?.user || null);
const creditBalance = computed(
    () => page.props.creditBalance ?? user.value?.credit_balance ?? '0.000000000',
);
const isAdmin = computed(() => Boolean(user.value?.is_admin));

const handleOpenBuyCredits = () => {
    showBuyCreditsModal.value = true;
};

const handleToppedUp = (amount) => {
    topupBanner.value = `+$${amount} has been added to your credit balance.`;
    setTimeout(() => {
        topupBanner.value = '';
    }, 4000);
};

onMounted(() => {
    window.addEventListener('open-buy-credits', handleOpenBuyCredits);
});

onUnmounted(() => {
    window.removeEventListener('open-buy-credits', handleOpenBuyCredits);
});
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100">
        <nav class="sticky top-0 z-30 border-b border-slate-800/80 bg-slate-950/90 backdrop-blur-md">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between gap-4">
                    <!-- Left: Brand & Nav Links -->
                    <div class="flex items-center gap-6">
                        <Link
                            :href="route('dashboard')"
                            class="flex items-center gap-2.5 text-white transition hover:opacity-90"
                        >
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

                        <div class="hidden items-center gap-1 sm:flex">
                            <Link
                                :href="route('dashboard')"
                                :class="[
                                    'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                                    route().current('dashboard')
                                        ? 'bg-slate-800 text-white'
                                        : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200',
                                ]"
                            >
                                Studio
                            </Link>
                            <Link
                                v-if="isAdmin"
                                :href="route('admin.report')"
                                :class="[
                                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                                    route().current('admin.report')
                                        ? 'bg-indigo-500/20 text-indigo-300 ring-1 ring-indigo-500/40'
                                        : 'text-slate-400 hover:bg-slate-900 hover:text-slate-200',
                                ]"
                            >
                                <span>Admin Report</span>
                                <span
                                    class="rounded bg-indigo-500/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-indigo-300"
                                >
                                    Admin
                                </span>
                            </Link>
                        </div>
                    </div>

                    <!-- Right: Balance, Buy Credits & User Menu -->
                    <div class="hidden items-center gap-3 sm:flex">
                        <div
                            class="flex items-center gap-2.5 rounded-xl border border-slate-800 bg-slate-900/90 px-3 py-1.5"
                            :title="`Exact balance: $${creditBalance}`"
                        >
                            <span class="text-xs font-medium text-slate-400">
                                Credits
                            </span>
                            <span class="font-mono text-sm font-semibold text-emerald-400">
                                {{ formatUsd(creditBalance) }}
                            </span>
                        </div>

                        <button
                            type="button"
                            @click="showBuyCreditsModal = true"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:bg-indigo-500"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                <path
                                    d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z"
                                />
                            </svg>
                            <span>Buy Credits</span>
                        </button>

                        <div class="relative">
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-900 px-3 py-1.5 text-sm font-medium text-slate-200 transition hover:border-slate-700 hover:bg-slate-800 focus:outline-none"
                                    >
                                        <span>{{ user?.name }}</span>
                                        <svg
                                            class="h-4 w-4 text-slate-400"
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                    </button>
                                </template>

                                <template #content>
                                    <DropdownLink :href="route('profile.edit')">
                                        Profile
                                    </DropdownLink>
                                    <DropdownLink
                                        v-if="isAdmin"
                                        :href="route('admin.report')"
                                    >
                                        Admin Report
                                    </DropdownLink>
                                    <DropdownLink
                                        :href="route('logout')"
                                        method="post"
                                        as="button"
                                    >
                                        Log Out
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>

                    <!-- Mobile Balance + Hamburger -->
                    <div class="flex items-center gap-2 sm:hidden">
                        <button
                            type="button"
                            @click="showBuyCreditsModal = true"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-800 bg-slate-900 px-2.5 py-1 text-xs font-semibold text-emerald-400"
                        >
                            <span class="font-mono">{{ formatUsd(creditBalance) }}</span>
                            <span class="rounded bg-indigo-600 px-1.5 py-0.5 text-[10px] text-white">
                                +
                            </span>
                        </button>

                        <button
                            type="button"
                            @click="showingNavigationDropdown = !showingNavigationDropdown"
                            class="inline-flex items-center justify-center rounded-lg p-2 text-slate-400 transition hover:bg-slate-800 hover:text-white focus:outline-none"
                        >
                            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path
                                    :class="{
                                        hidden: showingNavigationDropdown,
                                        'inline-flex': !showingNavigationDropdown,
                                    }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16"
                                />
                                <path
                                    :class="{
                                        hidden: !showingNavigationDropdown,
                                        'inline-flex': showingNavigationDropdown,
                                    }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile Drawer -->
            <div
                :class="{
                    block: showingNavigationDropdown,
                    hidden: !showingNavigationDropdown,
                }"
                class="border-t border-slate-800 bg-slate-900 sm:hidden"
            >
                <div class="space-y-1 px-4 pb-3 pt-2">
                    <ResponsiveNavLink
                        :href="route('dashboard')"
                        :active="route().current('dashboard')"
                    >
                        Studio
                    </ResponsiveNavLink>
                    <ResponsiveNavLink
                        v-if="isAdmin"
                        :href="route('admin.report')"
                        :active="route().current('admin.report')"
                    >
                        Admin Report
                    </ResponsiveNavLink>
                </div>

                <div class="border-t border-slate-800 px-4 py-3">
                    <div class="text-sm font-medium text-white">{{ user?.name }}</div>
                    <div class="text-xs text-slate-400">{{ user?.email }}</div>
                    <div class="mt-3 flex flex-col gap-2">
                        <button
                            type="button"
                            @click="
                                showingNavigationDropdown = false;
                                showBuyCreditsModal = true;
                            "
                            class="w-full rounded-lg bg-indigo-600 px-3 py-2 text-left text-sm font-semibold text-white"
                        >
                            Buy Credits ({{ formatUsd(creditBalance) }})
                        </button>
                        <ResponsiveNavLink :href="route('profile.edit')">
                            Profile
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('logout')"
                            method="post"
                            as="button"
                        >
                            Log Out
                        </ResponsiveNavLink>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Top-up toast -->
        <div
            v-if="topupBanner"
            class="border-b border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-center text-xs font-medium text-emerald-300"
        >
            {{ topupBanner }}
        </div>

        <!-- Optional Page Header -->
        <header v-if="$slots.header" class="border-b border-slate-800/80 bg-slate-900/50">
            <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <main>
            <slot />
        </main>

        <BuyCreditsModal
            :show="showBuyCreditsModal"
            :current-balance="creditBalance"
            @close="showBuyCreditsModal = false"
            @topped-up="handleToppedUp"
        />
    </div>
</template>
