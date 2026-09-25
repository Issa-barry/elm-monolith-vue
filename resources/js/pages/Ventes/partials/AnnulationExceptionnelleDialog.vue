<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Banknote,
    MailCheck,
    Package,
    ShieldAlert,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

interface EncaissementRecap {
    id: string;
    montant: number;
    mode_paiement_label: string | null;
    date: string | null;
    auteur: string | null;
    caisse: string | null;
    caisse_dediee: boolean;
}

interface LigneStockRecap {
    id: string;
    produit: string;
    quantite_a_reintegrer: number;
}

interface Recapitulatif {
    commande: {
        reference: string;
        statut: string;
        statut_label: string;
        montant: number;
        client: string | null;
    };
    facture: {
        reference: string;
        statut: string | null;
        statut_label: string;
        montant_net: number;
        montant_encaisse: number;
    } | null;
    encaissements: EncaissementRecap[];
    total_encaisse: number;
    commissions: { nombre: number; montant: number };
    cashback: { montant: number; statut: string } | null;
    stock: LigneStockRecap[];
    empreinte: string;
    blocages: string[];
    /** Paramètre d'organisation, relu par le serveur à la confirmation — jamais décidé ici. */
    mode_confirmation: 'email_code' | 'simple';
}

const MOTIF_MIN = 10;

const props = defineProps<{
    visible: boolean;
    commandeId: string;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
}>();

const toast = useToast();

const etape = ref<'recapitulatif' | 'code' | 'confirmation'>('recapitulatif');
const chargement = ref(false);
const envoiCode = ref(false);
const confirmation = ref(false);
const erreurs = ref<string[]>([]);
const donneesModifiees = ref(false);
const recap = ref<Recapitulatif | null>(null);
const motif = ref('');
const code = ref('');
const destination = ref('');
const renvoiDans = ref(0);
let minuteur: ReturnType<typeof setInterval> | null = null;

const enCours = computed(
    () => chargement.value || envoiCode.value || confirmation.value,
);

const lignesStock = computed(
    () => recap.value?.stock.filter((l) => l.quantite_a_reintegrer > 0) ?? [],
);

const peutContinuer = computed(
    () =>
        !!recap.value &&
        recap.value.blocages.length === 0 &&
        motif.value.trim().length >= MOTIF_MIN &&
        !enCours.value,
);

const modeSimple = computed(() => recap.value?.mode_confirmation === 'simple');

const peutConfirmer = computed(
    () => !enCours.value && (modeSimple.value || /^\d{6}$/.test(code.value)),
);

/** Étape suivante du récapitulatif : code par e-mail, ou confirmation directe en mode simple. */
function continuer(): void {
    if (!peutContinuer.value) return;
    erreurs.value = [];
    if (modeSimple.value) {
        etape.value = 'confirmation';
        return;
    }
    demanderCode();
}

watch(
    () => props.visible,
    (open) => {
        if (open) {
            etape.value = 'recapitulatif';
            motif.value = '';
            code.value = '';
            chargerRecapitulatif();
        } else {
            arreterMinuteur();
        }
    },
);

onBeforeUnmount(arreterMinuteur);

function formatGNF(val: number): string {
    return (
        Math.round(val)
            .toString()
            .replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' GNF'
    );
}

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
    if (data?.errors) {
        return Object.values(data.errors).flat() as string[];
    }
    return [data?.message ?? 'Une erreur est survenue.'];
}

async function chargerRecapitulatif(): Promise<void> {
    chargement.value = true;
    erreurs.value = [];
    donneesModifiees.value = false;
    recap.value = null;
    try {
        const res = await fetch(
            `/backoffice/ventes/${props.commandeId}/annulation-exceptionnelle`,
            { headers: { Accept: 'application/json' } },
        );
        const json = await res.json();
        if (!res.ok) {
            erreurs.value = messagesErreur(json);
            return;
        }
        recap.value = json as Recapitulatif;
    } catch {
        erreurs.value = ['Impossible de charger le récapitulatif.'];
    } finally {
        chargement.value = false;
    }
}

