<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    UserCog,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Cible {
    code: string;
    libelle: string;
}

interface Montant {
    montant: number;
    effective_from: string;
    regle_id: string;
    consultant_id?: string | null;
    consultant_label?: string | null;
}

interface Ligne {
    scope_type: 'categorie';
    scope_id: string;
    libelle: string;
    montants: Record<string, Montant | null>;
}

interface Option {
    value: string;
    label: string;
}

interface DraftLigne {
    key: string;
    categorie_id: string | null;
    consultant_id: string | null;
    montants: Record<string, string>;
}

const props = defineProps<{
    lignes: Ligne[];
    categories: Option[];
    cibles: Cible[];
    consultantActifId: string | null;
    consultantsEligibles: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Commissions', href: '/settings/commissions' },
];

const CONSULTANT_CODE = 'consultant';
const toast = useToast();
let nextLineKey = props.lignes.length;

function emptyMontants(): Record<string, string> {
    return Object.fromEntries(props.cibles.map((cible) => [cible.code, '']));
}

const draftLignes = ref<DraftLigne[]>(
    props.lignes.map((ligne, index) => ({
        key: `existing-${index}`,
        categorie_id: ligne.scope_id,
        // Repli temporaire pour les anciennes configurations qui utilisaient
        // encore un consultant global. Le prochain enregistrement le rattache
        // explicitement à la catégorie.
        consultant_id:
            ligne.montants[CONSULTANT_CODE]?.consultant_id ??
            props.consultantActifId,
        montants: Object.fromEntries(
            props.cibles.map((cible) => [
                cible.code,
                ligne.montants[cible.code]?.montant?.toString() ?? '',
            ]),
        ),
    })),
);

function serializableLines() {
    return draftLignes.value.map((ligne) => ({
        categorie_id: ligne.categorie_id,
        consultant_id: ligne.consultant_id,
        montants: { ...ligne.montants },
    }));
}

const initialSnapshot = JSON.stringify(serializableLines());
const hasChanges = computed(
    () => JSON.stringify(serializableLines()) !== initialSnapshot,
);
const confirmationVisible = ref(false);
const consultantDialogVisible = ref(false);
const editingConsultantIndex = ref<number | null>(null);
const consultantDraftId = ref<string | null>(null);
const consultantDraftMontant = ref('');
const consultantDialogErrors = ref<Record<string, string>>({});
const localErrors = ref<Record<string, string>>({});

const configurationForm = useForm({
    lignes: [] as Array<{
        categorie_id: string;
        consultant_id: string;
        montants: Record<string, string>;
    }>,
});

const selectedCategoryIds = computed(() =>
    draftLignes.value
        .map((ligne) => ligne.categorie_id)
        .filter((id): id is string => Boolean(id)),
);
const canAddCategory = computed(
    () => selectedCategoryIds.value.length < props.categories.length,
);
const editingCategoryLabel = computed(() => {
    if (editingConsultantIndex.value === null) return '';
    return categoryLabel(
        draftLignes.value[editingConsultantIndex.value]?.categorie_id ?? null,
    );
});

function categoryOptionsFor(ligne: DraftLigne): Option[] {
    const usedByOtherRows = new Set(
        draftLignes.value
            .filter((candidate) => candidate.key !== ligne.key)
            .map((candidate) => candidate.categorie_id)
            .filter((id): id is string => Boolean(id)),
    );
    return props.categories.filter(
        (categorie) => !usedByOtherRows.has(categorie.value),
    );
}

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

function addLine(): void {
    if (!canAddCategory.value) return;
    draftLignes.value.push({
        key: `new-${nextLineKey++}`,
        categorie_id: null,
        consultant_id: null,
        montants: emptyMontants(),
    });
    delete localErrors.value.lignes;
}

function removeLine(index: number): void {
    draftLignes.value.splice(index, 1);
    localErrors.value = {};
}

