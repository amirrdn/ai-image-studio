<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { formatUsd } from '@/utils/currency';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    currentBalance: {
        type: [String, Number],
        default: '0.000000000',
    },
});

const emit = defineEmits(['close', 'toppedUp']);

const presetAmounts = [
    { value: '5.00', label: '$5.00', tag: 'Starter' },
    { value: '10.00', label: '$10.00', tag: 'Popular' },
    { value: '25.00', label: '$25.00', tag: 'Pro Pack' },
];

const selectedAmount = ref('10.00');
const processing = ref(false);
const errorMessage = ref('');

const submitTopup = () => {
    if (processing.value) return;

    processing.value = true;
    errorMessage.value = '';

    router.post(
        route('credits.topup'),
        { amount: selectedAmount.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('toppedUp', selectedAmount.value);
                emit('close');
            },
            onError: (errors) => {
                errorMessage.value =
                    errors.amount || 'Failed to add credits. Please try again.';
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="bg-slate-900 p-6 text-slate-100 ring-1 ring-slate-800">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-white">
                        Buy Studio Credits
                    </h3>
                    <p class="mt-1 text-sm text-slate-400">
                        Instant simulated top-up to test image generation in
                        Pixelforge Studio.
                    </p>
                </div>
                <button
                    type="button"
                    @click="emit('close')"
                    class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-800 hover:text-white"
                    aria-label="Close modal"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path
                            fill-rule="evenodd"
                            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </button>
            </div>

            <div
                class="mt-4 flex items-center justify-between rounded-xl border border-slate-800 bg-slate-950/70 px-4 py-3"
            >
                <span class="text-xs font-medium uppercase tracking-wider text-slate-400">
                    Current Balance
                </span>
                <span class="font-mono text-base font-semibold text-emerald-400">
                    {{ formatUsd(currentBalance) }}
                </span>
            </div>

            <div class="mt-5 grid grid-cols-3 gap-3">
                <button
                    v-for="preset in presetAmounts"
                    :key="preset.value"
                    type="button"
                    @click="selectedAmount = preset.value"
                    :class="[
                        'flex flex-col items-center justify-center rounded-xl border px-3 py-4 text-center transition',
                        selectedAmount === preset.value
                            ? 'border-indigo-500 bg-indigo-500/15 text-white shadow-sm shadow-indigo-500/20'
                            : 'border-slate-800 bg-slate-950/50 text-slate-300 hover:border-slate-700 hover:bg-slate-800/60',
                    ]"
                >
                    <span class="text-[11px] font-medium uppercase tracking-wide text-indigo-300">
                        {{ preset.tag }}
                    </span>
                    <span class="mt-1 font-mono text-lg font-bold">
                        {{ preset.label }}
                    </span>
                </button>
            </div>

            <p v-if="errorMessage" class="mt-3 text-sm text-rose-400">
                {{ errorMessage }}
            </p>

            <div class="mt-6 flex items-center justify-end gap-3">
                <button
                    type="button"
                    @click="emit('close')"
                    class="rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-300 transition hover:bg-slate-800 hover:text-white"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="processing"
                    @click="submitTopup"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <svg
                        v-if="processing"
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
                        {{ processing ? 'Processing...' : `Add +$${selectedAmount}` }}
                    </span>
                </button>
            </div>
        </div>
    </Modal>
</template>

