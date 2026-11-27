<script setup lang="ts">
import { CARTE_APOLLO } from '../commissions/apollo';
import type { LigneIndicateurs, TonIndicateur } from './indicateurs';

// Lignes de cartes d'indicateurs partagées par Stat-Stock et Stat-RH : carte `.card` d'Apollo
// (StatsEcommerceWidget), titre en `text-lg font-semibold`, chiffre en `text-4xl font-bold`, tailles
// rem × 14 px comme les autres tableaux de bord. Sans évolution ni série à tracer, la place de la
// mini-courbe d'Apollo reste vide : l'icône de l'indicateur occupe l'angle du titre, comme les cartes
// métriques d'Apollo (apps/Files.vue), et le détail tient sur une ligne en petit texte.
// Grille d'Apollo : quatre cartes par ligne sur grand écran, deux sur tablette, une sur téléphone.
// Chaque ligne a son titre de section ; la page fournit la grille (`grid grid-cols-12 gap-7`).
defineProps<{
    lignes: LigneIndicateurs[];
    /** Préfixe des `data-testid` des titres de section (`rh`, `stock`). */
    prefixe: string;
}>();

const COULEUR_DETAIL: Record<TonIndicateur, string> = {
    neutre: 'text-[var(--p-text-muted-color)]',
    avertissement: 'text-amber-600 dark:text-amber-400',
};
</script>

<template>
    <template v-for="ligne in lignes" :key="ligne.id">
        <h2
            class="col-span-12 -mb-3.5 font-apollo leading-[24.5px]"
            :data-testid="`${prefixe}-ligne-${ligne.id}`"
        >
            <span
                class="text-[15.75px] font-semibold text-[var(--p-surface-900)] dark:text-[var(--p-surface-0)]"
                >{{ ligne.titre }}</span
            >
            <span class="text-[13px] text-[var(--p-text-muted-color)]">
                · {{ ligne.precision }}</span
            >
        </h2>
        <div
            v-for="carte in ligne.cartes"
            :key="carte.id"
            class="col-span-12 md:col-span-6 xl:col-span-3"
            :data-testid="`kpi-${carte.id}`"
        >
            <div :class="CARTE_APOLLO">
                <div class="flex items-start justify-between gap-3">
                    <span
                        class="min-w-0 text-[15.75px] leading-[24.5px] font-semibold"
                        :title="carte.definition"
                        >{{ carte.titre }}</span
                    >
                    <i
                        class="pi mt-0.5 shrink-0 text-[19.6px]! leading-none! text-[var(--p-primary-color)]"
                        :class="carte.icone"
                        aria-hidden="true"
                        data-slot="icone"
                    ></i>
                </div>
                <div
                    class="mt-3.5 text-[31.5px] leading-[35px] font-bold whitespace-nowrap text-[var(--p-surface-900)] tabular-nums dark:text-[var(--p-surface-0)]"
                    data-slot="valeur"
                >
                    {{ carte.valeur }}
                </div>
                <div
                    class="mt-1.5 text-[13px] leading-[18px] font-medium"
                    :class="COULEUR_DETAIL[carte.ton ?? 'neutre']"
                    data-slot="detail"
                >
                    {{ carte.detail }}
                </div>
            </div>
        </div>
    </template>
</template>
