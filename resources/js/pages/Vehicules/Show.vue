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
    CalendarClock,
    Car,
    CheckCircle,
    Droplets,
    Pencil,
    Plus,
    Tag,
    Trash2,
    UserRound,
    Wrench,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';

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
    created_at: string | null;
    createur_nom: string | null;
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
const confirm = useConfirm();
const page = usePage();
const flashSuccess = computed(
    () => (page.props as { flash?: { success?: string } }).flash?.success,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
    { title: props.vehicule.nom_vehicule, href: '#' },
];

// ── Frais : types ─────────────────────────────────────────────────────────────

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

// Badge colors (pill texte)
const typesBadgeClass: Record<string, string> = {
    carburant: 'bg-blue-50 text-blue-700',
    reparation: 'bg-orange-50 text-orange-700',
    autre: 'bg-muted text-muted-foreground',
};

// Avatar circle colors
const typesAvatarClass: Record<string, string> = {
    carburant: 'bg-blue-100 text-blue-600',
    reparation: 'bg-orange-100 text-orange-600',
    autre: 'bg-muted text-muted-foreground',
};

// Icons
const typesIcons: Record<string, typeof Droplets> = {
    carburant: Droplets,
    reparation: Wrench,
    autre: Tag,
};

// ── Frais : modal ajout ───────────────────────────────────────────────────────

const showFraisModal = ref(false);

const addForm = useForm({
    montant: null as number | null,
    type: null as string | null,
    commentaire: null as string | null,
});

function openModal() {
    addForm.reset();
    addForm.clearErrors();
    showFraisModal.value = true;
}

function submitAdd() {
    addForm.post(`/vehicules/${props.vehicule.id}/frais`, {
        onSuccess: () => {
            showFraisModal.value = false;
            addForm.reset();
        },
    });
}

// ── Frais : modal modification ────────────────────────────────────────────────

const showEditModal = ref(false);
const editingFrais = ref<Frais | null>(null);

const editForm = useForm({
    montant: null as number | null,
    type: null as string | null,
    commentaire: null as string | null,
});

function openEditModal(frais: Frais) {
    editingFrais.value = frais;
    editForm.montant = frais.montant;
    editForm.type = frais.type;
    editForm.commentaire = frais.commentaire;
    editForm.clearErrors();
    showEditModal.value = true;
}

function submitEdit() {
    if (!editingFrais.value) return;
    editForm.patch(`/vehicules/${props.vehicule.id}/frais/${editingFrais.value.id}`, {
        onSuccess: () => {
            showEditModal.value = false;
            editingFrais.value = null;
        },
    });
}

// ── Frais : suppression ───────────────────────────────────────────────────────

