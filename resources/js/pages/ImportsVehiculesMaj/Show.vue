<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    History,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted } from 'vue';

interface Changement {
    champ: string;
    label: string;
    avant: string | number;
    apres: string | number;
}

interface Ligne {
    ligne: number;
    immatriculation: string | null;
    statut: 'mise_a_jour' | 'inchange' | 'erreur';
    erreurs: string[];
    avertissements: string[];
    vehicule_id: string | null;
    vehicule_nom: string | null;
    changements: Changement[];
}

interface Rapport {
    nb_lignes_total: number;
    lignes: Ligne[];
}

interface ImportDetail {
    id: string;
    fichier_original: string;
    statut: 'analyse' | 'en_cours' | 'termine' | 'echoue';
    statut_label: string;
    nb_lignes_total: number;
    nb_lignes_maj: number;
    nb_lignes_inchange: number;
    nb_lignes_erreur: number;
    nb_vehicules_mis_a_jour: number | null;
    utilisateur: string | null;
    created_at: string | null;
    termine_le: string | null;
    peut_confirmer: boolean;
    rapport: Rapport | null;
    erreur_technique: string | null;
}

// Nommé `record` (et non `import`, mot réservé JS) pour éviter tout conflit
// dans le template compilé.
const props = defineProps<{ record: ImportDetail }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Véhicules', href: '/backoffice/vehicules' },
    {
        title: 'Mise à jour en masse',
        href: '/backoffice/vehicules/imports-maj',
    },
    { title: props.record.fichier_original, href: '#' },
];

const lignesMaj = computed(
    () =>
        props.record.rapport?.lignes.filter(
            (l) => l.statut === 'mise_a_jour',
        ) ?? [],
);
const lignesInchangees = computed(
    () =>
        props.record.rapport?.lignes.filter((l) => l.statut === 'inchange') ??
        [],
);
const lignesErreur = computed(
    () =>
        props.record.rapport?.lignes.filter((l) => l.statut === 'erreur') ?? [],
);

function formatLigne(l: Ligne): string {
    return `${l.immatriculation ?? 'sans immatriculation'} (ligne ${l.ligne})`;
}

const confirmForm = useForm({});
function confirmer() {
    confirmForm.post(
        `/backoffice/vehicules/imports-maj/${props.record.id}/confirmer`,
    );
}

const retryForm = useForm({});
function relancer() {
    retryForm.post(
        `/backoffice/vehicules/imports-maj/${props.record.id}/relancer`,
    );
}

let intervalId: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    if (props.record.statut === 'en_cours') {
        intervalId = setInterval(() => {
            router.reload({ only: ['record'] });
        }, 3000);
    }
});
onBeforeUnmount(() => {
    if (intervalId) clearInterval(intervalId);
});
</script>

