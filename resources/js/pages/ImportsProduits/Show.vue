<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Download, History } from 'lucide-vue-next';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import { computed, watch } from 'vue';

type StatutLigne = 'creation' | 'mise_a_jour' | 'inchange' | 'erreur';

interface Changement {
    avant: string | number | null;
    apres: string | number | null;
}

interface Ligne {
    numero_ligne: number | null;
    sku: string | null;
    nom: string | null;
    statut: StatutLigne;
    erreurs: string[];
    avertissements: string[];
    normalisations: string[];
    changements: Record<string, Changement>;
}

interface Rapport {
    nb_lignes_total: number;
    lignes: Ligne[];
    fichier_deja_importe: {
        import_id: string;
        termine_le: string | null;
    } | null;
}

interface ImportDetail {
    id: string;
    fichier_original: string;
    statut: 'analyse' | 'en_cours' | 'termine' | 'echoue';
    statut_label: string;
    nb_lignes_total: number;
    nb_lignes_creation: number;
    nb_lignes_mise_a_jour: number;
    nb_lignes_inchange: number;
    nb_lignes_erreur: number;
    nb_produits_crees: number | null;
    nb_produits_mis_a_jour: number | null;
    utilisateur: string | null;
    created_at: string | null;
    termine_le: string | null;
    peut_confirmer: boolean;
    rapport: Rapport | null;
    erreur_technique: string | null;
}

// Nommé `record` (et non `import`, mot réservé JS) — même convention qu'ImportsFlotte/Show.vue.
const props = defineProps<{ record: ImportDetail }>();
const page = usePage();
const toast = useToast();
const RESULT_TOAST_GROUP = 'import-produits-resultat';

interface FlashMessages {
    success?: string;
    error?: string;
}

const flash = computed(
    () => (page.props as { flash?: FlashMessages }).flash ?? {},
);

watch(
    flash,
    (messages) => {
        if (messages.success) {
            toast.add({
                group: RESULT_TOAST_GROUP,
                severity: 'success',
                summary: 'Import réussi',
                detail: messages.success,
                life: 5000,
            });
        } else if (messages.error) {
            toast.add({
                group: RESULT_TOAST_GROUP,
                severity: 'error',
                summary: "Échec de l'import",
                detail: messages.error,
                life: 7000,
            });
        }
    },
    { immediate: true },
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Produits', href: '/backoffice/produits' },
    { title: 'Import de produits', href: '/backoffice/produits/imports' },
    { title: props.record.fichier_original, href: '#' },
];

const STATUT_LABELS: Record<StatutLigne, string> = {
    creation: 'À créer',
    mise_a_jour: 'À mettre à jour',
    inchange: 'Sans changement',
    erreur: 'Erreur',
};

const lignes = computed(() => props.record.rapport?.lignes ?? []);

function formatChangement(champ: string, c: Changement): string {
    const avant = c.avant ?? 'Aucun';
    const apres = c.apres ?? 'Aucun';
    return `${champ} : ${avant} → ${apres}`;
}

const confirmForm = useForm({});
function confirmer() {
    confirmForm.post(
        `/backoffice/produits/imports/${props.record.id}/confirmer`,
    );
}

const retryForm = useForm({});
function relancer() {
    retryForm.post(`/backoffice/produits/imports/${props.record.id}/reessayer`);
}
</script>

