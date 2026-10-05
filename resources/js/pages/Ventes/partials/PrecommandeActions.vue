<script setup lang="ts">
import type {
    EncaissementPayload,
    MoyenEncaissement,
} from '@/components/payment/moyensEncaissement';
import PaymentCard from '@/components/payment/PaymentCard.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatGNF } from '@/lib/utils';
import { router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    CalendarClock,
    CheckCircle2,
    HandCoins,
    PackageCheck,
    PackageOpen,
    Truck,
    XCircle,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

export interface PrecommandeData {
    livraison: boolean;
    montants: {
        total: number;
        acomptes: number;
        rembourse: number;
        encaisse_net: number;
        reste: number;
        trop_percu: number;
    };
    lignes: {
        id: string;
        libelle: string | null;
        quantite_demandee: number;
        quantite_preparee: number | null;
    }[];
    can_lancer_preparation: boolean;
    can_valider_preparation: boolean;
    can_valider_retrait: boolean;
    can_confirmer_livraison: boolean;
    can_rembourser: boolean;
    can_annuler: boolean;
    /** Retrait ↔ livraison avant le chargement (D16). */
    can_changer_mode_remise: boolean;
    /** Véhicules proposés pour passer en livraison (vide si déjà en livraison). */
    vehicules_livraison: { id: string; nom: string }[];
    annulation_renforcee: boolean;
    annulation_code_requis: boolean;
    decaissement: {
        moyens: MoyenEncaissement[];
        especes_disponibles: boolean;
        solde_especes: number | null;
    } | null;
}

const props = defineProps<{
    commandeId: string;
    reference: string;
    statut: string;
    precommande: PrecommandeData;
}>();

const toast = useToast();
const urlBase = computed(
    () => `/backoffice/ventes/${props.commandeId}/precommande`,
);

// ── Étapes (jusqu'à la remise) ───────────────────────────────────────────────
// Le chargement ne vaut pas livraison (D13) : « En livraison » jusqu'à la confirmation.
const ETAPES = computed(() =>
    props.precommande.livraison
        ? [
              { cle: 'reservee', libelle: 'Réservée' },
              { cle: 'a_preparer', libelle: 'À préparer' },
              { cle: 'a_charger', libelle: 'À charger' },
              { cle: 'livraison_en_cours', libelle: 'En livraison' },
              { cle: 'remise', libelle: 'Livrée' },
          ]
        : [
              { cle: 'reservee', libelle: 'Réservée' },
              { cle: 'a_preparer', libelle: 'À préparer' },
              { cle: 'preparee', libelle: 'Préparée' },
              { cle: 'remise', libelle: 'Retirée' },
          ],
);
const etapeCourante = computed(() => {
    const statut =
        props.statut === 'chargement_en_cours' ? 'a_charger' : props.statut;
    const index = ETAPES.value.findIndex((e) => e.cle === statut);
    return index === -1 ? ETAPES.value.length - 1 : index;
});

// ── Actions simples ──────────────────────────────────────────────────────────
const enCours = ref(false);
const erreurs = ref<Record<string, string>>({});

function envoyer(
    url: string,
    donnees: Record<string, unknown>,
    succes: () => void,
    surErreur?: (e: Record<string, string>) => void,
) {
    enCours.value = true;
    erreurs.value = {};
    router.post(url, donnees as never, {
        preserveScroll: true,
        onSuccess: succes,
        onError: (e) => {
            erreurs.value = e as Record<string, string>;
            surErreur?.(erreurs.value);
            if (!surErreur) {
                toast.add({
                    severity: 'error',
                    summary: 'Action refusée',
                    detail: Object.values(e)[0] as string,
                    life: 6000,
                });
            }
        },
        onFinish: () => (enCours.value = false),
    });
}

function lancerPreparation() {
    envoyer(`${urlBase.value}/preparation/lancer`, {}, () => undefined);
}

// ── Confirmation de livraison ────────────────────────────────────────────────
const dialogueLivraison = ref(false);

function confirmerLivraison() {
    envoyer(
        `${urlBase.value}/livraison/confirmer`,
        {},
        () => (dialogueLivraison.value = false),
    );
}

// ── Changement du mode de remise (D16) ───────────────────────────────────────
// Le prix ne change jamais : le serveur refuse si le nouveau mode le modifierait.
const dialogueModeRemise = ref(false);
const vehiculeChoisi = ref<string | null>(null);
// Véhicule, capacité, impayés, partage de commission, prix ou nature : la première raison du refus.
const erreurModeRemise = computed(
    () => Object.values(erreurs.value)[0] ?? null,
);

function ouvrirModeRemise() {
    vehiculeChoisi.value = null;
    erreurs.value = {};
    dialogueModeRemise.value = true;
}

function changerModeRemise() {
    envoyer(
        `${urlBase.value}/mode-remise`,
        {
            vehicule_id: props.precommande.livraison
                ? null
                : vehiculeChoisi.value,
        },
        () => (dialogueModeRemise.value = false),
        () => undefined,
    );
}

// ── Quantités (préparation / retrait) ────────────────────────────────────────
type ModeQuantites = 'preparation' | 'retrait';
const dialogueQuantites = ref<ModeQuantites | null>(null);
const quantites = ref<Record<string, number>>({});

function plafond(ligne: PrecommandeData['lignes'][number]): number {
    return dialogueQuantites.value === 'retrait'
        ? (ligne.quantite_preparee ?? 0)
        : ligne.quantite_demandee;
}

function ouvrirQuantites(mode: ModeQuantites) {
    erreurs.value = {};
    dialogueQuantites.value = mode;
    quantites.value = Object.fromEntries(
        props.precommande.lignes.map((l) => [
            l.id,
            mode === 'retrait'
                ? (l.quantite_preparee ?? 0)
                : l.quantite_demandee,
        ]),
    );
}

const quantitesValides = computed(
    () =>
        props.precommande.lignes.every((l) => {
            const q = quantites.value[l.id];
            return Number.isInteger(q) && q >= 0 && q <= plafond(l);
        }) && props.precommande.lignes.some((l) => quantites.value[l.id] > 0),
);

function validerQuantites() {
    const mode = dialogueQuantites.value;
    if (!mode || !quantitesValides.value) return;
    envoyer(
        mode === 'retrait'
            ? `${urlBase.value}/retrait`
            : `${urlBase.value}/preparation/valider`,
        {
            lignes: props.precommande.lignes.map((l) => ({
                id: l.id,
                quantite: quantites.value[l.id],
            })),
        },
        () => (dialogueQuantites.value = null),
        () => undefined,
    );
}

// ── Remboursement du trop-perçu ──────────────────────────────────────────────
const dialogueRemboursement = ref(false);

function rembourser(paiement: EncaissementPayload) {
    envoyer(
        `${urlBase.value}/remboursement`,
        { ...paiement },
        () => (dialogueRemboursement.value = false),
        () => undefined,
    );
}

// ── Annulation ───────────────────────────────────────────────────────────────
const dialogueAnnulation = ref(false);
const dialogueRemboursementAnnulation = ref(false);
const motif = ref('');
const code = ref('');
const codeEnvoyeA = ref<string | null>(null);
const envoiCodeEnCours = ref(false);
const aRembourserAnnulation = computed(
    () => props.precommande.montants.encaisse_net,
);
const MOTIF_MIN = 10;

const annulationPrete = computed(
    () =>
        motif.value.trim().length >= MOTIF_MIN &&
        (!props.precommande.annulation_code_requis || code.value.length > 0),
);

function ouvrirAnnulation() {
    erreurs.value = {};
    motif.value = '';
    code.value = '';
    codeEnvoyeA.value = null;
    dialogueAnnulation.value = true;
}

async function demanderCode() {
    envoiCodeEnCours.value = true;
    erreurs.value = {};
    try {
        const reponse = await fetch(`${urlBase.value}/annulation/code`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie
                        .split('; ')
                        .find((c) => c.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1] ?? '',
                ),
            },
        });
        const json = await reponse.json();
        if (!reponse.ok) {
            erreurs.value = {
                code:
                    json?.errors?.code?.[0] ??
                    json?.message ??
                    "Le code n'a pas pu être envoyé.",
            };
            return;
        }
        codeEnvoyeA.value = json.destination;
    } finally {
        envoiCodeEnCours.value = false;
    }
}