function onCategoryChange(index: number, categoryId: string | null): void {
    const ligne = draftLignes.value[index];
    if (!ligne || ligne.categorie_id === categoryId) return;

    ligne.categorie_id = categoryId;
    ligne.montants = emptyMontants();
    ligne.consultant_id = null;
    localErrors.value = {};
    configurationForm.clearErrors();
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

function onMontantPaste(
    event: ClipboardEvent,
    ligne: DraftLigne,
    cibleCode: string,
): void {
    ligne.montants[cibleCode] = numericPasteValue(event);
}

function fieldError(path: string): string | undefined {
    return (
        localErrors.value[path] ??
        (configurationForm.errors as Record<string, string>)[path]
    );
}

function openConsultantDialog(index: number): void {
    if (!draftLignes.value[index]?.categorie_id) return;

    editingConsultantIndex.value = index;
    consultantDraftId.value = draftLignes.value[index].consultant_id;
    consultantDraftMontant.value =
        draftLignes.value[index].montants[CONSULTANT_CODE] ?? '';
    consultantDialogErrors.value = {};
    consultantDialogVisible.value = true;
}

function applyConsultant(): void {
    const errors: Record<string, string> = {};
    if (!consultantDraftId.value) {
        errors.consultant_id = 'Choisissez le consultant bénéficiaire.';
    }
    if (!/^\d+$/.test(consultantDraftMontant.value.trim())) {
        errors.montant = 'Saisissez un montant entier (0 autorisé).';
    } else if (Number(consultantDraftMontant.value) > 99_999_999) {
        errors.montant = 'Montant trop élevé.';
    }
    consultantDialogErrors.value = errors;
    if (Object.keys(errors).length || editingConsultantIndex.value === null)
        return;

    const index = editingConsultantIndex.value;
    const ligne = draftLignes.value[index];
    ligne.consultant_id = consultantDraftId.value;
    ligne.montants[CONSULTANT_CODE] = consultantDraftMontant.value.trim();
    delete localErrors.value[`lignes.${index}.consultant_id`];
    delete localErrors.value[`lignes.${index}.montants.${CONSULTANT_CODE}`];
    consultantDialogVisible.value = false;
}

function validateConfiguration(): boolean {
    const errors: Record<string, string> = {};
    if (draftLignes.value.length === 0) {
        errors.lignes = 'Ajoutez au moins une catégorie autorisée.';
    }

    const categoryIds = new Set<string>();
    draftLignes.value.forEach((ligne, index) => {
        if (!ligne.categorie_id) {
            errors[`lignes.${index}.categorie_id`] =
                'Choisissez une catégorie.';
            return;
        }

        if (categoryIds.has(ligne.categorie_id)) {
            errors[`lignes.${index}.categorie_id`] =
                'Cette catégorie est déjà ajoutée.';
        } else {
            categoryIds.add(ligne.categorie_id);
        }

        props.cibles.forEach((cible) => {
            const montant = ligne.montants[cible.code]?.trim() ?? '';
            if (!/^\d+$/.test(montant)) {
                errors[`lignes.${index}.montants.${cible.code}`] =
                    cible.code === CONSULTANT_CODE
                        ? 'Ajoutez le consultant et son montant.'
                        : 'Montant requis (0 autorisé).';
            } else if (Number(montant) > 99_999_999) {
                errors[`lignes.${index}.montants.${cible.code}`] =
                    'Montant trop élevé.';
            }
        });
        if (!ligne.consultant_id) {
            errors[`lignes.${index}.consultant_id`] =
                'Ajoutez le consultant bénéficiaire.';
        }
    });

    localErrors.value = errors;
    return Object.keys(errors).length === 0;
}

function openConfirmation(): void {
    configurationForm.clearErrors();
    if (!validateConfiguration()) return;
    confirmationVisible.value = true;
}

function submitConfiguration(): void {
    if (!validateConfiguration()) {
        confirmationVisible.value = false;
        return;
    }
    configurationForm.lignes = draftLignes.value.map((ligne) => ({
        categorie_id: ligne.categorie_id as string,
        consultant_id: ligne.consultant_id as string,
        montants: { ...ligne.montants },
    }));
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

function formatMontant(value: string): string {
    return `${new Intl.NumberFormat('fr-FR').format(Number(value))} GNF`;
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
                                Catégories autorisées
                            </h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Seules les catégories ajoutées génèrent des
                                commissions. Montant 0 = aucune commission.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="!canAddCategory"
                            data-testid="commission-add-row"
                            @click="addLine"
                        >
                            <Plus class="h-4 w-4" />
                            Ajouter une ligne
                        </Button>
                    </div>

                    <div v-if="draftLignes.length" class="overflow-x-auto">
                        <table class="w-full min-w-[1060px] text-sm">
                            <thead class="bg-muted/30 text-left">
                                <tr class="border-b">
                                    <th class="w-64 px-4 py-3 font-medium">
                                        Catégorie
                                    </th>
                                    <th
                                        v-for="cible in props.cibles"
                                        :key="cible.code"
                                        class="min-w-40 px-3 py-3 font-medium"
                                        :class="{
                                            'min-w-60':
                                                cible.code === CONSULTANT_CODE,
                                        }"
                                    >
                                        {{ cible.libelle }}
                                    </th>
                                    <th class="w-14 px-3 py-3">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(ligne, index) in draftLignes"
                                    :key="ligne.key"
                                    class="border-b last:border-b-0"
                                    :data-testid="`commission-row-${index}`"
                                >
                                    <td class="px-4 py-3 align-top">
                                        <div class="flex items-start gap-2">
                                            <div
                                                class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border bg-muted/30"
                                            >
                                                <Coins
                                                    class="h-4 w-4 text-muted-foreground"
                                                />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <Select
                                                    :model-value="
                                                        ligne.categorie_id
                                                    "
                                                    :options="
                                                        categoryOptionsFor(
                                                            ligne,
                                                        )
                                                    "
                                                    option-label="label"
                                                    option-value="value"
                                                    placeholder="Choisir la catégorie"
                                                    class="w-full"
                                                    :invalid="
                                                        Boolean(
                                                            fieldError(
                                                                `lignes.${index}.categorie_id`,
                                                            ),
                                                        )
                                                    "
                                                    :aria-label="`Catégorie de la ligne ${index + 1}`"
                                                    @update:model-value="
                                                        onCategoryChange(
                                                            index,
                                                            $event,
                                                        )
                                                    "
                                                />
                                                <p
                                                    v-if="
                                                        fieldError(
                                                            `lignes.${index}.categorie_id`,
                                                        )
                                                    "
                                                    class="mt-1 text-xs text-destructive"
                                                >
                                                    {{
                                                        fieldError(
                                                            `lignes.${index}.categorie_id`,
                                                        )
                                                    }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>

                                    <td
                                        v-for="cible in props.cibles"
                                        :key="cible.code"
                                        class="px-3 py-3 align-top"
                                    >
                                        <template
                                            v-if="
                                                cible.code === CONSULTANT_CODE
                                            "
                                        >
                                            <button
                                                type="button"
                                                class="flex min-h-10 w-full items-center justify-between gap-3 rounded-md border px-3 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none enabled:hover:border-primary/50 enabled:hover:bg-muted/40 disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60"
                                                :class="{
                                                    'border-destructive':
                                                        fieldError(
                                                            `lignes.${index}.consultant_id`,
                                                        ) ||
                                                        fieldError(
                                                            `lignes.${index}.montants.${CONSULTANT_CODE}`,
                                                        ),
                                                }"
                                                :disabled="!ligne.categorie_id"
                                                :aria-label="`Configurer le consultant de ${categoryLabel(ligne.categorie_id)}`"
                                                @click="
                                                    openConsultantDialog(index)
                                                "
                                            >
                                                <span
                                                    v-if="ligne.consultant_id"
                                                    class="min-w-0"
                                                >
                                                    <span
                                                        class="block text-sm font-medium tabular-nums"
                                                    >
                                                        {{
                                                            formatMontant(
                                                                ligne.montants[
                                                                    CONSULTANT_CODE
                                                                ],
                                                            )
                                                        }}
                                                    </span>
                                                    <span
                                                        class="block truncate text-xs text-muted-foreground"
                                                    >
                                                        {{
                                                            consultantLabel(
                                                                ligne.consultant_id,
                                                            )
                                                        }}
                                                    </span>
                                                </span>
                                                <span
                                                    v-else
                                                    class="flex items-center gap-1.5 font-medium"
                                                    :class="
                                                        ligne.categorie_id
                                                            ? 'text-primary'
                                                            : 'text-muted-foreground'
                                                    "
                                                >
                                                    <Plus class="h-4 w-4" />
                                                    {{
                                                        ligne.categorie_id
                                                            ? 'Ajouter'
                                                            : 'Catégorie requise'
                                                    }}
                                                </span>
                                                <Pencil
                                                    v-if="ligne.consultant_id"
                                                    class="h-3.5 w-3.5 shrink-0 text-primary"
                                                />
                                            </button>
                                            <p
                                                v-if="
                                                    fieldError(
                                                        `lignes.${index}.consultant_id`,
                                                    ) ||
                                                    fieldError(
                                                        `lignes.${index}.montants.${CONSULTANT_CODE}`,
                                                    )
                                                "
                                                class="mt-1 text-xs text-destructive"
                                            >
                                                Ajoutez le consultant et son
                                                montant.
                                            </p>
                                        </template>

                                        <template v-else>
                                            <div class="relative">
                                                <Input
                                                    v-model="
                                                        ligne.montants[
                                                            cible.code
                                                        ]
                                                    "
                                                    type="text"
                                                    inputmode="numeric"
                                                    :placeholder="
                                                        ligne.categorie_id
                                                            ? '0'
                                                            : 'Verrouillé'
                                                    "
                                                    class="pr-12 disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60"
                                                    :class="{
                                                        'border-destructive':
                                                            fieldError(
                                                                `lignes.${index}.montants.${cible.code}`,
                                                            ),
                                                    }"
                                                    :disabled="
                                                        !ligne.categorie_id
                                                    "
                                                    :aria-label="`${cible.libelle} — ligne ${index + 1}`"
                                                    @keydown="
                                                        blockNonIntegerKeydown
                                                    "
                                                    @paste="
                                                        onMontantPaste(
                                                            $event,
                                                            ligne,
                                                            cible.code,
                                                        )
                                                    "
                                                    @input="
                                                        delete localErrors[
                                                            `lignes.${index}.montants.${cible.code}`
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
                                                    fieldError(
                                                        `lignes.${index}.montants.${cible.code}`,
                                                    )
                                                "
                                                class="mt-1 text-xs text-destructive"
                                            >
                                                {{
                                                    fieldError(
                                                        `lignes.${index}.montants.${cible.code}`,
                                                    )
                                                }}
                                            </p>
                                        </template>
                                    </td>

                                    <td class="px-3 py-3 align-top">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground hover:text-destructive"
                                            :aria-label="`Retirer ${categoryLabel(ligne.categorie_id)}`"
                                            @click="removeLine(index)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
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
                            :disabled="!canAddCategory"
                            @click="addLine"
                        >
                            <Plus class="h-4 w-4" />
                            Ajouter la première catégorie
                        </Button>
                    </div>

                    <div
                        v-if="fieldError('lignes')"
                        class="flex items-center gap-2 border-t bg-destructive/5 px-4 py-3 text-xs text-destructive"
                    >
                        <CircleAlert class="h-4 w-4 shrink-0" />
                        {{ fieldError('lignes') }}
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
        v-model:visible="consultantDialogVisible"
        modal
        :header="`Commission Consultant — ${editingCategoryLabel}`"
        :style="{ width: 'min(480px, 95vw)' }"
        :dismissable-mask="false"
    >
        <div class="space-y-5 pt-1">
            <div v-if="props.consultantsEligibles.length" class="space-y-4">
                <div>
                    <label
                        for="commission-consultant"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Consultant bénéficiaire
                        <span class="text-destructive">*</span>
                    </label>
                    <Select
                        v-model="consultantDraftId"
                        input-id="commission-consultant"
                        :options="props.consultantsEligibles"
                        option-label="label"
                        option-value="value"
                        placeholder="Choisir un consultant"
                        class="w-full"
                        :invalid="Boolean(consultantDialogErrors.consultant_id)"
                        @update:model-value="
                            delete consultantDialogErrors.consultant_id
                        "
                    />
                    <p
                        v-if="consultantDialogErrors.consultant_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ consultantDialogErrors.consultant_id }}
                    </p>
                </div>

                <div>
                    <label
                        for="commission-consultant-montant"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Montant par unité vendue
                        <span class="text-destructive">*</span>
                    </label>
                    <div class="relative">
                        <Input
                            id="commission-consultant-montant"
                            v-model="consultantDraftMontant"
                            type="text"
                            inputmode="numeric"
                            placeholder="ex. 50"
                            class="pr-14"
                            :class="{
                                'border-destructive':
                                    consultantDialogErrors.montant,
                            }"
                            @keydown="blockNonIntegerKeydown"
                            @paste="
                                consultantDraftMontant =
                                    numericPasteValue($event)
                            "
                            @input="delete consultantDialogErrors.montant"
                        />
                        <span
                            class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-muted-foreground"
                        >
                            GNF
                        </span>
                    </div>
                    <p
                        v-if="consultantDialogErrors.montant"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ consultantDialogErrors.montant }}
                    </p>
                </div>

                <div
                    class="flex items-start gap-2 rounded-lg border bg-muted/20 p-3 text-xs text-muted-foreground"
                >
                    <UserCog class="mt-0.5 h-4 w-4 shrink-0" />
                    Cette association concerne uniquement la catégorie
                    {{ editingCategoryLabel }} et les ventes futures.
                </div>
            </div>

            <div
                v-else
                class="rounded-lg border border-destructive/40 bg-destructive/5 p-4"
            >
                <p class="text-sm font-medium text-destructive">
                    Aucun consultant actif disponible
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Créez d’abord un prestataire de type Consultant.
                </p>
                <Link
                    href="/backoffice/prestataires/create"
                    class="mt-3 inline-block text-sm font-medium text-primary underline-offset-4 hover:underline"
                >
                    Créer un prestataire Consultant
                </Link>
            </div>

            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    @click="consultantDialogVisible = false"
                >
                    Annuler
                </Button>
                <Button
                    v-if="props.consultantsEligibles.length"
                    type="button"
                    data-testid="commission-consultant-apply"
                    @click="applyConsultant"
                >
                    Ajouter à la ligne
                </Button>
            </div>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="confirmationVisible"
        modal
        header="Vérifier avant d’enregistrer"
        :style="{ width: 'min(960px, 96vw)' }"
        :dismissable-mask="false"
    >
        <div class="space-y-5 pt-1">
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[800px] text-sm">
                    <thead class="bg-muted/30 text-left">
                        <tr>
                            <th class="px-3 py-2.5 font-medium">Catégorie</th>
                            <th
                                v-for="cible in props.cibles"
                                :key="cible.code"
                                class="px-3 py-2.5 font-medium"
                            >
                                {{ cible.libelle }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="ligne in draftLignes"
                            :key="ligne.key"
                            class="border-t"
                        >
                            <td class="px-3 py-2.5 font-medium">
                                {{ categoryLabel(ligne.categorie_id) }}
                            </td>
                            <td
                                v-for="cible in props.cibles"
                                :key="cible.code"
                                class="px-3 py-2.5 tabular-nums"
                            >
                                <template v-if="cible.code === CONSULTANT_CODE">
                                    <span
                                        class="block font-medium tabular-nums"
                                    >
                                        {{
                                            formatMontant(
                                                ligne.montants[CONSULTANT_CODE],
                                            )
                                        }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{
                                            consultantLabel(ligne.consultant_id)
                                        }}
                                    </span>
                                </template>
                                <template v-else>
                                    {{
                                        formatMontant(
                                            ligne.montants[cible.code],
                                        )
                                    }}
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
            >
                <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                <p>
                    Les catégories absentes ne généreront aucune commission. Ces
                    changements s’appliqueront uniquement aux ventes futures.
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
