<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatPhoneDisplay } from '@/lib/utils';
import { AlertTriangle, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import MembreModal, { type MembreFormData } from './MembreModal.vue';

type Membre = MembreFormData;

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

// â”€â”€ Modal Ã©tat â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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

// â”€â”€ Computed â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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
        return "L'Ã©quipe doit avoir exactement un livreur principal.";
    if (count > 1)
        return "L'Ã©quipe ne peut avoir qu'un seul livreur principal.";
    return null;
});

const tauxWarning = computed(() => {
    if (props.form.membres.some((m) => Number(m.taux_commission) < 0)) {
        return 'Le taux de commission ne peut pas Ãªtre nÃ©gatif.';
    }

    if (sommeTaux.value > 100) {
        return "La somme des taux de l'Ã©quipe ne doit pas dÃ©passer 100 %.";
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

// â”€â”€ Gestion des membres â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

function onMembreConfirm(data: MembreFormData) {
    const newIsPrincipal = data.role === 'principal';
    const existingPrincipalIdx = props.form.membres.findIndex(
        (m, i) => m.role === 'principal' && i !== editingIndex.value,
    );

    if (newIsPrincipal && existingPrincipalIdx >= 0) {
        // Conflit : un principal existe dÃ©jÃ
        const existing = props.form.membres[existingPrincipalIdx];
        confirm.require({
            message: `Remplacer Â« ${existing.prenom} ${existing.nom} Â» comme principal par Â« ${data.prenom} ${data.nom} Â» ?`,
            header: 'Remplacer le principal ?',
            icon: 'pi pi-exclamation-triangle',
            rejectLabel: 'Annuler',
            acceptLabel: 'Remplacer',
            accept: () => {
                // RÃ©trograder l'ancien principal en assistant
                // eslint-disable-next-line vue/no-mutating-props
                props.form.membres[existingPrincipalIdx].role = 'assistant';
                applyMembreData(data);
            },
        });
    } else {
        applyMembreData(data);
    }
}

function applyMembreData(data: MembreFormData) {
    if (editingIndex.value === null) {
        // Ajout
        // eslint-disable-next-line vue/no-mutating-props
        props.form.membres.push({
            ...data,
            ordre: props.form.membres.length,
        });
    } else {
        // Mise a jour
        Object.assign(props.form.membres[editingIndex.value], data);
    }
    // Rafraichir les ordres
    props.form.membres.forEach((m, i) => (m.ordre = i));
}

function removeMembre(index: number) {
    // eslint-disable-next-line vue/no-mutating-props
    props.form.membres.splice(index, 1);
    props.form.membres.forEach((m, i) => (m.ordre = i));
}

// â”€â”€ Affichage â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

function initiales(prenom: string, nom: string): string {
    const p = prenom.trim()[0] ?? '';
    const n = nom.trim()[0] ?? '';
    return (p + n).toUpperCase();
}

function setIsActive(val: boolean | string) {
    // eslint-disable-next-line vue/no-mutating-props
    props.form.is_active = val === true;
}

// â”€â”€ Submit â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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
                        Nom de l'Ã©quipe
                        <span class="text-destructive">*</span>
                    </Label>
                    <!-- eslint-disable vue/no-mutating-props -->
                    <input
                        id="nom"
                        v-model="form.nom"
                        type="text"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="{ 'border-destructive': form.errors?.nom }"
                        placeholder="Ex: Ã‰quipe Centre-ville"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
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
                            @update:model-value="setIsActive($event)"
                        />
                        <div>
                            <Label
                                for="is_active"
                                class="cursor-pointer font-medium"
                                >Actif</Label
                            >
                            <p class="text-xs text-muted-foreground">
                                DÃ©cochez pour dÃ©sactiver l'Ã©quipe.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Membres -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <!-- En-tÃªte section -->
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3
                        class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Membres
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Î£ taux Ã©quipe :
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
                            pour le propriÃ©taire)</span
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

            <!-- Ã‰tat vide -->
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
                    <!-- Zone gauche : avatar + nom + tÃ©lÃ©phone -->
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

                    <!-- Zone milieu : rÃ´le centrÃ© -->
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
                {{ form.processing ? 'Enregistrementâ€¦' : 'Enregistrer' }}
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
