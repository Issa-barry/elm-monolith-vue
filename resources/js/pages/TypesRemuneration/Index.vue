<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import MontantInput from '@/components/MontantInput.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Ban,
    Gift,
    History,
    Loader2,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
    RotateCcw,
    Trash2,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Option {
    value: string;
    label: string;
}

interface TypeRemuneration {
    id: string;
    code: string;
    libelle: string;
    description: string | null;
    categorie: string;
    categorie_label: string;
    valorise: boolean;
    mode_calcul: string;
    mode_calcul_label: string;
    periodicite: string;
    periodicite_label: string;
    nature_montant: string;
    nature_montant_label: string;
    proratisable: boolean;
    cumul_autorise: boolean;
    montant_defaut: number | null;
    taux_defaut: number | null;
    date_debut_application: string | null;
    date_fin_application: string | null;
    statut: 'a_valider' | 'actif' | 'ferme' | 'inactif';
    statut_label: string;
    est_valide: boolean;
    attribuable: boolean;
    est_utilise: boolean;
    nb_beneficiaires: number;
    desactive_au: string | null;
    cree_par: string | null;
    modifie_par: string | null;
    valide_par: string | null;
    valide_le: string | null;
    created_at: string | null;
    updated_at: string | null;
}

type ActionStatut = 'valider' | 'fermer' | 'desactiver' | 'reactiver';

const props = defineProps<{
    types: TypeRemuneration[];
    modes_calcul: Option[];
    periodicites: Option[];
    natures_montant: Option[];
    statuts: Option[];
    premier_jour_modifiable: string | null;
}>();

useFlashToast();
const toast = useToast();
const { can } = usePermissions();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Primes et avantages', href: '/backoffice/types-remuneration' },
];

// ── Droits (le serveur revérifie chaque action) ──────────────────────────────

const peutValider = computed(() => can('rh-types-remuneration.valider'));

function peutModifier(t: TypeRemuneration): boolean {
    return (
        can('rh-types-remuneration.update') &&
        (!t.est_valide || peutValider.value)
    );
}

function peutSupprimer(t: TypeRemuneration): boolean {
    return (
        can('rh-types-remuneration.delete') &&
        !t.est_utilise &&
        (!t.est_valide || peutValider.value)
    );
}

function actionsStatut(t: TypeRemuneration): ActionStatut[] {
    if (!peutValider.value) return [];
    switch (t.statut) {
        case 'a_valider':
            return ['valider'];
        case 'actif':
            return ['fermer', 'desactiver'];
        case 'ferme':
            return ['reactiver', 'desactiver'];
        case 'inactif':
            return ['reactiver'];
    }
    return [];
}

function aDesActions(t: TypeRemuneration): boolean {
    return peutModifier(t) || peutSupprimer(t) || actionsStatut(t).length > 0;
}

// ── Filtres (côté client : référentiel de petite taille ; panneau du bouton
// « Filtres », comme Dépenses → Types) ─────────────────────────────────────────

const selectedStatut = ref('');
const selectedTexte = ref('');

const filterFields = computed((): FilterField[] => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: props.statuts,
    },
    {
        key: 'texte',
        label: 'Libellé ou code',
        type: 'text',
    },
]);

function premiere(valeur: unknown): string {
    if (Array.isArray(valeur)) return (valeur[0] as string) ?? '';
    return (valeur as string) ?? '';
}

function handleApply(values: Record<string, unknown>) {
    selectedStatut.value = premiere(values.statut);
    selectedTexte.value = premiere(values.texte);
}

function resetFilters() {
    selectedStatut.value = '';
    selectedTexte.value = '';
}

const filtered = computed(() => {
    const texte = selectedTexte.value.trim().toLowerCase();
    return props.types.filter(
        (t) =>
            (!selectedStatut.value || t.statut === selectedStatut.value) &&
            (!texte ||
                t.libelle.toLowerCase().includes(texte) ||
                t.code.toLowerCase().includes(texte)),
    );
});

const nbAValider = computed(
    () => props.types.filter((t) => t.statut === 'a_valider').length,
);

// ── Affichage ────────────────────────────────────────────────────────────────

