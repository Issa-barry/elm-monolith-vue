<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    Coins,
    Pencil,
    Plus,
    Save,
    Trash2,
    Truck,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Option {
    value: string;
    label: string;
}

interface Cible {
    code: string;
    libelle: string;
}

interface MontantInfo {
    montant: number;
    effective_from: string;
    regle_id: string;
}

interface ExceptionLigne {
    type_vehicule_id: string;
    type_vehicule_label: string;
    montants: Record<string, MontantInfo>;
}

interface Ligne {
    scope_type: 'categorie';
    scope_id: string;
    libelle: string;
    beneficiaires: string[];
    montants_standard: Record<string, MontantInfo>;
    consultant_id: string | null;
    consultant_label: string | null;
    exceptions: ExceptionLigne[];
}

const props = defineProps<{
    lignes: Ligne[];
    categories: Option[];
    cibles: Cible[];
    typesVehicules: Option[];
    consultantsEligibles: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Commissions', href: '/settings/commissions' },
];

const CONSULTANT_CODE = 'consultant';
const toast = useToast();
let nextLineKey = props.lignes.length;
let nextExceptionKey = 0;

// ── État local (brouillon) ──────────────────────────────────────────────

interface ExceptionOverride {
    active: boolean;
    montant: string;
}

interface DraftException {
    key: string;
    type_vehicule_id: string | null;
    overrides: Record<string, ExceptionOverride>;
}

interface DraftLigne {
    key: string;
    categorie_id: string | null;
    beneficiaires: Record<string, boolean>;
    montants: Record<string, string>;
    consultant_id: string | null;
    modeVehicule: 'standard' | 'par_vehicule';
    exceptions: DraftException[];
}

interface PayloadLigne {
    categorie_id: string;
    beneficiaires: string[];
    consultant_id: string | null;
    montants_standard: Record<string, number>;
    exceptions: Array<{ type_vehicule_id: string; montants: Record<string, number> }>;
}

function emptyOverrides(): Record<string, ExceptionOverride> {
    return Object.fromEntries(
        props.cibles.map((cible) => [cible.code, { active: false, montant: '' }]),
    );
}

function hydrateLigne(ligne: Ligne, key: string): DraftLigne {
    const beneficiaires: Record<string, boolean> = {};
    const montants: Record<string, string> = {};
    props.cibles.forEach((cible) => {
        beneficiaires[cible.code] = ligne.beneficiaires.includes(cible.code);
        montants[cible.code] =
            ligne.montants_standard[cible.code]?.montant?.toString() ?? '';
    });

    const exceptions: DraftException[] = ligne.exceptions.map(
        (exception, index) => {
            const overrides = emptyOverrides();
            props.cibles.forEach((cible) => {
                const info = exception.montants[cible.code];
                overrides[cible.code] = {
                    active: Boolean(info),
                    montant: info?.montant?.toString() ?? '',
                };
            });

            return {
                key: `existing-exception-${key}-${index}`,
                type_vehicule_id: exception.type_vehicule_id,
                overrides,
            };
        },
    );

    return {
        key,
        categorie_id: ligne.scope_id,
        beneficiaires,
        montants,
        consultant_id: ligne.consultant_id,
        modeVehicule: exceptions.length > 0 ? 'par_vehicule' : 'standard',
        exceptions,
    };
}

function emptyDraftLigne(): DraftLigne {
    return {
        key: `new-${nextLineKey++}`,
        categorie_id: null,
        beneficiaires: Object.fromEntries(props.cibles.map((c) => [c.code, false])),
        montants: Object.fromEntries(props.cibles.map((c) => [c.code, ''])),
        consultant_id: null,
        modeVehicule: 'standard',
        exceptions: [],
    };
}

function cloneDraftLigne(ligne: DraftLigne): DraftLigne {
    return JSON.parse(JSON.stringify(ligne));
}

