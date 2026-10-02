<?php

namespace App\Services\Tresorerie;

use App\Enums\ModePaiement;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\PieceComptable;
use Illuminate\Support\Collection;

/**
 * Où est réellement allé l'argent d'un encaissement déjà enregistré ? Lecture seule : ne classe
 * que ce que la comptabilité contient, ne reclasse rien (jamais de reclassement rétroactif,
 * ADR 0001) et ne suppose jamais l'intention d'origine.
 *
 * La destination se lit dans la pièce `encaissement_vente_recu` : la ligne de trésorerie débitée
 * (compte 5xxxxx). Elle est « conforme » quand ce compte est celui d'un support de trésorerie du
 * site de l'encaissement, cohérent avec le moyen de paiement — c'est ce qui rend l'argent visible
 * dans Situation, Financement et Journal financier (ces écrans ne voient que les comptes qui ont un
 * support). Chaque encaissement reçoit UNE catégorie, la première qui s'applique dans cet ordre :
 *
 *  1. pas de pièce comptable / pièce contrepassée alors que l'encaissement existe ;
 *  2. aucune ligne de trésorerie débitée (destination non identifiable) ;
 *  3. compte incohérent avec le moyen de paiement (ex. espèces débitées sur un compte Mobile Money) ;
 *  4. montant comptabilisé différent du montant encaissé ;
 *  5. caisse dédiée de l'agent → conforme ;
 *  6. espèces sur un compte 571 partagé (personne n'en est responsable — avant la règle du
 *     23/09/2026) ;
 *  7. compte sans support de trésorerie sur ce site : le solde existe au grand livre mais reste
 *     invisible pour la trésorerie ;
 *  8. Mobile Money sur le compte générique 561000 : l'opérateur n'est pas distingué ;
 *  9. support identifié → conforme.
 */
class EncaissementDestinationDiagnostic
{
    public const CAISSE_DEDIEE = 'caisse_dediee';

    public const SUPPORT_IDENTIFIE = 'support_identifie';

    public const ESPECES_HORS_CAISSE_DEDIEE = 'especes_hors_caisse_dediee';

    public const COMPTE_SANS_SUPPORT = 'compte_sans_support';

    public const COMPTE_INCOHERENT = 'compte_incoherent';

    public const COMPTE_GENERIQUE = 'compte_generique';

    public const ECART_MONTANT = 'ecart_montant';

    public const SANS_PIECE = 'sans_piece';

    public const PIECE_CONTREPASSEE = 'piece_contrepassee';

    public const DESTINATION_INCONNUE = 'destination_inconnue';

    /** Compte Mobile Money générique du plan comptable (PlanComptableBootstrapService). */
    public const COMPTE_MOBILE_MONEY_GENERIQUE = '561000';

    /** @var array<string, string> */
    public const LIBELLES = [
        self::CAISSE_DEDIEE => 'Conforme — caisse dédiée de l\'agent',
        self::SUPPORT_IDENTIFIE => 'Conforme — support de trésorerie identifié',
        self::ESPECES_HORS_CAISSE_DEDIEE => 'Espèces hors caisse dédiée (compte partagé)',
        self::SANS_PIECE => 'Aucun mouvement comptable',
        self::PIECE_CONTREPASSEE => 'Écriture contrepassée alors que l\'encaissement existe',
        self::DESTINATION_INCONNUE => 'Destination non identifiable (aucune ligne de trésorerie)',
        self::COMPTE_SANS_SUPPORT => 'Compte sans support de trésorerie (invisible)',
        self::COMPTE_INCOHERENT => 'Compte incohérent avec le moyen de paiement',
        self::COMPTE_GENERIQUE => 'Mobile Money sur compte générique (opérateur non distingué)',
        self::ECART_MONTANT => 'Autre anomalie — montant comptabilisé ≠ montant encaissé',
    ];

    /** Catégories conformes : la destination est claire, rien à faire. */
    public const CONFORMES = [
        self::CAISSE_DEDIEE,
        self::SUPPORT_IDENTIFIE,
    ];

    /** Préfixe du compte de trésorerie attendu selon le moyen de paiement (mappings de PlanComptableBootstrapService). */
    private const PREFIXE_ATTENDU = [
        'especes' => '571',
        'mobile_money' => '561',
        'virement' => '521',
        'cheque' => '521',
    ];

    public static function estAtraiter(string $categorie): bool
    {
        return ! in_array($categorie, self::CONFORMES, true);
    }

    /**
     * @param  Collection<string, Collection<int, CompteTresorerie>>  $supportsParCompte  supports de l'organisation, groupés par `compte_comptable_id`
     * @return array{categorie: string, compte: ?string, support: ?string}
     */
    public function classer(EncaissementVente $encaissement, ?PieceComptable $piece, Collection $supportsParCompte): array
    {
        if (! $piece) {
            return ['categorie' => self::SANS_PIECE, 'compte' => null, 'support' => null];
        }

        if ($piece->statut->value === 'contrepassee') {
            return ['categorie' => self::PIECE_CONTREPASSEE, 'compte' => null, 'support' => null];
        }

        $ligne = $piece->lignes->first(
            fn ($l) => (float) $l->debit > 0 && str_starts_with((string) $l->compte?->numero, '5'),
        );

        if (! $ligne) {
            return ['categorie' => self::DESTINATION_INCONNUE, 'compte' => null, 'support' => null];
        }

        $numero = (string) $ligne->compte->numero;
        $compte = "{$numero} {$ligne->compte->libelle}";
        $siteId = $ligne->site_id ?? $encaissement->facture?->site_id;

        // Le solde d'un support se calcule par (compte, site) : un support d'un autre site ne
        // rend pas visible l'argent encaissé sur celui-ci.
        $supports = ($supportsParCompte->get($ligne->compte_comptable_id) ?? collect())
            ->filter(fn (CompteTresorerie $s) => $s->site_id === $siteId || $s->isDediee());
        $dediee = $supports->first(fn (CompteTresorerie $s) => $s->isDediee());
        $support = $dediee ?? $supports->first();

        $resultat = fn (string $categorie) => ['categorie' => $categorie, 'compte' => $compte, 'support' => $support?->libelle];

        $prefixe = self::PREFIXE_ATTENDU[$encaissement->mode_paiement?->value ?? ''] ?? null;
        if ($prefixe !== null && ! str_starts_with($numero, $prefixe)) {
            return $resultat(self::COMPTE_INCOHERENT);
        }

        if (abs((float) $ligne->debit - (float) $encaissement->montant) > 0.01) {
            return $resultat(self::ECART_MONTANT);
        }

        if ($dediee) {
            return $resultat(self::CAISSE_DEDIEE);
        }

        if ($encaissement->mode_paiement === ModePaiement::ESPECES) {
            return $resultat(self::ESPECES_HORS_CAISSE_DEDIEE);
        }

        if (! $support) {
            return $resultat(self::COMPTE_SANS_SUPPORT);
        }

        if ($encaissement->mode_paiement === ModePaiement::MOBILE_MONEY && $numero === self::COMPTE_MOBILE_MONEY_GENERIQUE) {
            return $resultat(self::COMPTE_GENERIQUE);
        }

        return $resultat(self::SUPPORT_IDENTIFIE);
    }
}