const formatGnf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

function valeurDefaut(t: TypeRemuneration): string {
    if (t.mode_calcul === 'pourcentage_salaire_base') {
        return t.taux_defaut !== null
            ? `${t.taux_defaut.toLocaleString('fr-FR')} % du salaire de base`
            : '% du salaire de base';
    }
    return t.montant_defaut !== null
        ? `${formatGnf.format(t.montant_defaut)} GNF`
        : 'Montant à l’attribution';
}

function formatDate(iso: string | null): string {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}

function fenetre(t: TypeRemuneration): string {
    if (!t.date_debut_application && !t.date_fin_application) return '';
    if (t.date_debut_application && t.date_fin_application) {
        return `Du ${formatDate(t.date_debut_application)} au ${formatDate(t.date_fin_application)}`;
    }
    if (t.date_debut_application) {
        return `À partir du ${formatDate(t.date_debut_application)}`;
    }
    return `Jusqu’au ${formatDate(t.date_fin_application)}`;
}

// ── Formulaire création / modification ───────────────────────────────────────

const showDialog = ref(false);
const editingType = ref<TypeRemuneration | null>(null);

const dialogTitle = computed(() =>
    editingType.value ? 'Modifier la prime' : 'Nouvelle prime',
);

const form = useForm({
    libelle: '',
    code: '',
    description: '',
    mode_calcul: 'montant_fixe',
    periodicite: 'mensuelle',
    nature_montant: 'fixe',
    proratisable: false,
    cumul_autorise: false,
    montant_defaut: '' as string | number | null,
    taux_defaut: '' as string | number,
    date_debut_application: '',
    date_fin_application: '',
    valider: false,
});

const enPourcentage = computed(
    () => form.mode_calcul === 'pourcentage_salaire_base',
);

// « Enregistrer et valider » : création, ou proposition encore à valider.
const peutEnregistrerEtValider = computed(
    () =>
        peutValider.value &&
        (!editingType.value || editingType.value.statut === 'a_valider'),
);

// Options avancées : repliées par défaut, ouvertes d'office si l'une d'elles est en erreur.
const avanceOuvert = ref(false);
const CHAMPS_AVANCES = [
    'code',
    'nature_montant',
    'date_debut_application',
    'date_fin_application',
];
const erreurAvancee = computed(() =>
    CHAMPS_AVANCES.some((c) => !!(form.errors as Record<string, string>)[c]),
);

// Catégorie enregistrée avant la simplification (ADR 0046) : conservée, avec son traitement.
const categorieHistorique = computed(() => {
    const t = editingType.value;
    if (!t || t.categorie === 'prime') return null;
    return t.valorise
        ? 'Avantage en nature (catégorie conservée) : valorisé, jamais versé dans le net à payer.'
        : `Enregistré comme « ${t.categorie_label} » avant la simplification : traité comme une prime, versé en argent.`;
});

function openCreate() {
    avanceOuvert.value = false;
    editingType.value = null;
    form.reset();
    form.clearErrors();
    showDialog.value = true;
}

function openEdit(t: TypeRemuneration) {
    avanceOuvert.value = false;
    editingType.value = t;
    form.clearErrors();
    form.libelle = t.libelle;
    form.code = t.code;
    form.description = t.description ?? '';
    form.mode_calcul = t.mode_calcul;
    form.periodicite = t.periodicite;
    form.nature_montant = t.nature_montant;
    form.proratisable = t.proratisable;
    form.cumul_autorise = t.cumul_autorise;
    form.montant_defaut = t.montant_defaut ?? '';
    form.taux_defaut = t.taux_defaut ?? '';
    form.date_debut_application = t.date_debut_application ?? '';
    form.date_fin_application = t.date_fin_application ?? '';
    form.valider = false;
    showDialog.value = true;
}

function nombreOuNull(valeur: string | number | null): number | null {
    return valeur === '' || valeur === null ? null : Number(valeur);
}