<template>
    <Head :title="`Véhicules - ${record.fichier_original}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-lg font-semibold">
                        {{ record.fichier_original }}
                    </h1>
                    <div class="mt-1 flex items-center gap-3 text-sm">
                        <StatusDot
                            :status="record.statut"
                            :label="record.statut_label"
                        />
                        <span class="text-muted-foreground"
                            >{{ record.utilisateur }} ·
                            {{ record.created_at }}</span
                        >
                    </div>
                </div>
                <div class="flex gap-2">
                    <Link href="/backoffice/vehicules/imports-maj">
                        <Button size="sm" variant="outline">
                            <History class="mr-1.5 h-4 w-4" />
                            Historique
                        </Button>
                    </Link>
                    <Link href="/backoffice/vehicules/imports-maj/nouveau">
                        <Button size="sm" variant="outline">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Aperçu (analyse) -->
            <template v-if="record.statut === 'analyse'">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Véhicules à mettre à jour
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ record.nb_lignes_maj }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Sans changement
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ record.nb_lignes_inchange }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Lignes en erreur
                        </p>
                        <p
                            class="mt-1 text-2xl font-semibold"
                            :class="
                                record.nb_lignes_erreur > 0
                                    ? 'text-red-600 dark:text-red-400'
                                    : ''
                            "
                        >
                            {{ record.nb_lignes_erreur }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="lignesErreur.length"
                    class="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-900/40 dark:bg-red-950/20"
                >
                    <div class="flex items-center gap-2">
                        <AlertTriangle class="h-4 w-4 text-red-600" />
                        <h2
                            class="text-sm font-semibold text-red-700 dark:text-red-400"
                        >
                            {{ lignesErreur.length }} ligne(s) en erreur —
                            aucune mise à jour ne sera effectuée tant qu'elles
                            ne sont pas corrigées
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div
                            v-for="(l, i) in lignesErreur"
                            :key="i"
                            class="rounded-lg border border-red-200 bg-background p-3 text-sm dark:border-red-900/40"
                        >
                            <p class="font-medium">
                                {{ formatLigne(l) }}
                                <span
                                    v-if="l.vehicule_nom"
                                    class="text-xs text-muted-foreground"
                                    >— {{ l.vehicule_nom }}</span
                                >
                            </p>
                            <ul
                                class="mt-1 list-inside list-disc text-xs text-red-700 dark:text-red-400"
                            >
                                <li v-for="(e, j) in l.erreurs" :key="j">
                                    {{ e }}
                                </li>
                            </ul>
                            <ul
                                v-if="l.avertissements?.length"
                                class="mt-1 list-inside list-disc text-xs text-amber-600 dark:text-amber-500"
                            >
                                <li v-for="(a, j) in l.avertissements" :key="j">
                                    {{ a }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border bg-card p-5">
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Véhicules à mettre à jour ({{ lignesMaj.length }})
                    </h2>
                    <div
                        v-if="lignesMaj.length === 0"
                        class="mt-3 text-sm text-muted-foreground"
                    >
                        Aucun changement détecté dans ce fichier.
                    </div>
                    <div v-else class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b text-left text-xs text-muted-foreground"
                                >
                                    <th class="py-2 pr-3 font-medium">
                                        Immatriculation
                                    </th>
                                    <th class="py-2 pr-3 font-medium">
                                        Véhicule
                                    </th>
                                    <th class="py-2 font-medium">
                                        Changements
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="(l, i) in lignesMaj"
                                    :key="i"
                                    class="align-top"
                                >
                                    <td
                                        class="py-2 pr-3 font-medium whitespace-nowrap"
                                    >
                                        {{ l.immatriculation }}
                                    </td>
                                    <td
                                        class="py-2 pr-3 whitespace-nowrap text-muted-foreground"
                                    >
                                        {{ l.vehicule_nom }}
                                    </td>
                                    <td class="py-2 text-muted-foreground">
                                        <ul class="space-y-0.5">
                                            <li
                                                v-for="(c, k) in l.changements"
                                                :key="k"
                                                class="font-medium text-foreground"
                                            >
                                                {{ c.label }} : {{ c.avant }} →
                                                {{ c.apres }}
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-if="lignesInchangees.length"
                    class="rounded-xl border bg-card p-5"
                >
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Sans changement ({{ lignesInchangees.length }})
                    </h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{
                            lignesInchangees
                                .map((l) => l.immatriculation)
                                .join(', ')
                        }}
                    </p>
                </div>

                <Button
                    :disabled="!record.peut_confirmer || confirmForm.processing"
                    class="w-full"
                    @click="confirmer"
                >
                    <Spinner v-if="confirmForm.processing" class="mr-2" />
                    {{
                        confirmForm.processing
                            ? 'Mise à jour en cours, veuillez patienter…'
                            : 'Confirmer la mise à jour'
                    }}
                </Button>
            </template>

            <!-- En cours -->
            <div
                v-else-if="record.statut === 'en_cours'"
                class="flex flex-col items-center gap-3 rounded-xl border bg-card py-12 text-center"
            >
                <Clock class="h-8 w-8 animate-pulse text-muted-foreground" />
                <p class="text-sm font-medium">Traitement en cours…</p>
                <p class="text-xs text-muted-foreground">
                    Cette page se met à jour automatiquement.
                </p>
            </div>

            <!-- Terminé -->
            <div v-else-if="record.statut === 'termine'" class="space-y-4">
                <div
                    class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20"
                >
                    <CheckCircle2 class="h-5 w-5 text-emerald-600" />
                    <p
                        class="text-sm font-medium text-emerald-700 dark:text-emerald-400"
                    >
                        Mise à jour terminée avec succès.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-1">
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Véhicules mis à jour
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ record.nb_vehicules_mis_a_jour }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Échoué -->
            <div v-else class="space-y-4">
                <div
                    class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/40 dark:bg-red-950/20"
                >
                    <AlertTriangle class="h-5 w-5 text-red-600" />
                    <p
                        class="text-sm font-medium text-red-700 dark:text-red-400"
                    >
                        L'import a échoué — aucune donnée n'a été enregistrée.
                    </p>
                </div>
                <p
                    v-if="record.erreur_technique"
                    class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
                >
                    {{ record.erreur_technique }}
                </p>
                <div
                    v-if="lignesErreur.length"
                    class="rounded-xl border bg-card p-5"
                >
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Lignes en erreur
                    </h2>
                    <div class="mt-3 space-y-3">
                        <div
                            v-for="(l, i) in lignesErreur"
                            :key="i"
                            class="rounded-lg border p-3 text-sm"
                        >
                            <p class="font-medium">
                                {{ formatLigne(l) }}
                            </p>
                            <ul
                                class="mt-1 list-inside list-disc text-xs text-red-700 dark:text-red-400"
                            >
                                <li v-for="(e, j) in l.erreurs" :key="j">
                                    {{ e }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <Button
                    :disabled="retryForm.processing"
                    class="w-full"
                    @click="relancer"
                >
                    <Spinner v-if="retryForm.processing" class="mr-2" />
                    {{
                        retryForm.processing
                            ? 'Mise à jour en cours, veuillez patienter…'
                            : "Relancer l'import"
                    }}
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
