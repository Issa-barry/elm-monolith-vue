<script setup lang="ts">
import CommissionMontantFixeEditor, {
    type CommissionMontantFixeMembre,
} from '@/components/commission/CommissionMontantFixeEditor.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { paysOptionsByCode } from '@/lib/pays';
import { formatPhoneDisplay } from '@/lib/utils';
import { router, useForm } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import { computed, ref, watch } from 'vue';

// Même liste pays/indicatif que Proprietaires/Fournisseurs (resources/js/lib/pays.ts) — pas de
// deuxième logique téléphone, cf. CreateFournisseurModal.vue pour le patron d'origine.
const PAYS_OPTIONS = paysOptionsByCode;

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

export interface ParrainActuel {
    nom_complet: string | null;
    telephone: string | null;
    code_pays: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    pays: string | null;
    adresse: string | null;
}

interface PersonneTrouvee {
    id: string;
    nom_complet: string;
    telephone: string | null;
    code_phone_pays: string | null;
    code_pays: string | null;
    pays: string | null;
    ville: string | null;
    adresse: string | null;
}

/** Partage où le parrain actuel reçoit une part positive, à refaire pour le changer ou le retirer. */
interface GroupeARepartir {
    processus_code: string;
    processus_label: string;
    categorie_id: string;
    categorie_nom: string;
    bareme: number;
    part_parrain_actuelle: number;
    au_retrait: boolean;
    membres: Array<{
        livreur_id: string;
        nom_complet: string | null;
        role: string;
        montant_actuel: number;
    }>;
}

const props = defineProps<{
    visible: boolean;
    vehiculeId: string;
    /**
     * 'ajouter' : premier parrain ; 'changer' : nouvelle recherche puis remplacement ;
     * 'modifier' : identité éditée en place ; 'retirer' : retrait du parrain.
     */
    mode: 'ajouter' | 'changer' | 'modifier' | 'retirer';
    parrainActuel?: ParrainActuel | null;
    /**
     * Le parrain actuel reçoit une part des commissions : le changer ou le retirer impose
     * de refaire la répartition complète des partages concernés, enregistrée avec le
     * changement (ADR 0035).
     */
    partPositive?: boolean;
    /** Droit de répartir les commissions de l'équipe (`equipes-livraison.update`). */
    peutDefinirPart?: boolean;
    /** Le véhicule a une équipe : la part du parrain peut être répartie tout de suite. */
    aUneEquipe?: boolean;
    /** Effet d'une part « à répartir » sur les ventes (date d'activation, PARRAIN-022). */
    messageBlocage?: string;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
    /** Ouvrir la grille « Définir la part du parrain » après l'association (PARRAIN-021). */
    'definir-part': [];
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val: boolean) => emit('update:visible', val),
});

type Step =
    | 'telephone'
    | 'resultat'
    | 'creation'
    | 'edition'
    | 'repartition'
    | 'retrait'
    | 'apres_ajout';
const step = ref<Step>('telephone');

// ── Après l'association : sa part est à 0 GNF, à définir (PARRAIN-021) ──
const nomAssocie = ref('');

function apresAssociation(nom: string) {
    nomAssocie.value = nom;
    step.value = 'apres_ajout';
}

function definirPartMaintenant() {
    close();
    emit('definir-part');
}

// ── Nouvelle répartition (changement ou retrait d'un parrain qui a une part) ──

const groupes = ref<GroupeARepartir[]>([]);
const repartitions = ref<
    Record<
        string,
        { membres: CommissionMontantFixeMembre[]; partParrain: number }
    >
>({});
const chargementGroupes = ref(false);
const erreurRepartition = ref<string | null>(null);
const enregistrement = ref(false);
/** Étape d'où vient la répartition (pour « Retour ») et ce qu'elle enregistre. */
const origineRepartition = ref<'resultat' | 'creation' | null>(null);

const avecNouveauParrain = computed(() => props.mode !== 'retirer');
const nomNouveauParrain = computed(() =>
    origineRepartition.value === 'resultat'
        ? (personneTrouvee.value?.nom_complet ?? 'Nouveau parrain')
        : creationForm.nom_complet.trim() || 'Nouveau parrain',
);
const groupesAffiches = computed(() =>
    avecNouveauParrain.value
        ? groupes.value
        : groupes.value.filter((g) => g.au_retrait),
);

