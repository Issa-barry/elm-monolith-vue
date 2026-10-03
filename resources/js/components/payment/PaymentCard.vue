<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatGNF } from '@/lib/utils';
import { Building2, HandCoins } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';
import {
    type AgenceEncaissement,
    construireOptions,
    type EncaissementAgences,
    type EncaissementPayload,
    type ModeOption,
    type MoyenEncaissement,
} from './moyensEncaissement';

interface InfoRow {
    label: string;
    value: string;
}

interface Props {
    visible: boolean;
    title: string;
    /** Montant affiché dans le bloc "Montant dû" + valeur max par défaut */
    solde: number;
    /** Lignes de contexte optionnelles affichées au-dessus du bloc Montant dû (ex: référence commande) */
    infoRows?: InfoRow[];
    /** Surcharge du max InputNumber si différent du solde */
    maxMontant?: number;
    processing?: boolean;
    errors?: Record<string, string>;
    /** Moyens hors espèces disponibles dans l'agence de la facture, fournis par le backend
     * (`moyens_encaissement`, un par support de trésorerie actif) — jamais une liste fixe : un
     * opérateur sans support dans l'agence n'est pas proposé. Défaut vide : un écran qui l'oublierait
     * ne propose rien de plus que les espèces, jamais un moyen sans compte réel derrière. */
    moyens?: MoyenEncaissement[];
    /** L'utilisateur peut-il encaisser en espèces sur cette facture ? Fourni par le backend
     * (`peut_encaisser_especes`) : caisse dédiée active sur le site de la facture. Défaut `true` :
     * un écran qui l'oublierait ne masque rien, la garantie réelle reste côté serveur. */
    especesDisponibles?: boolean;
    /** « encaissement » (défaut) : l'argent entre dans un support. « decaissement » : il en sort
     * (paiement de fiche, ADR 0009) — libellés adaptés et solde disponible affiché/contrôlé. */
    sens?: 'encaissement' | 'decaissement';
    /** Décaissement : solde de la caisse dédiée du payeur (espèces), null = inconnu. */
    soldeEspeces?: number | null;
    /** Libellé du bloc principal (défaut « Montant dû »). */
    soldeLabel?: string;
    /** Agences où l'utilisateur peut encaisser (ADR 0012), fournies par le backend
     * (AgenceEncaissementResolver) — seulement par l'écran « commande d'une autre agence ». Quand
     * elles sont fournies, moyens et espèces sont ceux de l'agence choisie (`moyens` et
     * `especesDisponibles` sont alors ignorées) et le paiement porte `site_encaissement_id`. */
    agences?: AgenceEncaissement[];
    agenceDefaut?: string | null;
    /** Agence de la commande : un bandeau d'information s'affiche si l'argent est reçu ailleurs. */
    agenceCommande?: { id: string; nom: string } | null;
    /** Regroupe agences / agence présélectionnée / agence de la commande / raison d'un refus, tels que
     * fournis par le backend (AgenceEncaissementResolver::pourEcran()) — prioritaire sur les trois
     * props précédentes. L'agence d'encaissement est toujours une agence de l'utilisateur (ADR 0012). */
    encaissementAgences?: EncaissementAgences | null;
}

// Messages courts d'interface ; les contrôles et messages backend restent inchangés.
const MESSAGE_ESPECES_INDISPONIBLE =
    'Espèces indisponibles : aucune caisse active.';
const MESSAGE_ESPECES_INDISPONIBLE_DECAISSEMENT =
    'Paiement en espèces indisponible : aucune caisse active.';

// Une seule liste déroulante : Espèces, puis chaque support de l'agence (un Mobile Money par
// opérateur réellement configuré, virement/chèque par banque) — jamais un second select opérateur.
// Le choix envoie mode_paiement (4 valeurs stables attendues par la comptabilisation) + le support
// choisi (compte_tresorerie_id) ; l'opérateur est déduit du support côté serveur. Voir
// docs/encaissements.md.
const props = withDefaults(defineProps<Props>(), {
    infoRows: () => [],
    maxMontant: undefined,
    processing: false,
    errors: () => ({}),
    moyens: () => [],
    especesDisponibles: true,
    sens: 'encaissement',
    soldeEspeces: null,
    soldeLabel: 'Montant dû',
    agences: () => [],
    agenceDefaut: null,
    agenceCommande: null,
    encaissementAgences: null,
});

