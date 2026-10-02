<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRightLeft, Wallet } from 'lucide-vue-next';

// `en_cours_versement` : versements envoyés par cette caisse, pas encore reçus. Déjà sortis du
// solde (transit au grand livre) : information de suivi, jamais incluse dans `solde` ni `total`.
interface Support {
    compte_tresorerie_id: string;
    site_id: string;
    libelle: string;
    type: string;
    solde: number;
    en_cours_versement: number;
    versements_en_cours: number;
}

const props = defineProps<{
    site: { id: string; nom: string };
    supports: Support[];
    total: number;
    en_cours_versement: number;
    versements_en_cours: number;
    filters: { date: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Situation de trésorerie',
        href: '/backoffice/comptabilite/tresorerie/situation',
    },
    { title: props.site.nom, href: '#' },
];

const typeLabels: Record<string, string> = {
    caisse: 'Caisse',
    banque: 'Banque',
    mobile_money: 'Mobile Money',
};

const journalHref = `/backoffice/comptabilite/journal?site_ids[]=${props.site.id}`;
</script>

<template>
    <Head :title="`Situation de trésorerie — ${site.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <Link
                    href="/backoffice/comptabilite/tresorerie/situation"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground hover:underline"
                >
                    <ArrowLeft class="h-3.5 w-3.5" />
                    Situation de trésorerie
                </Link>
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    <Wallet class="h-5 w-5 text-muted-foreground" />
                    {{ site.nom }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Solde actuel par support, au
                    {{ new Date(filters.date).toLocaleDateString('fr-FR') }}.
                </p>
            </div>

            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Total disponible</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">
                    {{ formatGNF(total) }}
                </p>
            </div>

            <Alert
                v-if="en_cours_versement > 0"
                data-testid="situation-en-cours-versement"
            >
                <ArrowRightLeft class="text-blue-500" />
                <AlertTitle>
                    {{ formatGNF(en_cours_versement) }} en cours de versement
                </AlertTitle>
                <AlertDescription>
                    {{
                        versements_en_cours > 1
                            ? `${versements_en_cours} versements envoyés par des caisses d'agents attendent la confirmation de la caisse de l'agence.`
                            : "1 versement envoyé par une caisse d'agent attend la confirmation de la caisse de l'agence."
                    }}
                    Ce montant n'est plus dans les soldes ci-dessous et n'est
                    pas encore crédité : il l'est dès la réception confirmée.
                </AlertDescription>
            </Alert>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Support</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Solde actuel
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="s in supports"
                            :key="s.compte_tresorerie_id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ s.libelle }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ typeLabels[s.type] ?? s.type }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(s.solde) }}
                                <div
                                    v-if="s.en_cours_versement > 0"
                                    class="mt-0.5 text-xs font-medium whitespace-nowrap text-blue-600 dark:text-blue-400"
                                    data-testid="situation-support-en-cours"
                                >
                                    En cours de versement :
                                    {{ formatGNF(s.en_cours_versement) }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="supports.length === 0">
                            <td
                                colspan="3"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucun support de trésorerie configuré pour cette
                                agence.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="supports.length > 0">
                        <tr class="border-t bg-muted/20 font-semibold">
                            <td class="px-4 py-3" colspan="2">Total</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="text-sm text-muted-foreground">
                Pour le détail des entrées/sorties de chaque support, voir le
                <Link :href="journalHref" class="text-primary hover:underline"
                    >Journal financier</Link
                >
                filtré sur cette agence.
            </p>
        </div>
    </AppLayout>
</template>
