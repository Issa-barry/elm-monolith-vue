import type { EncaissementPayload } from '@/components/payment/moyensEncaissement';
import type { TresorerieFiche } from '@/types/commission';
import { router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';

/**
 * État et envoi du dialogue de paiement sortant (PaymentCard en mode décaissement), communs aux
 * commissions (usePaiementFiche) et aux salaires (usePaiementSalaire). Le solde et le support sont
 * revérifiés côté serveur.
 */
export function useDecaissement() {
    const toast = useToast();
    const visible = ref(false);
    const processing = ref(false);
    const errors = ref<Record<string, string>>({});

    /** Prépare l'ouverture ; faux si le paiement est impossible (la raison s'affiche en toast). */
    function peutOuvrir(tresorerie: TresorerieFiche): boolean {
        if (tresorerie.message) {
            toast.add({
                severity: 'error',
                summary: 'Paiement impossible',
                detail: tresorerie.message,
                life: 6000,
            });
            return false;
        }
        errors.value = {};
        return true;
    }

    function envoyer(url: string, payload: EncaissementPayload) {
        processing.value = true;
        errors.value = {};
        router.post(
            url,
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

    return { visible, processing, errors, peutOuvrir, envoyer };
}

/** Date locale (pas UTC) : un paiement saisi à 00h30 à Conakry reste daté du jour. */
function dateDuJour(): string {
    const d = new Date();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const jj = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${jj}`;
}