const decaissement = computed(() => props.sens === 'decaissement');

// Agence d'encaissement (ADR 0012) : sans liste d'agences, l'écran encaisse dans l'agence de la
// facture avec les moyens reçus en props — comportement de tous les écrans existants.
const siteEncaissementId = ref('');
const agencesProposees = computed<AgenceEncaissement[]>(
    () => props.encaissementAgences?.agences ?? props.agences,
);
const agenceDefautProposee = computed(() =>
    props.encaissementAgences
        ? props.encaissementAgences.agence_defaut
        : props.agenceDefaut,
);
const agenceDeLaCommande = computed(() =>
    props.encaissementAgences
        ? props.encaissementAgences.agence_commande
        : props.agenceCommande,
);
// Aucune agence où l'utilisateur peut encaisser (non affecté, pas autorisé) : bloquant.
const refusAgence = computed(() => props.encaissementAgences?.message ?? null);
const avecChoixAgence = computed(() => agencesProposees.value.length > 0);
const agenceActive = computed(
    () =>
        agencesProposees.value.find(
            (a) => a.site_id === siteEncaissementId.value,
        ) ?? null,
);
const moyensEffectifs = computed(() =>
    avecChoixAgence.value ? (agenceActive.value?.moyens ?? []) : props.moyens,
);
const especesEffectives = computed(() =>
    avecChoixAgence.value
        ? (agenceActive.value?.peut_encaisser_especes ?? false)
        : props.especesDisponibles,
);
// Agence qui détiendra l'argent : celle du compte choisi (un compte commun peut être détenu par
// une autre agence, ADR 0016), sinon l'agence qui encaisse (espèces, aucun mode choisi).
const detenteur = computed(() => {
    const mode = modeByKey(selectedKey.value);
    if (mode?.siteDetenteurId) {
        return { id: mode.siteDetenteurId, nom: mode.siteDetenteurNom ?? '' };
    }

    return agenceActive.value
        ? { id: agenceActive.value.site_id, nom: agenceActive.value.nom }
        : null;
});
const pourAutreAgence = computed(
    () =>
        avecChoixAgence.value &&
        !!agenceDeLaCommande.value &&
        !!detenteur.value &&
        detenteur.value.id !== agenceDeLaCommande.value.id,
);

const modeOptions = computed(() =>
    construireOptions(moyensEffectifs.value).map((option) =>
        option.requiresCaisse
            ? { ...option, soldeDisponible: props.soldeEspeces }
            : option,
    ),
);

const messageEspecesIndisponible = computed(() =>
    decaissement.value
        ? MESSAGE_ESPECES_INDISPONIBLE_DECAISSEMENT
        : MESSAGE_ESPECES_INDISPONIBLE,
);

function modeIndisponible(mode: ModeOption): boolean {
    return !!mode.requiresCaisse && !especesEffectives.value;
}

const especesBloquees = computed(() =>
    modeOptions.value.some((m) => modeIndisponible(m)),
);

const aucunAutreMoyen = computed(() => moyensEffectifs.value.length === 0);

// Mode présélectionné à l'ouverture : le premier, sauf s'il est indisponible — jamais un autre mode
// choisi à la place de l'utilisateur (un Mobile Money enregistré par erreur serait un encaissement
// mal classé) : la liste reste vide et il choisit lui-même.
function modeInitial(): string {
    const premier = modeOptions.value[0];

    return premier && !modeIndisponible(premier) ? premier.key : '';
}

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
    (e: 'submit', payload: EncaissementPayload): void;
}>();

// Proxy v-model:visible vers le parent sans mutation de prop
const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

const montant = ref<number | null>(null);
const selectedKey = ref(modeInitial());
const referencePaiement = ref('');

// Référence Mobile Money déjà utilisée (ADR 0014) : le serveur nomme la facture concernée dans le
// message et envoie son numéro seul dans `reference_paiement_facture`, rendu copiable ici.
const factureReferenceUtilisee = computed(
    () => props.errors?.reference_paiement_facture ?? '',
);
const messageReferenceUtilisee = computed(() => {
    const message = props.errors?.reference_paiement ?? '';
    const position = message.lastIndexOf(factureReferenceUtilisee.value);

    return position > 0 ? message.slice(0, position).trimEnd() : message;
});
const factureCopiee = ref(false);

