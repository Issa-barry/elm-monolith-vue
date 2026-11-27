<script setup lang="ts">
import SelecteurArticle from '@/components/dashboard/stock/SelecteurArticle.vue';
import StockAlertesWidget from '@/components/dashboard/stock/StockAlertesWidget.vue';
import StockCartesWidget from '@/components/dashboard/stock/StockCartesWidget.vue';
import StockFluxWidget from '@/components/dashboard/stock/StockFluxWidget.vue';
import StockSitesWidget from '@/components/dashboard/stock/StockSitesWidget.vue';
import StockSyntheseWidget from '@/components/dashboard/stock/StockSyntheseWidget.vue';
import SelecteurAgence from '@/components/filters/SelecteurAgence.vue';
import InfoTooltip from '@/components/InfoTooltip.vue';
import SelecteurPeriode from '@/components/periodes/SelecteurPeriode.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { parametresPeriode } from '@/lib/periodes';
import { dashboard } from '@/routes';
import { stock } from '@/routes/dashboard';
import { type BreadcrumbItem } from '@/types';
import type {
    ArticleStock,
    CompteurAlertesStock,
    EtatStock,
    EvolutionStock,
    FluxPeriodeStock,
    LigneAlerteStock,
    LigneSyntheseStock,
    SiteStock,
} from '@/types/dashboard-stock';
import type { PeriodeFiltre } from '@/types/periode';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

// Tableau de bord du stock, Stat-Stock (docs/tableau-de-bord-stock.md) : même grille et mêmes
// cartes Apollo que les autres tableaux de bord. Tous les chiffres viennent du serveur, déjà limités
// au périmètre de la page Stock. Trois filtres en tête de page : l'agence, l'article (les quantités
// ne s'additionnent jamais entre articles) et la période, qui ne concerne que les flux — l'état du
// stock est toujours celui du jour. Chacun conserve les deux autres.
const props = defineProps<{
    periode: PeriodeFiltre;
    agences: { value: string; label: string }[];
    agence: string | null;
    perimetre_limite: boolean;
    articles: ArticleStock[];
    article: string | null;
    etat: EtatStock;
    alertes: CompteurAlertesStock;
    flux_periode: FluxPeriodeStock;
    evolution: EvolutionStock;
    sites: SiteStock[];
    synthese: LigneSyntheseStock[];
    alertes_lignes: LigneAlerteStock[];
}>();

const libelleArticle = computed(
    () => props.articles.find((a) => a.value === props.article)?.label ?? null,
);

const libelleAgences = computed(() => {
    if (props.agence !== null) {
        const choisie = props.agences.find((a) => a.value === props.agence);
        return `Agence : ${choisie?.label ?? '—'}`;
    }
    if (!props.perimetre_limite) return 'Toutes les agences';

    return props.agences.length > 0
        ? `Vos agences : ${props.agences.map((a) => a.label).join(', ')}`
        : 'Aucune agence';
});

const filtreAgence = computed(
    (): Record<string, string> =>
        props.agence !== null ? { site_id: props.agence } : {},
);
const filtreArticle = computed(
    (): Record<string, string> =>
        props.article !== null ? { article: props.article } : {},
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: dashboard().url },
    { title: 'Stat-Stock', href: stock().url },
];
</script>

<template>
    <Head><title>Tableau de bord — Stock</title></Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div
                    class="flex min-w-0 items-center gap-1.5"
                    data-testid="dashboard-stock-contexte"
                >
                    <p
                        class="text-sm font-medium"
                        data-testid="dashboard-stock-periode"
                    >
                        {{ periode.libelle }}
                        <span
                            class="text-muted-foreground"
                            data-testid="dashboard-stock-agences"
                        >
                            · {{ libelleAgences }}</span
                        >
                    </p>
                    <InfoTooltip label="Lecture du tableau de bord">
                        <span data-testid="dashboard-stock-aide">
                            L’état du stock (disponible, réservé, en transit,
                            alertes) est celui du jour ; la période ne
                            s’applique qu’aux entrées, sorties, pertes et
                            écarts. Les quantités sont en unités de l’article
                            choisi, jamais additionnées entre articles.
                            <template v-if="perimetre_limite">
                                Périmètre de la page Stock : vos agences de
                                rattachement.
                            </template>
                        </span>
                    </InfoTooltip>
                </div>
                <div class="flex max-w-full flex-wrap items-start gap-2">
                    <SelecteurAgence
                        v-if="agences.length > 1"
                        :url="stock().url"
                        :agences="agences"
                        :agence="agence"
                        :parametres="{
                            ...parametresPeriode(periode),
                            ...filtreArticle,
                        }"
                    />
                    <SelecteurArticle
                        :url="stock().url"
                        :articles="articles"
                        :article="article"
                        :parametres="{
                            ...parametresPeriode(periode),
                            ...filtreAgence,
                        }"
                    />
                    <SelecteurPeriode
                        :url="stock().url"
                        :periode="periode"
                        :parametres="{ ...filtreAgence, ...filtreArticle }"
                    />
                </div>
            </div>

            <div class="grid grid-cols-12 gap-7">
                <StockCartesWidget
                    :etat="etat"
                    :alertes="alertes"
                    :flux="flux_periode"
                    :article="libelleArticle"
                />

                <div class="col-span-12 xl:col-span-8">
                    <StockFluxWidget
                        :evolution="evolution"
                        :article="libelleArticle"
                    />
                </div>
                <div class="col-span-12 xl:col-span-4">
                    <StockSitesWidget
                        :sites="sites"
                        :article="libelleArticle"
                    />
                </div>
                <div class="col-span-12">
                    <StockSyntheseWidget
                        :lignes="synthese"
                        :article="article"
                    />
                </div>
                <div class="col-span-12">
                    <StockAlertesWidget :lignes="alertes_lignes" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
