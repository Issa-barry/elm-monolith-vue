<script setup lang="ts">
import type {
    AgenceEncaissement,
    EncaissementPayload,
} from '@/components/payment/moyensEncaissement';
import PaymentCard from '@/components/payment/PaymentCard.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatGNF } from '@/lib/utils';
import { router } from '@inertiajs/vue3';
import { CreditCard, Search } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

/**
 * « Encaisser une commande d'une autre agence » (ADR 0012) : le client présente la référence de sa
 * commande, créée dans une autre agence ; l'utilisateur l'encaisse dans l'une de SES agences. La
 * recherche porte sur la référence exacte (jamais une liste des ventes des autres agences) ; tous
 * les contrôles sont rejoués par le serveur à l'enregistrement.
 */
interface FactureTrouvee {
    id: string;
    reference: string;
    commande_id: string | null;
    client_nom: string | null;
    site_id: string | null;
    site_nom: string | null;
    montant_net: number;
    montant_encaisse: number;
    montant_restant: number;
    statut_label: string;
}

interface Resultat {
    facture: FactureTrouvee;
    raison_non_encaissable: string | null;
    agences: AgenceEncaissement[];
    agence_defaut: string | null;
}

/** Champs dont PaymentCard affiche lui-même l'erreur sous la saisie correspondante. */
const CHAMPS_PAYMENT_CARD = [
    'montant',
    'mode_paiement',
    'compte_tresorerie_id',
    'reference_paiement',
    'site_encaissement_id',
];

const props = defineProps<{ visible: boolean }>();
const emit = defineEmits<{ (e: 'update:visible', val: boolean): void }>();

const toast = useToast();

const reference = ref('');
const recherche = ref(false);
const erreur = ref<string | null>(null);
const resultat = ref<Resultat | null>(null);

const paiementVisible = ref(false);
const paiementEnCours = ref(false);
const paiementErreurs = ref<Record<string, string>>({});

const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

watch(
    () => props.visible,
    (open) => {
        if (open) {
            reference.value = '';
            erreur.value = null;
            resultat.value = null;
        }
    },
);

async function rechercher(): Promise<void> {
    const saisie = reference.value.trim();
    if (!saisie || recherche.value) return;

    recherche.value = true;
    erreur.value = null;
    resultat.value = null;
    try {
        const res = await fetch(
            `/backoffice/factures/autre-agence?reference=${encodeURIComponent(saisie)}`,
            { headers: { Accept: 'application/json' } },
        );
        const json = await res.json();
        if (!res.ok) {
            erreur.value =
                (json?.errors
                    ? (Object.values(json.errors).flat()[0] as string)
                    : json?.message) ?? 'Une erreur est survenue.';
            return;
        }
        resultat.value = json as Resultat;
    } catch {
        erreur.value = 'Impossible de rechercher cette commande.';
    } finally {
        recherche.value = false;
    }
}

const agenceCommande = computed(() =>
    resultat.value?.facture.site_id
        ? {
              id: resultat.value.facture.site_id,
              nom: resultat.value.facture.site_nom ?? '—',
          }
        : null,
);

function ouvrirPaiement(): void {
    paiementErreurs.value = {};
    paiementEnCours.value = false;
    paiementVisible.value = true;
}

function encaisser(payload: EncaissementPayload): void {
    const facture = resultat.value?.facture;
    if (!facture) return;

    paiementEnCours.value = true;
    paiementErreurs.value = {};
    router.post(`/backoffice/factures/${facture.id}/encaissements`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            paiementVisible.value = false;
            localVisible.value = false;
            toast.add({
                severity: 'success',
                summary: 'Validé',
                detail: 'Encaissement enregistré avec succès.',
                life: 3000,
            });
        },
        onError: (e) => {
            paiementErreurs.value = e as Record<string, string>;
            // Erreurs sans champ dans PaymentCard (comptabilisation, date…) : affichées en toast.
            const horsChamp = Object.entries(e).filter(
                ([cle]) => !CHAMPS_PAYMENT_CARD.includes(cle),
            );
            if (horsChamp.length) {
                toast.add({
                    severity: 'error',
                    summary: 'Encaissement non enregistré',
                    detail: String(horsChamp[0][1]),
                    life: 6000,
                });
            }
        },
        onFinish: () => {
            paiementEnCours.value = false;
        },
    });
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        header="Encaisser une commande d'une autre agence"
        :style="{ width: '480px', maxWidth: '95vw' }"
        :draggable="false"
    >
        <div class="space-y-5 py-2">
            <form class="space-y-1.5" @submit.prevent="rechercher">
                <Label for="reference-autre-agence" class="block text-sm"
                    >Référence de la commande ou de la facture</Label
                >
                <div class="flex gap-2">
                    <InputText
                        id="reference-autre-agence"
                        v-model="reference"
                        placeholder="Ex. CMD-290926-004"
                        class="w-full font-mono"
                        autofocus
                    />
                    <Button
                        type="submit"
                        :disabled="!reference.trim() || recherche"
                    >
                        <Search class="mr-1.5 h-4 w-4" />
                        Rechercher
                    </Button>
                </div>
                <p v-if="erreur" class="text-xs text-destructive">
                    {{ erreur }}
                </p>
            </form>

            <div
                v-if="resultat"
                class="space-y-3 rounded-xl border bg-card p-4"
                data-testid="facture-autre-agence"
            >
                <div class="space-y-1 text-sm">
                    <div class="flex justify-between gap-3">
                        <span class="text-muted-foreground">Facture</span>
                        <span class="font-mono font-medium">{{
                            resultat.facture.reference
                        }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-muted-foreground">Client</span>
                        <span class="font-medium">{{
                            resultat.facture.client_nom ?? '—'
                        }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-muted-foreground"
                            >Agence de la commande</span
                        >
                        <span class="font-medium">{{
                            resultat.facture.site_nom ?? '—'
                        }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-muted-foreground">Montant</span>
                        <span class="tabular-nums">{{
                            formatGNF(resultat.facture.montant_net)
                        }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-muted-foreground"
                            >Restant à encaisser</span
                        >
                        <span class="font-semibold tabular-nums">{{
                            formatGNF(resultat.facture.montant_restant)
                        }}</span>
                    </div>
                </div>

                <!-- Encaissement impossible : information, rien n'est en erreur de saisie. -->
                <p
                    v-if="resultat.raison_non_encaissable"
                    class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                >
                    {{ resultat.raison_non_encaissable }}
                </p>
                <Button
                    v-else
                    class="w-full"
                    data-testid="encaisser-autre-agence"
                    @click="ouvrirPaiement"
                >
                    <CreditCard class="mr-1.5 h-4 w-4" />
                    Encaisser
                </Button>
            </div>
        </div>
    </Dialog>

    <PaymentCard
        v-if="resultat"
        v-model:visible="paiementVisible"
        :title="`Encaisser — ${resultat.facture.reference}`"
        :solde="resultat.facture.montant_restant"
        :agences="resultat.agences"
        :agence-defaut="resultat.agence_defaut"
        :agence-commande="agenceCommande"
        :processing="paiementEnCours"
        :errors="paiementErreurs"
        @submit="encaisser"
    />
</template>
