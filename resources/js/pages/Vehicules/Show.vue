<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Car,
    CheckCircle,
    Pencil,
    Trash2,
} from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed } from 'vue';

interface EquipeMembre {
    livreur_nom: string | null;
    taux_commission: number;
    role: string;
}

interface Frais {
    id: number;
    montant: number;
    type: string;
    commentaire: string | null;
}

interface VehiculeData {
    id: number;
    nom_vehicule: string;
    immatriculation: string;
    type_label: string;
    type_vehicule: string | null;
    capacite_packs: number | null;
    proprietaire_id: number | null;
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    equipe_livraison_id: number | null;
    equipe_nom: string | null;
    livreur_principal_nom: string | null;
    equipe_membres: EquipeMembre[];
    taux_commission_proprietaire: number;
    frais: Frais[];
    frais_total: number;
    pris_en_charge_par_usine: boolean;
    photo_url: string | null;
    is_active: boolean;
}

const props = defineProps<{ vehicule: VehiculeData }>();

const { can } = usePermissions();
const page = usePage();
const flashSuccess = computed(
    () => (page.props as { flash?: { success?: string } }).flash?.success,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
    { title: props.vehicule.nom_vehicule, href: '#' },
];

const typesFraisOptions = [
    { value: 'carburant', label: 'Carburant' },
    { value: 'reparation', label: 'Réparation' },
    { value: 'autre', label: 'Autre' },
];

const typesFraisColors: Record<string, string> = {
    carburant: 'bg-blue-50 text-blue-700',
    reparation: 'bg-orange-50 text-orange-700',
    autre: 'bg-muted text-muted-foreground',
};

const typesFraisLabels: Record<string, string> = {
    carburant: 'Carburant',
    reparation: 'Réparation',
    autre: 'Autre',
};

const addForm = useForm({
    montant: null as number | null,
    type: null as string | null,
    commentaire: null as string | null,
});

function submitAdd() {
    addForm.post(`/vehicules/${props.vehicule.id}/frais`, {
        onSuccess: () => {
            addForm.reset();
        },
    });
}

function deleteFrais(frais: Frais) {
    router.delete(`/vehicules/${props.vehicule.id}/frais/${frais.id}`);
}

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}
</script>

