<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatPhoneDisplay } from '@/lib/utils';
import { AlertTriangle, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import MembreModal, { type MembreFormData } from './MembreModal.vue';

interface Membre extends MembreFormData {}

interface FormData {
    nom: string;
    is_active: boolean;
    membres: Membre[];
    errors?: Record<string, string>;
    processing?: boolean;
}

const props = defineProps<{ form: FormData }>();
const emit = defineEmits<{ submit: [] }>();

const confirm = useConfirm();

// ── Modal état ────────────────────────────────────────────────────────────────

const showModal = ref(false);
const editingIndex = ref<number | null>(null); // null = nouveau membre

const membreEnEdition = computed<MembreFormData | null>(() =>
    editingIndex.value !== null ? props.form.membres[editingIndex.value] : null,
);

function openNewMembre() {
    editingIndex.value = null;
    showModal.value = true;
}

function openEditMembre(index: number) {
    editingIndex.value = index;
    showModal.value = true;
}

// ── Computed ──────────────────────────────────────────────────────────────────

const sommeTaux = computed(() =>
    props.form.membres.reduce((s, m) => s + (m.taux_commission || 0), 0),
);

const principalIndex = computed(() =>
    props.form.membres.findIndex((m) => m.role === 'principal'),
);

const hasPrincipal = computed(() => principalIndex.value >= 0);

const principalWarning = computed(() => {
    const count = props.form.membres.filter(
        (m) => m.role === 'principal',
    ).length;
    if (count === 0)
        return "L'équipe doit avoir exactement un livreur principal.";
    if (count > 1)
        return "L'équipe ne peut avoir qu'un seul livreur principal.";
    return null;
});

const tauxWarning = computed(() => {
    if (props.form.membres.some((m) => Number(m.taux_commission) < 0)) {
        return 'Le taux de commission ne peut pas être négatif.';
    }

    if (sommeTaux.value > 100) {
        return "La somme des taux de l'équipe ne doit pas dépasser 100 %.";
    }

    return null;
});

const maxTauxDisponible = computed(() => {
    const totalSansMembreEdite = props.form.membres.reduce(
        (sum, membre, index) => {
            if (editingIndex.value !== null && index === editingIndex.value)
                return sum;
            const taux = Number(membre.taux_commission);
            return sum + (Number.isFinite(taux) ? taux : 0);
        },
        0,
    );

    return Math.max(0, Number((100 - totalSansMembreEdite).toFixed(2)));
});

// ── Gestion des membres ───────────────────────────────────────────────────────

function onMembreConfirm(data: MembreFormData) {
    const newIsPrincipal = data.role === 'principal';
    const existingPrincipalIdx = props.form.membres.findIndex(
        (m, i) => m.role === 'principal' && i !== editingIndex.value,
    );

    if (newIsPrincipal && existingPrincipalIdx >= 0) {
        // Conflit : un principal existe déjà
        const existing = props.form.membres[existingPrincipalIdx];
        confirm.require({
            message: `Remplacer « ${existing.prenom} ${existing.nom} » comme principal par « ${data.prenom} ${data.nom} » ?`,
            header: 'Remplacer le principal ?',
            icon: 'pi pi-exclamation-triangle',
            rejectLabel: 'Annuler',
            acceptLabel: 'Remplacer',
            accept: () => {
                // Rétrograder l'ancien principal en assistant
                props.form.membres[existingPrincipalIdx].role = 'assistant';
                applyMembreData(data);
            },
        });
    } else {
        applyMembreData(data);
    }
}

function applyMembreData(data: MembreFormData) {
    if (editingIndex.value !== null) {
        // Mise à jour
        Object.assign(props.form.membres[editingIndex.value], data);
    } else {
        // Ajout
        props.form.membres.push({
            ...data,
            ordre: props.form.membres.length,
        });
    }
    // Rafraîchir les ordres
    props.form.membres.forEach((m, i) => (m.ordre = i));
}

function removeMembre(index: number) {
    props.form.membres.splice(index, 1);
    props.form.membres.forEach((m, i) => (m.ordre = i));
}

// ── Affichage ─────────────────────────────────────────────────────────────────

function initiales(prenom: string, nom: string): string {
    const p = prenom.trim()[0] ?? '';
    const n = nom.trim()[0] ?? '';
    return (p + n).toUpperCase();
}

// ── Submit ────────────────────────────────────────────────────────────────────

function handleSubmit() {
    if (principalWarning.value || tauxWarning.value) return;
    emit('submit');
}
</script>

<template>
    <form class="space-y-4 sm:space-y-6" @submit.prevent="handleSubmit">
        <!-- Identification -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Identification
            </h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <Label for="nom" class="mb-1.5 block">
                        Nom de l'équipe <span class="text-destructive">*</span>
                    </Label>
                    <input
                        id="nom"
                        v-model="form.nom"
                        type="text"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="{ 'border-destructive': form.errors?.nom }"
                        placeholder="Ex: Équipe Centre-ville"
                    />
                    <p
                        v-if="form.errors?.nom"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.nom }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <h4
                        class="mb-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Statut
                    </h4>
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="is_active"
                            :model-value="Boolean(form.is_active)"
                            @update:model-value="
                                form.is_active = $event === true
                            "
                        />
                        <div>
                            <Label
                                for="is_active"
                                class="cursor-pointer font-medium"
                                >Actif</Label
                            >
                            <p class="text-xs text-muted-foreground">
                                Décochez pour désactiver l'équipe.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Membres -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <!-- En-tête section -->
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Membres
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Σ taux équipe :
                        <span
                            class="font-semibold"
                            :class="
                                sommeTaux > 100
                                    ? 'text-destructive'
                                    : 'text-foreground'
                            "
                            >{{ sommeTaux }}%</span
                        >
                        <span class="ml-1"
                            >(reste
                            <span
                                :class="
                                    100 - sommeTaux < 0
                                        ? 'font-semibold text-destructive'
                                        : ''
                                "
                            >
                                {{ 100 - sommeTaux }}%
                            </span>
                            pour le propriétaire)</span
                        >
                    </p>
                </div>
                <Button type="button" size="sm" @click="openNewMembre">
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Ajouter un membre
                </Button>
            </div>

            <!-- Alerte principal -->
            <div
                v-if="principalWarning"
                class="mb-4 flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300"
            >
                <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                {{ principalWarning }}
            </div>

            <div
                v-if="tauxWarning"
                class="mb-4 flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
            >
                <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                {{ tauxWarning }}
            </div>

            <p
                v-if="form.errors?.membres"
                class="mb-3 text-xs text-destructive"
            >
                {{ form.errors.membres }}
            </p>

            <!-- État vide -->
            <div
                v-if="form.membres.length === 0"
                class="rounded-lg border border-dashed py-10 text-center"
            >
                <p class="text-sm text-muted-foreground">Aucun membre.</p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="mt-3"
                    @click="openNewMembre"
                >
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Ajouter le premier membre
                </Button>
            </div>

            <!-- Liste des membres -->
            <div v-else class="divide-y rounded-lg border">
                <div
                    v-for="(membre, index) in form.membres"
                    :key="index"
                    class="flex items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/30"
                >
                    <!-- Zone gauche : avatar + nom + téléphone -->
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                            :class="
                                membre.role === 'principal'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ initiales(membre.prenom, membre.nom) }}
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ membre.prenom }} {{ membre.nom }}
                            </div>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ formatPhoneDisplay(membre.telephone) }}
                            </div>
                        </div>
                    </div>

                    <!-- Zone milieu : rôle centré -->
                    <div class="w-28 shrink-0 text-center">
                        <span
                            class="inline-block rounded-sm px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                            :class="
                                membre.role === 'principal'
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ membre.role }}
                        </span>
                    </div>

                    <!-- Zone droite : taux + actions -->
                    <div class="flex shrink-0 items-center gap-3">
                        <span
                            class="w-12 text-right font-mono text-sm font-medium tabular-nums"
                        >
                            {{ membre.taux_commission }}%
                        </span>
                        <div class="flex gap-0.5">
                            <button
                                type="button"
                                title="Modifier ce membre"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                @click="openEditMembre(index)"
                            >
                                <Pencil class="h-3.5 w-3.5" />
                            </button>
                            <button
                                type="button"
                                title="Supprimer ce membre"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                @click="removeMembre(index)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pied de formulaire -->
        <div class="flex items-center justify-between">
            <a href="/equipes-livraison">
                <Button type="button" variant="outline">Retour</Button>
            </a>
            <Button
                type="submit"
                :disabled="
                    form.processing || !!principalWarning || !!tauxWarning
                "
            >
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
    </form>

    <!-- Modal membre -->
    <MembreModal
        v-model:visible="showModal"
        :membre="membreEnEdition"
        :has-principal="hasPrincipal && editingIndex !== principalIndex"
        :max-taux="maxTauxDisponible"
        @confirm="onMembreConfirm"
    />
</template>
