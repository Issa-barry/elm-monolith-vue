<script setup lang="ts">
// Saisie et modification d'une absence (docs/rh-absences.md). Champs dans l'ordre : salarié, motif,
// dates, commentaire, justificatif. La durée affichée n'est qu'un aperçu : le serveur la recalcule
// et contrôle aussi le salarié, le motif, la période d'emploi et les chevauchements.
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    BASE_ABSENCES,
    dureeEnJours,
    libelleDuree,
    type AbsenceRow,
    type Option,
} from '@/types/absence';
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, Eye, Loader2, Paperclip, X } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    visible: boolean;
    /** Absence à modifier ; null = nouvelle absence. */
    absence: AbsenceRow | null;
    motifs: Option[];
    /** Salariés proposés (nouvelle absence depuis la liste). */
    salaries?: Option[];
    /** Salarié imposé (fiche salarié) : le champ Salarié est alors affiché sans choix. */
    salarieFixe?: { id: string; nom: string } | null;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
    /** Ouvrir l'absence existante signalée par un chevauchement. */
    'voir-absence': [id: string];
}>();

const fichierInput = ref<HTMLInputElement | null>(null);

const form = useForm<{
    employe_id: string;
    motif_absence_id: string;
    date_debut: string;
    date_fin: string;
    commentaire: string;
    justificatif: File | null;
    retirer_justificatif: boolean;
}>({
    employe_id: '',
    motif_absence_id: '',
    date_debut: '',
    date_fin: '',
    commentaire: '',
    justificatif: null,
    retirer_justificatif: false,
});

const enModification = computed(() => props.absence !== null);

// Un motif désactivé depuis reste affiché s'il est déjà celui de l'absence.
const motifsProposes = computed<Option[]>(() => {
    const a = props.absence;
    if (!a || props.motifs.some((m) => m.value === a.motif_id))
        return props.motifs;
    return [...props.motifs, { value: a.motif_id, label: a.motif }];
});

function initialiser() {
    form.clearErrors();
    const a = props.absence;
    form.employe_id = a?.employe_id ?? props.salarieFixe?.id ?? '';
    form.motif_absence_id = a?.motif_id ?? '';
    form.date_debut = a?.date_debut ?? '';
    form.date_fin = a?.date_fin ?? '';
    form.commentaire = a?.commentaire ?? '';
    form.justificatif = null;
    form.retirer_justificatif = false;
    if (fichierInput.value) fichierInput.value.value = '';
}

watch(
    () => props.visible,
    (visible) => {
        if (visible) initialiser();
    },
    { immediate: true },
);

// Fin vide ou antérieure : reprend le début, cas le plus fréquent (absence d'un jour).
watch(
    () => form.date_debut,
    (debut) => {
        if (debut && (!form.date_fin || form.date_fin < debut))
            form.date_fin = debut;
    },
);

const duree = computed(() => dureeEnJours(form.date_debut, form.date_fin));
const datesInversees = computed(
    () =>
        !!form.date_debut && !!form.date_fin && form.date_fin < form.date_debut,
);

const erreurs = computed(
    () => form.errors as Record<string, string | undefined>,
);

const justificatifActuel = computed(() =>
    props.absence?.a_justificatif && !form.retirer_justificatif
        ? props.absence.justificatif_nom
        : null,
);

const peutEnregistrer = computed(
    () =>
        !form.processing &&
        !!form.employe_id &&
        !!form.motif_absence_id &&
        !!form.date_debut &&
        !!form.date_fin &&
        !datesInversees.value,
);

function choisirFichier(event: Event) {
    const fichier = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.justificatif = fichier;
    if (fichier) form.retirer_justificatif = false;
}

function retirerFichierChoisi() {
    form.justificatif = null;
    if (fichierInput.value) fichierInput.value.value = '';
}

function fermer() {
    if (!form.processing) emit('update:visible', false);
}

function enregistrer() {
    if (!peutEnregistrer.value) return;
    const options = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('update:visible', false),
    };

    if (props.absence) {
        // Le salarié d'une absence ne change pas ; fichier envoyé en multipart (PUT simulé).
        form.transform((data) => {
            const { employe_id: _ignore, ...reste } = data;
            void _ignore;
            return { ...reste, _method: 'put' };
        }).post(`${BASE_ABSENCES}/${props.absence.id}`, options);
    } else {
        form.transform((data) => data).post(BASE_ABSENCES, options);
    }
}