function toPayloadLigne(ligne: DraftLigne): PayloadLigne {
    const beneficiaires = props.cibles
        .map((c) => c.code)
        .filter((code) => ligne.beneficiaires[code]);

    const montants_standard: Record<string, number> = {};
    beneficiaires.forEach((code) => {
        montants_standard[code] = Number(ligne.montants[code]);
    });

    const exceptions =
        ligne.modeVehicule === 'par_vehicule'
            ? ligne.exceptions
                  .filter((exception) => exception.type_vehicule_id)
                  .map((exception) => {
                      const montants: Record<string, number> = {};
                      beneficiaires.forEach((code) => {
                          const override = exception.overrides[code];
                          if (override?.active && override.montant.trim() !== '') {
                              montants[code] = Number(override.montant);
                          }
                      });

                      return {
                          type_vehicule_id: exception.type_vehicule_id as string,
                          montants,
                      };
                  })
            : [];

    return {
        categorie_id: ligne.categorie_id as string,
        beneficiaires,
        consultant_id: beneficiaires.includes(CONSULTANT_CODE)
            ? ligne.consultant_id
            : null,
        montants_standard,
        exceptions,
    };
}

const draftLignes = ref<DraftLigne[]>(
    props.lignes.map((ligne, index) => hydrateLigne(ligne, `existing-${index}`)),
);

const initialSnapshot = JSON.stringify(draftLignes.value.map(toPayloadLigne));
const hasChanges = computed(
    () => JSON.stringify(draftLignes.value.map(toPayloadLigne)) !== initialSnapshot,
);

const canAddCategory = computed(
    () =>
        draftLignes.value.filter((l) => l.categorie_id).length <
        props.categories.length,
);

function categoryLabel(categoryId: string | null): string {
    return (
        props.categories.find((categorie) => categorie.value === categoryId)
            ?.label ?? 'Catégorie non sélectionnée'
    );
}

function consultantLabel(consultantId: string | null): string {
    return (
        props.consultantsEligibles.find(
            (consultant) => consultant.value === consultantId,
        )?.label ?? 'Consultant non sélectionné'
    );
}

function typeVehiculeLabel(typeVehiculeId: string | null): string {
    return (
        props.typesVehicules.find((t) => t.value === typeVehiculeId)?.label ??
        'Type de véhicule'
    );
}

function beneficiairesSummary(ligne: DraftLigne): string {
    const labels = props.cibles
        .filter((c) => ligne.beneficiaires[c.code])
        .map((c) => c.libelle);

    return labels.length ? labels.join(', ') : 'Aucun bénéficiaire';
}

function baremeSummary(ligne: DraftLigne): string {
    if (ligne.modeVehicule !== 'par_vehicule') return 'Barème standard';
    const n = ligne.exceptions.filter((e) => e.type_vehicule_id).length;
    if (n === 0) return 'Barème standard';

    return `Barème standard + ${n} exception${n > 1 ? 's' : ''} véhicule`;
}

function formatMontant(value: string | number): string {
    return `${new Intl.NumberFormat('fr-FR').format(Number(value))} GNF`;
}

function blockNonIntegerKeydown(event: KeyboardEvent): void {
    const allowed = [
        'Backspace',
        'Delete',
        'Tab',
        'Escape',
        'Enter',
        'ArrowLeft',
        'ArrowRight',
        'Home',
        'End',
    ];
    if (allowed.includes(event.key)) return;
    if (
        (event.ctrlKey || event.metaKey) &&
        ['a', 'c', 'v', 'x'].includes(event.key.toLowerCase())
    )
        return;
    if (!/^\d$/.test(event.key)) event.preventDefault();
}

function numericPasteValue(event: ClipboardEvent): string {
    event.preventDefault();
    return (event.clipboardData?.getData('text') ?? '').replace(/\D/g, '');
}

function removeLigne(index: number): void {
    draftLignes.value.splice(index, 1);
}

// ── Dialog de configuration d'une catégorie ─────────────────────────────

const dialogVisible = ref(false);
const dialogMode = ref<'add' | 'edit'>('add');
const editingIndex = ref<number | null>(null);
const dialogDraft = ref<DraftLigne>(emptyDraftLigne());
const dialogErrors = ref<Record<string, string>>({});

const checkedCibles = computed(() =>
    props.cibles.filter((c) => dialogDraft.value.beneficiaires[c.code]),
);

const canAddException = computed(
    () => dialogDraft.value.exceptions.length < props.typesVehicules.length,
);

const dialogCategoryOptions = computed<Option[]>(() => {
    const usedByOthers = new Set(
        draftLignes.value
            .filter((_, index) => index !== editingIndex.value)
            .map((l) => l.categorie_id)
            .filter((id): id is string => Boolean(id)),
    );

    return props.categories.filter((c) => !usedByOthers.has(c.value));
});