function cleGroupe(g: GroupeARepartir): string {
    return `${g.processus_code}|${g.categorie_id}`;
}

function libelleMembre(m: GroupeARepartir['membres'][number]): string {
    const role = m.role === 'chauffeur' ? 'Chauffeur' : 'Convoyeur';
    return m.nom_complet ? `${role} — ${m.nom_complet}` : role;
}

async function chargerGroupes(): Promise<boolean> {
    chargementGroupes.value = true;
    erreurRepartition.value = null;
    try {
        const response = await fetch(
            `/backoffice/vehicules/${props.vehiculeId}/parrain/repartitions`,
            { headers: { Accept: 'application/json' } },
        );
        if (!response.ok) {
            erreurRepartition.value =
                'Impossible de charger la répartition des commissions.';
            return false;
        }
        const body = await response.json();
        groupes.value = body.groupes as GroupeARepartir[];
        repartitions.value = Object.fromEntries(
            groupes.value.map((g) => [
                cleGroupe(g),
                {
                    membres: g.membres.map((m) => ({
                        id: m.livreur_id,
                        label: libelleMembre(m),
                        montant_unitaire: m.montant_actuel,
                    })),
                    // Point de départ : la part actuelle, au nouveau parrain — à confirmer
                    // ou modifier. Au retrait, cette part est à redistribuer à l'équipe.
                    partParrain: g.part_parrain_actuelle,
                },
            ]),
        );
        return true;
    } catch {
        erreurRepartition.value =
            'Impossible de charger la répartition des commissions.';
        return false;
    } finally {
        chargementGroupes.value = false;
    }
}

function totalGroupe(g: GroupeARepartir): number {
    const r = repartitions.value[cleGroupe(g)];
    if (!r) return 0;
    return (
        r.membres.reduce((s, m) => s + (m.montant_unitaire || 0), 0) +
        (avecNouveauParrain.value ? r.partParrain : 0)
    );
}

const repartitionsEquilibrees = computed(() =>
    groupesAffiches.value.every((g) => totalGroupe(g) === g.bareme),
);

/** Au retrait : répartit le barème entre les livreurs au prorata de leurs parts. */
function repartirAuProrata(g: GroupeARepartir) {
    const r = repartitions.value[cleGroupe(g)];
    if (!r || r.membres.length === 0) return;
    const total = r.membres.reduce((s, m) => s + (m.montant_unitaire || 0), 0);
    const parts = r.membres.map((m) =>
        total > 0
            ? Math.floor(((m.montant_unitaire || 0) * g.bareme) / total)
            : Math.floor(g.bareme / r.membres.length),
    );
    parts[0] += g.bareme - parts.reduce((s, p) => s + p, 0);
    r.membres = r.membres.map((m, i) => ({ ...m, montant_unitaire: parts[i] }));
}

function payloadRepartitions() {
    return groupesAffiches.value.map((g) => {
        const r = repartitions.value[cleGroupe(g)];
        return {
            processus_code: g.processus_code,
            categorie_id: g.categorie_id,
            parts: r.membres.map((m) => ({
                livreur_id: m.id,
                montant_unitaire: m.montant_unitaire || 0,
            })),
            ...(avecNouveauParrain.value
                ? { part_parrain: r.partParrain }
                : {}),
        };
    });
}

async function ouvrirRepartition(origine: 'resultat' | 'creation') {
    if (await chargerGroupes()) {
        origineRepartition.value = origine;
        step.value = 'repartition';
    }
}

function onErreurRepartition(errors: Record<string, string>) {
    erreurRepartition.value =
        errors.repartitions ??
        errors.parrain ??
        Object.values(errors)[0] ??
        null;
}

function enregistrerRepartition() {
    if (!repartitionsEquilibrees.value || enregistrement.value) return;

    const options = {
        preserveScroll: true,
        onStart: () => (enregistrement.value = true),
        onFinish: () => (enregistrement.value = false),
        onSuccess: () => close(),
        onError: onErreurRepartition,
    };

    if (props.mode === 'retirer') {
        router.delete(`/backoffice/vehicules/${props.vehiculeId}/parrain`, {
            ...options,
            data: { repartitions: payloadRepartitions() },
        });
        return;
    }

    const identite =
        origineRepartition.value === 'resultat' && personneTrouvee.value
            ? { personne_id: personneTrouvee.value.id }
            : { ...creationForm.data() };

    router.post(
        `/backoffice/vehicules/${props.vehiculeId}/parrain`,
        { ...identite, repartitions: payloadRepartitions() },
        options,
    );
}

