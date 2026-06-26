<script setup lang="ts">
import PaymentDialogCompact from '@/components/PaymentDialogCompact.vue';
import type { ModePaiementOption } from '@/types/commission';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    visible: boolean;
    beneficiaireNom: string;
    soldeAPayer: number;
    montantMax?: number;
    modesPaiement?: ModePaiementOption[];
    paymentRoute: string;
}>();

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
    (e: 'success'): void;
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val: boolean) => emit('update:visible', val),
});

const processing = ref(false);
const errors = ref<Record<string, string>>({});

function handleSubmit(payload: { montant: number; mode_paiement: string }) {
    processing.value = true;
    errors.value = {};
    router.post(props.paymentRoute, payload, {
        preserveScroll: true,
        onSuccess: () => {
            localVisible.value = false;
            emit('success');
        },
        onError: (e) => {
            errors.value = e as Record<string, string>;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <PaymentDialogCompact
        v-model:visible="localVisible"
        :title="`Payer — ${props.beneficiaireNom}`"
        :solde="props.soldeAPayer"
        :max-montant="props.montantMax"
        :processing="processing"
        :errors="errors"
        :modes-paiement="props.modesPaiement"
        @submit="handleSubmit"
    />
</template>
