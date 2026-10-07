<script setup lang="ts">
import { computed } from 'vue';

interface ProcessusData {
    code: string;
    label: string;
    montant: number;
    nb_factures: number;
}

const props = defineProps<{
    caParProcessus: ProcessusData[];
}>();

const total = computed(() =>
    props.caParProcessus.reduce((sum, p) => sum + p.montant, 0),
);

// Classées du plus gros au plus petit pour lire le classement d'un coup d'œil ; la
// longueur des barres est relative au premier, la part affichée relative au total.
const lignes = computed(() => {
    const max = Math.max(0, ...props.caParProcessus.map((p) => p.montant));
    return [...props.caParProcessus]
        .sort((a, b) => b.montant - a.montant)
        .map((p) => ({
            ...p,
            largeur: max > 0 ? (p.montant / max) * 100 : 0,
            part:
                total.value > 0
                    ? Math.round((p.montant / total.value) * 100)
                    : 0,
        }));
});

function gnf(val: number): string {
    return (
        new Intl.NumberFormat('fr-FR')
            .format(Math.round(val))
            .replace(/\u202f/g, '\u00a0') + ' GNF'
    );
}
</script>

<template>
    <div class="card flex h-full flex-col" data-testid="ventes-par-processus">
        <div class="mb-1 text-xl font-semibold">Ventes par processus</div>
        <p class="mb-6 text-sm text-muted-foreground">
            Chiffre d'affaires facturé, hors factures annulées
        </p>

        <ul v-if="total > 0" class="flex flex-1 flex-col gap-5">
            <li
                v-for="p in lignes"
                :key="p.code"
                :title="`${p.label} : ${gnf(p.montant)} — ${p.nb_factures} facture${p.nb_factures > 1 ? 's' : ''}, ${p.part} % du total`"
            >
                <div class="mb-1.5 flex items-baseline justify-between gap-3">
                    <span class="text-sm font-medium">{{ p.label }}</span>
                    <span
                        class="text-sm font-semibold whitespace-nowrap tabular-nums"
                    >
                        {{ gnf(p.montant) }}
                    </span>
                </div>
                <div class="h-2 w-full rounded-full bg-muted">
                    <div
                        class="h-2 rounded-full"
                        :style="{
                            width: `${p.largeur}%`,
                            backgroundColor: 'var(--p-primary-color)',
                        }"
                    />
                </div>
                <div class="mt-1 text-xs text-muted-foreground tabular-nums">
                    {{ p.nb_factures }} facture{{
                        p.nb_factures > 1 ? 's' : ''
                    }}
                    · {{ p.part }} %
                </div>
            </li>
        </ul>
        <div
            v-else
            class="flex h-48 items-center justify-center text-sm text-muted-foreground"
        >
            Aucune vente sur la période
        </div>

        <div
            v-if="total > 0"
            class="mt-6 flex items-baseline justify-between border-t pt-4 text-sm"
        >
            <span class="text-muted-foreground">Total</span>
            <span class="font-semibold tabular-nums">{{ gnf(total) }}</span>
        </div>
    </div>
</template>