function confirmerRetrait() {
    if (enregistrement.value) return;
    router.delete(`/backoffice/vehicules/${props.vehiculeId}/parrain`, {
        preserveScroll: true,
        onStart: () => (enregistrement.value = true),
        onFinish: () => (enregistrement.value = false),
        onSuccess: () => close(),
        onError: onErreurRepartition,
    });
}
const searching = ref(false);
const searchError = ref<string | null>(null);
const personneTrouvee = ref<PersonneTrouvee | null>(null);

const searchForm = useForm({
    code_pays: 'GN',
    code_phone_pays: '+224',
    telephone: null as string | null,
});

const creationForm = useForm({
    nom_complet: '',
    code_pays: 'GN',
    code_phone_pays: '+224',
    telephone: null as string | null,
    ville: null as string | null,
    adresse: null as string | null,
});

const editionForm = useForm({
    nom_complet: '',
    code_pays: 'GN',
    code_phone_pays: '+224',
    telephone: null as string | null,
    ville: null as string | null,
    adresse: null as string | null,
});

const associerForm = useForm({
    personne_id: null as string | null,
});

const searchCountry = computed(() =>
    PAYS_OPTIONS.find((c) => c.code === searchForm.code_pays),
);
const searchPhoneLength = computed(() => searchCountry.value?.localLength ?? 9);
const editionCountry = computed(() =>
    PAYS_OPTIONS.find((c) => c.code === editionForm.code_pays),
);
const editionPhoneLength = computed(
    () => editionCountry.value?.localLength ?? 9,
);

function digitsOnly(value: string | null | undefined): string {
    return String(value ?? '').replace(/\D/g, '');
}

function onSearchPaysChange(codePays: string) {
    const country = PAYS_OPTIONS.find((c) => c.value === codePays);
    if (!country) return;
    const currentDigits = digitsOnly(searchForm.telephone);
    const max = currentDigits.startsWith('0')
        ? country.localLength + 1
        : country.localLength;
    searchForm.code_pays = country.code;
    searchForm.code_phone_pays = country.dial;
    searchForm.telephone = currentDigits.slice(0, max) || null;
}

function onEditionPaysChange(codePays: string) {
    const country = PAYS_OPTIONS.find((c) => c.value === codePays);
    if (!country) return;
    const currentDigits = digitsOnly(editionForm.telephone);
    const max = currentDigits.startsWith('0')
        ? country.localLength + 1
        : country.localLength;
    editionForm.code_pays = country.code;
    editionForm.code_phone_pays = country.dial;
    editionForm.telephone = currentDigits.slice(0, max) || null;
}

function onSearchPhoneInput(value: string | null | undefined) {
    const raw = digitsOnly(value);
    const max = raw.startsWith('0')
        ? searchPhoneLength.value + 1
        : searchPhoneLength.value;
    searchForm.telephone = raw.slice(0, max) || null;
}

function onEditionPhoneInput(value: string | null | undefined) {
    const raw = digitsOnly(value);
    const max = raw.startsWith('0')
        ? editionPhoneLength.value + 1
        : editionPhoneLength.value;
    editionForm.telephone = raw.slice(0, max) || null;
}

function handlePhoneKeydown(e: KeyboardEvent) {
    const pass = [
        'Backspace',
        'Delete',
        'Tab',
        'Escape',
        'Enter',
        'ArrowLeft',
        'ArrowRight',
        'ArrowUp',
        'ArrowDown',
        'Home',
        'End',
    ];
    if (pass.includes(e.key)) return;
    if (
        (e.ctrlKey || e.metaKey) &&
        ['a', 'c', 'v', 'x'].includes(e.key.toLowerCase())
    )
        return;
    if (!/^\d$/.test(e.key)) e.preventDefault();
}

const dialogHeader = computed(() => {
    if (step.value === 'apres_ajout') return 'Parrain associé';
    if (step.value === 'repartition') return 'Nouvelle répartition';
    if (step.value === 'retrait') return 'Retirer le parrain';
    if (step.value === 'edition') return 'Modifier le parrain';
    if (step.value === 'creation') return 'Créer une personne';
    if (step.value === 'resultat')
        return personneTrouvee.value
            ? 'Personne trouvée'
            : 'Aucune personne trouvée';
    return props.mode === 'changer'
        ? 'Changer de parrain'
        : 'Ajouter un parrain';
});