<template>
    <Head :title="`${vehicule.nom_vehicule} — Détail`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/vehicules"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        {{ vehicule.nom_vehicule }}
                    </h1>
                    <p class="font-mono text-[11px] text-muted-foreground">
                        {{ vehicule.immatriculation }}
                    </p>
                </div>
                <Link
                    v-if="can('vehicules.update')"
                    :href="`/vehicules/${vehicule.id}/edit`"
                    class="absolute right-4"
                >
                    <Button size="sm" variant="outline" class="h-8 px-3 text-xs gap-1.5">
                        <Pencil class="h-3.5 w-3.5" />
                        Modifier
                    </Button>
                </Link>
            </div>
        </div>

        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
            <!-- Flash success -->
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <!-- Header desktop -->
            <div class="hidden items-start justify-between gap-6 sm:flex">
                <div class="flex items-center gap-5">
                    <!-- Photo -->
                    <div
                        class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-muted/30"
                    >
                        <img
                            v-if="vehicule.photo_url"
                            :src="vehicule.photo_url"
                            :alt="vehicule.nom_vehicule"
                            class="h-full w-full object-cover"
                        />
                        <Car
                            v-else
                            class="h-10 w-10 text-muted-foreground/30"
                        />
                    </div>
                    <!-- Title -->
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-semibold tracking-tight">
                                {{ vehicule.nom_vehicule }}
                            </h1>
                            <StatusDot
                                :label="vehicule.is_active ? 'Actif' : 'Inactif'"
                                :dot-class="
                                    vehicule.is_active
                                        ? 'bg-emerald-500'
                                        : 'bg-zinc-400 dark:bg-zinc-500'
                                "
                                class="text-sm text-muted-foreground"
                            />
                        </div>
                        <p class="mt-0.5 font-mono text-sm text-muted-foreground">
                            {{ vehicule.immatriculation }}
                        </p>
                        <div class="mt-1.5 flex items-center gap-2">
                            <span
                                class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium"
                            >
                                {{ vehicule.type_label }}
                            </span>
                            <span
                                v-if="vehicule.capacite_packs"
                                class="text-xs text-muted-foreground"
                            >
                                {{ vehicule.capacite_packs }} packs
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Link href="/vehicules">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                    <Link
                        v-if="can('vehicules.update')"
                        :href="`/vehicules/${vehicule.id}/edit`"
                    >
                        <Button size="sm">
                            <Pencil class="mr-1.5 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Cards grid -->
            <div class="grid gap-4 sm:grid-cols-2 sm:gap-6">
                <!-- Affectation -->
                <div class="rounded-xl border bg-card p-4 sm:p-5">
                    <h3
                        class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Affectation
                    </h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs text-muted-foreground">Équipe</dt>
                            <dd class="mt-0.5 text-sm font-medium">
                                {{ vehicule.equipe_nom ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Propriétaire</dt>
                            <dd class="mt-0.5 text-sm font-medium">
                                {{ vehicule.proprietaire_nom ?? '—' }}
                            </dd>
                            <dd
                                v-if="vehicule.proprietaire_telephone"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ vehicule.proprietaire_telephone }}
                            </dd>
                        </div>
                        <div v-if="vehicule.equipe_membres.length">
                            <dt class="mb-1.5 text-xs text-muted-foreground">
                                Membres de l'équipe
                            </dt>
                            <dd>
                                <div class="space-y-1">
                                    <div
                                        v-for="(m, i) in vehicule.equipe_membres"
                                        :key="i"
                                        class="flex items-center justify-between rounded-md bg-muted/40 px-2.5 py-1.5 text-xs"
                                    >
                                        <span class="font-medium">
                                            {{ m.livreur_nom ?? '—' }}
                                        </span>
                                        <span class="text-muted-foreground">
                                            <span
                                                v-if="m.role === 'principal'"
                                                class="mr-1.5 rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary"
                                                >Principal</span
                                            >
                                            {{ m.taux_commission }}%
                                        </span>
                                    </div>
                                </div>
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Commission -->
                <div class="rounded-xl border bg-card p-4 sm:p-5">
                    <h3
                        class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Commission
                    </h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Taux propriétaire
                            </dt>
                            <dd class="mt-0.5 text-sm font-semibold tabular-nums">
                                {{ vehicule.taux_commission_proprietaire }}%
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Pris en charge par l'usine
                            </dt>
                            <dd class="mt-0.5">
                                <span
                                    v-if="vehicule.pris_en_charge_par_usine"
                                    class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                                    >Oui</span
                                >
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                                    >Non</span
                                >
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Frais propriétaire -->
            <div class="rounded-xl border bg-card p-4 sm:p-5">
                <!-- Header -->
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Frais propriétaire
                        </h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Déduits automatiquement lors du premier versement.
                        </p>
                    </div>
                    <span
                        v-if="vehicule.frais.length"
                        class="rounded-lg bg-amber-50 px-3 py-1 text-sm font-semibold tabular-nums text-amber-700"
                    >
                        Total : {{ formatGNF(vehicule.frais_total) }}
                    </span>
                </div>

                <!-- Liste des frais -->
                <div class="mb-4 space-y-2">
                    <div
                        v-for="f in vehicule.frais"
                        :key="f.id"
                        class="flex items-center gap-3 rounded-lg border bg-muted/20 px-3 py-2 text-sm"
                    >
                        <span
                            class="tabular-nums font-semibold"
                        >{{ formatGNF(f.montant) }}</span>
                        <span
                            :class="[
                                'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                typesFraisColors[f.type] ?? 'bg-muted text-muted-foreground',
                            ]"
                        >{{ typesFraisLabels[f.type] ?? f.type }}</span>
                        <span
                            v-if="f.commentaire"
                            class="flex-1 truncate text-xs text-muted-foreground"
                        >{{ f.commentaire }}</span>
                        <span v-else class="flex-1" />
                        <button
                            v-if="can('vehicules.update')"
                            type="button"
                            class="shrink-0 text-muted-foreground/50 transition-colors hover:text-destructive"
                            title="Supprimer"
                            @click="deleteFrais(f)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </button>
                    </div>
                    <p
                        v-if="!vehicule.frais.length"
                        class="text-xs text-muted-foreground italic"
                    >
                        Aucun frais enregistré.
                    </p>
                </div>

                <!-- Formulaire d'ajout -->
                <form
                    v-if="can('vehicules.update')"
                    class="border-t pt-4"
                    @submit.prevent="submitAdd"
                >
                    <p class="mb-2 text-xs font-medium text-muted-foreground">
                        Ajouter un frais
                    </p>
                    <div class="flex flex-wrap items-end gap-3">
                        <!-- Montant -->
                        <div class="min-w-[140px] flex-1">
                            <Label for="add_montant" class="mb-1 block text-xs">
                                Montant
                            </Label>
                            <InputNumber
                                input-id="add_montant"
                                v-model="addForm.montant"
                                :min="0.01"
                                :use-grouping="true"
                                locale="fr-FR"
                                suffix=" GNF"
                                class="w-full"
                                input-class="w-full h-9 text-sm"
                                :class="{ 'p-invalid': addForm.errors.montant }"
                                placeholder="0 GNF"
                            />
                            <p
                                v-if="addForm.errors.montant"
                                class="mt-1 text-xs text-destructive"
                            >{{ addForm.errors.montant }}</p>
                        </div>

                        <!-- Type -->
                        <div class="min-w-[140px] flex-1">
                            <Label for="add_type" class="mb-1 block text-xs">
                                Type <span class="text-destructive">*</span>
                            </Label>
                            <Dropdown
                                input-id="add_type"
                                v-model="addForm.type"
                                @update:model-value="(v) => { addForm.type = v; if (v !== 'autre') addForm.commentaire = null; }"
                                :options="typesFraisOptions"
                                option-label="label"
                                option-value="value"
                                placeholder="Type…"
                                class="w-full"
                                :class="{ 'p-invalid': addForm.errors.type }"
                            />
                            <p
                                v-if="addForm.errors.type"
                                class="mt-1 text-xs text-destructive"
                            >{{ addForm.errors.type }}</p>
                        </div>

                        <!-- Commentaire (type = autre) -->
                        <div
                            v-if="addForm.type === 'autre'"
                            class="min-w-[160px] flex-[2]"
                        >
                            <Label for="add_commentaire" class="mb-1 block text-xs">
                                Commentaire <span class="text-destructive">*</span>
                            </Label>
                            <InputText
                                id="add_commentaire"
                                v-model="addForm.commentaire"
                                :maxlength="150"
                                placeholder="Motif…"
                                class="w-full"
                                input-class="h-9 text-sm"
                                :class="{ 'p-invalid': addForm.errors.commentaire }"
                            />
                            <p
                                v-if="addForm.errors.commentaire"
                                class="mt-1 text-xs text-destructive"
                            >{{ addForm.errors.commentaire }}</p>
                        </div>

                        <!-- Bouton -->
                        <Button
                            type="submit"
                            size="sm"
                            class="h-9 shrink-0"
                            :disabled="addForm.processing || !addForm.montant || !addForm.type"
                        >
                            {{ addForm.processing ? '…' : '+ Ajouter' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
