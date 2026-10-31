<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { formatGNF } from '@/lib/utils';
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Banknote,
    MailCheck,
    Package,
    Users,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import RadioButton from 'primevue/radiobutton';
import Textarea from 'primevue/textarea';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/** Ligne de la commande telle qu'affichée : quantité vendue = chargée − déjà retirée. */
export interface LigneRegularisable {
    id: string;
    produit: string;
    quantite_vendue: number;
    prix_unitaire: number;
}

interface LigneRecap {
    id: string;
    produit: string;
    quantite_avant: number;
    quantite_apres: number;
    quantite_retiree: number;
    montant_avant: number;
    montant_apres: number;
    reintegre_stock: boolean;
}

interface EncaissementRecap {
    id: string;
    date: string | null;
    mode_paiement_label: string | null;
    caisse: string | null;
    montant_avant: number;
    montant_retire: number;
    montant_apres: number;
}

interface PartRecap {
    part_id: string | null;
    cible: string;
    beneficiaire: string;
    engagee: boolean;
    montant_avant: number;
    montant_apres: number;
    montant_verse: number;
    ecart: number;
    sens: 'trop_percu' | 'complement' | 'aucun';
}

interface Recapitulatif {
    commande: { reference: string };
    facture: {
        reference: string;
        montant_avant: number;
        montant_apres: number;
        encaisse: number;
    };
    lignes: LigneRecap[];
    excedent: number;
    traitement_excedent: 'non_recu' | 'a_rembourser' | null;
    encaissements: EncaissementRecap[];
    commissions: {
        total_avant: number;
        total_apres: number;
        parts: PartRecap[];
    };
    empreinte: string;
    blocages: string[];
    /** Réglage d'organisation relu par le serveur à la confirmation. */
    mode_confirmation: 'email_code' | 'simple';
}

const MOTIF_MIN = 10;

const props = defineProps<{
    visible: boolean;
    commandeId: string;
    reference: string;
    lignes: LigneRegularisable[];
}>();

const emit = defineEmits<{ 'update:visible': [value: boolean] }>();

const toast = useToast();

const etape = ref<'saisie' | 'code' | 'confirmation'>('saisie');
const quantites = ref<Record<string, number | null>>({});
const traitement = ref<'non_recu' | 'a_rembourser' | null>(null);
const motif = ref('');
const code = ref('');
const destination = ref('');
const renvoiDans = ref(0);
const recap = ref<Recapitulatif | null>(null);
const erreurs = ref<string[]>([]);
const donneesModifiees = ref(false);
const chargement = ref(false);
const envoiCode = ref(false);
const confirmation = ref(false);
let minuteur: ReturnType<typeof setInterval> | null = null;
let attente: ReturnType<typeof setTimeout> | null = null;
let numeroRequete = 0;

const enCours = computed(
    () => chargement.value || envoiCode.value || confirmation.value,
);
const modeSimple = computed(() => recap.value?.mode_confirmation === 'simple');

/** Champ vidé ou non numérique : la ligne garde sa quantité vendue. */
function quantiteSaisie(l: LigneRegularisable): number {
    const q = quantites.value[l.id];
    return typeof q === 'number' && Number.isInteger(q) ? q : l.quantite_vendue;
}

const lignesSaisies = computed(() =>
    props.lignes.map((l) => ({ id: l.id, quantite: quantiteSaisie(l) })),
);

const auMoinsUneBaisse = computed(() =>
    props.lignes.some((l) => quantiteSaisie(l) < l.quantite_vendue),
);

const lignesRetirees = computed(
    () => recap.value?.lignes.filter((l) => l.quantite_retiree > 0) ?? [],
);

const partsModifiees = computed(
    () =>
        recap.value?.commissions.parts.filter(
            (p) => p.montant_avant !== p.montant_apres || p.ecart !== 0,
        ) ?? [],
);

const peutContinuer = computed(
    () =>
        !!recap.value &&
        recap.value.blocages.length === 0 &&
        motif.value.trim().length >= MOTIF_MIN &&
        !enCours.value,
);

const peutConfirmer = computed(
    () => !enCours.value && (modeSimple.value || /^\d{6}$/.test(code.value)),
);

