<script setup lang="ts">
import InfoTooltip from '@/components/InfoTooltip.vue';
import KpiCard from '@/components/KpiCard.vue';
import { formatGNF } from '@/lib/utils';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface LigneRemuneration {
    id: string;
    libelle: string;
    categorie_label: string;
    montant: number;
    detail: string;
}

export interface Remuneration {
    date: string;
    hors_emploi: string | null;
    contrat: {
        id: string;
        type_contrat_label: string;
        date_debut: string | null;
        date_fin: string | null;
    } | null;
    salaire_base: number | null;
    primes: LigneRemuneration[];
    total_primes: number;
    total: number | null;
    avantages_nature: LigneRemuneration[];
    total_avantages_nature: number;
    non_comptees: { id: string; libelle: string; raison: string }[];
}

/**
 * Rémunération mensuelle totale (docs/primes-avantages.md, AFF-016) : calculée par le serveur
 * (RemunerationMensuelleService) à chaque ouverture de la fiche — l'écran ne fait qu'afficher.
 */
const props = defineProps<{ remuneration: Remuneration }>();

const detailOuvert = ref(false);

function dateFr(iso: string | null): string {
    if (!iso) return '';
    const [annee, mois, jour] = iso.split('-');
    return `${jour}/${mois}/${annee}`;
}

/** Montant sans l'unité, pour la grande valeur de la carte (l'unité est affichée à côté). */
function nombre(montant: number): string {
    return formatGNF(montant).replace(/ GNF$/, '');
}

const valeur = computed(() =>
    props.remuneration.total === null ? '—' : nombre(props.remuneration.total),
);

const sousTitre = computed(() => {
    const r = props.remuneration;
    if (r.hors_emploi) return r.hors_emploi;
    if (r.salaire_base === null) {
        return 'Aucun contrat en cours : pas de salaire de base, aucun total calculé.';
    }
    return `Salaire de base + primes récurrentes, au ${dateFr(r.date)}`;
});

const nbPrimes = computed(() => props.remuneration.primes.length);
</script>

