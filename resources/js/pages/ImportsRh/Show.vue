<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Download,
    History,
    Lock,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Changement {
    champ: string;
    libelle: string;
    avant: string | number | null;
    apres: string | number | null;
    sensible: boolean;
    // Montant de salaire masqué par le serveur (rh-remunerations.read manquante).
    masque: boolean;
}

interface Ligne {
    numero_ligne: number;
    matricule: string;
    nom: string | null;
    statut: 'mise_a_jour' | 'inchange' | 'erreur';
    erreurs: string[];
    avertissements: string[];
    changements: Changement[];
}

interface ImportDetail {
    id: string;
    fichier_original: string;
    statut: string;
    statut_label: string;
    auteur: string | null;
    cree_le: string | null;
    nb_lignes_total: number;
    nb_lignes_maj: number;
    nb_lignes_inchange: number;
    nb_lignes_erreur: number;
    nb_employes_mis_a_jour: number | null;
    nb_contrats_crees: number | null;
    erreur_technique: string | null;
    est_pret: boolean;
    colonne_salaire_ignoree: boolean;
    // Lignes de salariés hors des agences consultables par le lecteur (non envoyées).
    lignes_masquees: number;
    lignes: Ligne[];
}

const props = defineProps<{
    record: ImportDetail;
    peut_confirmer: boolean;
}>();

useFlashToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Employés', href: '/backoffice/employes' },
    { title: 'Imports des dossiers', href: '/backoffice/employes/imports' },
    { title: props.record.fichier_original, href: '#' },
];

const lignesErreur = computed(() =>
    props.record.lignes.filter((l) => l.statut === 'erreur'),
);
const lignesMaj = computed(() =>
    props.record.lignes.filter((l) => l.statut === 'mise_a_jour'),
);
const enAttente = computed(() => props.record.statut === 'analyse');

function valeur(v: string | number | null): string {
    if (v === null || v === '') return '—';
    if (typeof v === 'number')
        return `${new Intl.NumberFormat('fr-FR').format(v)} GNF`;
    return v;
}

// ── Confirmation / abandon : spinner, boutons désactivés, fermeture bloquée pendant l'envoi ──
const action = ref<'confirmer' | 'abandonner' | null>(null);
const enCours = ref(false);
const CROIX_INACTIVE =
    '[&>button:last-child]:pointer-events-none [&>button:last-child]:opacity-40';