function confirmerAnnulation() {
    if (!annulationPrete.value) return;
    if (aRembourserAnnulation.value > 0) {
        // Le client doit d'abord être remboursé : on choisit d'où sort l'argent.
        dialogueAnnulation.value = false;
        dialogueRemboursementAnnulation.value = true;
        return;
    }
    annuler(null);
}

function annuler(paiement: EncaissementPayload | null) {
    envoyer(
        `${urlBase.value}/annulation`,
        {
            motif: motif.value.trim(),
            code: code.value || null,
            ...(paiement ?? {}),
        },
        () => {
            dialogueAnnulation.value = false;
            dialogueRemboursementAnnulation.value = false;
        },
        (e) => {
            // Motif ou code refusé : retour à la fenêtre d'annulation, qui affiche le message.
            if (e.motif || e.code) {
                dialogueRemboursementAnnulation.value = false;
                dialogueAnnulation.value = true;
            }
        },
    );
}
</script>

<template>
    <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <CalendarClock
                    class="h-4 w-4 text-blue-700 dark:text-blue-300"
                />
                <h3 class="text-sm font-semibold">Précommande</h3>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="precommande.can_lancer_preparation"
                    size="sm"
                    :disabled="enCours"
                    @click="lancerPreparation"
                >
                    <PackageOpen class="mr-2 h-4 w-4" />
                    Lancer la préparation
                </Button>
                <Button
                    v-if="precommande.can_valider_preparation"
                    size="sm"
                    :disabled="enCours"
                    @click="ouvrirQuantites('preparation')"
                >
                    <CheckCircle2 class="mr-2 h-4 w-4" />
                    Valider la préparation
                </Button>
                <Button
                    v-if="precommande.can_valider_retrait"
                    size="sm"
                    :disabled="enCours"
                    @click="ouvrirQuantites('retrait')"
                >
                    <PackageCheck class="mr-2 h-4 w-4" />
                    Valider le retrait
                </Button>
                <Button
                    v-if="precommande.can_confirmer_livraison"
                    size="sm"
                    :disabled="enCours"
                    @click="dialogueLivraison = true"
                >
                    <Truck class="mr-2 h-4 w-4" />
                    Confirmer la livraison
                </Button>
                <Button
                    v-if="precommande.can_changer_mode_remise"
                    size="sm"
                    variant="outline"
                    data-testid="precommande-changer-mode"
                    :disabled="enCours"
                    @click="ouvrirModeRemise"
                >
                    <ArrowLeftRight class="mr-2 h-4 w-4" />
                    {{
                        precommande.livraison
                            ? 'Passer en retrait'
                            : 'Passer en livraison'
                    }}
                </Button>
                <Button
                    v-if="precommande.can_rembourser"
                    size="sm"
                    variant="outline"
                    :disabled="enCours"
                    @click="dialogueRemboursement = true"
                >
                    <HandCoins class="mr-2 h-4 w-4" />
                    Rembourser le trop-perçu
                </Button>
                <Button
                    v-if="precommande.can_annuler"
                    size="sm"
                    variant="outline"
                    class="border-amber-300 text-amber-700 hover:bg-amber-50 dark:hover:bg-amber-950"
                    :disabled="enCours"
                    @click="ouvrirAnnulation"
                >
                    <XCircle class="mr-2 h-4 w-4" />
                    Annuler la précommande
                </Button>
            </div>
        </div>

        <!-- Étapes jusqu'à la remise — sans objet pour une précommande annulée ou retournée. -->
        <ol
            v-if="statut !== 'annulee' && statut !== 'retournee'"
            class="mt-4 flex flex-wrap items-center gap-2 text-xs"
        >
            <li
                v-for="(etape, index) in ETAPES"
                :key="etape.cle"
                class="flex items-center gap-2"
            >
                <span
                    class="rounded-md px-2 py-1 font-medium"
                    :class="
                        index < etapeCourante
                            ? 'text-emerald-700 dark:text-emerald-400'
                            : index === etapeCourante
                              ? 'bg-muted text-foreground'
                              : 'text-muted-foreground'
                    "
                >
                    {{ etape.libelle }}
                </span>
                <span
                    v-if="index < ETAPES.length - 1"
                    class="text-muted-foreground"
                    >→</span
                >
            </li>
        </ol>

        <!-- Montants -->
        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-xs text-muted-foreground">Total</dt>
                <dd class="font-semibold tabular-nums">
                    {{ formatGNF(precommande.montants.total) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Acomptes versés</dt>
                <dd class="font-semibold tabular-nums">
                    {{ formatGNF(precommande.montants.acomptes) }}
                </dd>
            </div>
            <div v-if="precommande.montants.rembourse > 0">
                <dt class="text-xs text-muted-foreground">Remboursé</dt>
                <dd class="font-semibold tabular-nums">
                    {{ formatGNF(precommande.montants.rembourse) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Reste à payer</dt>
                <dd class="font-semibold tabular-nums">
                    {{ formatGNF(precommande.montants.reste) }}
                </dd>
            </div>
            <!-- Trop-perçu : attention (ambre), l'opération reste possible — CLAUDE.md § 10. -->
            <div v-if="precommande.montants.trop_percu > 0">
                <dt class="text-xs text-amber-700 dark:text-amber-400">
                    Trop-perçu à rembourser
                </dt>
                <dd
                    class="font-semibold text-amber-700 tabular-nums dark:text-amber-400"
                >
                    {{ formatGNF(precommande.montants.trop_percu) }}
                </dd>
            </div>
        </dl>
    </div>

    <!-- Préparation / retrait : quantité par ligne -->
    <Dialog
        :visible="dialogueQuantites !== null"
        modal
        :closable="!enCours"
        :header="
            dialogueQuantites === 'retrait'
                ? 'Valider le retrait'
                : 'Valider la préparation'
        "
        :style="{ width: '520px', maxWidth: 'calc(100vw - 2rem)' }"
        @update:visible="
            (v: boolean) => !v && !enCours && (dialogueQuantites = null)
        "
    >
        <p class="mb-4 text-sm text-muted-foreground">
            {{
                dialogueQuantites === 'retrait'
                    ? 'Quantité réellement remise au client, ligne par ligne (au plus la quantité préparée). Le montant est recalculé sur ce qui est remis.'
                    : 'Quantité réellement préparée, ligne par ligne (au plus la quantité précommandée). Le surplus est libéré du stock réservé.'
            }}
        </p>
        <div class="space-y-3">
            <div
                v-for="ligne in precommande.lignes"
                :key="ligne.id"
                class="flex items-center justify-between gap-3"
            >
                <Label :for="`qte-${ligne.id}`" class="text-sm">
                    {{ ligne.libelle }}
                    <span class="block text-xs text-muted-foreground"
                        >au plus {{ plafond(ligne) }}</span
                    >
                </Label>
                <input
                    :id="`qte-${ligne.id}`"
                    v-model.number="quantites[ligne.id]"
                    type="number"
                    min="0"
                    :max="plafond(ligne)"
                    step="1"
                    class="h-10 w-28 rounded-md border border-input bg-background px-3 text-right text-sm tabular-nums"
                />
            </div>
        </div>
        <p v-if="erreurs.lignes" class="mt-3 text-xs text-destructive">
            {{ erreurs.lignes }}
        </p>
        <p
            v-if="erreurs.comptabilisation"
            class="mt-3 text-xs text-destructive"
        >
            {{ erreurs.comptabilisation }}
        </p>
        <template #footer>
            <Button
                variant="outline"
                :disabled="enCours"
                @click="dialogueQuantites = null"
                >Annuler</Button
            >
            <Button
                :disabled="enCours || !quantitesValides"
                @click="validerQuantites"
            >
                <span
                    v-if="enCours"
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                Confirmer
            </Button>
        </template>
    </Dialog>

    <!-- Confirmation de livraison (D13) -->
    <Dialog
        :visible="dialogueLivraison"
        modal
        :closable="!enCours"
        header="Confirmer la livraison"
        :style="{ width: '480px', maxWidth: 'calc(100vw - 2rem)' }"
        @update:visible="
            (v: boolean) => !v && !enCours && (dialogueLivraison = false)
        "
    >
        <p class="text-sm text-muted-foreground">
            Le client a bien reçu la marchandise chargée. Après confirmation,
            plus aucun retour de livraison n'est possible.
            <template v-if="precommande.montants.reste > 0">
                Le reste à payer
                <strong class="text-foreground">{{
                    formatGNF(precommande.montants.reste)
                }}</strong>
                pourra ensuite être encaissé.
            </template>
        </p>
        <template #footer>
            <Button
                variant="outline"
                :disabled="enCours"
                @click="dialogueLivraison = false"
                >Retour</Button
            >
            <Button :disabled="enCours" @click="confirmerLivraison">
                <span
                    v-if="enCours"
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                Confirmer la livraison
            </Button>
        </template>
    </Dialog>

    <!-- Changement du mode de remise (D16) -->
    <Dialog
        :visible="dialogueModeRemise"
        modal
        :closable="!enCours"
        :header="
            precommande.livraison ? 'Passer en retrait' : 'Passer en livraison'
        "
        :style="{ width: '480px', maxWidth: 'calc(100vw - 2rem)' }"
        @update:visible="
            (v: boolean) => !v && !enCours && (dialogueModeRemise = false)
        "
    >
        <div class="space-y-4 text-sm">
            <p class="text-muted-foreground">
                <template v-if="precommande.livraison">
                    Le client viendra chercher la marchandise : le véhicule est
                    retiré de la précommande.
                </template>
                <template v-else>
                    La marchandise sera chargée dans le véhicule choisi puis
                    livrée au client.
                </template>
                Le prix et les acomptes restent inchangés ; si le nouveau mode
                modifiait le prix, le changement est refusé.
            </p>
            <div v-if="!precommande.livraison" class="space-y-1.5">
                <Label for="precommande-vehicule">Véhicule de livraison</Label>
                <Select
                    v-model="vehiculeChoisi"
                    input-id="precommande-vehicule"
                    :options="precommande.vehicules_livraison"
                    option-label="nom"
                    option-value="id"
                    filter
                    placeholder="Choisir un véhicule"
                    class="w-full"
                    :invalid="!!erreurs.vehicule_id"
                />
            </div>
            <p
                v-if="erreurModeRemise"
                data-testid="precommande-mode-erreur"
                class="text-sm text-red-600 dark:text-red-400"
            >
                {{ erreurModeRemise }}
            </p>
        </div>
        <template #footer>
            <Button
                variant="outline"
                :disabled="enCours"
                @click="dialogueModeRemise = false"
                >Retour</Button
            >
            <Button
                :disabled="
                    enCours || (!precommande.livraison && !vehiculeChoisi)
                "
                @click="changerModeRemise"
            >
                <span
                    v-if="enCours"
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                {{
                    precommande.livraison
                        ? 'Passer en retrait'
                        : 'Passer en livraison'
                }}
            </Button>
        </template>
    </Dialog>

    <!-- Remboursement du trop-perçu (décaissement, ADR 0009) -->
    <PaymentCard
        v-if="precommande.decaissement"
        v-model:visible="dialogueRemboursement"
        title="Rembourser le trop-perçu"
        sens="decaissement"
        solde-label="Trop-perçu à rembourser"
        :solde="precommande.montants.trop_percu"
        :info-rows="[{ label: 'Précommande', value: reference }]"
        :moyens="precommande.decaissement.moyens"
        :especes-disponibles="precommande.decaissement.especes_disponibles"
        :solde-especes="precommande.decaissement.solde_especes"
        :processing="enCours"
        :errors="erreurs"
        @submit="rembourser"
    />

    <!-- Annulation : motif (+ code si procédure renforcée et exigée) -->
    <Dialog
        :visible="dialogueAnnulation"
        modal
        :closable="!enCours"
        header="Annuler la précommande"
        :style="{ width: '520px', maxWidth: 'calc(100vw - 2rem)' }"
        @update:visible="
            (v: boolean) => !v && !enCours && (dialogueAnnulation = false)
        "
    >
        <div class="space-y-4 text-sm">
            <p class="text-muted-foreground">
                La réservation de stock sera libérée et la facture annulée.
                <template v-if="aRembourserAnnulation > 0">
                    Le client sera remboursé de
                    <strong class="text-foreground">{{
                        formatGNF(aRembourserAnnulation)
                    }}</strong>
                    à l'étape suivante.
                </template>
            </p>
            <p
                v-if="precommande.annulation_renforcee"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
            >
                La préparation a commencé : procédure renforcée.
            </p>
            <div>
                <Label for="annulation-precommande-motif" class="mb-1.5 block">
                    Motif <span class="text-destructive">*</span>
                </Label>
                <textarea
                    id="annulation-precommande-motif"
                    v-model="motif"
                    rows="3"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    placeholder="Ex. : le client renonce à sa commande"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    Au moins {{ MOTIF_MIN }} caractères.
                </p>
                <p v-if="erreurs.motif" class="mt-1 text-xs text-destructive">
                    {{ erreurs.motif }}
                </p>
            </div>
            <div v-if="precommande.annulation_code_requis">
                <Label for="annulation-precommande-code" class="mb-1.5 block">
                    Code de confirmation reçu par e-mail
                    <span class="text-destructive">*</span>
                </Label>
                <div class="flex gap-2">
                    <input
                        id="annulation-precommande-code"
                        v-model="code"
                        inputmode="numeric"
                        maxlength="12"
                        class="h-10 w-40 rounded-md border border-input bg-background px-3 text-sm tracking-widest"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="envoiCodeEnCours"
                        @click="demanderCode"
                    >
                        {{
                            codeEnvoyeA
                                ? 'Renvoyer le code'
                                : 'Recevoir le code'
                        }}
                    </Button>
                </div>
                <p
                    v-if="codeEnvoyeA"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Code envoyé à {{ codeEnvoyeA }}.
                </p>
                <p v-if="erreurs.code" class="mt-1 text-xs text-destructive">
                    {{ erreurs.code }}
                </p>
            </div>
            <p
                v-if="erreurs.montant || erreurs.comptabilisation"
                class="text-xs text-destructive"
            >
                {{ erreurs.montant ?? erreurs.comptabilisation }}
            </p>
        </div>
        <template #footer>
            <Button
                variant="outline"
                :disabled="enCours"
                @click="dialogueAnnulation = false"
                >Retour</Button
            >
            <Button
                class="bg-amber-600 text-white hover:bg-amber-700"
                :disabled="enCours || !annulationPrete"
                @click="confirmerAnnulation"
            >
                <span
                    v-if="enCours"
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                {{
                    aRembourserAnnulation > 0
                        ? 'Continuer vers le remboursement'
                        : 'Annuler la précommande'
                }}
            </Button>
        </template>
    </Dialog>

    <!-- Annulation : remboursement des sommes versées (montant imposé) -->
    <PaymentCard
        v-if="precommande.decaissement"
        v-model:visible="dialogueRemboursementAnnulation"
        title="Rembourser le client avant l'annulation"
        sens="decaissement"
        solde-label="À rembourser"
        :solde="aRembourserAnnulation"
        :montant-initial="aRembourserAnnulation"
        :min-montant="aRembourserAnnulation"
        :max-montant="aRembourserAnnulation"
        :info-rows="[{ label: 'Précommande', value: reference }]"
        :moyens="precommande.decaissement.moyens"
        :especes-disponibles="precommande.decaissement.especes_disponibles"
        :solde-especes="precommande.decaissement.solde_especes"
        :processing="enCours"
        :errors="erreurs"
        @submit="annuler"
    />
</template>