<template>
    <Head :title="`Import produits - ${record.fichier_original}`" />

    <Toast :group="RESULT_TOAST_GROUP" position="top-right" />

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
                    <a
                        v-if="record.statut === 'termine'"
                        :href="`/backoffice/produits/imports/${record.id}/reprise`"
                    >
                        <Button size="sm" variant="outline">
                            <Download class="mr-1.5 h-4 w-4" />
                            Fichier de reprise
                        </Button>
                    </a>
                    <Link href="/backoffice/produits/imports">
                        <Button size="sm" variant="outline">
                            <History class="mr-1.5 h-4 w-4" />
                            Historique
                        </Button>
                    </Link>
                    <Link href="/backoffice/produits">
                        <Button size="sm" variant="outline">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                </div>
            </div>

            <div
                v-if="record.rapport?.fichier_deja_importe"
                class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
                Ce fichier a déjà été importé intégralement. Téléchargez le
                fichier de reprise de l'import précédent plutôt que de
                réimporter celui-ci tel quel.
                <Link
                    :href="`/backoffice/produits/imports/${record.rapport.fichier_deja_importe.import_id}`"
                    class="font-medium underline"
                >
                    Voir cet import
                </Link>
            </div>

            <div
                v-if="record.erreur_technique"
                class="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
            >
                {{ record.erreur_technique }}
            </div>

            <!-- Compteurs -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        {{
                            record.statut === 'termine'
                                ? 'Produits créés'
                                : 'Produits à créer'
                        }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold">
                        {{
                            record.statut === 'termine'
                                ? (record.nb_produits_crees ?? 0)
                                : record.nb_lignes_creation
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        {{
                            record.statut === 'termine'
                                ? 'Produits mis à jour'
                                : 'Produits à mettre à jour'
                        }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold">
                        {{
                            record.statut === 'termine'
                                ? (record.nb_produits_mis_a_jour ?? 0)
                                : record.nb_lignes_mise_a_jour
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Sans changement</p>
                    <p class="mt-1 text-2xl font-semibold">
                        {{ record.nb_lignes_inchange }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">En erreur</p>
                    <p
                        class="mt-1 text-2xl font-semibold"
                        :class="
                            record.nb_lignes_erreur > 0
                                ? 'text-destructive'
                                : ''
                        "
                    >
                        {{ record.nb_lignes_erreur }}
                    </p>
                </div>
            </div>

            <!-- Actions -->
            <div
                v-if="record.statut === 'analyse'"
                class="flex items-center gap-3"
            >
                <Button
                    :disabled="!record.peut_confirmer || confirmForm.processing"
                    @click="confirmer"
                >
                    Confirmer l'import
                </Button>
                <p
                    v-if="!record.peut_confirmer"
                    class="text-sm text-muted-foreground"
                >
                    Corrigez les lignes en erreur avant de pouvoir confirmer.
                </p>
            </div>
            <div v-else-if="record.statut === 'echoue'">
                <Button :disabled="retryForm.processing" @click="relancer">
                    Réessayer
                </Button>
            </div>

            <!-- Détail des lignes -->
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/30 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Ligne</th>
                            <th class="px-3 py-2 font-medium">SKU</th>
                            <th class="px-3 py-2 font-medium">Nom</th>
                            <th class="px-3 py-2 font-medium">Statut</th>
                            <th class="px-3 py-2 font-medium">Détail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="l in lignes"
                            :key="l.numero_ligne ?? Math.random()"
                        >
                            <td
                                class="px-3 py-2 align-top text-muted-foreground"
                            >
                                {{ l.numero_ligne ?? '—' }}
                            </td>
                            <td class="px-3 py-2 align-top font-mono text-xs">
                                {{ l.sku ?? 'généré automatiquement' }}
                            </td>
                            <td class="px-3 py-2 align-top">
                                {{ l.nom ?? '—' }}
                            </td>
                            <td class="px-3 py-2 align-top">
                                <span
                                    :class="
                                        l.statut === 'erreur'
                                            ? 'text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                    >{{ STATUT_LABELS[l.statut] }}</span
                                >
                            </td>
                            <td class="px-3 py-2 align-top">
                                <p
                                    v-for="(e, i) in l.erreurs"
                                    :key="`e-${i}`"
                                    class="text-xs text-destructive"
                                >
                                    {{ e }}
                                </p>
                                <p
                                    v-for="(a, i) in l.avertissements"
                                    :key="`a-${i}`"
                                    class="text-xs text-amber-600 dark:text-amber-500"
                                >
                                    ⚠ {{ a }}
                                </p>
                                <p
                                    v-for="(champ, i) in Object.keys(
                                        l.changements,
                                    )"
                                    :key="`c-${i}`"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{
                                        formatChangement(
                                            champ,
                                            l.changements[champ],
                                        )
                                    }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