function executer() {
    if (!action.value) return;
    enCours.value = true;
    router.post(
        `/backoffice/employes/imports/${props.record.id}/${action.value}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                enCours.value = false;
                action.value = null;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Import RH - ${record.fichier_original}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
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
                            >{{ record.auteur }} · {{ record.cree_le }}</span
                        >
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <template v-if="enAttente && peut_confirmer">
                        <Button
                            size="sm"
                            variant="outline"
                            data-testid="import-rh-abandonner"
                            @click="action = 'abandonner'"
                        >
                            Abandonner
                        </Button>
                        <Button
                            v-if="record.est_pret && record.nb_lignes_maj > 0"
                            size="sm"
                            data-testid="import-rh-confirmer"
                            @click="action = 'confirmer'"
                        >
                            Confirmer l'import
                        </Button>
                    </template>
                    <Link href="/backoffice/employes/imports">
                        <Button size="sm" variant="outline">
                            <History class="mr-1.5 h-4 w-4" />
                            Historique
                        </Button>
                    </Link>
                    <Link href="/backoffice/employes">
                        <Button size="sm" variant="outline">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Employés
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Résultat ou motif d'échec -->
            <div
                v-if="record.erreur_technique"
                class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900/40 dark:bg-red-950/20 dark:text-red-400"
            >
                {{ record.erreur_technique }}
            </div>
            <div
                v-if="record.statut === 'termine'"
                class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-950/20 dark:text-emerald-300"
            >
                Import terminé :
                {{ record.nb_employes_mis_a_jour ?? 0 }} salarié(s) mis à jour,
                {{ record.nb_contrats_crees ?? 0 }}
                contrat(s) créé(s). Le fichier déposé a été supprimé.
            </div>
            <div
                v-if="record.colonne_salaire_ignoree"
                class="flex items-center gap-2 rounded-xl border bg-muted/30 p-4 text-sm text-muted-foreground"
            >
                <Lock class="h-4 w-4" />
                La colonne salaire du fichier a été ignorée : vous n'avez pas
                accès aux salaires.
            </div>
            <div
                v-if="record.lignes_masquees > 0"
                class="flex items-center gap-2 rounded-xl border bg-muted/30 p-4 text-sm text-muted-foreground"
                data-testid="import-rh-lignes-masquees"
            >
                <Lock class="h-4 w-4" />
                {{ record.lignes_masquees }} ligne(s) concernent des salariés
                hors de vos agences : elles ne sont pas affichées.
            </div>

            <!-- Compteurs -->
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Salariés à mettre à jour
                    </p>
                    <p class="mt-1 text-2xl font-semibold">
                        {{ record.nb_lignes_maj }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Sans changement</p>
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

            <!-- Lignes en erreur -->
            <div
                v-if="lignesErreur.length"
                class="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-900/40 dark:bg-red-950/20"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <AlertTriangle class="h-4 w-4 text-red-600" />
                        <h2
                            class="text-sm font-semibold text-red-700 dark:text-red-400"
                        >
                            {{ lignesErreur.length }} ligne(s) en erreur — rien
                            ne sera enregistré tant qu'elles ne sont pas
                            corrigées
                        </h2>
                    </div>
                    <a
                        :href="`/backoffice/employes/imports/${record.id}/erreurs`"
                        class="inline-flex items-center gap-2 rounded-md border bg-background px-3 py-1.5 text-xs font-medium hover:bg-muted"
                    >
                        <Download class="h-3.5 w-3.5" />
                        Fichier des erreurs
                    </a>
                </div>
                <div class="mt-4 space-y-3">
                    <div
                        v-for="l in lignesErreur"
                        :key="l.numero_ligne"
                        class="rounded-lg border border-red-200 bg-background p-3 text-sm dark:border-red-900/40"
                    >
                        <p class="font-medium">
                            Ligne {{ l.numero_ligne }} · matricule
                            {{ l.matricule || '—' }}
                            <span
                                v-if="l.nom"
                                class="text-xs text-muted-foreground"
                                >— {{ l.nom }}</span
                            >
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

            <!-- Changements -->
            <div class="rounded-xl border bg-card p-5">
                <h2
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    {{
                        record.statut === 'termine'
                            ? 'Changements appliqués'
                            : 'Changements prévus'
                    }}
                    ({{ lignesMaj.length }})
                </h2>
                <div
                    v-if="lignesMaj.length === 0"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    Aucun changement dans ce fichier.
                </div>
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <th class="py-2 pr-3 font-medium">Salarié</th>
                                <th class="py-2 font-medium">
                                    Changements (avant → après)
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="l in lignesMaj"
                                :key="l.numero_ligne"
                                class="align-top"
                            >
                                <td class="py-2.5 pr-3">
                                    <p class="font-medium">{{ l.nom }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ l.matricule }}
                                    </p>
                                </td>
                                <td class="py-2.5">
                                    <ul class="space-y-1">
                                        <li
                                            v-for="(c, j) in l.changements"
                                            :key="j"
                                            class="text-sm"
                                        >
                                            <span class="text-muted-foreground"
                                                >{{ c.libelle }} :</span
                                            >
                                            <template v-if="c.masque">
                                                <span
                                                    class="inline-flex items-center gap-1 text-muted-foreground italic"
                                                >
                                                    <Lock class="h-3 w-3" />
                                                    montant masqué
                                                </span>
                                            </template>
                                            <template v-else>
                                                {{ valeur(c.avant) }} →
                                                <span class="font-medium">{{
                                                    valeur(c.apres)
                                                }}</span>
                                            </template>
                                        </li>
                                    </ul>
                                    <ul
                                        v-if="l.avertissements.length"
                                        class="mt-1 list-inside list-disc text-xs text-amber-600 dark:text-amber-500"
                                    >
                                        <li
                                            v-for="(a, j) in l.avertissements"
                                            :key="j"
                                        >
                                            {{ a }}
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>

    <Dialog
        :open="action !== null"
        @update:open="
            (ouvert: boolean) => {
                if (!ouvert && !enCours) action = null;
            }
        "
    >
        <DialogContent
            class="sm:max-w-md"
            :class="{ [CROIX_INACTIVE]: enCours }"
        >
            <DialogHeader>
                <DialogTitle>{{
                    action === 'confirmer'
                        ? "Confirmer l'import ?"
                        : 'Abandonner cet import ?'
                }}</DialogTitle>
                <DialogDescription>
                    <template v-if="action === 'confirmer'">
                        {{ record.nb_lignes_maj }} salarié(s) seront mis à jour.
                        Le fichier est analysé à nouveau : si une donnée a
                        changé depuis l'aperçu, rien n'est enregistré.
                    </template>
                    <template v-else>
                        Rien ne sera modifié et le fichier déposé sera supprimé.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="enCours"
                    @click="action = null"
                >
                    Annuler
                </Button>
                <Button
                    :disabled="enCours"
                    :aria-busy="enCours"
                    data-testid="import-rh-action-confirmer"
                    @click="executer"
                >
                    <Spinner v-if="enCours" class="mr-1.5" />
                    {{ action === 'confirmer' ? 'Confirmer' : 'Abandonner' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
