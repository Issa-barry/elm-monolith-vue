<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, type HTMLAttributes } from 'vue';

// Table de couleur centralisée : tout nouveau statut s'ajoute UNIQUEMENT ici.
// Le statut brut (ex: "livraison_en_cours") suffit, pas besoin de map locale.
const STATUS_COLOR_MAP: Record<string, string> = {
    // Vert — succès / terminé
    actif: 'bg-emerald-500',
    active: 'bg-emerald-500',
    valide: 'bg-emerald-500',
    validee: 'bg-emerald-500',
    approuve: 'bg-emerald-500',
    paye: 'bg-emerald-500',
    payee: 'bg-emerald-500',
    livre: 'bg-emerald-500',
    livree: 'bg-emerald-500',
    cloture: 'bg-emerald-500',
    cloturee: 'bg-emerald-500',
    receptionnee: 'bg-emerald-500',
    // Facture fournisseur dont la pièce comptable est passée (ADR 0022).
    comptabilisee: 'bg-emerald-500',
    reception: 'bg-emerald-500',
    termine: 'bg-emerald-500',
    disponible: 'bg-emerald-500',
    couvert: 'bg-emerald-500',
    recu: 'bg-emerald-500',
    verse: 'bg-emerald-500',
    // Partage préparé conforme au nouveau barème (reconfiguration groupée, ADR 0006).
    conforme: 'bg-emerald-500',
    // Partage Livreur d'un véhicule (liste des véhicules, PartageConformiteVehiculesService).
    fait: 'bg-emerald-500',
    non_requis: 'bg-zinc-400 dark:bg-zinc-500',
    // Monitoring des commissions (CommissionAnomalieStatut) : commission finalement générée.
    regularisee: 'bg-emerald-500',

    // Bleu — en cours
    en_cours: 'bg-blue-500',
    chargement: 'bg-blue-500',
    chargement_en_cours: 'bg-blue-500',
    livraison_en_cours: 'bg-blue-500',
    transit: 'bg-blue-500',
    fonds_en_transit: 'bg-blue-500',
    envoye: 'bg-blue-500',
    calculee: 'bg-blue-500',
    repartition_validee: 'bg-blue-500',
    // Vente encaissée en totalité, en attente du versement des commissions avant clôture
    // (CommandeVente::statutAffichage()).
    commissions_a_verser: 'bg-blue-500',
    // Encaissement reçu pour une autre agence, reversement parti mais pas encore reçu (ADR 0012).
    en_cours_versement: 'bg-blue-500',
    // Précommande enregistrée, stock réservé, pas encore remise (StatutCommandeVente::RESERVEE,
    // ADR 0019). À ne pas confondre avec `reserve` (gris, règlement inter-agences).
    reservee: 'bg-blue-500',
    // Précommande prête au retrait, en attente du client (StatutCommandeVente::PREPAREE).
    preparee: 'bg-teal-500',
    // Précommande remise au client par retrait (CommandeVente::statutAffichage(), affichage seul).
    retiree: 'bg-emerald-500',
    // MessageLog.status (App\Enums\MessageLogStatus) — "sent" = Nimba a accepté
    // l'envoi, jamais une confirmation de livraison (pas de statut "delivered"
    // en P1, cf. docblock de l'enum) : même couleur "en cours" que "envoye".
    sent: 'bg-blue-500',

    // Gris — brouillon / créé / pas commencé / neutralisé
    brouillon: 'bg-zinc-400 dark:bg-zinc-500',
    creee: 'bg-zinc-400 dark:bg-zinc-500',
    a_charger: 'bg-zinc-400 dark:bg-zinc-500',
    a_verifier: 'bg-zinc-400 dark:bg-zinc-500',
    inactif: 'bg-zinc-400 dark:bg-zinc-500',
    inactive: 'bg-zinc-400 dark:bg-zinc-500',
    contrepassee: 'bg-zinc-400 dark:bg-zinc-500',
    // Monitoring des commissions : plus aucune commission due (opération annulée, barème retiré).
    sans_objet: 'bg-zinc-400 dark:bg-zinc-500',
    // Encaissement inter-agences retenu dans un règlement en préparation (ADR 0012).
    reserve: 'bg-zinc-400 dark:bg-zinc-500',

    // Rouge — impayé / rejeté / annulé
    impaye: 'bg-red-500',
    impayee: 'bg-red-500',
    a_payer: 'bg-red-500',
    rejete: 'bg-red-500',
    rejetee: 'bg-red-500',
    annule: 'bg-red-500',
    annulee: 'bg-red-500',
    // Vente annulée exceptionnellement (saisie par erreur) — cf. StatutCommandeVente::ANNULEE_ERREUR_SAISIE.
    annulee_erreur_saisie: 'bg-red-500',
    retourne: 'bg-red-500',
    ko: 'bg-red-500',
    expiree: 'bg-red-500',
    echoue: 'bg-red-500',
    erreur: 'bg-red-500',
    // Monitoring des commissions : commission attendue mais absente (erreur réelle à corriger).
    non_generee: 'bg-red-500',
    echec_recurrent: 'bg-red-500',
    // MessageLog.status — cf. commentaire "sent" plus haut.
    failed: 'bg-red-500',
    rupture: 'bg-red-500',

    // Orange — partiel / en attente / soumis
    // Vente retournée (retour total avant encaissement) : attention, pas une erreur — cf.
    // StatutCommandeVente::RETOURNEE. À ne pas confondre avec `retourne` (rouge, mouvement de fonds).
    retournee: 'bg-orange-500',
    conteste: 'bg-orange-500',
    partiel: 'bg-orange-500',
    partielle: 'bg-orange-500',
    partiellement_paye: 'bg-orange-500',
    partiellement_verse: 'bg-orange-500',
    en_attente: 'bg-orange-500',
    // Précommande en cours de préparation (StatutCommandeVente::A_PREPARER).
    a_preparer: 'bg-orange-500',
    // Bon de commande fournisseur en attente de validation / reçu en partie (StatutCommandeAchat).
    a_valider: 'bg-orange-500',
    partiellement_receptionnee: 'bg-orange-500',
    // Facture fournisseur réglée en partie (StatutFactureFournisseur, lot 4).
    partiellement_payee: 'bg-orange-500',
    // Facture d'achat validée et pas encore payée, affichée « Impayée » comme une facture de vente
    // (StatutFactureFournisseur::statutAffichage()) : à régler, pas une erreur.
    facture_impayee: 'bg-amber-500',
    // Statut de facturation d'un bon de commande (liste des achats) : quantités reçues sans
    // facture, ou facturées en partie — à faire, pas une erreur.
    a_facturer: 'bg-orange-500',
    partiellement_facturee: 'bg-orange-500',
    // MessageLog.status — cf. commentaire "sent" ci-dessus.
    pending: 'bg-orange-500',
    a_reverifier: 'bg-orange-500',
    // Reconfiguration groupée des partages (ADR 0006) : partage à saisir/corriger, équipe modifiée
    // depuis la préparation, ou saisie non encore enregistrée dans le brouillon.
    a_corriger: 'bg-orange-500',
    a_revalider: 'bg-orange-500',
    // Partage Livreur à faire / véhicule sans équipe : commandes refusées sur la catégorie.
    a_faire: 'bg-orange-500',
    sans_equipe: 'bg-orange-500',
    modifie: 'bg-blue-500',
    pending_validation: 'bg-orange-500',
    soumis: 'bg-orange-500',
    expire_bientot: 'bg-amber-500',
    analyse: 'bg-orange-500',
    stock_faible: 'bg-amber-500',
    a_financer: 'bg-orange-500',
    // Encaissement reçu pour une autre agence, pas encore reversé : à faire, pas une erreur (ADR 0012).
    a_verser: 'bg-amber-500',
    // Agence qui doit remettre des fonds à la trésorerie principale : à faire, pas une erreur (ADR 0016).
    a_remettre: 'bg-amber-500',
    // Le site central lui-même (ADR 0017) : une information, pas une situation à traiter.
    tresorerie_principale: 'bg-blue-500',
    // Remises des agences au Trésor principal (ADR 0016).
    remise_en_cours: 'bg-blue-500',
    partiellement_remis: 'bg-amber-500',
    remis: 'bg-emerald-500',
    rien_a_remettre: 'bg-emerald-500',
    donnees_incompletes: 'bg-amber-500',
    stock_negatif: 'bg-orange-500',
};