function confirmDeleteFrais(frais: Frais) {
    confirm.require({
        message: `Supprimer ce frais de ${formatGNF(frais.montant)} ?`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/vehicules/${props.vehicule.id}/frais/${frais.id}`);
        },
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────

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
                        <Car v-else class="h-10 w-10 text-muted-foreground/30" />
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

            <!-- ── Frais propriétaire ──────────────────────────────────────── -->
            <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                <!-- En-tête section -->
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h3
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Frais propriétaire
                        </h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Déduits automatiquement lors du premier versement.
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span
                            v-if="vehicule.frais.length"
                            class="rounded-lg bg-amber-50 px-3 py-1 text-sm font-semibold tabular-nums text-amber-700"
                        >
                            Total : {{ formatGNF(vehicule.frais_total) }}
                        </span>
                        <Button
                            v-if="can('vehicules.update')"
                            type="button"
                            size="sm"
                            @click="openModal"
                        >
                            <Plus class="mr-1.5 h-3.5 w-3.5" />
                            Ajouter un frais
                        </Button>
                    </div>
                </div>

                <!-- État vide -->
                <div
                    v-if="!vehicule.frais.length"
                    class="rounded-lg border border-dashed py-10 text-center"
                >
                    <p class="text-sm text-muted-foreground">Aucun frais enregistré.</p>
                    <Button
                        v-if="can('vehicules.update')"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-3"
                        @click="openModal"
                    >
                        <Plus class="mr-1.5 h-3.5 w-3.5" />
                        Ajouter le premier frais
                    </Button>
                </div>

                <!-- Liste des frais -->
                <div v-else class="divide-y rounded-lg border">
                    <div
                        v-for="f in vehicule.frais"
                        :key="f.id"
                        class="flex items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/30"
                    >
                        <!-- Icône type (avatar) -->
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                            :class="typesAvatarClass[f.type] ?? 'bg-muted text-muted-foreground'"
                        >
                            <component :is="typesIcons[f.type] ?? Tag" class="h-4 w-4" />
                        </div>

                        <!-- Montant + commentaire -->
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold tabular-nums">
                                {{ formatGNF(f.montant) }}
                            </div>
                            <div
                                v-if="f.commentaire"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ f.commentaire }}
                            </div>
                        </div>

                        <!-- Badge type -->
                        <span
                            class="shrink-0 rounded-sm px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                            :class="typesBadgeClass[f.type] ?? 'bg-muted text-muted-foreground'"
                        >
                            {{ typesFraisLabels[f.type] ?? f.type }}
                        </span>

                        <!-- Date + créateur -->
                        <div class="hidden shrink-0 text-right sm:block">
                            <div
                                v-if="f.created_at"
                                class="flex items-center justify-end gap-1 text-[11px] text-muted-foreground"
                            >
                                <CalendarClock class="h-3 w-3 shrink-0" />
                                {{ f.created_at }}
                            </div>
                            <div
                                v-if="f.createur_nom"
                                class="flex items-center justify-end gap-1 text-[11px] text-muted-foreground"
                            >
                                <UserRound class="h-3 w-3 shrink-0" />
                                {{ f.createur_nom }}
                            </div>
                        </div>

                        <!-- Actions : crayon (admin) + corbeille -->
                        <div v-if="can('vehicules.update')" class="flex shrink-0 gap-0.5">
                            <button
                                type="button"
                                title="Modifier"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                @click="openEditModal(f)"
                            >
                                <Pencil class="h-3.5 w-3.5" />
                            </button>
                            <button
                                type="button"
                                title="Supprimer"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                @click="confirmDeleteFrais(f)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Dialog : Modifier un frais ───────────────────────────────────── -->
        <Dialog
            v-model:visible="showEditModal"
            modal
            header="Modifier le frais"
            :style="{ width: 'min(420px, 95vw)' }"
            :dismissable-mask="true"
            :pt="{ content: { style: 'overflow: visible' } }"
        >
            <div class="space-y-4 pt-2 pb-1">
                <div>
                    <Label for="edit-montant" class="mb-1 block text-xs font-medium">
                        Montant <span class="text-destructive">*</span>
                    </Label>
                    <InputNumber
                        input-id="edit-montant"
                        v-model="editForm.montant"
                        :min="0.01"
                        :use-grouping="true"
                        locale="fr-FR"
                        suffix=" GNF"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': editForm.errors.montant }"
                        autofocus
                    />
                    <p v-if="editForm.errors.montant" class="mt-1 text-xs text-destructive">
                        {{ editForm.errors.montant }}
                    </p>
                </div>
                <div>
                    <Label for="edit-type" class="mb-1 block text-xs font-medium">
                        Type <span class="text-destructive">*</span>
                    </Label>
                    <Dropdown
                        input-id="edit-type"
                        v-model="editForm.type"
                        :options="typesFraisOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un type…"
                        class="w-full"
                        :class="{ 'p-invalid': editForm.errors.type }"
                        @update:model-value="(v) => { editForm.type = v; if (v !== 'autre') editForm.commentaire = null; }"
                    />
                    <p v-if="editForm.errors.type" class="mt-1 text-xs text-destructive">
                        {{ editForm.errors.type }}
                    </p>
                </div>
                <div v-if="editForm.type === 'autre'">
                    <Label for="edit-commentaire" class="mb-1 block text-xs font-medium">
                        Commentaire <span class="text-destructive">*</span>
                    </Label>
                    <InputText
                        id="edit-commentaire"
                        v-model="editForm.commentaire"
                        :maxlength="150"
                        placeholder="Motif…"
                        class="w-full"
                        :class="{ 'p-invalid': editForm.errors.commentaire }"
                    />
                    <p v-if="editForm.errors.commentaire" class="mt-1 text-xs text-destructive">
                        {{ editForm.errors.commentaire }}
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="showEditModal = false"
                    >
                        Annuler
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="editForm.processing || !editForm.montant || !editForm.type"
                        @click="submitEdit"
                    >
                        {{ editForm.processing ? '…' : 'Enregistrer' }}
                    </Button>
                </div>
            </template>
        </Dialog>

        <!-- ── Dialog : Ajouter un frais ──────────────────────────────────── -->
        <Dialog
            v-model:visible="showFraisModal"
            modal
            header="Ajouter un frais"
            :style="{ width: 'min(420px, 95vw)' }"
            :dismissable-mask="true"
            :pt="{ content: { style: 'overflow: visible' } }"
        >
            <div class="space-y-4 pt-2 pb-1">
                <!-- Montant -->
                <div>
                    <Label for="frais-montant" class="mb-1 block text-xs font-medium">
                        Montant <span class="text-destructive">*</span>
                    </Label>
                    <InputNumber
                        input-id="frais-montant"
                        v-model="addForm.montant"
                        :min="0.01"
                        :use-grouping="true"
                        locale="fr-FR"
                        suffix=" GNF"
                        class="w-full"
                        input-class="w-full"
                        :class="{ 'p-invalid': addForm.errors.montant }"
                        placeholder="0 GNF"
                        autofocus
                    />
                    <p v-if="addForm.errors.montant" class="mt-1 text-xs text-destructive">
                        {{ addForm.errors.montant }}
                    </p>
                </div>

                <!-- Type -->
                <div>
                    <Label for="frais-type" class="mb-1 block text-xs font-medium">
                        Type <span class="text-destructive">*</span>
                    </Label>
                    <Dropdown
                        input-id="frais-type"
                        v-model="addForm.type"
                        :options="typesFraisOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner un type…"
                        class="w-full"
                        :class="{ 'p-invalid': addForm.errors.type }"
                        @update:model-value="(v) => { addForm.type = v; if (v !== 'autre') addForm.commentaire = null; }"
                    />
                    <p v-if="addForm.errors.type" class="mt-1 text-xs text-destructive">
                        {{ addForm.errors.type }}
                    </p>
                </div>

                <!-- Commentaire (type = autre) -->
                <div v-if="addForm.type === 'autre'">
                    <Label for="frais-commentaire" class="mb-1 block text-xs font-medium">
                        Commentaire <span class="text-destructive">*</span>
                    </Label>
                    <InputText
                        id="frais-commentaire"
                        v-model="addForm.commentaire"
                        :maxlength="150"
                        placeholder="Motif…"
                        class="w-full"
                        :class="{ 'p-invalid': addForm.errors.commentaire }"
                    />
                    <p v-if="addForm.errors.commentaire" class="mt-1 text-xs text-destructive">
                        {{ addForm.errors.commentaire }}
                    </p>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="showFraisModal = false"
                    >
                        Annuler
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="addForm.processing || !addForm.montant || !addForm.type"
                        @click="submitAdd"
                    >
                        {{ addForm.processing ? '…' : 'Ajouter' }}
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