const champTexte =
    'flex min-h-[72px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="enModification ? 'Modifier l’absence' : 'Nouvelle absence'"
        :style="{ width: 'min(34rem, 95vw)' }"
        :draggable="false"
        :closable="!form.processing"
        data-testid="absence-form"
        @update:visible="(v: boolean) => (v ? null : fermer())"
    >
        <form class="space-y-4 pt-1" @submit.prevent="enregistrer">
            <!-- 1. Salarié -->
            <div class="space-y-1.5">
                <Label for="absence-salarie">
                    Salarié <span class="text-destructive">*</span>
                </Label>
                <p
                    v-if="absence || salarieFixe"
                    id="absence-salarie"
                    class="rounded-md border bg-muted/40 px-3 py-2 text-sm font-medium"
                >
                    {{ absence?.salarie ?? salarieFixe?.nom }}
                </p>
                <Select
                    v-else
                    v-model="form.employe_id"
                    input-id="absence-salarie"
                    :options="salaries ?? []"
                    option-label="label"
                    option-value="value"
                    filter
                    filter-placeholder="Nom, prénom ou matricule…"
                    placeholder="Sélectionner un salarié"
                    empty-filter-message="Aucun salarié trouvé"
                    empty-message="Aucun salarié dans votre périmètre"
                    class="w-full"
                    :invalid="!!erreurs.employe_id"
                    data-testid="absence-salarie"
                />
                <p v-if="erreurs.employe_id" class="text-xs text-destructive">
                    {{ erreurs.employe_id }}
                </p>
            </div>

            <!-- 2. Motif -->
            <div class="space-y-1.5">
                <Label for="absence-motif">
                    Motif <span class="text-destructive">*</span>
                </Label>
                <Select
                    v-model="form.motif_absence_id"
                    input-id="absence-motif"
                    :options="motifsProposes"
                    option-label="label"
                    option-value="value"
                    placeholder="Choisir un motif"
                    class="w-full"
                    :invalid="!!erreurs.motif_absence_id"
                    data-testid="absence-motif"
                />
                <p
                    v-if="erreurs.motif_absence_id"
                    class="text-xs text-destructive"
                >
                    {{ erreurs.motif_absence_id }}
                </p>
            </div>

            <!-- 3-4. Dates -->
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label for="absence-debut">
                        Date de début <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="absence-debut"
                        v-model="form.date_debut"
                        type="date"
                        data-testid="absence-debut"
                    />
                    <p
                        v-if="erreurs.date_debut"
                        class="text-xs text-destructive"
                    >
                        {{ erreurs.date_debut }}
                    </p>
                </div>
                <div class="space-y-1.5">
                    <Label for="absence-fin">
                        Date de fin <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="absence-fin"
                        v-model="form.date_fin"
                        type="date"
                        :min="form.date_debut || undefined"
                        data-testid="absence-fin"
                    />
                    <p
                        v-if="erreurs.date_fin || datesInversees"
                        class="text-xs text-destructive"
                    >
                        {{
                            erreurs.date_fin ??
                            'La date de fin doit être postérieure ou égale à la date de début.'
                        }}
                    </p>
                </div>
            </div>

            <p
                class="text-sm text-muted-foreground"
                data-testid="absence-duree"
            >
                Durée :
                <span class="font-medium text-foreground">{{
                    duree === null ? '—' : libelleDuree(duree)
                }}</span>
                <span class="text-xs">
                    (jours calendaires, dates incluses)</span
                >
            </p>

            <!-- Chevauchement ou doublon refusé par le serveur -->
            <div
                v-if="erreurs.chevauchement"
                class="flex gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300"
                role="alert"
                data-testid="absence-chevauchement"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <div class="space-y-1.5">
                    <p>{{ erreurs.chevauchement }}</p>
                    <Button
                        v-if="erreurs.absence_existante"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-7"
                        data-testid="absence-voir-existante"
                        @click="emit('voir-absence', erreurs.absence_existante)"
                    >
                        <Eye class="mr-1.5 h-3.5 w-3.5" />
                        Voir l’absence existante
                    </Button>
                </div>
            </div>

            <!-- 5. Commentaire -->
            <div class="space-y-1.5">
                <Label for="absence-commentaire">Commentaire</Label>
                <textarea
                    id="absence-commentaire"
                    v-model="form.commentaire"
                    rows="3"
                    maxlength="2000"
                    :class="champTexte"
                    placeholder="Précisions éventuelles (facultatif)"
                    data-testid="absence-commentaire"
                />
                <p v-if="erreurs.commentaire" class="text-xs text-destructive">
                    {{ erreurs.commentaire }}
                </p>
            </div>

            <!-- 6. Justificatif -->
            <div class="space-y-1.5">
                <Label for="absence-justificatif">Justificatif</Label>
                <div
                    v-if="justificatifActuel && !form.justificatif"
                    class="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <Paperclip
                            class="h-4 w-4 shrink-0 text-muted-foreground"
                        />
                        <span class="truncate">{{ justificatifActuel }}</span>
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-7 shrink-0"
                        @click="form.retirer_justificatif = true"
                    >
                        Retirer
                    </Button>
                </div>
                <div class="flex items-center gap-2">
                    <input
                        id="absence-justificatif"
                        ref="fichierInput"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                        class="block w-full text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm file:font-medium"
                        data-testid="absence-justificatif"
                        @change="choisirFichier"
                    />
                    <Button
                        v-if="form.justificatif"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 shrink-0"
                        aria-label="Retirer le fichier choisi"
                        @click="retirerFichierChoisi"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Facultatif — PDF ou image (JPG, PNG, WEBP), 5 Mo maximum.
                    <template v-if="justificatifActuel">
                        Un nouveau fichier remplace l’actuel.</template
                    >
                </p>
                <p v-if="erreurs.justificatif" class="text-xs text-destructive">
                    {{ erreurs.justificatif }}
                </p>
            </div>

            <p v-if="erreurs.absence" class="text-sm text-destructive">
                {{ erreurs.absence }}
            </p>

            <div class="flex justify-end gap-2 pt-1">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="form.processing"
                    @click="fermer"
                >
                    Annuler
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="!peutEnregistrer"
                    data-testid="absence-enregistrer"
                >
                    <Loader2
                        v-if="form.processing"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    Enregistrer
                </Button>
            </div>
        </form>
    </Dialog>
</template>
