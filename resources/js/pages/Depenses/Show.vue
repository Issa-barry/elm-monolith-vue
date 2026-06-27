<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle,
    Edit,
    Send,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface Imputation {
    id: string;
    imputation_type: string;
    beneficiaire_type: string;
    beneficiaire_label: string;
    montant: number;
    periode_type: string | null;
    periode_debut: string | null;
    periode_fin: string | null;
    statut: string;
}

interface DepenseDetail {
    id: string;
    date_depense: string;
    montant: number;
    montant_formatte: string;
    statut: string;
    statut_label: string;
    commentaire: string | null;
    motif_rejet: string | null;
    commentaire_rejet: string | null;
    justificatif_path: string | null;
    date_validation: string | null;
    created_at: string;
    type_libelle: string;
    categorie: string;
    categorie_label: string;
    impact_message: string;
    vehicule_nom: string | null;
    vehicule_immatriculation: string | null;
    beneficiaire_label: string | null;
    site_nom: string | null;
    saisi_par: string;
    validateur: string | null;
    imputations: Imputation[];
    can_edit: boolean;
    can_submit: boolean;
    can_validate: boolean;
    can_reject: boolean;
    can_delete: boolean;
}

const props = defineProps<{ depense: DepenseDetail }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dépenses', href: '/depenses' },
    { title: props.depense.type_libelle, href: '#' },
];

const statutConfig: Record<string, { label: string }> = {
    brouillon: { label: 'Brouillon' },
    soumis: { label: 'Soumis' },
    valide: { label: 'Validée' },
    rejete: { label: 'Rejetée' },
    annule: { label: 'Annulée' },
};

const categorieClass: Record<string, string> = {
    interne: 'bg-slate-100 text-slate-700 border-slate-200',
    employe: 'bg-blue-100 text-blue-700 border-blue-200',
    livreur: 'bg-amber-100 text-amber-700 border-amber-200',
    proprietaire: 'bg-purple-100 text-purple-700 border-purple-200',
    vehicule: 'bg-green-100 text-green-700 border-green-200',
};

const impactBanner: Record<string, string> = {
    interne: 'border-slate-200 bg-slate-50 text-slate-700',
    employe: 'border-blue-200 bg-blue-50 text-blue-700',
    livreur: 'border-amber-200 bg-amber-50 text-amber-700',
    proprietaire: 'border-purple-200 bg-purple-50 text-purple-700',
    vehicule: 'border-green-200 bg-green-50 text-green-700',
};

const toast = useToast();
const statut = computed(
    () =>
        statutConfig[props.depense.statut] ?? {
            label: props.depense.statut,
        },
);

const activeTab = ref<'informations' | 'historique'>('informations');
const showRejectDialog = ref(false);
const rejectMotif = ref('');
const rejectCommentaire = ref('');
const rejectErrors = ref<{ motif?: string; commentaire?: string }>({});
const processing = ref(false);

// ── Historique ───────────────────────────────────────────────────────────────
interface HistoriqueEntry {
    id: string;
    date: string;
    acteur: string;
    event_code: string;
    action: string;
    description: string;
}

const historiqueLogs = ref<HistoriqueEntry[]>([]);
const historiqueLoading = ref(false);
const historiqueError = ref(false);
const historiqueLoaded = ref(false);