<template>
    <KpiCard
        title="Rémunération mensuelle totale"
        :value="valeur"
        :unit="remuneration.total === null ? undefined : 'GNF'"
        :detail="sousTitre"
        :warning="
            !remuneration.hors_emploi && remuneration.salaire_base === null
        "
        data-testid="remuneration-mensuelle"
    >
        <template #indicator>
            <InfoTooltip label="Aide : rémunération mensuelle totale">
                Montant mensuel en vigueur aujourd'hui : salaire de base du
                contrat en cours et primes récurrentes versées en argent. Primes
                ponctuelles, montants saisis chaque mois, propositions à valider
                et avantages en nature ne sont pas additionnés. Ce n'est pas la
                paie du mois (aucun prorata).
            </InfoTooltip>
        </template>

        <template v-if="!remuneration.hors_emploi">
            <dl
                class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2"
                :class="
                    remuneration.avantages_nature.length > 0 &&
                    '@[36rem]:grid-cols-3'
                "
            >
                <div class="rounded-lg bg-muted/50 px-4 py-3">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Salaire de base
                    </dt>
                    <dd
                        class="mt-1 text-base font-semibold whitespace-nowrap tabular-nums"
                        data-testid="remuneration-salaire-base"
                    >
                        {{
                            remuneration.salaire_base === null
                                ? 'Aucun contrat'
                                : formatGNF(remuneration.salaire_base)
                        }}
                    </dd>
                </div>
                <div class="rounded-lg bg-muted/50 px-4 py-3">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Primes récurrentes
                        <span v-if="nbPrimes > 0">({{ nbPrimes }})</span>
                    </dt>
                    <dd
                        class="mt-1 text-base font-semibold whitespace-nowrap tabular-nums"
                        data-testid="remuneration-total-primes"
                    >
                        {{ formatGNF(remuneration.total_primes) }}
                    </dd>
                </div>
                <div
                    v-if="remuneration.avantages_nature.length > 0"
                    class="rounded-lg border border-dashed px-4 py-3"
                >
                    <dt class="text-xs font-medium text-muted-foreground">
                        Avantages en nature
                    </dt>
                    <dd
                        class="mt-1 text-base font-semibold whitespace-nowrap tabular-nums"
                        data-testid="remuneration-avantages-nature"
                    >
                        {{ formatGNF(remuneration.total_avantages_nature) }}
                    </dd>
                    <p class="text-[11px] text-muted-foreground">
                        Valorisés, hors net à payer
                    </p>
                </div>
            </dl>

            <button
                type="button"
                class="mt-4 inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-primary hover:text-primary/80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-expanded="detailOuvert"
                data-testid="remuneration-detail-toggle"
                @click="detailOuvert = !detailOuvert"
            >
                <ChevronDown
                    class="h-4 w-4 transition-transform"
                    :class="{ 'rotate-180': detailOuvert }"
                />
                {{
                    detailOuvert
                        ? 'Masquer le détail du calcul'
                        : 'Voir le détail du calcul'
                }}
            </button>

            <div
                v-if="detailOuvert"
                class="mt-3 space-y-4 text-sm"
                data-testid="remuneration-detail"
            >
                <ul class="divide-y rounded-lg border">
                    <li
                        class="flex items-start justify-between gap-4 px-4 py-2.5"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">Salaire de base</p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="remuneration.contrat">
                                    Contrat
                                    {{
                                        remuneration.contrat.type_contrat_label
                                    }}
                                    depuis le
                                    {{
                                        dateFr(remuneration.contrat.date_debut)
                                    }}
                                </template>
                                <template v-else
                                    >Aucun contrat en cours</template
                                >
                            </p>
                        </div>
                        <span class="whitespace-nowrap tabular-nums">{{
                            remuneration.salaire_base === null
                                ? '—'
                                : formatGNF(remuneration.salaire_base)
                        }}</span>
                    </li>
                    <li
                        v-for="p in remuneration.primes"
                        :key="p.id"
                        class="flex items-start justify-between gap-4 px-4 py-2.5"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">{{ p.libelle }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ p.categorie_label }} · {{ p.detail }}
                            </p>
                        </div>
                        <span class="whitespace-nowrap tabular-nums"
                            >+ {{ formatGNF(p.montant) }}</span
                        >
                    </li>
                    <li
                        class="flex items-center justify-between gap-4 bg-muted/40 px-4 py-2.5 font-semibold"
                    >
                        <span>Rémunération mensuelle totale</span>
                        <span class="whitespace-nowrap tabular-nums">{{
                            remuneration.total === null
                                ? '—'
                                : formatGNF(remuneration.total)
                        }}</span>
                    </li>
                </ul>

                <div v-if="remuneration.avantages_nature.length > 0">
                    <p class="mb-1.5 text-xs font-medium text-muted-foreground">
                        Avantages en nature — valorisés, non versés, non
                        additionnés
                    </p>
                    <ul class="divide-y rounded-lg border border-dashed">
                        <li
                            v-for="a in remuneration.avantages_nature"
                            :key="a.id"
                            class="flex items-start justify-between gap-4 px-4 py-2.5"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">{{ a.libelle }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ a.detail }}
                                </p>
                            </div>
                            <span
                                class="whitespace-nowrap text-muted-foreground tabular-nums"
                                >{{ formatGNF(a.montant) }}</span
                            >
                        </li>
                    </ul>
                </div>

                <div
                    v-if="remuneration.non_comptees.length > 0"
                    data-testid="remuneration-non-comptees"
                >
                    <p class="mb-1.5 text-xs font-medium text-muted-foreground">
                        Non comptées dans le total
                    </p>
                    <ul class="space-y-1 text-xs text-muted-foreground">
                        <li v-for="n in remuneration.non_comptees" :key="n.id">
                            <span class="font-medium text-foreground">{{
                                n.libelle
                            }}</span>
                            — {{ n.raison }}
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </KpiCard>
</template>
