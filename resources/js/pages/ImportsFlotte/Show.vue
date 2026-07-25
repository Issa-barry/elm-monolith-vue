<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
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

interface GroupeLivreur {
    existe: boolean;
    id: string | null;
    nom: string;
    prenom: string;
    telephone: string;
    role: string;
    montant_par_pack: number;
}

interface Groupe {
    immatriculation: string | null;
    ligne_vehicule: number | null;
    lignes_livreurs: number[];
    statut: 'valide' | 'erreur';
    erreurs: string[];
    vehicule?: { existe: boolean; nom_vehicule: string; categorie: string };
    proprietaire?: { existe: boolean; nom: string; prenom: string } | null;
    equipe?: { existe: boolean };
    livreurs?: GroupeLivreur[];
}

interface Rapport {
    nb_lignes_total: number;
    groupes: Groupe[];
    erreur_fatale?: string;
}

interface ImportDetail {
    id: string;
    fichier_original: string;
    statut: 'analyse' | 'en_cours' | 'termine' | 'echoue';
    statut_label: string;
    nb_groupes_valides: number;
    nb_groupes_erreur: number;
    nb_proprietaires_crees: number | null;
    nb_vehicules_crees: number | null;
    nb_livreurs_crees: number | null;
    nb_equipes_creees: number | null;
    utilisateur: string | null;
    created_at: string | null;
    termine_le: string | null;
    peut_confirmer: boolean;
    rapport: Rapport | null;
}

// Nommé `record` (et non `import`, mot réservé JS) pour éviter tout conflit
// dans le template compilé.
const props = defineProps<{ record: ImportDetail }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/parametres' },
    { title: 'Import flotte', href: '/settings/imports-flotte' },
    { title: props.record.fichier_original, href: '#' },
];

const groupesValides = computed(
    () => props.record.rapport?.groupes.filter((g) => g.statut === 'valide') ?? [],
);
const groupesErreur = computed(
    () => props.record.rapport?.groupes.filter((g) => g.statut === 'erreur') ?? [],
);

const aperçu = computed(() => {
    const vehicules = { creer: 0, existants: 0 };
    const proprietaires = { creer: 0, existants: 0 };
    const livreurs = { creer: 0, existants: 0 };
    let equipes = 0;

    for (const g of groupesValides.value) {
        if (g.vehicule) {
            g.vehicule.existe ? vehicules.existants++ : vehicules.creer++;
        }
        if (g.equipe && !g.equipe.existe) equipes++;
        if (g.proprietaire) {
            g.proprietaire.existe
                ? proprietaires.existants++
                : proprietaires.creer++;
        }
        for (const l of g.livreurs ?? []) {
            l.existe ? livreurs.existants++ : livreurs.creer++;
        }
    }

    return { vehicules, proprietaires, livreurs, equipes };
});

function formatLignes(g: Groupe): string {
    const parties: string[] = [];
    if (g.ligne_vehicule) {
        parties.push(`véhicule : ligne ${g.ligne_vehicule}`);
    }
    if (g.lignes_livreurs.length) {
        parties.push(
            `livreur${g.lignes_livreurs.length > 1 ? 's' : ''} : ligne${g.lignes_livreurs.length > 1 ? 's' : ''} ${g.lignes_livreurs.join(', ')}`,
        );
    }
    return parties.join(' · ') || 'ligne inconnue';
}

const confirmForm = useForm({});
function confirmer() {
    confirmForm.post(`/settings/imports-flotte/${props.record.id}/confirmer`);
}

