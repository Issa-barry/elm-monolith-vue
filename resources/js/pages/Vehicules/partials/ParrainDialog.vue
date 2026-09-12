<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { paysOptionsByCode } from '@/lib/pays';
import { formatPhoneDisplay } from '@/lib/utils';
import { useForm } from '@inertiajs/vue3';
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

const props = defineProps<{
    visible: boolean;
    vehiculeId: string;
    /** 'ajouter' couvre aussi "Changer de parrain" (nouvelle recherche) ; 'modifier' édite en place. */
    mode: 'ajouter' | 'modifier';
    parrainActuel?: ParrainActuel | null;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val: boolean) => emit('update:visible', val),
});

type Step = 'telephone' | 'resultat' | 'creation' | 'edition';
const step = ref<Step>('telephone');
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
    if (step.value === 'edition') return 'Modifier le parrain';
    if (step.value === 'creation') return 'Créer une personne';
    if (step.value === 'resultat')
        return personneTrouvee.value
            ? 'Personne trouvée'
            : 'Aucune personne trouvée';
    return 'Ajouter un parrain';
});

watch(
    () => props.visible,
    (visible) => {
        if (!visible) return;
        searchError.value = null;
        personneTrouvee.value = null;

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
    associerForm.personne_id = personneTrouvee.value.id;
    associerForm.post(`/backoffice/vehicules/${props.vehiculeId}/parrain`, {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}

function submitCreation() {
    creationForm.post(`/backoffice/vehicules/${props.vehiculeId}/parrain`, {
        preserveScroll: true,
        onSuccess: () => close(),
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

        <template #footer>
            <div class="flex justify-end gap-2">
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
                        Créer et associer
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
