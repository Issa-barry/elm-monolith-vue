<?php

namespace App\Services\Notification;

use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\UserAuthIdentity;

/**
 * Résout le compte User réellement connecté d'un bénéficiaire métier à partir
 * du couple beneficiaire_type/beneficiaire_id — même vocabulaire que
 * CommissionEnveloppePart, CommissionLogistiquePart, PaiementFiche et
 * DepenseImputation. Remplace la logique dupliquée (userForLivreur/
 * userForProprietaire) qui existait dans NotifierLivreursCommandeVenteJob.
 *
 * `site`/`salarie`/`prestataire` n'ont aucun compte utilisateur aujourd'hui
 * (cf. audit notifications du 27/08/2026) : retourne toujours null pour ces
 * types, jamais une erreur — un bénéficiaire sans compte ne doit simplement
 * déclencher aucun envoi.
 */
class BeneficiaireUserResolver
{
    public static function resolve(string $beneficiaireType, ?string $beneficiaireId): ?User
    {
        if (! $beneficiaireId) {
            return null;
        }

        return match ($beneficiaireType) {
            'proprietaire' => self::resolveProprietaire($beneficiaireId),
            'livreur' => self::resolveLivreur($beneficiaireId),
            default => null,
        };
    }

    private static function resolveProprietaire(string $id): ?User
    {
        $proprietaire = Proprietaire::find($id);
        if (! $proprietaire) {
            return null;
        }

        if ($proprietaire->user_id) {
            return User::find($proprietaire->user_id);
        }

        return $proprietaire->telephone
            ? UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($proprietaire->telephone))
            : null;
    }

    private static function resolveLivreur(string $id): ?User
    {
        $livreur = Livreur::find($id);
        if (! $livreur) {
            return null;
        }

        if ($livreur->user_id) {
            return $livreur->user ?? User::find($livreur->user_id);
        }

        return $livreur->telephone
            ? UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($livreur->telephone))
            : null;
    }
}
