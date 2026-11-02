import type { EncaissementPayload } from '@/components/payment/moyensEncaissement';
import { useDecaissement } from '@/composables/useDecaissement';
import { formatGNF } from '@/lib/utils';
import type { FicheAPayer } from '@/types/commission';
import { computed, ref } from 'vue';

const TITRES: Record<string, string> = {
    livreur: 'Payer une commission livreur',
    proprietaire: 'Payer une commission propriétaire',
    site: 'Payer une commission site',
    prestataire: 'Payer une commission consultant',
    parrain: 'Payer une commission parrain',
};

/**
 * Paiement d'une fiche (écrans Commissions et écran de la fiche) : PaymentCard en mode
 * décaissement, mêmes moyens que l'encaissement — ceux de l'agence de la fiche, ou du siège
 * principal pour une fiche sans agence (ADR 0009). Toujours enregistré sur la fiche, jamais une
 * chaîne de paiement parallèle ; le solde et le support sont revérifiés côté serveur.
 */
export function usePaiementFiche() {
    const { visible, processing, errors, peutOuvrir, envoyer } =
        useDecaissement();
    const fiche = ref<FicheAPayer | null>(null);

    const title = computed(
        () => TITRES[fiche.value?.beneficiaire_type ?? ''] ?? 'Payer',
    );

    const infoRows = computed(() => {
        const f = fiche.value;
        if (!f) return [];
        const periode = libellePeriode(f.periode_debut, f.periode_fin);
        const code = codePeriode(f.periode_reference);
        return [
            { label: 'Bénéficiaire', value: f.beneficiaire_nom },
            ...(periode
                ? [
                      {
                          label: 'Période',
                          value: code ? `${periode} (${code})` : periode,
                      },
                  ]
                : []),
            ...(f.periode_reference
                ? [{ label: 'Référence', value: f.periode_reference }]
                : []),
            { label: 'Montant dû', value: formatGNF(f.montant_net) },
            { label: 'Déjà payé', value: formatGNF(f.montant_paye) },
        ];
    });

    function open(target: FicheAPayer) {
        if (!peutOuvrir(target.tresorerie)) return;
        fiche.value = target;
        visible.value = true;
    }

    function submit(payload: EncaissementPayload) {
        if (!fiche.value) return;
        envoyer(
            `/backoffice/comptabilite/fiches/${fiche.value.id}/paiements`,
            payload,
        );
    }

    return {
        visible,
        processing,
        errors,
        fiche,
        title,
        infoRows,
        open,
        submit,
    };
}

/** « PAY-202609-P2-LIV » → « P2 » (M pour une période mensuelle). */
function codePeriode(reference: string | null): string | null {
    return reference?.match(/-(P1|P2|M)(?:-|$)/)?.[1] ?? null;
}

/** « 16–30 septembre 2026 », ou « 25 août – 7 septembre 2026 » à cheval sur deux mois. */
function libellePeriode(
    debut: string | null,
    fin: string | null,
): string | null {
    if (!debut || !fin) return null;
    const [a, b] = [debut, fin].map((iso) => {
        const [y, m, d] = iso.split('-').map(Number);
        return new Date(y, m - 1, d);
    });
    const mois = (d: Date) => d.toLocaleDateString('fr-FR', { month: 'long' });
    if (a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth()) {
        return `${a.getDate()}–${b.getDate()} ${mois(b)} ${b.getFullYear()}`;
    }
    const complet = (d: Date) =>
        d.toLocaleDateString('fr-FR', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    return a.getFullYear() === b.getFullYear()
        ? `${a.getDate()} ${mois(a)} – ${complet(b)}`
        : `${complet(a)} – ${complet(b)}`;
}