async function copierFactureUtilisee() {
    await navigator.clipboard.writeText(factureReferenceUtilisee.value);
    factureCopiee.value = true;
    setTimeout(() => (factureCopiee.value = false), 2000);
}

function modeByKey(key: string): ModeOption | undefined {
    return modeOptions.value.find((m) => m.key === key);
}

const modeActif = computed(() => modeByKey(selectedKey.value));

// Décaissement : solde connu du support choisi. Le contrôle réel reste serveur (sous verrou) —
// ici on évite seulement une confirmation vouée à l'échec.
const soldeSupport = computed(() =>
    decaissement.value ? (modeActif.value?.soldeDisponible ?? null) : null,
);
const soldeInsuffisant = computed(
    () =>
        soldeSupport.value !== null &&
        (montant.value ?? 0) > soldeSupport.value + 0.004,
);
const referencePaiementRequise = computed(
    () => modeActif.value?.requiresReference ?? false,
);
const referencePlaceholder = computed(
    () =>
        modeActif.value?.referencePlaceholder ??
        'Numéro ou référence de la transaction',
);

watch(
    () => props.visible,
    (open) => {
        if (open) {
            montant.value = props.solde > 0 ? props.solde : null;
            siteEncaissementId.value =
                agenceDefautProposee.value ??
                agencesProposees.value[0]?.site_id ??
                '';
            selectedKey.value = modeInitial();
            referencePaiement.value = '';
        }
    },
    { immediate: true },
);

// La disponibilité des espèces peut changer pendant que la fenêtre est ouverte (rafraîchissement
// des données de la page) : une sélection devenue impossible est retirée, jamais soumise.
// Changer d'agence change la liste des moyens : le mode est choisi à nouveau, jamais conservé
// d'une agence à l'autre (un support n'appartient qu'à une agence).
watch(siteEncaissementId, () => {
    selectedKey.value = modeInitial();
});

watch(
    () => especesEffectives.value,
    () => {
        const actif = modeActif.value;
        if (actif && modeIndisponible(actif)) {
            selectedKey.value = '';
        }
    },
);

// Effacer la référence dès qu'on quitte un mode qui l'exige, pour ne jamais soumettre une
// valeur devenue obsolète après un changement de mode dans la même ouverture.
watch(selectedKey, (key) => {
    if (!modeByKey(key)?.requiresReference) {
        referencePaiement.value = '';
    }
});

// Les montants pré-formatés reçus des pages parentes (infoRows) utilisent souvent l'espace fine
// U+202F de fr-FR, quasi invisible : on la remplace par une espace normale.
function espacer(texte: string): string {
    return texte.replace(/[  ]/g, ' ');
}

function close() {
    emit('update:visible', false);
}