function submit(valider: boolean) {
    form.valider = valider;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showDialog.value = false;
            form.reset();
        },
    };
    form.transform((data) => ({
        ...data,
        code: data.code.trim() || null,
        description: data.description.trim() || null,
        montant_defaut: enPourcentage.value
            ? null
            : nombreOuNull(data.montant_defaut),
        taux_defaut: enPourcentage.value
            ? nombreOuNull(data.taux_defaut)
            : null,
        date_debut_application: data.date_debut_application || null,
        date_fin_application: data.date_fin_application || null,
    }));
    if (editingType.value) {
        form.put(
            `/backoffice/types-remuneration/${editingType.value.id}`,
            options,
        );
    } else {
        form.post('/backoffice/types-remuneration', options);
    }
}

// ── Confirmation : changement de statut ou suppression ───────────────────────

interface Confirmation {
    type: TypeRemuneration;
    action: ActionStatut | 'supprimer';
}

const confirmation = ref<Confirmation | null>(null);
const confirmationEnCours = ref(false);
// Désactivation : date à partir de laquelle plus rien n'est versé (obligatoire avec des bénéficiaires).
const dateEffet = ref('');

const LIBELLES_ACTION: Record<ActionStatut | 'supprimer', string> = {
    valider: 'Valider',
    fermer: 'Fermer aux nouvelles attributions',
    desactiver: 'Désactiver',
    reactiver: 'Réactiver',
    supprimer: 'Supprimer',
};

const EXPLICATIONS_ACTION: Record<ActionStatut | 'supprimer', string> = {
    valider:
        'Le type devient actif : il pourra être attribué aux salariés (dans sa période d’application).',
    fermer: 'Plus aucune nouvelle attribution. Les salariés qui en bénéficient déjà le conservent.',
    desactiver:
        'Le type ne pourra plus être attribué et plus aucun montant n’en sera versé à partir de la date d’effet. Il reste visible dans l’historique et peut être réactivé.',
    reactiver:
        'Le type redevient actif avec le même code : il pourra de nouveau être attribué. Les affectations arrêtées ne reprennent pas d’elles-mêmes.',
    supprimer:
        'Le type sera définitivement supprimé. Cette action est réservée aux types qui n’ont jamais été attribués.',
};

function demander(type: TypeRemuneration, action: Confirmation['action']) {
    dateEffet.value = '';
    confirmation.value = { type, action };
}

const dateEffetRequise = computed(
    () =>
        confirmation.value?.action === 'desactiver' &&
        confirmation.value.type.nb_beneficiaires > 0,
);

function fermerConfirmation(visible: boolean) {
    if (!visible && !confirmationEnCours.value) confirmation.value = null;
}

function confirmer() {
    const c = confirmation.value;
    if (!c || confirmationEnCours.value) return;
    confirmationEnCours.value = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            confirmation.value = null;
        },
        // Refus du serveur (transition devenue impossible, type déjà attribué…).
        onError: (errors: Record<string, string>) => {
            confirmation.value = null;
            toast.add({
                group: 'top',
                severity: 'error',
                summary: 'Action refusée',
                detail: Object.values(errors)[0],
                life: 6000,
            });
        },
        onFinish: () => {
            confirmationEnCours.value = false;
        },
    };
    if (c.action === 'supprimer') {
        router.delete(`/backoffice/types-remuneration/${c.type.id}`, options);
    } else {
        router.patch(
            `/backoffice/types-remuneration/${c.type.id}/${c.action}`,
            c.action === 'desactiver' && dateEffet.value
                ? { date_effet: dateEffet.value }
                : {},
            options,
        );
    }
}

// ── Historique ───────────────────────────────────────────────────────────────

const historiqueType = ref<TypeRemuneration | null>(null);
const historiqueVisible = ref(false);