function exceptionVehicleOptions(exception: DraftException): Option[] {
    const used = new Set(
        dialogDraft.value.exceptions
            .filter((e) => e.key !== exception.key)
            .map((e) => e.type_vehicule_id)
            .filter((id): id is string => Boolean(id)),
    );

    return props.typesVehicules.filter((t) => !used.has(t.value));
}

function openAddDialog(): void {
    dialogMode.value = 'add';
    editingIndex.value = null;
    dialogDraft.value = emptyDraftLigne();
    dialogErrors.value = {};
    dialogVisible.value = true;
}

function openEditDialog(index: number): void {
    dialogMode.value = 'edit';
    editingIndex.value = index;
    dialogDraft.value = cloneDraftLigne(draftLignes.value[index]);
    dialogErrors.value = {};
    dialogVisible.value = true;
}

function toggleBeneficiaire(code: string, checked: boolean): void {
    dialogDraft.value.beneficiaires[code] = checked;
    delete dialogErrors.value.beneficiaires;

    if (checked) return;

    dialogDraft.value.montants[code] = '';
    if (code === CONSULTANT_CODE) dialogDraft.value.consultant_id = null;
    dialogDraft.value.exceptions.forEach((exception) => {
        exception.overrides[code] = { active: false, montant: '' };
    });
}

function addException(): void {
    dialogDraft.value.exceptions.push({
        key: `new-exception-${nextExceptionKey++}`,
        type_vehicule_id: null,
        overrides: emptyOverrides(),
    });
}

function removeException(index: number): void {
    dialogDraft.value.exceptions.splice(index, 1);
}

function validateDialog(): boolean {
    const errors: Record<string, string> = {};

    if (!dialogDraft.value.categorie_id) {
        errors.categorie_id = 'Choisissez une catégorie.';
    }

    if (checkedCibles.value.length === 0) {
        errors.beneficiaires = 'Cochez au moins un bénéficiaire.';
    }

    checkedCibles.value.forEach((cible) => {
        const raw = dialogDraft.value.montants[cible.code]?.trim() ?? '';
        if (!/^\d+$/.test(raw) || Number(raw) < 1) {
            errors[`montant_${cible.code}`] =
                'Montant entier requis (supérieur à 0).';
        } else if (Number(raw) > 99_999_999) {
            errors[`montant_${cible.code}`] = 'Montant trop élevé.';
        }
    });

    if (
        dialogDraft.value.beneficiaires[CONSULTANT_CODE] &&
        !dialogDraft.value.consultant_id
    ) {
        errors.consultant_id = 'Choisissez le consultant bénéficiaire.';
    }

    if (dialogDraft.value.modeVehicule === 'par_vehicule') {
        dialogDraft.value.exceptions.forEach((exception, index) => {
            if (!exception.type_vehicule_id) {
                errors[`exception_${index}_type`] =
                    'Choisissez un type de véhicule.';
            }

            checkedCibles.value.forEach((cible) => {
                const override = exception.overrides[cible.code];
                if (!override?.active) return;

                const raw = override.montant.trim();
                if (!/^\d+$/.test(raw) || Number(raw) < 1) {
                    errors[`exception_${index}_${cible.code}`] =
                        'Montant entier requis (supérieur à 0).';
                } else if (Number(raw) > 99_999_999) {
                    errors[`exception_${index}_${cible.code}`] = 'Montant trop élevé.';
                }
            });
        });
    }

    dialogErrors.value = errors;
    return Object.keys(errors).length === 0;
}

function confirmDialog(): void {
    if (!validateDialog()) return;

    const toSave = cloneDraftLigne(dialogDraft.value);
    if (dialogMode.value === 'add') {
        draftLignes.value.push(toSave);
    } else if (editingIndex.value !== null) {
        draftLignes.value[editingIndex.value] = toSave;
    }
    dialogVisible.value = false;
}

// ── Confirmation et enregistrement ──────────────────────────────────────

const confirmationVisible = ref(false);
const globalError = ref('');

const configurationForm = useForm({
    lignes: [] as PayloadLigne[],
});

function openConfirmation(): void {
    if (draftLignes.value.length === 0) {
        globalError.value = 'Ajoutez au moins une catégorie autorisée.';
        return;
    }
    globalError.value = '';
    confirmationVisible.value = true;
}

