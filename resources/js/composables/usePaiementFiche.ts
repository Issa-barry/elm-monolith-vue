import type { EncaissementPayload } from '@/components/payment/moyensEncaissement';
import { formatGNF } from '@/lib/utils';
import type { FicheAPayer } from '@/types/commission';
import { router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

const TITRES: Record<string, string> = {
    livreur: 'Payer une commission livreur',
    proprietaire: 'Payer une commission propriétaire',
    site: 'Payer une commission site',
    prestataire: 'Payer une commission consultant',
};

/**
 * Paiement d'une fiche (écrans Commissions et écran de la fiche) : PaymentCard en mode
 * décaissement, mêmes moyens que l'encaissement — ceux de l'agence de la fiche, ou du siège
 * principal pour une fiche sans agence (ADR 0009). Toujours enregistré sur la fiche, jamais une
 * chaîne de paiement parallèle ; le solde et le support sont revérifiés côté serveur.
 */
export function usePaiementFiche() {
    const toast = useToast();
    const visible = ref(false);
    const processing = ref(false);
    const errors = ref<Record<string, string>>({});
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
        if (target.tresorerie.message) {
            toast.add({
                severity: 'error',
                summary: 'Paiement impossible',
                detail: target.tresorerie.message,
                life: 6000,
            });
            return;
        }
        fiche.value = target;
        errors.value = {};
        visible.value = true;
    }

    function submit(payload: EncaissementPayload) {
        if (!fiche.value) return;
        processing.value = true;
        errors.value = {};
        router.post(
            `/backoffice/comptabilite/fiches/${fiche.value.id}/paiements`,
            {
                ...payload,
                date_paiement: dateDuJour(),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    visible.value = false;
                    toast.add({
                        severity: 'success',
                        summary: 'Paiement enregistré',
                        life: 3000,
                    });
                },
                onError: (e) => {
                    errors.value = e as Record<string, string>;
                    // PaymentCard affiche montant/mode/support/référence sous leurs champs :
                    // les autres erreurs (comptabilisation, date...) ne restent pas silencieuses.
                    const affichees = [
                        'montant',
                        'mode_paiement',
                        'compte_tresorerie_id',
                        'reference_paiement',
                    ];
                    const autres = Object.entries(errors.value)
                        .filter(([key]) => !affichees.includes(key))
                        .map(([, message]) => message);
                    if (autres.length > 0) {
                        toast.add({
                            severity: 'error',
                            summary: 'Paiement non enregistré',
                            detail: autres.join(' '),
                            life: 6000,
                        });
                    }
                },
                onFinish: () => {
                    processing.value = false;
                },
            },
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

/** Date locale (pas UTC) : un paiement saisi à 00h30 à Conakry reste daté du jour. */
function dateDuJour(): string {
    const d = new Date();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const jj = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${jj}`;
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