watch(
    () => props.visible,
    (open) => {
        if (open) {
            etape.value = 'saisie';
            quantites.value = Object.fromEntries(
                props.lignes.map((l) => [l.id, l.quantite_vendue]),
            );
            traitement.value = null;
            motif.value = '';
            code.value = '';
            recap.value = null;
            erreurs.value = [];
        } else {
            arreterMinuteur();
        }
    },
);

watch(
    [quantites, traitement],
    () => {
        if (!props.visible || etape.value !== 'saisie') return;
        if (attente) clearTimeout(attente);
        attente = setTimeout(chargerRecapitulatif, 400);
    },
    { deep: true },
);

onBeforeUnmount(() => {
    arreterMinuteur();
    if (attente) clearTimeout(attente);
});

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

function messagesErreur(json: unknown): string[] {
    const data = json as {
        errors?: Record<string, string[] | string>;
        message?: string;
    };
    if (data?.errors) return Object.values(data.errors).flat() as string[];
    return [data?.message ?? 'Une erreur est survenue.'];
}

function charge(): Record<string, unknown> {
    return {
        lignes: lignesSaisies.value,
        traitement_excedent: traitement.value,
    };
}

async function chargerRecapitulatif(): Promise<void> {
    recap.value = null;
    erreurs.value = [];
    donneesModifiees.value = false;
    if (!auMoinsUneBaisse.value) return;

    const numero = ++numeroRequete;
    chargement.value = true;
    try {
        const res = await fetch(
            `/backoffice/ventes/${props.commandeId}/regularisation/recapitulatif`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(charge()),
            },
        );
        const json = await res.json();
        // Une saisie plus récente a relancé le calcul : on ignore cette réponse.
        if (numero !== numeroRequete) return;
        if (!res.ok) {
            erreurs.value = messagesErreur(json);
            return;
        }
        recap.value = json as Recapitulatif;
    } catch {
        if (numero === numeroRequete) {
            erreurs.value = ["Impossible de calculer l'aperçu."];
        }
    } finally {
        if (numero === numeroRequete) chargement.value = false;
    }
}

function continuer(): void {
    if (!peutContinuer.value) return;
    erreurs.value = [];
    if (modeSimple.value) {
        etape.value = 'confirmation';
        return;
    }
    demanderCode();
}

async function demanderCode(): Promise<void> {
    if (!recap.value || envoiCode.value) return;
    envoiCode.value = true;
    erreurs.value = [];
    try {
        const res = await fetch(
            `/backoffice/ventes/${props.commandeId}/regularisation/code`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    ...charge(),
                    motif: motif.value.trim(),
                    empreinte: recap.value.empreinte,
                }),
            },
        );
        const json = await res.json();
        if (!res.ok) {
            donneesModifiees.value = !!json?.errors?.empreinte;
            erreurs.value = messagesErreur(json);
            return;
        }
        destination.value = json.destination;
        code.value = '';
        etape.value = 'code';
        demarrerMinuteur(json.renvoi_dans ?? 30);
    } catch {
        erreurs.value = ["Le code n'a pas pu être demandé."];
    } finally {
        envoiCode.value = false;
    }
}

function confirmer(): void {
    if (!recap.value || !peutConfirmer.value) return;
    confirmation.value = true;
    erreurs.value = [];

    router.post(
        `/backoffice/ventes/${props.commandeId}/regularisation`,
        {
            ...charge(),
            motif: motif.value.trim(),
            empreinte: recap.value.empreinte,
            code: modeSimple.value ? null : code.value,
        } as never,
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('update:visible', false);
                toast.add({
                    severity: 'success',
                    summary: 'Vente régularisée',
                    detail: 'Facture, stock, encaissements et commissions mis à jour.',
                    life: 6000,
                });
            },
            onError: (errors) => {
                donneesModifiees.value = 'empreinte' in errors;
                erreurs.value = Object.values(errors).flat() as string[];
            },
            onFinish: () => {
                confirmation.value = false;
            },
        },
    );
}

function reprendre(): void {
    etape.value = 'saisie';
    code.value = '';
    chargerRecapitulatif();
}

function demarrerMinuteur(secondes: number): void {
    arreterMinuteur();
    renvoiDans.value = secondes;
    minuteur = setInterval(() => {
        renvoiDans.value = Math.max(0, renvoiDans.value - 1);
        if (renvoiDans.value === 0) arreterMinuteur();
    }, 1000);
}