const retryForm = useForm({});
function relancer() {
    retryForm.post(`/settings/imports-flotte/${props.record.id}/relancer`);
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
    <Head :title="`Import flotte - ${record.fichier_original}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
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
                    <Link href="/settings/imports-flotte">
                        <Button size="sm" variant="outline">
                            <History class="mr-1.5 h-4 w-4" />
                            Historique
                        </Button>
                    </Link>
                    <Link href="/settings/parametres">
                        <Button size="sm" variant="outline">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Aperçu (analyse) -->
            <template v-if="record.statut === 'analyse'">
                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Propriétaires à créer
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ aperçu.proprietaires.creer }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ aperçu.proprietaires.existants }} déjà
                            existants
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Véhicules à créer
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ aperçu.vehicules.creer }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ aperçu.vehicules.existants }} déjà existants
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Livreurs à créer
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ aperçu.livreurs.creer }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ aperçu.livreurs.existants }} déjà existants
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Équipes à créer
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ aperçu.equipes }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ groupesErreur.length }} groupe(s) en erreur
                        </p>
                    </div>
                </div>

                <div
                    v-if="groupesErreur.length"
                    class="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-900/40 dark:bg-red-950/20"
                >
                    <div class="flex items-center gap-2">
                        <AlertTriangle class="h-4 w-4 text-red-600" />
                        <h2 class="text-sm font-semibold text-red-700 dark:text-red-400">
                            {{ groupesErreur.length }} groupe(s) en erreur —
                            aucun import ne sera effectué tant qu'elles ne
                            sont pas corrigées
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div
                            v-for="(g, i) in groupesErreur"
                            :key="i"
                            class="rounded-lg border border-red-200 bg-background p-3 text-sm dark:border-red-900/40"
                        >
                            <p class="font-medium">
                                {{ g.immatriculation ?? 'Sans immatriculation' }}
                                <span class="text-xs text-muted-foreground"
                                    >({{ formatLignes(g) }})</span
                                >
                            </p>
                            <ul
                                class="mt-1 list-inside list-disc text-xs text-red-700 dark:text-red-400"
                            >
                                <li v-for="(e, j) in g.erreurs" :key="j">
                                    {{ e }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border bg-card p-5">
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Groupes valides ({{ groupesValides.length }})
                    </h2>
                    <div class="mt-3 divide-y">
                        <div
                            v-for="(g, i) in groupesValides"
                            :key="i"
                            class="flex items-center justify-between gap-4 py-2 text-sm"
                        >
                            <span class="font-medium">{{
                                g.immatriculation
                            }}</span>
                            <span class="text-muted-foreground">
                                {{ g.livreurs?.length ?? 0 }} livreur(s)
                                <span v-if="g.vehicule?.existe">
                                    · véhicule existant</span
                                >
                                <span v-if="g.equipe?.existe">
                                    · équipe existante</span
                                >
                            </span>
                        </div>
                    </div>
                </div>

                <Button
                    :disabled="!record.peut_confirmer || confirmForm.processing"
                    class="w-full"
                    @click="confirmer"
                >
                    Confirmer l'import
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
            <div
                v-else-if="record.statut === 'termine'"
                class="space-y-4"
            >
                <div
                    class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20"
                >
                    <CheckCircle2 class="h-5 w-5 text-emerald-600" />
                    <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                        Import terminé avec succès.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Propriétaires créés
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ record.nb_proprietaires_crees }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Véhicules créés
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ record.nb_vehicules_crees }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Livreurs créés
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ record.nb_livreurs_crees }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Équipes créées
                        </p>
                        <p class="mt-1 text-2xl font-semibold">
                            {{ record.nb_equipes_creees }}
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
                    <p class="text-sm font-medium text-red-700 dark:text-red-400">
                        L'import a échoué — aucune donnée n'a été enregistrée.
                    </p>
                </div>
                <p
                    v-if="record.rapport?.erreur_fatale"
                    class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
                >
                    {{ record.rapport.erreur_fatale }}
                </p>
                <div
                    v-if="groupesErreur.length"
                    class="rounded-xl border bg-card p-5"
                >
                    <h2
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Groupes en erreur
                    </h2>
                    <div class="mt-3 space-y-3">
                        <div
                            v-for="(g, i) in groupesErreur"
                            :key="i"
                            class="rounded-lg border p-3 text-sm"
                        >
                            <p class="font-medium">
                                {{ g.immatriculation ?? 'Sans immatriculation' }}
                                <span class="text-xs text-muted-foreground"
                                    >({{ formatLignes(g) }})</span
                                >
                            </p>
                            <ul
                                class="mt-1 list-inside list-disc text-xs text-red-700 dark:text-red-400"
                            >
                                <li v-for="(e, j) in g.erreurs" :key="j">
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
                    Relancer l'import
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