watch(
    () => props.visible,
    (visible) => {
        if (!visible) return;
        searchError.value = null;
        personneTrouvee.value = null;
        erreurRepartition.value = null;
        groupes.value = [];
        origineRepartition.value = null;

        if (props.mode === 'retirer') {
            step.value = props.partPositive ? 'repartition' : 'retrait';
            if (props.partPositive) {
                void chargerGroupes();
            }
            return;
        }

        if (props.mode === 'modifier' && props.parrainActuel) {
            const dial = digitsOnly(props.parrainActuel.code_phone_pays);
            const raw = digitsOnly(props.parrainActuel.telephone);

            step.value = 'edition';
            editionForm.reset();
            editionForm.clearErrors();
            editionForm.nom_complet = props.parrainActuel.nom_complet ?? '';
            editionForm.code_pays = props.parrainActuel.code_pays ?? 'GN';
            editionForm.code_phone_pays =
                props.parrainActuel.code_phone_pays ?? '+224';
            editionForm.telephone =
                dial && raw.startsWith(dial) ? raw.slice(dial.length) : raw;
            editionForm.ville = props.parrainActuel.ville;
            editionForm.adresse = props.parrainActuel.adresse;
        } else {
            step.value = 'telephone';
            searchForm.reset();
            searchForm.clearErrors();
            searchForm.code_pays = 'GN';
            searchForm.code_phone_pays = '+224';
        }
    },
);