async function demanderCode(): Promise<void> {
    if (!recap.value || envoiCode.value) return;
    envoiCode.value = true;
    erreurs.value = [];
    try {
        const res = await fetch(
            `/backoffice/ventes/${props.commandeId}/annulation-exceptionnelle/code`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
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
        `/backoffice/ventes/${props.commandeId}/annulation-exceptionnelle`,
        {
            motif: motif.value.trim(),
            empreinte: recap.value.empreinte,
            code: modeSimple.value ? null : code.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('update:visible', false);
                toast.add({
                    severity: 'success',
                    summary: 'Commande annulée',
                    detail: 'Annulation exceptionnelle effectuée : encaissements contrepassés, facture annulée, stock réintégré.',
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
    etape.value = 'recapitulatif';
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
    // Fermeture bloquée pendant une requête : l'annulation est peut-être déjà en train d'être
    // exécutée côté serveur.
    if (!value && enCours.value) return;
    emit('update:visible', value);
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Annulation exceptionnelle"
        aria-labelledby="annulation-exceptionnelle-titre"
        :style="{
            width: 'min(820px, calc(100vw - 2rem))',
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
                    id="annulation-exceptionnelle-titre"
                    class="text-lg font-semibold text-foreground"
                >
                    Annulation exceptionnelle
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Étape {{ etape === 'recapitulatif' ? '1' : '2' }} sur 2
                    <span aria-hidden="true"> · </span>
                    {{
                        etape === 'recapitulatif'
                            ? 'Récapitulatif et motif'
                            : 'Confirmation'
                    }}
                </p>
            </div>
        </template>

        <div
            class="mb-4 flex gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm leading-relaxed dark:border-red-900 dark:bg-red-950/40"
        >
            <ShieldAlert
                class="mt-0.5 size-5 shrink-0 text-red-700 dark:text-red-400"
                aria-hidden="true"
            />
            <div>
                <p class="font-semibold text-red-800 dark:text-red-300">
                    Cette opération est irréversible.
                </p>
                <p class="mt-0.5 text-red-900 dark:text-red-200">
                    Réservée aux commandes saisies par erreur. La commande, la
                    facture, les encaissements, le stock et les commissions
                    associés seront régularisés.
                </p>
            </div>
        </div>

        <div
            v-if="erreurs.length"
            role="alert"
            class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950"
            data-testid="annulation-exceptionnelle-erreurs"
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
                Recharger le récapitulatif
            </Button>
        </div>

        <div
            v-if="chargement"
            role="status"
            class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
        >
            <span
                class="inline-block size-4 animate-spin rounded-full border-2 border-current border-t-transparent"
                aria-hidden="true"
            />
            Chargement du récapitulatif…
        </div>

        <template v-else-if="recap && etape === 'recapitulatif'">
            <div class="grid gap-3 text-sm sm:grid-cols-2">
                <section class="min-w-0 rounded-lg border bg-muted/30 p-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3 class="font-medium text-muted-foreground">
                            Commande
                        </h3>
                        <StatusDot
                            :status="recap.commande.statut"
                            :label="recap.commande.statut_label"
                        />
                    </div>
                    <p
                        class="mt-2 font-mono text-base font-semibold break-all text-foreground"
                    >
                        {{ recap.commande.reference }}
                    </p>
                    <p
                        v-if="recap.commande.client"
                        class="mt-1 break-words text-muted-foreground"
                    >
                        {{ recap.commande.client }}
                    </p>
                    <p class="mt-2 font-semibold text-foreground tabular-nums">
                        {{ formatGNF(recap.commande.montant) }}
                    </p>
                </section>
                <section class="min-w-0 rounded-lg border bg-muted/30 p-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3 class="font-medium text-muted-foreground">
                            Facture
                        </h3>
                        <StatusDot
                            v-if="recap.facture"
                            :status="recap.facture.statut"
                            :label="recap.facture.statut_label"
                        />
                    </div>
                    <template v-if="recap.facture">
                        <p
                            class="mt-2 font-mono text-base font-semibold break-all text-foreground"
                        >
                            {{ recap.facture.reference }}
                        </p>
                        <div
                            class="mt-2 flex flex-wrap items-center justify-between gap-2"
                        >
                            <p
                                class="font-semibold text-foreground tabular-nums"
                            >
                                {{ formatGNF(recap.facture.montant_net) }}
                            </p>
                            <p
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <ArrowRight
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Sera annulée
                            </p>
                        </div>
                    </template>
                    <p v-else class="mt-2 text-muted-foreground">
                        Aucune facture
                    </p>
                </section>
            </div>

            <div class="mt-4 overflow-hidden rounded-lg border text-sm">
                <section class="p-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3
                            class="flex items-center gap-2 font-semibold text-foreground"
                        >
                            <Banknote
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Encaissements à contrepasser
                        </h3>
                        <p class="font-semibold text-foreground tabular-nums">
                            {{ formatGNF(recap.total_encaisse) }}
                        </p>
                    </div>
                    <ul
                        v-if="recap.encaissements.length"
                        class="mt-3 divide-y border-t"
                    >
                        <li
                            v-for="e in recap.encaissements"
                            :key="e.id"
                            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 pt-2.5 pb-1.5 last:pb-0"
                        >
                            <div class="min-w-0 flex-1 basis-48">
                                <p
                                    class="font-medium break-words text-foreground"
                                >
                                    {{ e.caisse ?? 'Caisse non renseignée' }}
                                    <span
                                        v-if="e.caisse_dediee"
                                        class="font-normal text-muted-foreground"
                                        >(caisse dédiée)</span
                                    >
                                </p>
                                <p class="mt-0.5 text-muted-foreground">
                                    {{ e.date ?? 'Date non renseignée' }}
                                    <span aria-hidden="true"> · </span>
                                    {{
                                        e.mode_paiement_label ??
                                        'Mode non renseigné'
                                    }}
                                </p>
                            </div>
                            <p class="font-medium text-foreground tabular-nums">
                                {{ formatGNF(e.montant) }}
                            </p>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-muted-foreground">
                        Aucun encaissement à contrepasser.
                    </p>
                </section>

                <dl class="space-y-2 border-t px-3 py-3">
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
                    >
                        <dt class="text-muted-foreground">
                            Commissions à annuler ({{
                                recap.commissions.nombre
                            }})
                        </dt>
                        <dd class="font-semibold text-foreground tabular-nums">
                            {{ formatGNF(recap.commissions.montant) }}
                        </dd>
                    </div>
                    <div
                        v-if="recap.cashback"
                        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
                    >
                        <dt class="text-muted-foreground">
                            Cashback client à retirer
                        </dt>
                        <dd class="font-semibold text-foreground tabular-nums">
                            {{ formatGNF(recap.cashback.montant) }}
                        </dd>
                    </div>
                </dl>

                <section class="border-t p-3">
                    <h3
                        class="flex items-center gap-2 font-semibold text-foreground"
                    >
                        <Package
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Stock à réintégrer
                    </h3>
                    <p
                        v-if="!lignesStock.length"
                        class="mt-2 text-muted-foreground"
                    >
                        Aucune sortie de stock à annuler.
                    </p>
                    <ul v-else class="mt-2 space-y-2">
                        <li
                            v-for="l in lignesStock"
                            :key="l.id"
                            class="flex items-start justify-between gap-4"
                        >
                            <span
                                class="min-w-0 break-words text-muted-foreground"
                                >{{ l.produit }}</span
                            >
                            <span
                                class="shrink-0 font-semibold text-foreground tabular-nums"
                                >+{{ l.quantite_a_reintegrer }}</span
                            >
                        </li>
                    </ul>
                </section>
            </div>

            <div
                v-if="recap.blocages.length"
                role="alert"
                class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm leading-relaxed text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-300"
                data-testid="annulation-exceptionnelle-blocages"
            >
                <p class="mb-1 flex items-center gap-2 font-semibold">
                    <AlertTriangle class="size-4 shrink-0" aria-hidden="true" />
                    Annulation impossible
                </p>
                <p v-for="b in recap.blocages" :key="b">{{ b }}</p>
            </div>

            <div v-else class="mt-4">
                <div class="mb-2 flex items-baseline justify-between gap-3">
                    <label
                        class="text-sm font-semibold text-foreground"
                        for="annulation-exceptionnelle-motif"
                    >
                        Motif de l'annulation
                        <span class="text-red-600 dark:text-red-400">*</span>
                    </label>
                    <span
                        class="shrink-0 text-xs text-muted-foreground tabular-nums"
                        >{{ motif.length }} / 2 000</span
                    >
                </div>
                <Textarea
                    id="annulation-exceptionnelle-motif"
                    v-model="motif"
                    rows="3"
                    maxlength="2000"
                    aria-required="true"
                    aria-describedby="annulation-exceptionnelle-motif-aide"
                    :disabled="enCours"
                    placeholder="Ex. : commande de formation saisie par erreur en production"
                    class="w-full resize-y !text-sm !leading-relaxed"
                />
                <p
                    id="annulation-exceptionnelle-motif-aide"
                    class="mt-1 text-xs leading-relaxed text-muted-foreground"
                >
                    Précisez la raison de l'erreur ({{ MOTIF_MIN }} caractères
                    minimum).
                </p>
            </div>
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
                        for="annulation-exceptionnelle-code"
                        class="mb-2 block text-sm font-medium text-foreground"
                        >Code de confirmation</label
                    >
                    <InputText
                        id="annulation-exceptionnelle-code"
                        v-model="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        placeholder="000000"
                        aria-describedby="annulation-exceptionnelle-code-aide"
                        :disabled="enCours"
                        class="w-full max-w-56 !text-center !font-mono !text-2xl !tracking-[0.3em]"
                        @keyup.enter="confirmer"
                    />
                    <p
                        id="annulation-exceptionnelle-code-aide"
                        class="mt-2 text-xs leading-relaxed text-muted-foreground"
                    >
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
                data-testid="annulation-exceptionnelle-confirmation-simple"
            >
                <div class="rounded-xl bg-red-50 p-3 dark:bg-red-950/50">
                    <AlertTriangle
                        class="size-7 text-red-700 dark:text-red-400"
                        aria-hidden="true"
                    />
                </div>
                <h3 class="text-base font-semibold text-foreground">
                    Confirmer l'annulation de {{ recap.commande.reference }} ?
                </h3>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Cette opération ne peut pas être annulée.
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
                    :disabled="enCours"
                    class="w-full sm:w-auto"
                    @click="
                        etape === 'recapitulatif'
                            ? onUpdateVisible(false)
                            : (etape = 'recapitulatif')
                    "
                >
                    {{ etape === 'recapitulatif' ? 'Fermer' : 'Retour' }}
                </Button>
                <Button
                    v-if="etape === 'recapitulatif'"
                    variant="destructive"
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
                    variant="destructive"
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
                            ? 'Annulation en cours…'
                            : "Confirmer l'annulation"
                    }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