const DEFAULT_DOT_CLASS = 'bg-zinc-400 dark:bg-zinc-500';

const SIZE_CLASS: Record<'sm' | 'md' | 'lg', string> = {
    sm: 'h-1.5 w-1.5',
    md: 'h-2.5 w-2.5',
    lg: 'h-3 w-3',
};

const props = withDefaults(
    defineProps<{
        label: string;
        /** Statut brut (ex: "livraison_en_cours") — résolu via STATUS_COLOR_MAP. */
        status?: string | null;
        /** Override explicite, prioritaire sur `status`. */
        dotClass?: string;
        size?: 'sm' | 'md' | 'lg';
        class?: HTMLAttributes['class'];
    }>(),
    {
        status: null,
        dotClass: undefined,
        size: 'md',
    },
);

const resolvedDotClass = computed(() => {
    if (props.dotClass) return props.dotClass;
    if (props.status) {
        return (
            STATUS_COLOR_MAP[props.status.toLowerCase()] ?? DEFAULT_DOT_CLASS
        );
    }
    return DEFAULT_DOT_CLASS;
});
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-2 text-xs font-medium whitespace-nowrap text-foreground',
                props.class,
            )
        "
    >
        <span
            :class="
                cn('shrink-0 rounded-full', SIZE_CLASS[size], resolvedDotClass)
            "
        />
        <span>{{ label }}</span>
    </span>
</template>
