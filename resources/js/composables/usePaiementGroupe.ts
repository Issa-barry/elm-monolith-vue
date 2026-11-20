import { traiterParLots } from '@/lib/traiterParLots';
import { formatGNF } from '@/lib/utils';
import type { FicheAPayer } from '@/types/commission';
import { router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';

/** Choix faits dans la fenêtre « Marquer comme payés » : un seul moyen pour tout le groupe. */
export interface PaiementGroupePayload {
    mode_paiement: string;
    compte_tresorerie_id?: string;
    /** Référence commune, appliquée aux lignes sans référence propre. */
    reference_paiement?: string;
    date_paiement: string;
    /** Référence propre à une fiche (id de fiche → référence). */
    references: Record<string, string>;
}

interface ReponseLot {
    payees: number;
    montant_total: number;
    deja_payees: string[];
}

/** Lots courts (plafond serveur : PaiementFicheLotController::LOT_MAX = 50). */
export const LOT_PAIEMENT_GROUPE = 20;

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

async function envoyerLot(
    fiches: FicheAPayer[],
    payload: PaiementGroupePayload,
): Promise<ReponseLot> {
    const response = await fetch(
        '/backoffice/comptabilite/fiches/paiements/lot',
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                mode_paiement: payload.mode_paiement,
                compte_tresorerie_id: payload.compte_tresorerie_id,
                reference_paiement: payload.reference_paiement,
                date_paiement: payload.date_paiement,
                fiches: fiches.map((f) => ({
                    id: f.id,
                    montant: f.montant_restant,
                    reference_paiement:
                        payload.references[f.id]?.trim() || undefined,
                })),
            }),
        },
    );
    const data = await response.json().catch(() => null);
    if (!response.ok) {
        throw new Error(data?.message ?? "Le serveur n'a pas répondu à temps.");
    }
    return data as ReponseLot;
}

/**
 * Paiement groupé (ADR 0068) : les fiches réglées hors application (dépôts Orange Money…) sont
 * marquées payées pour leur reste entier, par lots successifs. Chaque lot passe en entier ou pas du
 * tout ; un lot déjà passé reste acquis et un renvoi ignore les fiches déjà soldées.
 */
export function usePaiementGroupe(surTermine: () => void) {
    const toast = useToast();
    const visible = ref(false);
    const fiches = ref<FicheAPayer[]>([]);
    const processing = ref(false);
    const progression = ref(0);
    const erreur = ref<string | null>(null);

    function open(cibles: FicheAPayer[]) {
        if (cibles.length === 0) return;
        fiches.value = cibles;
        erreur.value = null;
        progression.value = 0;
        visible.value = true;
    }

    async function submit(payload: PaiementGroupePayload) {
        if (processing.value || fiches.value.length === 0) return;
        processing.value = true;
        erreur.value = null;
        progression.value = 0;

        const cibles = fiches.value;
        let payees = 0;
        let total = 0;
        const dejaPayees: string[] = [];

        const resultat = await traiterParLots(
            cibles,
            LOT_PAIEMENT_GROUPE,
            async (lot) => {
                const reponse = await envoyerLot(lot, payload);
                payees += reponse.payees;
                total += reponse.montant_total;
                dejaPayees.push(...reponse.deja_payees);
            },
            (fait) => {
                progression.value = Math.floor((fait / cibles.length) * 100);
            },
        );

        // Rien d'enregistré : la fenêtre reste ouverte avec la raison, pour corriger et relancer.
        if (resultat.erreur !== null && resultat.traites === 0) {
            erreur.value = resultat.erreur;
            processing.value = false;
            return;
        }

        visible.value = false;
        const ignorees =
            dejaPayees.length > 0
                ? ` ${dejaPayees.length} fiche(s) déjà payée(s), ignorée(s).`
                : '';
        toast.add(
            resultat.erreur === null
                ? {
                      severity: 'success',
                      summary: 'Paiements enregistrés',
                      detail: `${payees} paiement(s) enregistré(s), ${formatGNF(total)}.${ignorees}`,
                      life: 6000,
                  }
                : {
                      severity: 'warn',
                      summary: 'Paiement groupé interrompu',
                      detail: `Arrêt à ${progression.value} % : ${resultat.erreur} ${payees} paiement(s) déjà enregistré(s) (${formatGNF(total)}) ; relancez pour payer le reste.`,
                      life: 10000,
                  },
        );

        router.reload({
            onFinish: () => {
                processing.value = false;
                surTermine();
            },
        });
    }

    return { visible, fiches, processing, progression, erreur, open, submit };
}
