<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, HandCoins } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ReglementDialog, {
    type LigneReglable,
    type SupportReglement,
} from './partials/ReglementDialog.vue';

/**
 * Détail d'une dette inter-agences (ADR 0012) : les encaissements reçus par l'agence débitrice pour
 * des commandes de l'agence créancière, avec leur statut de reversement, et le règlement.
 */
interface Ligne {
    encaissement_id: string;
    montant: number;
    date_encaissement: string | null;
    mode_paiement_label: string | null;
    reference_paiement: string | null;
    facture_reference: string | null;
    commande_id: string | null;
    client_nom: string | null;
    auteur: string | null;
    statut: string;
    statut_label: string;
    mouvement_reference: string | null;
    selectionnable: boolean;
}

const props = defineProps<{
    debiteur: { id: string; nom: string };
    creancier: { id: string; nom: string };
    lignes: Ligne[];
    lignes_a_regler: LigneReglable[];
    resume: {
        a_verser: number;
        reserve: number;
        en_cours_versement: number;
        verse: number;
    };
    filters: { statut: string };
    statut_options: { value: string; label: string }[];
    peut_regler: boolean;
    supports: SupportReglement[];
}>();

useFlashToast('top');

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Inter-agences',
        href: '/backoffice/comptabilite/tresorerie/inter-agences',
    },
    { title: `${props.debiteur.nom} → ${props.creancier.nom}`, href: '#' },
]);

const filterFields: FilterField[] = [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: props.statut_options,
        inline: true,
    },
];

const reglementOuvert = ref(false);
const aRegler = computed(() => props.lignes_a_regler.length > 0);

function dateFr(date: string | null): string {
    return date ? date.split('-').reverse().join('/') : '—';
}
</script>

<template>
    <Head :title="`Inter-agences — ${debiteur.nom} → ${creancier.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex flex-col gap-1">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        {{ debiteur.nom }}
                        <ArrowRight class="h-5 w-5 text-muted-foreground" />
                        {{ creancier.nom }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Encaissements reçus par {{ debiteur.nom }} pour des
                        commandes de {{ creancier.nom }}.
                    </p>
                </div>
                <Button
                    v-if="peut_regler && aRegler"
                    data-testid="ouvrir-reglement"
                    @click="reglementOuvert = true"
                >
                    <HandCoins class="mr-1.5 h-4 w-4" />
                    Régler
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">À verser</p>
                    <p
                        class="mt-1 text-2xl font-bold tabular-nums"
                        data-testid="resume-a-verser"
                    >
                        {{ formatGNF(resume.a_verser + resume.reserve) }}
                    </p>
                    <p
                        v-if="resume.reserve > 0"
                        class="text-xs text-muted-foreground"
                    >
                        dont {{ formatGNF(resume.reserve) }} réservé
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        En cours de versement
                    </p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(resume.en_cours_versement) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        Reste à recevoir par {{ creancier.nom }}
                    </p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{
                            formatGNF(
                                resume.a_verser +
                                    resume.reserve +
                                    resume.en_cours_versement,
                            )
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">Déjà versé</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(resume.verse) }}
                    </p>
                </div>
            </div>

            <DataFilters
                :url="`/backoffice/comptabilite/tresorerie/inter-agences/${debiteur.id}/${creancier.id}`"
                :values="filters"
                :fields="filterFields"
                :result-count="lignes.length"
                hide-agence-selector
            />

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[860px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Commande</th>
                            <th class="px-4 py-3 font-medium">Client</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Moyen</th>
                            <th class="px-4 py-3 font-medium">Auteur</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Montant
                            </th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="ligne in lignes"
                            :key="ligne.encaissement_id"
                            class="hover:bg-muted/30"
                            data-testid="ligne-dette"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">
                                <Link
                                    v-if="ligne.commande_id"
                                    :href="`/backoffice/ventes/${ligne.commande_id}`"
                                    class="font-mono text-xs text-primary hover:underline"
                                    >{{ ligne.facture_reference }}</Link
                                >
                                <span v-else class="font-mono text-xs">{{
                                    ligne.facture_reference ?? '—'
                                }}</span>
                            </td>
                            <td class="px-4 py-3">
                                {{ ligne.client_nom ?? '—' }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ dateFr(ligne.date_encaissement) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ ligne.mode_paiement_label ?? '—' }}
                                <div
                                    v-if="ligne.reference_paiement"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ ligne.reference_paiement }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                {{ ligne.auteur ?? '—' }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-medium tabular-nums"
                            >
                                {{ formatGNF(ligne.montant) }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="ligne.statut"
                                    :label="ligne.statut_label"
                                />
                                <div
                                    v-if="ligne.mouvement_reference"
                                    class="mt-0.5 text-xs text-muted-foreground"
                                >
                                    {{ ligne.mouvement_reference }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="lignes.length === 0">
                            <td
                                colspan="7"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucun encaissement.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ReglementDialog
            v-if="peut_regler"
            v-model:open="reglementOuvert"
            :lignes="lignes_a_regler"
            :supports="supports"
            :debiteur="debiteur"
            :creancier="creancier"
        />
    </AppLayout>
</template>
