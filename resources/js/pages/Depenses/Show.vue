<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle, Clock, Edit, Send, Trash2, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
    justificatif_path: string | null;
    date_validation: string | null;
    created_at: string;
    type_libelle: string;
    categorie: string;
    categorie_label: string;
    impact_message: string;
    vehicule_nom: string | null;
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

const statutConfig: Record<string, { label: string; class: string; icon: unknown }> = {
    brouillon: { label: 'Brouillon', class: 'bg-slate-100 text-slate-700 border-slate-200', icon: Clock },
    soumis: { label: 'Soumis', class: 'bg-blue-100 text-blue-700 border-blue-200', icon: Send },
    valide: { label: 'Validée', class: 'bg-green-100 text-green-700 border-green-200', icon: CheckCircle },
    annule: { label: 'Annulée', class: 'bg-red-100 text-red-700 border-red-200', icon: XCircle },
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

const statut = computed(() => statutConfig[props.depense.statut] ?? { label: props.depense.statut, class: '', icon: Clock });

const showRejectDialog = ref(false);
const motifRejet = ref('');
const processing = ref(false);

function soumettre() {
    processing.value = true;
    router.patch(`/depenses/${props.depense.id}/soumettre`, {}, {
        onFinish: () => { processing.value = false; },
    });
}

function valider() {
    processing.value = true;
    router.patch(`/depenses/${props.depense.id}/valider`, {}, {
        onFinish: () => { processing.value = false; },
    });
}

function rejeter() {
    processing.value = true;
    router.patch(`/depenses/${props.depense.id}/rejeter`, { motif_rejet: motifRejet.value }, {
        onFinish: () => { processing.value = false; showRejectDialog.value = false; },
    });
}

function supprimer() {
    if (!confirm('Supprimer cette dépense ?')) return;
    router.delete(`/depenses/${props.depense.id}`);
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('fr-GN', { day: '2-digit', month: 'short', year: 'numeric' });
}
</script>

<template>
    <Head :title="`Dépense — ${depense.type_libelle}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 sm:p-6">
            <div class="mx-auto max-w-3xl space-y-5">

                <!-- Header -->
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-semibold">{{ depense.type_libelle }}</h1>
                            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium" :class="statut.class">
                                <component :is="statut.icon" class="h-3 w-3" />
                                {{ statut.label }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Saisie le {{ formatDate(depense.created_at) }} par {{ depense.saisi_par }}
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="flex shrink-0 gap-2">
                        <Button v-if="depense.can_edit" variant="outline" size="sm" as-child>
                            <a :href="`/depenses/${depense.id}/edit`">
                                <Edit class="mr-1 h-3.5 w-3.5" />
                                Modifier
                            </a>
                        </Button>
                        <Button v-if="depense.can_submit" size="sm" :disabled="processing" @click="soumettre">
                            <Send class="mr-1 h-3.5 w-3.5" />
                            Soumettre
                        </Button>
                        <Button v-if="depense.can_validate" size="sm" class="bg-green-600 hover:bg-green-700 text-white" :disabled="processing" @click="valider">
                            <CheckCircle class="mr-1 h-3.5 w-3.5" />
                            Valider
                        </Button>
                        <Button v-if="depense.can_reject" size="sm" variant="destructive" :disabled="processing" @click="showRejectDialog = true">
                            <XCircle class="mr-1 h-3.5 w-3.5" />
                            Rejeter
                        </Button>
                        <Button v-if="depense.can_delete" size="sm" variant="destructive" :disabled="processing" @click="supprimer">
                            <Trash2 class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>

                <!-- Motif rejet si annulé -->
                <div v-if="depense.statut === 'annule' && depense.motif_rejet" class="flex items-start gap-2.5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <p class="font-medium">Motif du rejet</p>
                        <p class="mt-0.5">{{ depense.motif_rejet }}</p>
                    </div>
                </div>

                <!-- Impact -->
                <div class="flex items-start gap-2.5 rounded-lg border p-3 text-sm" :class="impactBanner[depense.categorie]">
                    <p>{{ depense.impact_message }}</p>
                </div>

                <!-- Infos principales -->
                <div class="rounded-xl border bg-card">
                    <div class="px-4 py-3 border-b">
                        <h2 class="text-sm font-semibold">Informations</h2>
                    </div>
                    <dl class="divide-y">
                        <div class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm">
                            <dt class="text-muted-foreground">Montant</dt>
                            <dd class="col-span-2 font-semibold text-base">{{ depense.montant_formatte }} GNF</dd>
                        </div>
                        <div class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm">
                            <dt class="text-muted-foreground">Date</dt>
                            <dd class="col-span-2">{{ formatDate(depense.date_depense) }}</dd>
                        </div>
                        <div class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm">
                            <dt class="text-muted-foreground">Concerné</dt>
                            <dd class="col-span-2 flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium" :class="categorieClass[depense.categorie]">
                                    {{ depense.categorie_label }}
                                </span>
                                <span v-if="depense.beneficiaire_label" class="font-medium">{{ depense.beneficiaire_label }}</span>
                            </dd>
                        </div>
                        <div v-if="depense.vehicule_nom" class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm">
                            <dt class="text-muted-foreground">Véhicule</dt>
                            <dd class="col-span-2">{{ depense.vehicule_nom }}</dd>
                        </div>
                        <div v-if="depense.site_nom" class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm">
                            <dt class="text-muted-foreground">Site</dt>
                            <dd class="col-span-2">{{ depense.site_nom }}</dd>
                        </div>
                        <div v-if="depense.commentaire" class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm">
                            <dt class="text-muted-foreground">Commentaire</dt>
                            <dd class="col-span-2 whitespace-pre-line">{{ depense.commentaire }}</dd>
                        </div>
                        <div v-if="depense.validateur" class="grid grid-cols-3 gap-1 px-4 py-2.5 text-sm">
                            <dt class="text-muted-foreground">Validé par</dt>
                            <dd class="col-span-2">{{ depense.validateur }} — {{ formatDate(depense.date_validation) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Imputations -->
                <div v-if="depense.imputations.length > 0" class="rounded-xl border bg-card">
                    <div class="px-4 py-3 border-b">
                        <h2 class="text-sm font-semibold">Imputations</h2>
                    </div>
                    <div class="divide-y">
                        <div v-for="imp in depense.imputations" :key="imp.id" class="px-4 py-3 text-sm">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium">{{ imp.beneficiaire_label }}</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ imp.imputation_type }}
                                        <template v-if="imp.periode_debut">
                                            — {{ formatDate(imp.periode_debut) }} → {{ formatDate(imp.periode_fin) }}
                                        </template>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold">{{ Number(imp.montant).toLocaleString('fr-GN') }} GNF</p>
                                    <Badge variant="outline" class="mt-0.5 text-xs">{{ imp.statut }}</Badge>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Reject dialog -->
        <Teleport to="body">
            <div v-if="showRejectDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div class="w-full max-w-md rounded-xl border bg-card p-5 shadow-xl space-y-4">
                    <h3 class="text-base font-semibold">Motif de rejet</h3>
                    <textarea
                        v-model="motifRejet"
                        rows="3"
                        placeholder="Expliquer le motif du rejet…"
                        class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <div class="flex justify-end gap-2">
                        <Button variant="outline" size="sm" @click="showRejectDialog = false">Annuler</Button>
                        <Button size="sm" variant="destructive" :disabled="!motifRejet.trim() || processing" @click="rejeter">
                            Confirmer le rejet
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