function ouvrirHistorique(t: TypeRemuneration) {
    historiqueType.value = t;
    historiqueVisible.value = true;
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems" :hide-mobile-header="true">
        <Head title="Primes et avantages" />

        <SettingsLayout wide>
            <div class="space-y-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <HeadingSmall
                        title="Primes et avantages"
                        description="Les primes de votre organisation : transport, logement, rendement, ancienneté, exceptionnelle… Une prime doit être validée avant de pouvoir être attribuée à un salarié."
                    />
                    <div class="flex items-center gap-2">
                        <DataFilters
                            trigger-only
                            :fields="filterFields"
                            :values="{
                                statut: selectedStatut,
                                texte: selectedTexte,
                            }"
                            :result-count="filtered.length"
                            :hide-agence-selector="true"
                            @apply="handleApply"
                            @reset="resetFilters"
                        />
                        <Button
                            v-if="can('rh-types-remuneration.create')"
                            size="sm"
                            data-testid="type-remuneration-nouveau"
                            @click="openCreate"
                        >
                            <Plus class="mr-1.5 h-3.5 w-3.5" />
                            Nouveau type
                        </Button>
                    </div>
                </div>

                <div
                    v-if="nbAValider > 0"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    {{ nbAValider }}
                    {{
                        nbAValider > 1
                            ? 'types attendent leur validation'
                            : 'type attend sa validation'
                    }}
                    : {{ nbAValider > 1 ? 'ils ne peuvent' : 'il ne peut' }}
                    pas encore être attribués.
                </div>

                <div class="overflow-hidden rounded-xl border bg-card">
                    <DataTable
                        :value="filtered"
                        :paginator="filtered.length > 15"
                        :rows="15"
                        data-key="id"
                        striped-rows
                        removable-sort
                        class="text-sm"
                        :pt="{
                            root: { class: 'w-full' },
                            tbody: { class: 'divide-y' },
                        }"
                    >
                        <Column
                            field="libelle"
                            header="Type"
                            sortable
                            style="min-width: 220px"
                        >
                            <template #body="{ data }">
                                <div
                                    :class="{
                                        'opacity-60': data.statut === 'inactif',
                                    }"
                                >
                                    <p
                                        class="flex flex-wrap items-center gap-2 font-medium"
                                    >
                                        {{ data.libelle }}
                                        <span
                                            class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] tracking-wide text-muted-foreground"
                                        >
                                            {{ data.code }}
                                        </span>
                                        <span
                                            v-if="data.valorise"
                                            class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-900 dark:text-amber-300"
                                            >Avantage en nature · non
                                            versé</span
                                        >
                                    </p>
                                    <p
                                        v-if="data.description"
                                        class="mt-0.5 line-clamp-1 text-xs text-muted-foreground"
                                    >
                                        {{ data.description }}
                                    </p>
                                </div>
                            </template>
                        </Column>

                        <Column header="Calcul" style="min-width: 200px">
                            <template #body="{ data }">
                                <p>{{ valeurDefaut(data) }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ data.periodicite_label }} ·
                                    {{ data.nature_montant_label }}
                                </p>
                                <p
                                    v-if="fenetre(data)"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ fenetre(data) }}
                                </p>
                            </template>
                        </Column>

                        <Column
                            field="statut"
                            header="Statut"
                            sortable
                            style="width: 170px"
                        >
                            <template #body="{ data }">
                                <StatusDot
                                    :status="data.statut"
                                    :label="data.statut_label"
                                />
                                <p
                                    v-if="data.valide_par"
                                    class="mt-0.5 text-[11px] text-muted-foreground"
                                >
                                    Validé par {{ data.valide_par }}
                                </p>
                            </template>
                        </Column>

                        <Column header="" style="width: 56px">
                            <template #body="{ data }">
                                <div class="flex justify-end">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-8 w-8"
                                                :data-testid="`type-remuneration-actions-${data.code}`"
                                            >
                                                <MoreHorizontal
                                                    class="h-4 w-4"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-60"
                                        >
                                            <DropdownMenuLabel>{{
                                                data.libelle
                                            }}</DropdownMenuLabel>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                @click="ouvrirHistorique(data)"
                                            >
                                                <History class="mr-2 h-4 w-4" />
                                                Historique
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="peutModifier(data)"
                                                @click="openEdit(data)"
                                            >
                                                <Pencil class="mr-2 h-4 w-4" />
                                                Modifier
                                            </DropdownMenuItem>
                                            <template
                                                v-if="
                                                    actionsStatut(data).length >
                                                    0
                                                "
                                            >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    v-for="action in actionsStatut(
                                                        data,
                                                    )"
                                                    :key="action"
                                                    :data-testid="`type-remuneration-${action}`"
                                                    @click="
                                                        demander(data, action)
                                                    "
                                                >
                                                    <BadgeCheck
                                                        v-if="
                                                            action === 'valider'
                                                        "
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    <Lock
                                                        v-else-if="
                                                            action === 'fermer'
                                                        "
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    <Ban
                                                        v-else-if="
                                                            action ===
                                                            'desactiver'
                                                        "
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    <RotateCcw
                                                        v-else
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    {{
                                                        LIBELLES_ACTION[action]
                                                    }}
                                                </DropdownMenuItem>
                                            </template>
                                            <template
                                                v-if="peutSupprimer(data)"
                                            >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    class="text-destructive focus:text-destructive"
                                                    @click="
                                                        demander(
                                                            data,
                                                            'supprimer',
                                                        )
                                                    "
                                                >
                                                    <Trash2
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Supprimer
                                                </DropdownMenuItem>
                                            </template>
                                            <p
                                                v-if="
                                                    !aDesActions(data) &&
                                                    data.est_valide &&
                                                    can(
                                                        'rh-types-remuneration.update',
                                                    )
                                                "
                                                class="px-2 py-1.5 text-xs text-muted-foreground"
                                            >
                                                Type validé : seule une personne
                                                habilitée à valider peut le
                                                modifier.
                                            </p>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </template>
                        </Column>

                        <template #empty>
                            <div
                                class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                            >
                                <Gift class="h-10 w-10 opacity-30" />
                                <p class="text-sm">
                                    {{
                                        props.types.length === 0
                                            ? 'Aucun type de prime ou d’avantage pour le moment.'
                                            : 'Aucun type ne correspond aux filtres.'
                                    }}
                                </p>
                                <Button
                                    v-if="
                                        props.types.length === 0 &&
                                        can('rh-types-remuneration.create')
                                    "
                                    variant="outline"
                                    size="sm"
                                    @click="openCreate"
                                >
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier type
                                </Button>
                            </div>
                        </template>
                    </DataTable>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>

    <!-- Création / modification -->
    <Dialog
        v-model:visible="showDialog"
        modal
        :header="dialogTitle"
        :style="{ width: 'min(620px, 95vw)' }"
        :draggable="false"
        :closable="!form.processing"
        @hide="form.clearErrors()"
    >
        <form class="space-y-4 pt-2 pb-1" @submit.prevent="submit(false)">
            <div>
                <Label
                    for="tr-libelle"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Nom de la prime <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="tr-libelle"
                    v-model="form.libelle"
                    placeholder="Ex : Prime de transport, prime de rendement"
                    :class="{ 'border-destructive': form.errors.libelle }"
                />
                <p
                    v-if="form.errors.libelle"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.libelle }}
                </p>
            </div>

            <p
                v-if="categorieHistorique"
                class="rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
                data-testid="categorie-historique"
            >
                {{ categorieHistorique }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label
                        for="tr-mode"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Mode de calcul <span class="text-destructive">*</span>
                    </Label>
                    <select
                        id="tr-mode"
                        v-model="form.mode_calcul"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                    >
                        <option
                            v-for="m in modes_calcul"
                            :key="m.value"
                            :value="m.value"
                        >
                            {{ m.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.mode_calcul"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.mode_calcul }}
                    </p>
                </div>
                <div>
                    <Label
                        for="tr-periodicite"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Périodicité <span class="text-destructive">*</span>
                    </Label>
                    <select
                        id="tr-periodicite"
                        v-model="form.periodicite"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                    >
                        <option
                            v-for="p in periodicites"
                            :key="p.value"
                            :value="p.value"
                        >
                            {{ p.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.periodicite"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.periodicite }}
                    </p>
                </div>
            </div>

            <div>
                <template v-if="enPourcentage">
                    <Label
                        for="tr-taux"
                        class="mb-1.5 block text-xs font-medium"
                        >Taux par défaut (% du salaire de base)</Label
                    >
                    <Input
                        id="tr-taux"
                        v-model.number="form.taux_defaut"
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        placeholder="Facultatif"
                        :class="{
                            'border-destructive': form.errors.taux_defaut,
                        }"
                    />
                    <p
                        v-if="form.errors.taux_defaut"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.taux_defaut }}
                    </p>
                </template>
                <template v-else>
                    <Label
                        for="tr-montant"
                        class="mb-1.5 block text-xs font-medium"
                        >Montant par défaut (GNF)</Label
                    >
                    <MontantInput
                        id="tr-montant"
                        v-model="form.montant_defaut"
                        class="h-9"
                        placeholder="Facultatif — proposé à l’attribution"
                        :invalid="!!form.errors.montant_defaut"
                    />
                    <p
                        v-if="form.errors.montant_defaut"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.montant_defaut }}
                    </p>
                </template>
            </div>

            <div>
                <Label
                    for="tr-description"
                    class="mb-1.5 block text-xs font-medium"
                    >Description</Label
                >
                <textarea
                    id="tr-description"
                    v-model="form.description"
                    rows="2"
                    placeholder="Facultatif — conditions d’attribution, observations…"
                    class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
                <p
                    v-if="form.errors.description"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.description }}
                </p>
            </div>

            <details
                class="rounded-lg border"
                :open="avanceOuvert || erreurAvancee"
                data-testid="options-avancees"
                @toggle="
                    avanceOuvert = ($event.target as HTMLDetailsElement).open
                "
            >
                <summary
                    class="cursor-pointer px-3 py-2 text-sm font-medium select-none"
                >
                    Options avancées
                    <span class="ml-1 text-xs font-normal text-muted-foreground"
                        >code, nature du montant, période d’application,
                        prorata, cumul</span
                    >
                </summary>
                <div class="space-y-4 border-t p-3">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label
                                for="tr-code"
                                class="mb-1.5 block text-xs font-medium"
                                >Code</Label
                            >
                            <Input
                                id="tr-code"
                                v-model="form.code"
                                placeholder="Proposé automatiquement"
                                class="font-mono uppercase"
                                :class="{
                                    'border-destructive': form.errors.code,
                                }"
                            />
                            <p
                                v-if="form.errors.code"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.code }}
                            </p>
                        </div>
                        <div>
                            <Label
                                for="tr-nature"
                                class="mb-1.5 block text-xs font-medium"
                            >
                                Nature du montant
                            </Label>
                            <select
                                id="tr-nature"
                                v-model="form.nature_montant"
                                class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                            >
                                <option
                                    v-for="n in natures_montant"
                                    :key="n.value"
                                    :value="n.value"
                                >
                                    {{ n.label }}
                                </option>
                            </select>
                            <p
                                v-if="form.errors.nature_montant"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.nature_montant }}
                            </p>
                        </div>
                        <div>
                            <Label
                                for="tr-debut"
                                class="mb-1.5 block text-xs font-medium"
                                >Applicable à partir du</Label
                            >
                            <Input
                                id="tr-debut"
                                v-model="form.date_debut_application"
                                type="date"
                            />
                            <p
                                v-if="form.errors.date_debut_application"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.date_debut_application }}
                            </p>
                        </div>
                        <div>
                            <Label
                                for="tr-fin"
                                class="mb-1.5 block text-xs font-medium"
                                >Applicable jusqu’au</Label
                            >
                            <Input
                                id="tr-fin"
                                v-model="form.date_fin_application"
                                type="date"
                            />
                            <p
                                v-if="form.errors.date_fin_application"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.date_fin_application }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <Checkbox
                                id="tr-prorata"
                                :model-value="form.proratisable"
                                @update:model-value="
                                    form.proratisable = $event === true
                                "
                            />
                            <div>
                                <Label
                                    for="tr-prorata"
                                    class="cursor-pointer text-sm font-medium"
                                    >Proratisable</Label
                                >
                                <p class="text-xs text-muted-foreground">
                                    Réduite au prorata en cas d’entrée ou de
                                    sortie en cours de mois.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <Checkbox
                                id="tr-cumul"
                                :model-value="form.cumul_autorise"
                                @update:model-value="
                                    form.cumul_autorise = $event === true
                                "
                            />
                            <div>
                                <Label
                                    for="tr-cumul"
                                    class="cursor-pointer text-sm font-medium"
                                    >Cumul autorisé</Label
                                >
                                <p class="text-xs text-muted-foreground">
                                    Un salarié peut la recevoir plusieurs fois
                                    sur la même période.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </details>

            <p
                v-if="!peutValider"
                class="rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
            >
                {{
                    editingType
                        ? 'La proposition reste « À valider ».'
                        : 'Le type sera créé « À valider » : il ne pourra être attribué qu’après sa validation.'
                }}
            </p>

            <div class="flex flex-wrap justify-between gap-2 pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="form.processing"
                    @click="showDialog = false"
                    >Annuler</Button
                >
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        :variant="
                            peutEnregistrerEtValider ? 'outline' : 'default'
                        "
                        :disabled="form.processing"
                        data-testid="type-remuneration-enregistrer"
                    >
                        <Loader2
                            v-if="form.processing && !form.valider"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        {{ editingType ? 'Enregistrer' : 'Créer' }}
                    </Button>
                    <Button
                        v-if="peutEnregistrerEtValider"
                        type="button"
                        size="sm"
                        :disabled="form.processing"
                        data-testid="type-remuneration-enregistrer-valider"
                        @click="submit(true)"
                    >
                        <Loader2
                            v-if="form.processing && form.valider"
                            class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        />
                        {{
                            editingType
                                ? 'Enregistrer et valider'
                                : 'Créer et valider'
                        }}
                    </Button>
                </div>
            </div>
        </form>
    </Dialog>

    <!-- Confirmation d'un changement de statut ou d'une suppression -->
    <Dialog
        :visible="confirmation !== null"
        modal
        :header="
            confirmation
                ? `${LIBELLES_ACTION[confirmation.action]} « ${confirmation.type.libelle} »`
                : ''
        "
        :style="{ width: 'min(28rem, 95vw)' }"
        :draggable="false"
        :closable="!confirmationEnCours"
        @update:visible="fermerConfirmation"
    >
        <p v-if="confirmation" class="text-sm text-muted-foreground">
            {{ EXPLICATIONS_ACTION[confirmation.action] }}
        </p>
        <div
            v-if="confirmation?.action === 'desactiver'"
            class="mt-3 space-y-1.5"
        >
            <p
                v-if="confirmation.type.nb_beneficiaires > 0"
                class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
            >
                {{ confirmation.type.nb_beneficiaires }} affectation(s) en cours
                ou à venir : elles s’arrêteront la veille de la date d’effet
                (celles qui n’auraient commencé qu’après seront annulées).
            </p>
            <Label for="tr-date-effet" class="block text-xs font-medium">
                Plus rien n’est versé à partir du
                <span v-if="dateEffetRequise" class="text-destructive">*</span>
            </Label>
            <Input
                id="tr-date-effet"
                v-model="dateEffet"
                type="date"
                :min="premier_jour_modifiable ?? undefined"
            />
            <p v-if="!dateEffetRequise" class="text-xs text-muted-foreground">
                Aucun bénéficiaire : vide, la désactivation prend effet
                aujourd’hui.
            </p>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    variant="outline"
                    :disabled="confirmationEnCours"
                    @click="fermerConfirmation(false)"
                    >Annuler</Button
                >
                <Button
                    :variant="
                        confirmation?.action === 'supprimer' ||
                        confirmation?.action === 'desactiver'
                            ? 'destructive'
                            : 'default'
                    "
                    :disabled="
                        confirmationEnCours || (dateEffetRequise && !dateEffet)
                    "
                    data-testid="type-remuneration-confirmer"
                    @click="confirmer"
                >
                    <Loader2
                        v-if="confirmationEnCours"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    {{
                        confirmation ? LIBELLES_ACTION[confirmation.action] : ''
                    }}
                </Button>
            </div>
        </template>
    </Dialog>

    <AuditDrawer
        v-model:visible="historiqueVisible"
        :title="`Historique — ${historiqueType?.libelle ?? ''}`"
        auditable-type="type_remuneration"
        :auditable-id="historiqueType?.id ?? ''"
        :endpoint="`/backoffice/types-remuneration/${historiqueType?.id ?? ''}/historique`"
    />
</template>