function handleSubmit() {
    if (refusAgence.value) return;
    if (!montant.value || montant.value <= 0) return;
    const mode = modeActif.value;
    if (!mode || modeIndisponible(mode)) return;
    if (mode.requiresReference && !referencePaiement.value) return;
    if (soldeInsuffisant.value) return;
    emit('submit', {
        montant: montant.value,
        mode_paiement: mode.mode_paiement,
        compte_tresorerie_id: mode.compte_tresorerie_id,
        reference_paiement: referencePaiement.value || undefined,
        site_encaissement_id: avecChoixAgence.value
            ? siteEncaissementId.value || undefined
            : undefined,
    });
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        :header="title"
        :style="{ width: '460px', maxWidth: 'calc(100vw - 2rem)' }"
        :draggable="false"
    >
        <div class="space-y-5 py-1">
            <!-- Contexte optionnel (ex: référence commande, montant total) — secondaire, ne doit
                 jamais rivaliser visuellement avec le montant dû ci-dessous. -->
            <div
                v-if="infoRows.length"
                class="space-y-1 text-sm text-muted-foreground"
            >
                <div
                    v-for="row in infoRows"
                    :key="row.label"
                    class="flex justify-between"
                >
                    <span>{{ row.label }}</span>
                    <span class="font-medium text-foreground">{{
                        espacer(row.value)
                    }}</span>
                </div>
            </div>

            <!-- Montant dû — information principale du modal, à ne jamais confondre avec le
                 montant saisi ci-dessous (cf. docs/encaissements.md). -->
            <div
                class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-lg bg-muted px-4 py-3"
            >
                <p class="text-sm font-medium text-foreground">
                    {{ soldeLabel }}
                </p>
                <p
                    class="text-xl font-bold tracking-tight text-foreground tabular-nums [word-spacing:0.16em]"
                >
                    {{ formatGNF(solde) }}
                </p>
            </div>

            <!-- Aucune agence où encaisser : l'opération est réellement bloquée (rouge). -->
            <p
                v-if="refusAgence"
                class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                data-testid="refus-agence-encaissement"
            >
                {{ refusAgence }}
            </p>

            <!-- Agence d'encaissement (ADR 0012) : toujours l'une des agences de l'utilisateur. -->
            <div v-if="avecChoixAgence" data-testid="agence-encaissement">
                <Label class="mb-1.5 block text-sm"
                    >Agence <span class="text-destructive">*</span></Label
                >
                <Select
                    v-if="agencesProposees.length > 1"
                    v-model="siteEncaissementId"
                    :options="agencesProposees"
                    option-label="nom"
                    option-value="site_id"
                    placeholder="Choisir une agence"
                    class="w-full"
                    :class="{ 'p-invalid': errors?.site_encaissement_id }"
                />
                <p
                    v-else
                    class="flex items-center gap-2 text-sm font-medium text-foreground"
                >
                    <Building2 class="h-4 w-4 text-muted-foreground" />
                    {{ agenceActive?.nom ?? '—' }}
                </p>
                <p
                    v-if="errors?.site_encaissement_id"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.site_encaissement_id }}
                </p>
                <!-- Rappel court du reversement à l'agence de la commande. -->
                <p
                    v-if="pourAutreAgence"
                    class="mt-2 text-sm leading-relaxed text-foreground"
                    data-testid="bandeau-autre-agence"
                >
                    {{ detenteur?.nom }} devra reverser à
                    {{ agenceDeLaCommande?.nom }}.
                </p>
            </div>

            <!-- Montant à encaisser -->
            <div>
                <Label class="mb-2 flex items-center gap-1 text-sm font-medium"
                    >{{
                        decaissement ? 'Montant à payer' : 'Montant à encaisser'
                    }}
                    <span class="text-destructive">*</span>
                </Label>
                <div class="relative">
                    <!-- fr-CA et non fr-FR : sépare les milliers par une espace insécable de largeur
                         normale (U+00A0) au lieu de l'espace fine U+202F, quasi invisible en gras. -->
                    <InputNumber
                        v-model="montant"
                        :min="1"
                        :max="maxMontant ?? solde"
                        :min-fraction-digits="0"
                        :max-fraction-digits="0"
                        :use-grouping="true"
                        locale="fr-CA"
                        class="w-full"
                        input-class="h-14 w-full pr-16 text-xl font-bold tabular-nums [word-spacing:0.19em]"
                        :class="{ 'p-invalid': errors?.montant }"
                    />
                    <span
                        class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 rounded-md border border-border bg-muted px-2.5 py-1 text-sm font-semibold text-muted-foreground"
                    >
                        GNF
                    </span>
                </div>
                <p v-if="errors?.montant" class="mt-1 text-xs text-destructive">
                    {{ errors.montant }}
                </p>
            </div>

            <!-- Mode de paiement — une seule liste : espèces + supports de l'agence -->
            <div>
                <Label class="mb-1.5 block text-sm"
                    >Mode de paiement
                    <span class="text-destructive">*</span></Label
                >
                <Select
                    v-model="selectedKey"
                    :options="modeOptions"
                    option-label="label"
                    option-value="key"
                    :option-disabled="modeIndisponible"
                    placeholder="Choisir un mode de paiement"
                    class="w-full"
                    :class="{ 'p-invalid': errors?.mode_paiement }"
                >
                    <template #value="{ value }">
                        <div
                            v-if="modeByKey(value)"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md"
                                :class="modeByKey(value)!.badgeClass"
                            >
                                <component
                                    :is="modeByKey(value)!.icon"
                                    class="h-3.5 w-3.5"
                                />
                            </span>
                            <span>{{ modeByKey(value)!.label }}</span>
                        </div>
                        <span v-else class="text-muted-foreground"
                            >Choisir un mode de paiement</span
                        >
                    </template>
                    <template #option="{ option }">
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md"
                                :class="option.badgeClass"
                            >
                                <component
                                    :is="option.icon"
                                    class="h-3.5 w-3.5"
                                />
                            </span>
                            <span>{{ option.label }}</span>
                            <span
                                v-if="option.numero"
                                class="text-xs text-muted-foreground tabular-nums"
                                data-testid="moyen-numero"
                                >N° {{ option.numero }}</span
                            >
                            <span
                                v-if="modeIndisponible(option)"
                                class="text-xs text-muted-foreground"
                                >— caisse requise</span
                            >
                        </div>
                    </template>
                </Select>
                <!-- Espèces impossibles (aucune caisse dédiée active sur ce site) : message visible en
                     permanence sous la liste, pas seulement au survol. Ambre (attention, les autres
                     modes restent possibles) — jamais rouge : rien n'est bloqué pour eux. -->
                <p
                    v-if="especesBloquees"
                    class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm leading-relaxed text-amber-950 dark:bg-amber-950/40 dark:text-amber-200"
                    data-testid="especes-indisponible"
                >
                    <template v-if="aucunAutreMoyen">
                        Aucun moyen de paiement disponible. Contactez votre
                        responsable.
                    </template>
                    <template v-else>{{ messageEspecesIndisponible }}</template>
                </p>
                <!-- Décaissement : solde du support choisi ; rouge seulement s'il bloque le paiement. -->
                <p
                    v-if="soldeSupport !== null"
                    class="mt-1.5 text-xs"
                    :class="
                        soldeInsuffisant
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                    data-testid="solde-disponible"
                >
                    <template v-if="soldeInsuffisant">
                        Solde insuffisant : {{ formatGNF(soldeSupport) }}
                        disponible.
                    </template>
                    <template v-else>
                        Disponible : {{ formatGNF(soldeSupport) }}
                    </template>
                </p>
                <p
                    v-if="errors?.mode_paiement"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.mode_paiement }}
                </p>
                <!-- Mention utile seulement si les espèces sont effectivement disponibles. -->
                <p
                    v-if="aucunAutreMoyen && !especesBloquees"
                    class="mt-2 text-sm leading-relaxed text-foreground"
                    data-testid="aucun-autre-moyen"
                >
                    Espèces uniquement.
                </p>
                <p
                    v-if="errors?.compte_tresorerie_id"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.compte_tresorerie_id }}
                </p>
            </div>

            <!-- Référence de paiement -->
            <div v-if="referencePaiementRequise">
                <Label class="mb-1.5 block text-sm"
                    >Référence de transaction
                    <span class="text-destructive">*</span></Label
                >
                <InputText
                    v-model="referencePaiement"
                    :placeholder="referencePlaceholder"
                    class="w-full"
                    :class="{ 'p-invalid': errors?.reference_paiement }"
                />
                <p
                    v-if="
                        errors?.reference_paiement && factureReferenceUtilisee
                    "
                    class="mt-1 flex flex-wrap items-center gap-1 text-xs text-destructive"
                >
                    {{ messageReferenceUtilisee }}
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 font-mono text-foreground transition-colors hover:bg-muted/70"
                        title="Cliquez pour copier le numéro de facture"
                        @click="copierFactureUtilisee"
                    >
                        {{ factureReferenceUtilisee }}
                        <i
                            :class="
                                factureCopiee
                                    ? 'pi pi-check text-green-600'
                                    : 'pi pi-clipboard'
                            "
                            style="font-size: 0.75rem"
                        />
                    </button>
                </p>
                <p
                    v-else-if="errors?.reference_paiement"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.reference_paiement }}
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex w-full items-center justify-between gap-3">
                <Button variant="outline" :disabled="processing" @click="close">
                    Annuler
                </Button>
                <Button
                    :disabled="
                        processing ||
                        !!refusAgence ||
                        !montant ||
                        !modeActif ||
                        soldeInsuffisant ||
                        (referencePaiementRequise && !referencePaiement)
                    "
                    @click="handleSubmit"
                >
                    <HandCoins v-if="!processing" class="mr-1.5 h-4 w-4" />
                    <span
                        v-else
                        class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                    />
                    Confirmer
                </Button>
            </div>
        </template>
    </Dialog>
</template>