function arreterMinuteur(): void {
    if (minuteur) {
        clearInterval(minuteur);
        minuteur = null;
    }
}

function onUpdateVisible(value: boolean): void {
    // Fermeture bloquée pendant une requête : la régularisation est peut-être en cours d'exécution.
    if (!value && enCours.value) return;
    emit('update:visible', value);
}

function libelleEcart(p: PartRecap): string {
    if (p.sens === 'trop_percu') return `Trop-perçu ${formatGNF(p.ecart)}`;
    if (p.sens === 'complement')
        return `Complément ${formatGNF(Math.abs(p.ecart))}`;
    return p.engagee ? 'Payée, sans écart' : 'Remplacée';
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Régulariser la vente"
        aria-labelledby="regularisation-titre"
        :style="{
            width: 'min(860px, calc(100vw - 2rem))',
            maxHeight: 'calc(100dvh - 2rem)',
        }"
        :pt="{
            header: { class: '!border-b !px-5 !py-4' },
            content: { class: '!px-5 !py-4' },
            footer: { class: '!border-t !px-5 !py-4' },
        }"
        :draggable="false"
        :resizable="false"
        :closable="!enCours"
        :close-on-escape="!enCours"
        @update:visible="onUpdateVisible"
    >
        <template #header>
            <div class="min-w-0">
                <h2
                    id="regularisation-titre"
                    class="text-lg font-semibold text-foreground"
                >
                    Régulariser la vente {{ reference }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Étape {{ etape === 'saisie' ? '1' : '2' }} sur 2
                    <span aria-hidden="true"> · </span>
                    {{
                        etape === 'saisie'
                            ? 'Quantités corrigées, aperçu et motif'
                            : 'Confirmation'
                    }}
                </p>
            </div>
        </template>

        <div
            class="mb-4 flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-relaxed dark:border-amber-900 dark:bg-amber-950/40"
        >
            <AlertTriangle
                class="mt-0.5 size-5 shrink-0 text-amber-700 dark:text-amber-400"
                aria-hidden="true"
            />
            <p class="text-amber-900 dark:text-amber-200">
                Pour corriger une quantité saisie en trop sur une vente déjà
                encaissée. La facture, le stock, les encaissements et les
                commissions sont recalculés ; les paiements déjà faits ne sont
                jamais modifiés.
            </p>
        </div>

        <div
            v-if="erreurs.length"
            role="alert"
            class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950"
            data-testid="regularisation-erreurs"
        >
            <p
                v-for="err in erreurs"
                :key="err"
                class="text-sm leading-relaxed text-red-700 dark:text-red-300"
            >
                {{ err }}
            </p>
            <Button
                v-if="donneesModifiees"
                variant="outline"
                size="sm"
                class="mt-2"
                :disabled="enCours"
                @click="reprendre"
            >
                Recalculer l'aperçu
            </Button>
        </div>

        <template v-if="etape === 'saisie'">
            <!-- Quantités -->
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[480px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-xs">
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Produit
                            </th>
                            <th
                                class="px-3 py-2 text-right font-medium text-muted-foreground"
                            >
                                Prix unitaire
                            </th>
                            <th
                                class="px-3 py-2 text-right font-medium text-muted-foreground"
                            >
                                Vendue
                            </th>
                            <th
                                class="px-3 py-2 text-right font-medium text-muted-foreground"
                            >
                                Quantité corrigée
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="l in lignes" :key="l.id">
                            <td class="px-3 py-2">{{ l.produit }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatGNF(l.prix_unitaire) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ l.quantite_vendue }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <input
                                    v-model.number="quantites[l.id]"
                                    type="number"
                                    min="0"
                                    :max="l.quantite_vendue"
                                    step="1"
                                    inputmode="numeric"
                                    :aria-label="`Quantité corrigée — ${l.produit}`"
                                    :disabled="enCours && !chargement"
                                    class="h-9 w-28 rounded-md border bg-background px-2 text-right tabular-nums focus:ring-2 focus:ring-primary focus:outline-none"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p
                v-if="!auMoinsUneBaisse"
                class="mt-3 text-sm text-muted-foreground"
            >
                Saisissez la quantité réellement vendue (inférieure à la
                quantité actuelle) pour voir l'aperçu.
            </p>

            <div
                v-else-if="chargement && !recap"
                role="status"
                class="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground"
            >
                <span
                    class="inline-block size-4 animate-spin rounded-full border-2 border-current border-t-transparent"
                    aria-hidden="true"
                />
                Calcul de l'aperçu…
            </div>

            <template v-if="recap">
                <!-- Facture -->
                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <section class="rounded-lg border bg-muted/30 p-3">
                        <h3 class="text-xs font-medium text-muted-foreground">
                            Facture {{ recap.facture.reference }}
                        </h3>
                        <p class="mt-1 tabular-nums">
                            <span class="text-muted-foreground line-through">{{
                                formatGNF(recap.facture.montant_avant)
                            }}</span>
                            <ArrowRight
                                class="mx-1 inline size-3.5"
                                aria-hidden="true"
                            />
                            <strong>{{
                                formatGNF(recap.facture.montant_apres)
                            }}</strong>
                        </p>
                    </section>
                    <section class="rounded-lg border bg-muted/30 p-3">
                        <h3 class="text-xs font-medium text-muted-foreground">
                            Encaissé
                        </h3>
                        <p class="mt-1 font-semibold tabular-nums">
                            {{ formatGNF(recap.facture.encaisse) }}
                        </p>
                    </section>
                    <section class="rounded-lg border bg-muted/30 p-3">
                        <h3 class="text-xs font-medium text-muted-foreground">
                            Excédent encaissé
                        </h3>
                        <p
                            class="mt-1 font-semibold tabular-nums"
                            :class="
                                recap.excedent > 0
                                    ? 'text-amber-700 dark:text-amber-400'
                                    : ''
                            "
                        >
                            {{ formatGNF(recap.excedent) }}
                        </p>
                    </section>
                </div>

                <!-- Excédent : reçu ou non -->
                <fieldset
                    v-if="recap.excedent > 0"
                    class="mt-4 rounded-lg border p-3 text-sm"
                >
                    <legend class="px-1 font-semibold text-foreground">
                        L'excédent de {{ formatGNF(recap.excedent) }} a-t-il
                        réellement été reçu ?
                        <span class="text-red-600 dark:text-red-400">*</span>
                    </legend>
                    <div class="mt-1 space-y-2">
                        <label class="flex cursor-pointer items-start gap-2">
                            <RadioButton
                                v-model="traitement"
                                input-id="excedent-non-recu"
                                value="non_recu"
                                :disabled="enCours && !chargement"
                            />
                            <span>
                                <span class="font-medium"
                                    >Non, il n'a jamais été reçu</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >L'encaissement est ramené au montant
                                    corrigé ; la caisse ou le compte diminue
                                    d'autant (contrepassation).</span
                                >
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2">
                            <RadioButton
                                v-model="traitement"
                                input-id="excedent-a-rembourser"
                                value="a_rembourser"
                                :disabled="enCours && !chargement"
                            />
                            <span>
                                <span class="font-medium"
                                    >Oui, il a été reçu</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >L'encaissement est conservé ; l'excédent
                                    devient un trop-perçu à rembourser au client
                                    (onglet Facturation).</span
                                >
                            </span>
                        </label>
                    </div>
                </fieldset>

                <div class="mt-4 overflow-hidden rounded-lg border text-sm">
                    <!-- Encaissements réduits -->
                    <section
                        v-if="recap.encaissements.length"
                        class="border-b p-3"
                    >
                        <h3
                            class="flex items-center gap-2 font-semibold text-foreground"
                        >
                            <Banknote
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Encaissements réduits
                        </h3>
                        <ul class="mt-2 space-y-1.5">
                            <li
                                v-for="e in recap.encaissements"
                                :key="e.id"
                                class="flex flex-wrap justify-between gap-2"
                            >
                                <span class="text-muted-foreground"
                                    >{{ e.date }} · {{ e.mode_paiement_label
                                    }}{{
                                        e.caisse ? ` · ${e.caisse}` : ''
                                    }}</span
                                >
                                <span class="tabular-nums"
                                    >{{ formatGNF(e.montant_avant) }}
                                    <ArrowRight
                                        class="mx-1 inline size-3.5"
                                        aria-hidden="true"
                                    />
                                    <strong>{{
                                        formatGNF(e.montant_apres)
                                    }}</strong></span
                                >
                            </li>
                        </ul>
                    </section>

                    <!-- Stock -->
                    <section class="border-b p-3">
                        <h3
                            class="flex items-center gap-2 font-semibold text-foreground"
                        >
                            <Package
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Stock réintégré
                        </h3>
                        <ul class="mt-2 space-y-1">
                            <li
                                v-for="l in lignesRetirees"
                                :key="l.id"
                                class="flex justify-between gap-3"
                            >
                                <span class="text-muted-foreground">{{
                                    l.produit
                                }}</span>
                                <span class="font-semibold tabular-nums">{{
                                    l.reintegre_stock
                                        ? `+${l.quantite_retiree}`
                                        : 'sans stock'
                                }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- Commissions -->
                    <section class="p-3">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <h3
                                class="flex items-center gap-2 font-semibold text-foreground"
                            >
                                <Users
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                Commissions recalculées
                            </h3>
                            <p class="tabular-nums">
                                {{ formatGNF(recap.commissions.total_avant) }}
                                <ArrowRight
                                    class="mx-1 inline size-3.5"
                                    aria-hidden="true"
                                />
                                <strong>{{
                                    formatGNF(recap.commissions.total_apres)
                                }}</strong>
                            </p>
                        </div>
                        <p
                            v-if="!partsModifiees.length"
                            class="mt-2 text-muted-foreground"
                        >
                            Aucune commission concernée.
                        </p>
                        <div v-else class="mt-2 overflow-x-auto">
                            <table class="w-full min-w-[520px] text-xs">
                                <thead>
                                    <tr class="text-muted-foreground">
                                        <th class="py-1 text-left font-medium">
                                            Bénéficiaire
                                        </th>
                                        <th class="py-1 text-right font-medium">
                                            Avant
                                        </th>
                                        <th class="py-1 text-right font-medium">
                                            Après
                                        </th>
                                        <th class="py-1 text-right font-medium">
                                            Déjà versé
                                        </th>
                                        <th class="py-1 text-right font-medium">
                                            Écart
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="(p, i) in partsModifiees"
                                        :key="p.part_id ?? `nouvelle-${i}`"
                                    >
                                        <td class="py-1.5">
                                            {{ p.beneficiaire }}
                                            <span class="text-muted-foreground"
                                                >({{ p.cible }})</span
                                            >
                                        </td>
                                        <td
                                            class="py-1.5 text-right tabular-nums"
                                        >
                                            {{ formatGNF(p.montant_avant) }}
                                        </td>
                                        <td
                                            class="py-1.5 text-right tabular-nums"
                                        >
                                            {{ formatGNF(p.montant_apres) }}
                                        </td>
                                        <td
                                            class="py-1.5 text-right tabular-nums"
                                        >
                                            {{ formatGNF(p.montant_verse) }}
                                        </td>
                                        <td
                                            class="py-1.5 text-right"
                                            :class="
                                                p.sens !== 'aucun'
                                                    ? 'font-medium text-amber-700 dark:text-amber-400'
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{ libelleEcart(p) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p
                                v-if="partsModifiees.some((p) => p.engagee)"
                                class="mt-2 text-xs leading-relaxed text-muted-foreground"
                            >
                                Les commissions déjà payées gardent leurs
                                paiements. Le reste à payer de leur fiche est
                                ramené au montant recalculé ; un trop-perçu est
                                suivi sur la commande jusqu'à sa régularisation
                                (retenue, remboursement…).
                            </p>
                        </div>
                    </section>
                </div>

                <div
                    v-if="recap.blocages.length"
                    role="alert"
                    class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm leading-relaxed text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-300"
                    data-testid="regularisation-blocages"
                >
                    <p class="mb-1 flex items-center gap-2 font-semibold">
                        <AlertTriangle
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        Régularisation impossible pour l'instant
                    </p>
                    <p v-for="b in recap.blocages" :key="b">{{ b }}</p>
                </div>

                <div class="mt-4">
                    <div class="mb-2 flex items-baseline justify-between gap-3">
                        <label
                            class="text-sm font-semibold text-foreground"
                            for="regularisation-motif"
                        >
                            Motif de la régularisation
                            <span class="text-red-600 dark:text-red-400"
                                >*</span
                            >
                        </label>
                        <span
                            class="shrink-0 text-xs text-muted-foreground tabular-nums"
                            >{{ motif.length }} / 2 000</span
                        >
                    </div>
                    <Textarea
                        id="regularisation-motif"
                        v-model="motif"
                        rows="3"
                        maxlength="2000"
                        aria-required="true"
                        :disabled="enCours && !chargement"
                        placeholder="Ex. : 2 700 packs saisis au lieu de 270 à la création de la commande"
                        class="w-full resize-y !text-sm !leading-relaxed"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ MOTIF_MIN }} caractères minimum.
                    </p>
                </div>
            </template>
        </template>

        <template v-else-if="recap && etape === 'code'">
            <div
                class="mx-auto flex max-w-md flex-col items-center gap-4 py-5 text-center"
            >
                <div class="rounded-xl bg-muted p-3">
                    <MailCheck
                        class="size-7 text-foreground"
                        aria-hidden="true"
                    />
                </div>
                <div>
                    <h3 class="text-base font-semibold text-foreground">
                        Vérifiez votre e-mail
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        Un code de confirmation a été envoyé à
                        <strong class="break-words text-foreground">{{
                            destination
                        }}</strong
                        >.
                    </p>
                </div>
                <div class="w-full">
                    <label
                        for="regularisation-code"
                        class="mb-2 block text-sm font-medium text-foreground"
                        >Code de confirmation</label
                    >
                    <InputText
                        id="regularisation-code"
                        v-model="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        placeholder="000000"
                        :disabled="enCours"
                        class="w-full max-w-56 !text-center !font-mono !text-2xl !tracking-[0.3em]"
                        @keyup.enter="confirmer"
                    />
                    <p class="mt-2 text-xs text-muted-foreground">
                        Valable 10 minutes, pour une seule utilisation.
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="enCours || renvoiDans > 0"
                    @click="demanderCode"
                >
                    {{
                        renvoiDans > 0
                            ? `Renvoyer le code (${renvoiDans} s)`
                            : 'Renvoyer le code'
                    }}
                </Button>
            </div>
        </template>

        <template v-else-if="recap && etape === 'confirmation'">
            <div
                class="mx-auto flex max-w-md flex-col items-center gap-3 py-5 text-center"
            >
                <div class="rounded-xl bg-amber-50 p-3 dark:bg-amber-950/50">
                    <AlertTriangle
                        class="size-7 text-amber-700 dark:text-amber-400"
                        aria-hidden="true"
                    />
                </div>
                <h3 class="text-base font-semibold text-foreground">
                    Régulariser {{ recap.commande.reference }} ?
                </h3>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    La facture passera de
                    {{ formatGNF(recap.facture.montant_avant) }} à
                    {{ formatGNF(recap.facture.montant_apres) }}. Cette
                    opération ne peut pas être annulée.
                </p>
                <div
                    class="mt-1 w-full rounded-lg border bg-muted/30 p-3 text-left"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        Motif renseigné
                    </p>
                    <p
                        class="mt-1 text-sm leading-relaxed break-words whitespace-pre-wrap text-foreground"
                    >
                        {{ motif.trim() }}
                    </p>
                </div>
            </div>
        </template>

        <template #footer>
            <div
                class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end"
            >
                <Button
                    variant="outline"
                    :disabled="enCours && !chargement"
                    class="w-full sm:w-auto"
                    @click="
                        etape === 'saisie'
                            ? onUpdateVisible(false)
                            : (etape = 'saisie')
                    "
                >
                    {{ etape === 'saisie' ? 'Fermer' : 'Retour' }}
                </Button>
                <Button
                    v-if="etape === 'saisie'"
                    :disabled="!peutContinuer"
                    class="w-full sm:w-auto"
                    @click="continuer"
                >
                    <span
                        v-if="envoiCode"
                        class="inline-block size-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                        aria-hidden="true"
                    />
                    {{
                        envoiCode
                            ? 'Envoi du code…'
                            : 'Continuer vers la confirmation'
                    }}
                    <ArrowRight
                        v-if="!envoiCode"
                        class="size-4"
                        aria-hidden="true"
                    />
                </Button>
                <Button
                    v-else
                    :disabled="!peutConfirmer"
                    class="w-full sm:w-auto"
                    @click="confirmer"
                >
                    <span
                        v-if="confirmation"
                        class="inline-block size-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                        aria-hidden="true"
                    />
                    {{
                        confirmation
                            ? 'Régularisation en cours…'
                            : 'Confirmer la régularisation'
                    }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