async function fetchHistorique() {
    historiqueLoading.value = true;
    historiqueError.value = false;
    try {
        const res = await fetch(`/depenses/${props.depense.id}/historique`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error();
        const data = await res.json();
        historiqueLogs.value = data.logs ?? [];
        historiqueLoaded.value = true;
    } catch {
        historiqueError.value = true;
    } finally {
        historiqueLoading.value = false;
    }
}

watch(activeTab, (tab) => {
    if (tab === 'historique' && !historiqueLoaded.value) {
        fetchHistorique();
    }
});

function ouvrirDialogRejet() {
    rejectMotif.value = '';
    rejectCommentaire.value = '';
    rejectErrors.value = {};
    showRejectDialog.value = true;
}

function fermerDialogRejet() {
    if (processing.value) return;
    showRejectDialog.value = false;
}

function soumettre() {
    processing.value = true;
    router.patch(
        `/depenses/${props.depense.id}/soumettre`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Soumise',
                    detail: 'Dépense soumise pour validation.',
                    life: 3000,
                }),
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function valider() {
    processing.value = true;
    router.patch(
        `/depenses/${props.depense.id}/valider`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Validée',
                    detail: 'Dépense validée avec succès.',
                    life: 3000,
                }),
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function rejeter() {
    rejectErrors.value = {};

    if (!rejectMotif.value) {
        rejectErrors.value.motif = 'Le motif de rejet est obligatoire.';
        return;
    }
    if (rejectMotif.value === 'Autre') {
        const trim = rejectCommentaire.value.trim();
        if (!trim) {
            rejectErrors.value.commentaire =
                'Le commentaire est obligatoire pour le motif "Autre".';
            return;
        }
        if (trim.length < 5) {
            rejectErrors.value.commentaire =
                'Le commentaire doit faire au moins 5 caractères.';
            return;
        }
    }

    processing.value = true;
    router.patch(
        `/depenses/${props.depense.id}/rejeter`,
        {
            motif_rejet: rejectMotif.value,
            commentaire_rejet:
                rejectMotif.value === 'Autre'
                    ? rejectCommentaire.value.trim()
                    : null,
        },
        {
            onSuccess: () => {
                showRejectDialog.value = false;
                toast.add({
                    severity: 'warn',
                    summary: 'Rejetée',
                    detail: 'Dépense rejetée.',
                    life: 3000,
                });
            },
            onError: (errors) => {
                if (errors.motif_rejet)
                    rejectErrors.value.motif = errors.motif_rejet;
                if (errors.commentaire_rejet)
                    rejectErrors.value.commentaire = errors.commentaire_rejet;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function supprimer() {
    if (!confirm('Supprimer cette dépense ?')) return;
    router.delete(`/depenses/${props.depense.id}`);
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('fr-GN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <Head :title="`Dépense — ${depense.type_libelle}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 sm:p-6">
            <div class="mx-auto max-w-3xl space-y-5">
                <!-- Header -->
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <Link
                            href="/depenses"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                        >
                            <ArrowLeft class="h-4 w-4" />
                        </Link>
                        <div>
                            <p
                                class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                            >
                                Détail de la dépense
                            </p>
                            <div class="mt-0.5 flex items-center gap-2">
                                <h1 class="text-xl font-semibold">
                                    {{ depense.type_libelle }}
                                </h1>
                                <StatusDot
                                    :status="depense.statut"
                                    :label="statut.label"
                                />
                            </div>
                            <p class="text-sm text-muted-foreground">
                                Saisie le
                                {{ formatDate(depense.created_at) }} par
                                {{ depense.saisi_par }}
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex shrink-0 gap-2">
                        <Button
                            v-if="depense.can_edit"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <a :href="`/depenses/${depense.id}/edit`">
                                <Edit class="mr-1 h-3.5 w-3.5" />
                                Modifier
                            </a>
                        </Button>
                        <Button
                            v-if="depense.can_submit"
                            size="sm"
                            :disabled="processing"
                            @click="soumettre"
                        >
                            <Send class="mr-1 h-3.5 w-3.5" />
                            Soumettre
                        </Button>
                        <Button
                            v-if="depense.can_validate"
                            size="sm"
                            class="bg-green-600 text-white hover:bg-green-700"
                            :disabled="processing"
                            @click="valider"
                        >
                            <CheckCircle class="mr-1 h-3.5 w-3.5" />
                            Valider
                        </Button>
                        <Button
                            v-if="depense.can_reject"
                            size="sm"
                            variant="destructive"
                            :disabled="processing"
                            @click="ouvrirDialogRejet"
                        >
                            <XCircle class="mr-1 h-3.5 w-3.5" />
                            Rejeter
                        </Button>
                        <Button
                            v-if="depense.can_delete"
                            size="sm"
                            variant="destructive"
                            :disabled="processing"
                            @click="supprimer"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="flex border-b">
                    <button
                        type="button"
                        class="px-4 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'informations'
                                ? 'border-b-2 border-primary text-primary'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="activeTab = 'informations'"
                    >
                        Informations
                    </button>
                    <button
                        type="button"
                        class="px-4 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'historique'
                                ? 'border-b-2 border-primary text-primary'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="activeTab = 'historique'"
                    >
                        Historique
                    </button>
                </div>

                <template v-if="activeTab === 'informations'">
                    <!-- Motif rejet si rejeté ou annulé avec motif -->
                    <div
                        v-if="
                            ['rejete', 'annule'].includes(depense.statut) &&
                            depense.motif_rejet
                        "
                        class="flex items-start gap-2.5 rounded-lg border border-orange-200 bg-orange-50 p-3 text-sm text-orange-700"
                    >
                        <AlertTriangle
                            class="mt-0.5 h-4 w-4 shrink-0 text-orange-600"
                        />
                        <div>
                            <p class="font-medium">Motif du rejet</p>
                            <p class="mt-0.5">{{ depense.motif_rejet }}</p>
                            <p
                                v-if="depense.commentaire_rejet"
                                class="mt-1 italic opacity-80"
                            >
                                {{ depense.commentaire_rejet }}
                            </p>
                        </div>
                    </div>

                    <!-- Impact -->
                    <div
                        class="flex items-start gap-2.5 rounded-lg border p-3 text-sm"
                        :class="impactBanner[depense.categorie]"
                    >
                        <p>{{ depense.impact_message }}</p>
                    </div>

                    <!-- Infos principales -->
                    <div class="rounded-xl border bg-card">
                        <div class="border-b px-4 py-3">
                            <h2 class="text-sm font-semibold">Informations</h2>
                        </div>
                        <dl class="divide-y">
                            <div
                                class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm"
                            >
                                <dt class="text-muted-foreground">Montant</dt>
                                <dd class="col-span-2 text-base font-semibold">
                                    {{ depense.montant_formatte }} GNF
                                </dd>
                            </div>
                            <div
                                class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm"
                            >
                                <dt class="text-muted-foreground">Date</dt>
                                <dd class="col-span-2">
                                    {{ formatDate(depense.date_depense) }}
                                </dd>
                            </div>
                            <div
                                class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm"
                            >
                                <dt class="text-muted-foreground">Concerné</dt>
                                <dd
                                    class="col-span-2 flex flex-wrap items-center gap-2"
                                >
                                    <span
                                        class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            categorieClass[depense.categorie]
                                        "
                                    >
                                        {{ depense.categorie_label }}
                                    </span>
                                    <span
                                        v-if="depense.beneficiaire_label"
                                        class="font-medium"
                                        >{{ depense.beneficiaire_label }}</span
                                    >
                                </dd>
                            </div>
                            <div
                                v-if="depense.vehicule_nom"
                                class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm"
                            >
                                <dt class="text-muted-foreground">Véhicule</dt>
                                <dd class="col-span-2">
                                    {{ depense.vehicule_nom }}
                                    <span
                                        v-if="depense.vehicule_immatriculation"
                                        class="text-muted-foreground"
                                    >
                                        — {{ depense.vehicule_immatriculation }}
                                    </span>
                                </dd>
                            </div>
                            <div
                                v-if="depense.site_nom"
                                class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm"
                            >
                                <dt class="text-muted-foreground">Site</dt>
                                <dd class="col-span-2">
                                    {{ depense.site_nom }}
                                </dd>
                            </div>
                            <div
                                v-if="depense.commentaire"
                                class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm"
                            >
                                <dt class="text-muted-foreground">
                                    Commentaire
                                </dt>
                                <dd class="col-span-2 whitespace-pre-line">
                                    {{ depense.commentaire }}
                                </dd>
                            </div>
                            <div
                                v-if="depense.validateur"
                                class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm"
                            >
                                <dt class="text-muted-foreground">
                                    Validé par
                                </dt>
                                <dd class="col-span-2">
                                    {{ depense.validateur }} —
                                    {{ formatDate(depense.date_validation) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Imputations -->
                    <div
                        v-if="depense.imputations.length > 0"
                        class="rounded-xl border bg-card"
                    >
                        <div class="border-b px-4 py-3">
                            <h2 class="text-sm font-semibold">Imputations</h2>
                        </div>
                        <div class="divide-y">
                            <div
                                v-for="imp in depense.imputations"
                                :key="imp.id"
                                class="px-4 py-3 text-sm"
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div>
                                        <p class="font-medium">
                                            {{ imp.beneficiaire_label }}
                                        </p>
                                        <p
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            {{ imp.imputation_type }}
                                            <template v-if="imp.periode_debut">
                                                —
                                                {{
                                                    formatDate(
                                                        imp.periode_debut,
                                                    )
                                                }}
                                                →
                                                {{
                                                    formatDate(imp.periode_fin)
                                                }}
                                            </template>
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold">
                                            {{
                                                Number(
                                                    imp.montant,
                                                ).toLocaleString('fr-GN')
                                            }}
                                            GNF
                                        </p>
                                        <Badge
                                            variant="outline"
                                            class="mt-0.5 text-xs"
                                            >{{ imp.statut }}</Badge
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <template v-if="activeTab === 'historique'">
                    <div class="overflow-hidden rounded-xl border bg-card">
                        <div
                            v-if="historiqueLoading"
                            class="flex items-center justify-center py-12 text-sm text-muted-foreground"
                        >
                            Chargement…
                        </div>

                        <div
                            v-else-if="historiqueError"
                            class="p-4 text-sm text-destructive"
                        >
                            Impossible de charger l'historique.
                        </div>

                        <div
                            v-else-if="historiqueLogs.length === 0"
                            class="flex flex-col items-center gap-2 py-12 text-muted-foreground"
                        >
                            <p class="text-sm">Aucune action enregistrée.</p>
                        </div>

                        <table v-else class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-muted/40">
                                    <th
                                        class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                    >
                                        Date / Heure
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                    >
                                        Action
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                    >
                                        Utilisateur
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                    >
                                        Détail
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="log in historiqueLogs"
                                    :key="log.id"
                                    class="border-b transition-colors last:border-b-0 hover:bg-muted/20"
                                >
                                    <td
                                        class="px-4 py-3 whitespace-nowrap text-muted-foreground tabular-nums"
                                    >
                                        {{ log.date }}
                                    </td>
                                    <td class="px-4 py-3 font-medium">
                                        {{ log.action }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ log.acteur }}
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">
                                        {{ log.description }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>

        <!-- Reject dialog -->
        <Dialog
            :open="showRejectDialog"
            @update:open="
                (v: boolean) => {
                    if (!v) fermerDialogRejet();
                }
            "
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle
                        class="flex items-center gap-2 text-destructive"
                    >
                        <AlertTriangle class="h-5 w-5" />
                        Rejeter la dépense
                    </DialogTitle>
                </DialogHeader>

                <div class="space-y-4 py-2">
                    <!-- Motif -->
                    <div class="space-y-1.5">
                        <Label for="show-reject-motif">
                            Motif de rejet
                            <span class="text-destructive">*</span>
                        </Label>
                        <select
                            id="show-reject-motif"
                            v-model="rejectMotif"
                            class="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <option value="" disabled>
                                Sélectionner un motif…
                            </option>
                            <option value="Non conforme">Non conforme</option>
                            <option value="Autre">Autre</option>
                        </select>
                        <p
                            v-if="rejectErrors.motif"
                            class="text-sm text-destructive"
                        >
                            {{ rejectErrors.motif }}
                        </p>
                    </div>

                    <!-- Commentaire (Autre seulement) -->
                    <div v-if="rejectMotif === 'Autre'" class="space-y-1.5">
                        <Label for="show-reject-commentaire">
                            Commentaire <span class="text-destructive">*</span>
                        </Label>
                        <textarea
                            id="show-reject-commentaire"
                            v-model="rejectCommentaire"
                            rows="3"
                            placeholder="Veuillez préciser le motif du rejet…"
                            class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                        <p
                            v-if="rejectErrors.commentaire"
                            class="text-sm text-destructive"
                        >
                            {{ rejectErrors.commentaire }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="processing"
                        @click="fermerDialogRejet"
                    >
                        Annuler
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="processing"
                        @click="rejeter"
                    >
                        <span v-if="processing">Rejet en cours…</span>
                        <span v-else>Rejeter la dépense</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