async function rechercher() {
    searchError.value = null;
    if (!searchForm.telephone) return;

    searching.value = true;
    try {
        const params = new URLSearchParams({
            telephone: searchForm.telephone,
            code_pays: searchForm.code_pays,
        });
        const response = await fetch(
            `/backoffice/vehicules/${props.vehiculeId}/parrain/rechercher?${params.toString()}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            const body = await response.json().catch(() => null);
            searchError.value =
                body?.errors?.telephone?.[0] ??
                'Le numéro de téléphone est invalide.';
            return;
        }

        const body = await response.json();
        personneTrouvee.value = body.found ? body.personne : null;
        step.value = 'resultat';
    } catch {
        searchError.value = 'Impossible de vérifier ce numéro pour le moment.';
    } finally {
        searching.value = false;
    }
}

function ouvrirCreation() {
    creationForm.reset();
    creationForm.clearErrors();
    creationForm.nom_complet = '';
    creationForm.code_pays = searchForm.code_pays;
    creationForm.code_phone_pays = searchForm.code_phone_pays;
    creationForm.telephone = searchForm.telephone;
    step.value = 'creation';
}

function retourRecherche() {
    step.value = 'telephone';
    personneTrouvee.value = null;
}

function utiliserPersonneTrouvee() {
    if (!personneTrouvee.value) return;
    // Remplacer un parrain qui a une part : nouvelle répartition jointe au changement.
    if (props.mode === 'changer' && props.partPositive) {
        void ouvrirRepartition('resultat');
        return;
    }
    const nom = personneTrouvee.value.nom_complet;
    associerForm.personne_id = personneTrouvee.value.id;
    associerForm.post(`/backoffice/vehicules/${props.vehiculeId}/parrain`, {
        preserveScroll: true,
        // La fenêtre reste ouverte sur l'étape « Parrain associé » après le rechargement.
        preserveState: true,
        onSuccess: () => apresAssociation(nom),
    });
}

function submitCreation() {
    if (props.mode === 'changer' && props.partPositive) {
        void ouvrirRepartition('creation');
        return;
    }
    const nom = creationForm.nom_complet.trim();
    creationForm.post(`/backoffice/vehicules/${props.vehiculeId}/parrain`, {
        preserveScroll: true,
        // La fenêtre reste ouverte sur l'étape « Parrain associé » après le rechargement.
        preserveState: true,
        onSuccess: () => apresAssociation(nom),
    });
}

function submitEdition() {
    editionForm.put(`/backoffice/vehicules/${props.vehiculeId}/parrain`, {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}

function close() {
    localVisible.value = false;
}

function onHide() {
    step.value = 'telephone';
    personneTrouvee.value = null;
    searchError.value = null;
    searchForm.reset();
    searchForm.clearErrors();
    creationForm.reset();
    creationForm.clearErrors();
    editionForm.reset();
    editionForm.clearErrors();
    associerForm.reset();
    associerForm.clearErrors();
    groupes.value = [];
    repartitions.value = {};
    erreurRepartition.value = null;
    origineRepartition.value = null;
    nomAssocie.value = '';
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        :header="dialogHeader"
        :style="{ width: '28rem' }"
        :draggable="false"
        @hide="onHide"
    >
        <!-- Étape 1 : saisie du téléphone, point d'entrée obligatoire -->
        <div v-if="step === 'telephone'" class="space-y-4">
            <p class="text-sm text-muted-foreground">
                Saisissez le numéro de téléphone du parrain : nous vérifions
                d'abord s'il correspond à une personne déjà connue.
            </p>

            <div class="space-y-1.5">
                <Label for="parrain-recherche-pays"
                    >Pays <span class="text-destructive">*</span></Label
                >
                <Dropdown
                    input-id="parrain-recherche-pays"
                    :model-value="searchForm.code_pays"
                    @update:model-value="onSearchPaysChange($event)"
                    :options="PAYS_OPTIONS"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                >
                    <template #value="{ value }">
                        <div v-if="value" class="flex items-center gap-2">
                            <img
                                :src="flagUrl(value)"
                                alt=""
                                class="h-4 w-auto rounded-sm shadow-sm"
                            />
                            <span>{{
                                PAYS_OPTIONS.find((c) => c.value === value)
                                    ?.label
                            }}</span>
                        </div>
                    </template>
                    <template #option="{ option }">
                        <div class="flex items-center gap-2">
                            <img
                                :src="flagUrl(option.code)"
                                :alt="option.label"
                                class="h-4 w-auto rounded-sm shadow-sm"
                            />
                            <span>{{ option.label }}</span>
                        </div>
                    </template>
                </Dropdown>
            </div>

            <div class="space-y-1.5">
                <Label for="parrain-recherche-telephone"
                    >Téléphone <span class="text-destructive">*</span></Label
                >
                <div class="flex gap-2">
                    <div
                        class="flex h-10 w-24 shrink-0 items-center justify-center gap-1.5 rounded-md border bg-muted/40 px-2 font-mono text-sm text-muted-foreground"
                    >
                        <img
                            v-if="searchCountry"
                            :src="flagUrl(searchCountry.code)"
                            alt=""
                            class="h-4 w-auto rounded-sm shadow-sm"
                        />
                        <span>{{ searchForm.code_phone_pays }}</span>
                    </div>
                    <InputText
                        id="parrain-recherche-telephone"
                        data-testid="parrain-recherche-telephone-input"
                        :model-value="searchForm.telephone ?? ''"
                        @update:model-value="onSearchPhoneInput($event)"
                        @keydown="handlePhoneKeydown"
                        :placeholder="`${searchPhoneLength} chiffres`"
                        inputmode="numeric"
                        class="w-full font-mono"
                        :class="{ 'p-invalid': searchError }"
                        @keyup.enter="rechercher"
                    />
                </div>
                <p v-if="searchError" class="text-xs text-destructive">
                    {{ searchError }}
                </p>
            </div>
        </div>

        <!-- Étape 2 : résultat de la recherche -->
        <div v-else-if="step === 'resultat'" class="space-y-4">
            <template v-if="personneTrouvee">
                <div class="rounded-lg border bg-muted/30 p-4">
                    <p class="text-sm font-semibold">
                        {{ personneTrouvee.nom_complet }}
                    </p>
                    <p class="mt-0.5 font-mono text-xs text-muted-foreground">
                        {{ formatPhoneDisplay(personneTrouvee.telephone) }}
                    </p>
                    <p
                        v-if="personneTrouvee.ville || personneTrouvee.pays"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        {{
                            [personneTrouvee.ville, personneTrouvee.pays]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </p>
                </div>
                <p class="text-xs text-muted-foreground">
                    Cette personne existe déjà dans l'organisation — elle ne
                    sera pas dupliquée.
                </p>
            </template>
            <template v-else>
                <p class="text-sm text-muted-foreground">
                    Aucune personne trouvée avec ce numéro.
                </p>
            </template>
        </div>

        <!-- Étape 3 : création d'une nouvelle personne (téléphone déjà vérifié, non modifiable ici) -->
        <div v-else-if="step === 'creation'" class="space-y-4">
            <div
                class="flex items-center justify-between rounded-lg border bg-muted/30 px-3 py-2"
            >
                <div class="flex items-center gap-1.5 font-mono text-sm">
                    <img
                        v-if="searchCountry"
                        :src="flagUrl(searchCountry.code)"
                        alt=""
                        class="h-4 w-auto rounded-sm shadow-sm"
                    />
                    {{ creationForm.code_phone_pays }}
                    {{ creationForm.telephone }}
                </div>
                <button
                    type="button"
                    class="text-xs font-medium text-primary hover:underline"
                    @click="retourRecherche"
                >
                    Modifier le numéro
                </button>
            </div>

            <div class="space-y-1.5">
                <Label for="parrain-creation-nom"
                    >Nom complet <span class="text-destructive">*</span></Label
                >
                <InputText
                    id="parrain-creation-nom"
                    data-testid="parrain-creation-nom-input"
                    v-model="creationForm.nom_complet"
                    class="w-full"
                    :class="{ 'p-invalid': creationForm.errors.nom_complet }"
                    autofocus
                    @keyup.enter="submitCreation"
                />
                <p
                    v-if="creationForm.errors.nom_complet"
                    class="text-xs text-destructive"
                >
                    {{ creationForm.errors.nom_complet }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <Label for="parrain-creation-ville">Ville</Label>
                    <InputText
                        id="parrain-creation-ville"
                        v-model="creationForm.ville"
                        class="w-full"
                        @keyup.enter="submitCreation"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="parrain-creation-adresse"
                        >Adresse / Quartier</Label
                    >
                    <InputText
                        id="parrain-creation-adresse"
                        v-model="creationForm.adresse"
                        class="w-full"
                        @keyup.enter="submitCreation"
                    />
                </div>
            </div>
        </div>

        <!-- Étape "modifier" : identité du parrain déjà rattaché, éditable en place -->
        <div v-else-if="step === 'edition'" class="space-y-4">
            <div class="space-y-1.5">
                <Label for="parrain-edition-nom"
                    >Nom complet <span class="text-destructive">*</span></Label
                >
                <InputText
                    id="parrain-edition-nom"
                    v-model="editionForm.nom_complet"
                    class="w-full"
                    :class="{ 'p-invalid': editionForm.errors.nom_complet }"
                    autofocus
                    @keyup.enter="submitEdition"
                />
                <p
                    v-if="editionForm.errors.nom_complet"
                    class="text-xs text-destructive"
                >
                    {{ editionForm.errors.nom_complet }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label for="parrain-edition-pays"
                    >Pays <span class="text-destructive">*</span></Label
                >
                <Dropdown
                    input-id="parrain-edition-pays"
                    :model-value="editionForm.code_pays"
                    @update:model-value="onEditionPaysChange($event)"
                    :options="PAYS_OPTIONS"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                >
                    <template #value="{ value }">
                        <div v-if="value" class="flex items-center gap-2">
                            <img
                                :src="flagUrl(value)"
                                alt=""
                                class="h-4 w-auto rounded-sm shadow-sm"
                            />
                            <span>{{
                                PAYS_OPTIONS.find((c) => c.value === value)
                                    ?.label
                            }}</span>
                        </div>
                    </template>
                    <template #option="{ option }">
                        <div class="flex items-center gap-2">
                            <img
                                :src="flagUrl(option.code)"
                                :alt="option.label"
                                class="h-4 w-auto rounded-sm shadow-sm"
                            />
                            <span>{{ option.label }}</span>
                        </div>
                    </template>
                </Dropdown>
            </div>

            <div class="space-y-1.5">
                <Label for="parrain-edition-telephone"
                    >Téléphone <span class="text-destructive">*</span></Label
                >
                <div class="flex gap-2">
                    <div
                        class="flex h-10 w-24 shrink-0 items-center justify-center gap-1.5 rounded-md border bg-muted/40 px-2 font-mono text-sm text-muted-foreground"
                    >
                        <img
                            v-if="editionCountry"
                            :src="flagUrl(editionCountry.code)"
                            alt=""
                            class="h-4 w-auto rounded-sm shadow-sm"
                        />
                        <span>{{ editionForm.code_phone_pays }}</span>
                    </div>
                    <InputText
                        id="parrain-edition-telephone"
                        data-testid="parrain-edition-telephone-input"
                        :model-value="editionForm.telephone ?? ''"
                        @update:model-value="onEditionPhoneInput($event)"
                        @keydown="handlePhoneKeydown"
                        :placeholder="`${editionPhoneLength} chiffres`"
                        inputmode="numeric"
                        class="w-full font-mono"
                        :class="{ 'p-invalid': editionForm.errors.telephone }"
                        @keyup.enter="submitEdition"
                    />
                </div>
                <p
                    v-if="editionForm.errors.telephone"
                    class="text-xs text-destructive"
                >
                    {{ editionForm.errors.telephone }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <Label for="parrain-edition-ville">Ville</Label>
                    <InputText
                        id="parrain-edition-ville"
                        v-model="editionForm.ville"
                        class="w-full"
                        @keyup.enter="submitEdition"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="parrain-edition-adresse"
                        >Adresse / Quartier</Label
                    >
                    <InputText
                        id="parrain-edition-adresse"
                        v-model="editionForm.adresse"
                        class="w-full"
                        @keyup.enter="submitEdition"
                    />
                </div>
            </div>
        </div>

        <!-- Nouvelle répartition : changer ou retirer un parrain qui a une part (ADR 0035) -->
        <div v-else-if="step === 'repartition'" class="space-y-4">
            <p class="text-sm text-muted-foreground">
                <template v-if="avecNouveauParrain">
                    Le parrain actuel reçoit une part des commissions. Vérifiez
                    la répartition de chaque enveloppe entre l'équipe et le
                    nouveau parrain : elle est enregistrée avec le changement.
                </template>
                <template v-else>
                    Le parrain reçoit une part des commissions : répartissez à
                    nouveau chaque enveloppe entre les membres de l'équipe.
                </template>
                Les commissions déjà calculées ne changent pas.
            </p>

            <p v-if="chargementGroupes" class="text-sm text-muted-foreground">
                Chargement de la répartition…
            </p>

            <div
                v-for="g in groupesAffiches"
                :key="cleGroupe(g)"
                class="space-y-2"
                :data-testid="`parrain-repartition-${g.processus_code}`"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <p class="text-sm font-semibold">
                        {{ g.processus_label }} · {{ g.categorie_nom }}
                    </p>
                    <button
                        v-if="!avecNouveauParrain"
                        type="button"
                        class="text-xs font-medium text-primary hover:underline"
                        @click="repartirAuProrata(g)"
                    >
                        Répartir au prorata
                    </button>
                </div>
                <CommissionMontantFixeEditor
                    v-if="repartitions[cleGroupe(g)]"
                    :model-value="repartitions[cleGroupe(g)].membres"
                    :enveloppe-unitaire="g.bareme"
                    :parrain="
                        avecNouveauParrain ? { nom: nomNouveauParrain } : null
                    "
                    :part-parrain="repartitions[cleGroupe(g)].partParrain"
                    @update:model-value="
                        repartitions[cleGroupe(g)].membres = $event
                    "
                    @update:part-parrain="
                        repartitions[cleGroupe(g)].partParrain = $event
                    "
                />
            </div>

            <p
                v-if="erreurRepartition"
                class="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
                data-testid="parrain-repartition-erreur"
            >
                {{ erreurRepartition }}
            </p>
        </div>

        <!-- Après l'association : sa part est à 0 GNF, à définir (PARRAIN-021) -->
        <div
            v-else-if="step === 'apres_ajout'"
            class="space-y-3"
            data-testid="parrain-apres-ajout"
        >
            <p class="text-sm">
                <span class="font-semibold">{{ nomAssocie }}</span> est associé
                au véhicule.
            </p>
            <div
                class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-200"
            >
                Sa part des commissions est de
                <span class="font-semibold">0 GNF</span> pour l'instant : il ne
                reçoit rien tant qu'elle n'est pas répartie.
                {{ messageBlocage }}
            </div>
            <p v-if="!aUneEquipe" class="text-xs text-muted-foreground">
                Le véhicule n'a pas encore d'équipe : sa part se définira une
                fois l'équipe configurée.
            </p>
            <p
                v-else-if="!peutDefinirPart"
                class="text-xs text-muted-foreground"
            >
                Un responsable des équipes de livraison doit définir sa part
                (onglet Parrain).
            </p>
        </div>

        <!-- Retrait d'un parrain sans part : simple confirmation -->
        <div v-else-if="step === 'retrait'" class="space-y-3">
            <p class="text-sm">
                Retirer
                <span class="font-semibold">{{
                    parrainActuel?.nom_complet
                }}</span>
                de ce véhicule ?
            </p>
            <p class="text-xs text-muted-foreground">
                Sa part des commissions est nulle : la répartition de l'équipe
                ne change pas. Il reste enregistré comme personne et peut
                parrainer d'autres véhicules.
            </p>
            <p
                v-if="erreurRepartition"
                class="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
            >
                {{ erreurRepartition }}
            </p>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <template v-if="step === 'apres_ajout'">
                    <Button
                        variant="outline"
                        data-testid="parrain-part-plus-tard-btn"
                        @click="close"
                        >{{
                            peutDefinirPart && aUneEquipe
                                ? 'Plus tard'
                                : 'Fermer'
                        }}</Button
                    >
                    <Button
                        v-if="peutDefinirPart && aUneEquipe"
                        data-testid="parrain-definir-part-btn"
                        @click="definirPartMaintenant"
                    >
                        Définir sa part maintenant
                    </Button>
                </template>

                <template v-if="step === 'repartition'">
                    <Button
                        v-if="origineRepartition"
                        variant="outline"
                        :disabled="enregistrement"
                        @click="step = origineRepartition"
                        >Retour</Button
                    >
                    <Button
                        v-else
                        variant="outline"
                        :disabled="enregistrement"
                        @click="close"
                        >Annuler</Button
                    >
                    <Button
                        data-testid="parrain-repartition-enregistrer-btn"
                        :disabled="
                            !repartitionsEquilibrees ||
                            chargementGroupes ||
                            enregistrement
                        "
                        @click="enregistrerRepartition"
                    >
                        {{
                            enregistrement
                                ? 'Enregistrement…'
                                : avecNouveauParrain
                                  ? 'Changer de parrain'
                                  : 'Retirer le parrain'
                        }}
                    </Button>
                </template>

                <template v-else-if="step === 'retrait'">
                    <Button
                        variant="outline"
                        :disabled="enregistrement"
                        @click="close"
                        >Annuler</Button
                    >
                    <Button
                        variant="destructive"
                        data-testid="parrain-retrait-confirmer-btn"
                        :disabled="enregistrement"
                        @click="confirmerRetrait"
                    >
                        {{ enregistrement ? 'Retrait…' : 'Retirer' }}
                    </Button>
                </template>

                <template v-if="step === 'telephone'">
                    <Button variant="outline" @click="close">Annuler</Button>
                    <Button
                        data-testid="parrain-rechercher-btn"
                        :disabled="!searchForm.telephone || searching"
                        @click="rechercher"
                    >
                        {{ searching ? 'Recherche…' : 'Rechercher' }}
                    </Button>
                </template>

                <template v-else-if="step === 'resultat'">
                    <Button variant="outline" @click="retourRecherche"
                        >Modifier le numéro</Button
                    >
                    <Button
                        v-if="personneTrouvee"
                        data-testid="parrain-utiliser-personne-btn"
                        :disabled="associerForm.processing"
                        @click="utiliserPersonneTrouvee"
                    >
                        Utiliser cette personne
                    </Button>
                    <Button
                        v-else
                        data-testid="parrain-creer-personne-btn"
                        @click="ouvrirCreation"
                    >
                        Créer une personne
                    </Button>
                </template>

                <template v-else-if="step === 'creation'">
                    <Button variant="outline" @click="retourRecherche"
                        >Retour</Button
                    >
                    <Button
                        data-testid="parrain-creer-et-associer-btn"
                        :disabled="
                            creationForm.processing ||
                            !creationForm.nom_complet.trim()
                        "
                        @click="submitCreation"
                    >
                        {{
                            mode === 'changer' && partPositive
                                ? 'Continuer'
                                : 'Créer et associer'
                        }}
                    </Button>
                </template>

                <template v-else-if="step === 'edition'">
                    <Button variant="outline" @click="close">Annuler</Button>
                    <Button
                        data-testid="parrain-enregistrer-btn"
                        :disabled="
                            editionForm.processing ||
                            !editionForm.nom_complet.trim()
                        "
                        @click="submitEdition"
                    >
                        Enregistrer
                    </Button>
                </template>
            </div>
        </template>
    </Dialog>
</template>
