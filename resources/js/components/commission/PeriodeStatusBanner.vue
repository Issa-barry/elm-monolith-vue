<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Link } from '@inertiajs/vue3';

interface PeriodeAffichee {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
}

defineProps<{
    periode: PeriodeAffichee | null;
}>();
</script>

<template>
    <div
        v-if="periode"
        class="flex items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3"
    >
        <div class="flex items-center gap-3">
            <span class="text-sm text-muted-foreground">Période</span>
            <Link
                :href="`/backoffice/comptabilite/periodes/${periode.id}`"
                class="font-mono text-sm font-medium hover:underline"
            >
                {{ periode.reference }}
            </Link>
        </div>
        <StatusDot :status="periode.statut" :label="periode.statut_label" />
    </div>
</template>
