<?php

namespace App\Support\Contacts;

use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Models\User;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Règle commune à toute l'application (ADR 0060, lot 9b, CONTACT-063) : le numéro secondaire d'une
 * fiche ne peut devenir le numéro principal d'aucune autre fiche du même type dans l'organisation.
 * Appliquée par les modèles Personne et EntrepriseTierce à la création et à tout changement de
 * numéro principal : aucun formulaire (Client, Livreur, Employé, Propriétaire, Parrain, Fournisseur,
 * Prestataire, équipe, import, invitation…) ne peut donc créer une nouvelle identité avec ce
 * numéro. Jamais de rattachement automatique : la fiche existante est seulement désignée. Exception
 * métier : retirer d'abord ce numéro secondaire de la fiche existante (Contacts → Modifier), geste
 * explicite et tracé.
 */
final class NumeroSecondaire
{
    /**
     * Fiche active (non archivée) de l'organisation qui porte ce numéro en secondaire, hors les
     * fiches $sauf.
     *
     * @param  class-string<Personne|EntrepriseTierce>  $classe
     * @param  string|list<string>|null  $sauf
     */
    public static function fiche(string $classe, ?string $organizationId, ?string $normalise, string|array|null $sauf = null): Personne|EntrepriseTierce|null
    {
        if ($organizationId === null || $normalise === null || $normalise === '') {
            return null;
        }

        return $classe::where('organization_id', $organizationId)
            ->where('telephone_secondaire_normalise', $normalise)
            ->when($sauf !== null && $sauf !== [], fn ($q) => $q->whereKeyNot($sauf))
            ->first();
    }

    /**
     * Même refus, avant écriture, pour un formulaire dont le champ téléphone n'est pas `telephone`.
     *
     * @param  class-string<Personne|EntrepriseTierce>  $classe
     */
    public static function assertLibre(string $classe, ?string $organizationId, ?string $telephone, string $cle, ?string $saufId = null): void
    {
        $normalise = $telephone !== null ? Personne::normaliserTelephone($telephone) : null;
        $autre = self::fiche($classe, $organizationId, $normalise, $saufId);
        if ($autre !== null) {
            throw ValidationException::withMessages([$cle => self::message($autre, Auth::user())]);
        }
    }

    /** Garde des modèles : refuse la création ou le changement de numéro principal. */
    public static function verifier(Personne|EntrepriseTierce $fiche): void
    {
        if (! $fiche->isDirty('telephone_normalise')) {
            return;
        }

        $autre = self::fiche($fiche::class, $fiche->organization_id, $fiche->telephone_normalise, $fiche->exists ? $fiche->getKey() : null);
        if ($autre !== null) {
            throw ValidationException::withMessages(['telephone' => self::message($autre, Auth::user())]);
        }
    }

    /**
     * La fiche telle que le lecteur peut la voir (nom, fonctions, lien), ou null si elle ne lui est
     * pas visible (décision 1 du lot 3 : message neutre, aucune donnée).
     *
     * @return array{id: string, type: string, nom: string, fonctions: list<string>, lien: string}|null
     */
    public static function presentation(Personne|EntrepriseTierce $fiche, ?User $lecteur): ?array
    {
        if (! $lecteur instanceof User || $lecteur->organization_id !== $fiche->organization_id) {
            return null;
        }

        $visibilite = app(VisibiliteContacts::class);
        $presentation = new PresentationContacts($fiche->organization_id);

        if ($fiche instanceof Personne) {
            if (! $visibilite->personneVisible($fiche, $lecteur)) {
                return null;
            }
            $fonctions = $visibilite->fonctionsDesPersonnes(collect([$fiche]), $lecteur)[$fiche->id] ?? [];

            return [
                'id' => $fiche->id,
                'type' => 'personne',
                'nom' => $presentation->nomPersonne($fiche, $fonctions),
                'fonctions' => array_values(array_unique(array_column($presentation->fonctions($fonctions), 'libelle'))),
                'lien' => "/backoffice/contacts/personnes/{$fiche->id}",
            ];
        }

        if (! $visibilite->entrepriseVisible($fiche, $lecteur)) {
            return null;
        }
        $fonctions = $visibilite->fonctionsDesEntreprises(collect([$fiche]), $lecteur)[$fiche->id] ?? [];

        return [
            'id' => $fiche->id,
            'type' => 'entreprise',
            'nom' => (string) $fiche->raison_sociale,
            'fonctions' => array_values(array_unique(array_column($presentation->fonctions($fonctions), 'libelle'))),
            'lien' => "/backoffice/contacts/entreprises/{$fiche->id}",
        ];
    }

    public static function message(Personne|EntrepriseTierce $fiche, ?User $lecteur): string
    {
        $vue = self::presentation($fiche, $lecteur);
        if ($vue === null) {
            return MessagesContacts::NUMERO_SECONDAIRE_NON_VISIBLE;
        }

        $fonctions = $vue['fonctions'] === [] ? '' : ' ('.implode(', ', $vue['fonctions']).')';

        return sprintf(MessagesContacts::NUMERO_SECONDAIRE_FICHE, $vue['nom'].$fonctions);
    }
}
