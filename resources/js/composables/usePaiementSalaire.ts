import type { EncaissementPayload } from '@/components/payment/moyensEncaissement';
import { useDecaissement } from '@/composables/useDecaissement';
import { formatGNF } from '@/lib/utils';
import type { TresorerieFiche } from '@/types/commission';
import { computed, ref } from 'vue';

export interface SalaireAPayer {
    /** Route du paiement : ligne de paie, salarié (paiement global) ou ancienne route Paie RH. */
    url: string;
    beneficiaire_nom: string;
    periode?: string;
    /** Absents pour un paiement global réparti sur plusieurs mois. */
    montant_du?: number;
    deja_paye?: number;
    reste: number;
    tresorerie: TresorerieFiche;
}

/**
 * Paiement d'un salaire : même PaymentCard et mêmes moyens que le paiement d'une commission
 * (ADR 0049) — ceux de l'agence du salarié, ou du site central pour un salarié sans agence.
 */
export function usePaiementSalaire() {
    const { visible, processing, errors, peutOuvrir, envoyer } =
        useDecaissement();
    const salaire = ref<SalaireAPayer | null>(null);

    const infoRows = computed(() => {
        const s = salaire.value;
        if (!s) return [];
        return [
            { label: 'Bénéficiaire', value: s.beneficiaire_nom },
            ...(s.periode ? [{ label: 'Période', value: s.periode }] : []),
            ...(s.montant_du !== undefined
                ? [{ label: 'Montant dû', value: formatGNF(s.montant_du) }]
                : []),
            ...(s.deja_paye !== undefined
                ? [{ label: 'Déjà payé', value: formatGNF(s.deja_paye) }]
                : []),
        ];
    });

    function open(target: SalaireAPayer) {
        if (!peutOuvrir(target.tresorerie)) return;
        salaire.value = target;
        visible.value = true;
    }

    function submit(payload: EncaissementPayload) {
        if (!salaire.value) return;
        envoyer(salaire.value.url, payload);
    }

    return { visible, processing, errors, salaire, infoRows, open, submit };
}