function submitConfiguration(): void {
    configurationForm.lignes = draftLignes.value.map(toPayloadLigne);
    configurationForm.post('/settings/commissions/configuration', {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            confirmationVisible.value = false;
            toast.add({
                severity: 'success',
                summary: 'Configuration enregistrée',
                detail: 'Les nouvelles règles s’appliquent aux ventes futures.',
                life: 4000,
            });
        },
        onError: () => {
            confirmationVisible.value = false;
        },
    });
}
</script>

<template>
    <Head title="Paramètres commissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :wide="true">
            <div class="max-w-full min-w-0 space-y-6 pb-24">
                <HeadingSmall title="Commissions" />

                <section class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex flex-col gap-3 border-b px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
                    >
                        <div>
                            <h2 class="text-sm font-semibold">
                                Règles de commission
                            </h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Choisissez les bénéficiaires et les montants par
                                produit vendu.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="!canAddCategory"
                            data-testid="commission-add-row"
                            @click="openAddDialog"
                        >
                            <Plus class="h-4 w-4" />
                            Ajouter une catégorie
                        </Button>
                    </div>

                    <div v-if="draftLignes.length" class="divide-y">
                        <div
                            v-for="(ligne, index) in draftLignes"
                            :key="ligne.key"
                            class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
                            :data-testid="`commission-row-${index}`"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border bg-muted/30"
                                >
                                    <Coins
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ categoryLabel(ligne.categorie_id) }}
                                    </p>
                                    <p
                                        class="mt-0.5 truncate text-xs text-muted-foreground"
                                    >
                                        {{ beneficiairesSummary(ligne) }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ baremeSummary(ligne) }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :data-testid="`commission-edit-${index}`"
                                    @click="openEditDialog(index)"
                                >
                                    <Pencil class="h-4 w-4" />
                                    Modifier
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="text-muted-foreground hover:text-destructive"
                                    :aria-label="`Retirer ${categoryLabel(ligne.categorie_id)}`"
                                    @click="removeLigne(index)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="flex flex-col items-center px-6 py-12 text-center"
                        data-testid="commission-empty-configuration"
                    >
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-xl border bg-muted/30"
                        >
                            <Coins class="h-5 w-5 text-muted-foreground" />
                        </div>
                        <p class="mt-3 text-sm font-medium">
                            Aucune catégorie autorisée
                        </p>
                        <p class="mt-1 max-w-md text-xs text-muted-foreground">
                            Ajoutez uniquement les catégories qui doivent ouvrir
                            droit à une commission.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            class="mt-4"
                            @click="openAddDialog"
                        >
                            <Plus class="h-4 w-4" />
                            Ajouter la première catégorie
                        </Button>
                    </div>

                    <div
                        v-if="globalError"
                        class="flex items-center gap-2 border-t bg-destructive/5 px-4 py-3 text-xs text-destructive"
                    >
                        <CircleAlert class="h-4 w-4 shrink-0" />
                        {{ globalError }}
                    </div>
                </section>

                <div
                    class="sticky bottom-0 z-10 flex items-center justify-between gap-4 rounded-lg border bg-card px-5 py-3 sm:px-6"
                >
                    <div
                        v-if="hasChanges"
                        class="flex items-center gap-2 text-sm"
                    >
                        <CircleAlert class="h-4 w-4 shrink-0 text-amber-600" />
                        <span>
                            <strong>Modifications non enregistrées</strong>
                            <span
                                class="hidden text-muted-foreground sm:inline"
                            >
                                — vérifiez le récapitulatif avant de confirmer.
                            </span>
                        </span>
                    </div>
                    <div
                        v-else
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <CircleCheck
                            class="h-4 w-4 shrink-0 text-emerald-600"
                        />
                        Configuration à jour
                    </div>
                    <Button
                        type="button"
                        :disabled="configurationForm.processing || !hasChanges"
                        data-testid="commission-save"
                        @click="openConfirmation"
                    >
                        <Save class="h-4 w-4" />
                        Vérifier et enregistrer
                    </Button>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>

    <Dialog
        v-model:visible="dialogVisible"
        modal
        :header="
            dialogMode === 'add'
                ? 'Ajouter une catégorie'
                : `Modifier — ${categoryLabel(dialogDraft.categorie_id)}`
        "
        :style="{ width: 'min(640px, 96vw)' }"
        :dismissable-mask="false"
    >
        <div class="space-y-6 pt-1">
            <div>
                <Label class="mb-1.5">
                    Catégorie
                    <span class="text-destructive">*</span>
                </Label>
                <Select
                    :model-value="dialogDraft.categorie_id"
                    :options="dialogCategoryOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Choisir la catégorie"
                    class="w-full"
                    :invalid="Boolean(dialogErrors.categorie_id)"
                    aria-label="Catégorie"
                    @update:model-value="
                        dialogDraft.categorie_id = $event;
                        delete dialogErrors.categorie_id;
                    "
                />
                <p
                    v-if="dialogErrors.categorie_id"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ dialogErrors.categorie_id }}
                </p>
            </div>

            <div>
                <Label class="mb-2">
                    Qui bénéficie d’une commission ?
                    <span class="text-destructive">*</span>
                </Label>
                <div class="space-y-3">
                    <div
                        v-for="cible in props.cibles"
                        :key="cible.code"
                        class="rounded-lg border p-3"
                    >
                        <label class="flex cursor-pointer items-center gap-3">
                            <Checkbox
                                :model-value="
                                    dialogDraft.beneficiaires[cible.code]
                                "
                                @update:model-value="
                                    toggleBeneficiaire(
                                        cible.code,
                                        $event === true,
                                    )
                                "
                            />
                            <span class="text-sm font-medium">{{
                                cible.libelle
                            }}</span>
                        </label>

                        <div
                            v-if="dialogDraft.beneficiaires[cible.code]"
                            class="mt-3 space-y-3 pl-7"
                        >
                            <div v-if="cible.code === CONSULTANT_CODE">
                                <template
                                    v-if="props.consultantsEligibles.length"
                                >
                                    <Label class="mb-1.5">
                                        Consultant bénéficiaire
                                        <span class="text-destructive"
                                            >*</span
                                        >
                                    </Label>
                                    <Select
                                        :model-value="
                                            dialogDraft.consultant_id
                                        "
                                        :options="props.consultantsEligibles"
                                        option-label="label"
                                        option-value="value"
                                        placeholder="Choisir un consultant"
                                        class="w-full"
                                        :invalid="
                                            Boolean(
                                                dialogErrors.consultant_id,
                                            )
                                        "
                                        aria-label="Consultant bénéficiaire"
                                        @update:model-value="
                                            dialogDraft.consultant_id = $event;
                                            delete dialogErrors.consultant_id;
                                        "
                                    />
                                    <p
                                        v-if="dialogErrors.consultant_id"
                                        class="mt-1 text-xs text-destructive"
                                    >
                                        {{ dialogErrors.consultant_id }}
                                    </p>
                                </template>
                                <div
                                    v-else
                                    class="rounded-lg border border-destructive/40 bg-destructive/5 p-3"
                                >
                                    <p
                                        class="text-sm font-medium text-destructive"
                                    >
                                        Aucun consultant actif disponible
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Créez d’abord un prestataire de type
                                        Consultant.
                                    </p>
                                    <Link
                                        href="/backoffice/prestataires/create"
                                        class="mt-2 inline-block text-sm font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        Créer un prestataire Consultant
                                    </Link>
                                </div>
                            </div>

                            <div>
                                <Label class="mb-1.5">
                                    Montant standard
                                    <span class="text-destructive">*</span>
                                </Label>
                                <div class="relative">
                                    <Input
                                        v-model="
                                            dialogDraft.montants[cible.code]
                                        "
                                        type="text"
                                        inputmode="numeric"
                                        placeholder="ex. 650"
                                        class="pr-12"
                                        :class="{
                                            'border-destructive':
                                                dialogErrors[
                                                    `montant_${cible.code}`
                                                ],
                                        }"
                                        :aria-label="`Montant standard — ${cible.libelle}`"
                                        @keydown="blockNonIntegerKeydown"
                                        @paste="
                                            dialogDraft.montants[
                                                cible.code
                                            ] = numericPasteValue($event)
                                        "
                                        @input="
                                            delete dialogErrors[
                                                `montant_${cible.code}`
                                            ]
                                        "
                                    />
                                    <span
                                        class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[11px] text-muted-foreground"
                                    >
                                        GNF
                                    </span>
                                </div>
                                <p
                                    v-if="
                                        dialogErrors[`montant_${cible.code}`]
                                    "
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{
                                        dialogErrors[`montant_${cible.code}`]
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <p
                    v-if="dialogErrors.beneficiaires"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ dialogErrors.beneficiaires }}
                </p>
            </div>

            <div v-if="checkedCibles.length">
                <Label class="mb-2">Montants selon le type de véhicule</Label>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-2 text-left text-sm font-medium transition-colors"
                        :class="
                            dialogDraft.modeVehicule === 'standard'
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'text-muted-foreground hover:bg-muted/40'
                        "
                        :aria-pressed="dialogDraft.modeVehicule === 'standard'"
                        @click="dialogDraft.modeVehicule = 'standard'"
                    >
                        Même montant pour tous les véhicules
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-2 text-left text-sm font-medium transition-colors"
                        :class="
                            dialogDraft.modeVehicule === 'par_vehicule'
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'text-muted-foreground hover:bg-muted/40'
                        "
                        :aria-pressed="
                            dialogDraft.modeVehicule === 'par_vehicule'
                        "
                        @click="dialogDraft.modeVehicule = 'par_vehicule'"
                    >
                        Montants différents selon le type de véhicule
                    </button>
                </div>

                <div
                    v-if="dialogDraft.modeVehicule === 'par_vehicule'"
                    class="mt-4 space-y-4"
                >
                    <p class="text-xs text-muted-foreground">
                        Les véhicules sans exception ci-dessous utilisent
                        automatiquement le montant standard.
                    </p>

                    <div
                        v-for="(exception, exceptionIndex) in dialogDraft.exceptions"
                        :key="exception.key"
                        class="rounded-lg border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <Label class="mb-1.5">
                                    <Truck class="h-3.5 w-3.5" />
                                    Type de véhicule
                                    <span class="text-destructive">*</span>
                                </Label>
                                <Select
                                    :model-value="exception.type_vehicule_id"
                                    :options="
                                        exceptionVehicleOptions(exception)
                                    "
                                    option-label="label"
                                    option-value="value"
                                    placeholder="Choisir le type de véhicule"
                                    class="w-full"
                                    :invalid="
                                        Boolean(
                                            dialogErrors[
                                                `exception_${exceptionIndex}_type`
                                            ],
                                        )
                                    "
                                    aria-label="Type de véhicule de l'exception"
                                    @update:model-value="
                                        exception.type_vehicule_id = $event;
                                        delete dialogErrors[
                                            `exception_${exceptionIndex}_type`
                                        ];
                                    "
                                />
                                <p
                                    v-if="
                                        dialogErrors[
                                            `exception_${exceptionIndex}_type`
                                        ]
                                    "
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{
                                        dialogErrors[
                                            `exception_${exceptionIndex}_type`
                                        ]
                                    }}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="mt-6 shrink-0 text-muted-foreground hover:text-destructive"
                                aria-label="Retirer cette exception"
                                @click="removeException(exceptionIndex)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>

                        <div class="mt-3 space-y-2">
                            <div
                                v-for="cible in checkedCibles"
                                :key="cible.code"
                                class="rounded-md border p-2.5"
                            >
                                <label
                                    class="flex cursor-pointer items-center gap-2.5"
                                >
                                    <Checkbox
                                        :model-value="
                                            exception.overrides[cible.code]
                                                .active
                                        "
                                        @update:model-value="
                                            exception.overrides[
                                                cible.code
                                            ].active = $event === true;
                                            if ($event !== true)
                                                exception.overrides[
                                                    cible.code
                                                ].montant = '';
                                        "
                                    />
                                    <span class="text-xs font-medium">
                                        {{ cible.libelle }} — montant
                                        différent du standard
                                    </span>
                                </label>
                                <div
                                    v-if="
                                        exception.overrides[cible.code].active
                                    "
                                    class="relative mt-2"
                                >
                                    <Input
                                        v-model="
                                            exception.overrides[cible.code]
                                                .montant
                                        "
                                        type="text"
                                        inputmode="numeric"
                                        placeholder="ex. 700"
                                        class="pr-12"
                                        :class="{
                                            'border-destructive':
                                                dialogErrors[
                                                    `exception_${exceptionIndex}_${cible.code}`
                                                ],
                                        }"
                                        :aria-label="`Montant exception — ${cible.libelle}`"
                                        @keydown="blockNonIntegerKeydown"
                                        @paste="
                                            exception.overrides[
                                                cible.code
                                            ].montant = numericPasteValue(
                                                $event,
                                            )
                                        "
                                        @input="
                                            delete dialogErrors[
                                                `exception_${exceptionIndex}_${cible.code}`
                                            ]
                                        "
                                    />
                                    <span
                                        class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[11px] text-muted-foreground"
                                    >
                                        GNF
                                    </span>
                                </div>
                                <p
                                    v-else
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Utilise le montant standard.
                                </p>
                                <p
                                    v-if="
                                        dialogErrors[
                                            `exception_${exceptionIndex}_${cible.code}`
                                        ]
                                    "
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{
                                        dialogErrors[
                                            `exception_${exceptionIndex}_${cible.code}`
                                        ]
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="!canAddException"
                        @click="addException"
                    >
                        <Plus class="h-4 w-4" />
                        Ajouter une exception
                    </Button>
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <Button
                type="button"
                variant="outline"
                @click="dialogVisible = false"
            >
                Annuler
            </Button>
            <Button
                type="button"
                data-testid="commission-dialog-save"
                @click="confirmDialog"
            >
                Enregistrer la catégorie
            </Button>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="confirmationVisible"
        modal
        header="Vérifier avant d’enregistrer"
        :style="{ width: 'min(720px, 96vw)' }"
        :dismissable-mask="false"
    >
        <div class="space-y-4 pt-1">
            <div
                v-for="ligne in draftLignes"
                :key="ligne.key"
                class="rounded-lg border p-4"
            >
                <p class="font-medium">
                    {{ categoryLabel(ligne.categorie_id) }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Bénéficiaires : {{ beneficiairesSummary(ligne) }}
                </p>

                <div class="mt-3">
                    <p class="text-xs font-medium text-muted-foreground">
                        Montant standard
                    </p>
                    <ul class="mt-1 space-y-0.5 text-sm">
                        <li
                            v-for="cible in props.cibles.filter(
                                (c) => ligne.beneficiaires[c.code],
                            )"
                            :key="cible.code"
                        >
                            {{ cible.libelle }} :
                            <span class="font-medium tabular-nums">{{
                                formatMontant(ligne.montants[cible.code])
                            }}</span>
                            <span
                                v-if="cible.code === CONSULTANT_CODE"
                                class="text-muted-foreground"
                            >
                                — {{ consultantLabel(ligne.consultant_id) }}
                            </span>
                        </li>
                    </ul>
                </div>

                <div
                    v-if="
                        ligne.modeVehicule === 'par_vehicule' &&
                        ligne.exceptions.length
                    "
                    class="mt-3 space-y-2"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        Exceptions par type de véhicule
                    </p>
                    <div
                        v-for="exception in ligne.exceptions"
                        :key="exception.key"
                        class="rounded-md bg-muted/30 p-2.5 text-sm"
                    >
                        <p class="font-medium">
                            {{ typeVehiculeLabel(exception.type_vehicule_id) }}
                        </p>
                        <ul class="mt-1 space-y-0.5">
                            <li
                                v-for="cible in props.cibles.filter(
                                    (c) => ligne.beneficiaires[c.code],
                                )"
                                :key="cible.code"
                                class="text-xs"
                            >
                                {{ cible.libelle }} :
                                <span
                                    v-if="
                                        exception.overrides[cible.code]
                                            ?.active
                                    "
                                    class="font-medium tabular-nums"
                                    >{{
                                        formatMontant(
                                            exception.overrides[cible.code]
                                                .montant,
                                        )
                                    }}</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >montant standard</span
                                >
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div
                class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
            >
                <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                <p>
                    Les catégories absentes ne généreront aucune commission.
                    Ces changements s’appliqueront uniquement aux ventes
                    futures.
                </p>
            </div>

            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="configurationForm.processing"
                    @click="confirmationVisible = false"
                >
                    Retour
                </Button>
                <Button
                    type="button"
                    :disabled="configurationForm.processing"
                    data-testid="commission-confirm-save"
                    @click="submitConfiguration"
                >
                    <Save class="h-4 w-4" />
                    {{
                        configurationForm.processing
                            ? 'Enregistrement…'
                            : 'Confirmer et enregistrer'
                    }}
                </Button>
            </div>
        </div>
    </Dialog>
</template>
