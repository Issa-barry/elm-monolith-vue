<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Car,
    CheckCircle,
    Pencil,
    TriangleAlert,
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
    frais_proprietaire_montant: number;
    frais_proprietaire_type: string | null;
    frais_proprietaire_commentaire: string | null;
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

const typesFraisLabels: Record<string, string> = {
    carburant: 'Carburant',
    reparation: 'Réparation',
    autre: 'Autre',
};

const fraisForm = useForm({
    frais_proprietaire_montant: props.vehicule.frais_proprietaire_montant,
    frais_proprietaire_type: props.vehicule.frais_proprietaire_type,
    frais_proprietaire_commentaire: props.vehicule.frais_proprietaire_commentaire,
});

function submitFrais() {
    fraisForm.patch(`/vehicules/${props.vehicule.id}/frais`);
}

const hasFrais = computed(() => props.vehicule.frais_proprietaire_montant > 0);

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
                <div class="mb-4 flex items-start justify-between">
                    <div>
                        <h3
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Frais propriétaire
                        </h3>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Déduits automatiquement de la part propriétaire lors
                            du premier versement.
                        </p>
                    </div>
                    <!-- Badge solde actuel -->
                    <div
                        v-if="hasFrais"
                        class="flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-1.5 text-sm font-semibold text-amber-700"
                    >
                        <TriangleAlert class="h-3.5 w-3.5" />
                        {{ formatGNF(vehicule.frais_proprietaire_montant) }}
                        <span class="text-xs font-normal text-amber-600">
                            ({{
                                vehicule.frais_proprietaire_type
                                    ? typesFraisLabels[vehicule.frais_proprietaire_type]
                                    : '—'
                            }})
                        </span>
                    </div>
                </div>

                <form
                    v-if="can('vehicules.update')"
                    class="grid gap-4 sm:grid-cols-2"
                    @submit.prevent="submitFrais"
                >
                    <!-- Montant -->
                    <div>
                        <Label for="frais_montant" class="mb-1.5 block">
                            Montant des frais
                        </Label>
                        <InputNumber
                            input-id="frais_montant"
                            v-model="fraisForm.frais_proprietaire_montant"
                            @update:model-value="
                                (v) => {
                                    fraisForm.frais_proprietaire_montant =
                                        v ?? 0;
                                    if ((v ?? 0) <= 0) {
                                        fraisForm.frais_proprietaire_type =
                                            null;
                                        fraisForm.frais_proprietaire_commentaire =
                                            null;
                                    }
                                }
                            "
                            :min="0"
                            :use-grouping="true"
                            locale="fr-FR"
                            suffix=" GNF"
                            class="w-full"
                            input-class="w-full"
                            :class="{
                                'p-invalid':
                                    fraisForm.errors
                                        .frais_proprietaire_montant,
                            }"
                        />
                        <p
                            v-if="fraisForm.errors.frais_proprietaire_montant"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ fraisForm.errors.frais_proprietaire_montant }}
                        </p>
                    </div>

                    <!-- Type -->
                    <div v-if="fraisForm.frais_proprietaire_montant > 0">
                        <Label for="frais_type" class="mb-1.5 block">
                            Type <span class="text-destructive">*</span>
                        </Label>
                        <Dropdown
                            input-id="frais_type"
                            v-model="fraisForm.frais_proprietaire_type"
                            @update:model-value="
                                (v) => {
                                    fraisForm.frais_proprietaire_type = v;
                                    if (v !== 'autre')
                                        fraisForm.frais_proprietaire_commentaire =
                                            null;
                                }
                            "
                            :options="typesFraisOptions"
                            option-label="label"
                            option-value="value"
                            placeholder="Sélectionner…"
                            class="w-full"
                            :class="{
                                'p-invalid':
                                    fraisForm.errors.frais_proprietaire_type,
                            }"
                        />
                        <p
                            v-if="fraisForm.errors.frais_proprietaire_type"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ fraisForm.errors.frais_proprietaire_type }}
                        </p>
                    </div>

                    <!-- Commentaire (type = autre) -->
                    <div
                        v-if="fraisForm.frais_proprietaire_type === 'autre'"
                        class="sm:col-span-2"
                    >
                        <Label for="frais_commentaire" class="mb-1.5 block">
                            Commentaire <span class="text-destructive">*</span>
                            <span
                                class="ml-1 font-normal text-muted-foreground"
                            >
                                ({{
                                    (
                                        fraisForm.frais_proprietaire_commentaire ??
                                        ''
                                    ).length
                                }}/150)
                            </span>
                        </Label>
                        <InputText
                            id="frais_commentaire"
                            v-model="fraisForm.frais_proprietaire_commentaire"
                            :maxlength="150"
                            placeholder="Précisez le motif des frais…"
                            class="w-full"
                            :class="{
                                'p-invalid':
                                    fraisForm.errors
                                        .frais_proprietaire_commentaire,
                            }"
                        />
                        <p
                            v-if="
                                fraisForm.errors.frais_proprietaire_commentaire
                            "
                            class="mt-1 text-xs text-destructive"
                        >
                            {{
                                fraisForm.errors.frais_proprietaire_commentaire
                            }}
                        </p>
                    </div>

                    <!-- Submit -->
                    <div class="flex items-center gap-3 sm:col-span-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="fraisForm.processing"
                        >
                            {{ fraisForm.processing ? 'Enregistrement…' : 'Enregistrer les frais' }}
                        </Button>
                        <Button
                            v-if="hasFrais"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:text-destructive"
                            :disabled="fraisForm.processing"
                            @click="
                                () => {
                                    fraisForm.frais_proprietaire_montant = 0;
                                    fraisForm.frais_proprietaire_type = null;
                                    fraisForm.frais_proprietaire_commentaire =
                                        null;
                                    submitFrais();
                                }
                            "
                        >
                            Effacer les frais
                        </Button>
                    </div>
                </form>

                <!-- Read-only pour les non-éditeurs -->
                <div v-else class="text-sm text-muted-foreground">
                    <template v-if="hasFrais">
                        <span class="font-medium text-foreground">{{
                            formatGNF(vehicule.frais_proprietaire_montant)
                        }}</span>
                        —
                        {{
                            vehicule.frais_proprietaire_type
                                ? typesFraisLabels[
                                      vehicule.frais_proprietaire_type
                                  ]
                                : '—'
                        }}
                        <span
                            v-if="vehicule.frais_proprietaire_commentaire"
                            class="ml-1 text-muted-foreground/70"
                            >({{ vehicule.frais_proprietaire_commentaire }})</span
                        >
                    </template>
                    <span v-else>Aucun frais enregistré.</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
