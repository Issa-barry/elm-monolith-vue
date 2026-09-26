<script setup lang="ts">
import KpiCard from '@/components/KpiCard.vue';
import { ArrowRight, ChevronDown } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    libelle: string;
    valeur: string;
    detail: string;
    active: boolean;
    focusable?: boolean;
    testid: string;
    avertissement?: string | null;
    large?: boolean;
}>();

defineEmits<{ choisir: [] }>();
const montant = computed(() => props.valeur.replace(/\sGNF$/, ''));
const unite = computed(() =>
    props.valeur.endsWith(' GNF') ? 'GNF' : undefined,
);
</script>

<template>
    <KpiCard
        as="button"
        role="tab"
        :id="testid"
        :aria-controls="testid.replace('rapport-tab-', 'rapport-panel-')"
        :aria-selected="active"
        :tabindex="active || focusable ? 0 : -1"
        :data-testid="testid"
        :title="libelle"
        :value="montant"
        :unit="unite"
        :detail="avertissement ? `⚠ ${avertissement}` : detail"
        :warning="!!avertissement"
        :active="active"
        :horizontal="large"
        @click="$emit('choisir')"
    >
        <template #indicator>
            <ChevronDown
                v-if="active"
                class="mt-1 h-4 w-4 shrink-0 text-primary"
                aria-hidden="true"
            />
            <ArrowRight
                v-else
                class="mt-1 h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </template>
        <span v-if="active" class="sr-only">(détail affiché)</span>
    </KpiCard>
</template>
