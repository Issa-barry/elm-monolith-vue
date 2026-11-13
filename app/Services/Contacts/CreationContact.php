<?php

namespace App\Services\Contacts;

use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Models\User;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Création d'une fiche Personne ou Entreprise avec sa première fonction (ADR 0060, lot 3,
 * CONTACT-017). Le numéro doit être libre : un numéro déjà connu n'est jamais repris ici, la
 * fonction s'ajoute alors à la fiche existante par un geste explicite (FonctionsContactEcriture).
 * Fiche et fonction sont créées dans une seule transaction : un échec de la fonction n'en laisse
 * aucune trace. Deux créations simultanées du même numéro se heurtent à l'index unique
 * (organization_id, telephone_normalise) : la seconde reçoit un message, jamais un doublon.
 */
final class CreationContact
{
    public function __construct(private readonly FonctionsContactEcriture $fonctions) {}

    /**
     * @param  array<string, mixed>  $identite  colonnes de `personnes`, dont `telephone` au format international
     * @param  array{type?: ?string, notes?: ?string}  $donneesFonction
     */
    public function creerPersonne(array $identite, string $fonction, array $donneesFonction, User $acteur): Personne
    {
        return $this->creer(Personne::class, $identite, $fonction, $donneesFonction, $acteur);
    }

    /**
     * @param  array<string, mixed>  $identite  colonnes de `entreprises_tierces`
     * @param  array{type?: ?string, notes?: ?string}  $donneesFonction
     */
    public function creerEntreprise(array $identite, string $fonction, array $donneesFonction, User $acteur): EntrepriseTierce
    {
        return $this->creer(EntrepriseTierce::class, $identite, $fonction, $donneesFonction, $acteur);
    }

    /** @param class-string<Personne|EntrepriseTierce> $classe */
    private function creer(string $classe, array $identite, string $fonction, array $donneesFonction, User $acteur): Personne|EntrepriseTierce
    {
        $normalise = Personne::normaliserTelephone((string) ($identite['telephone'] ?? ''));
        if ($normalise === '') {
            throw ValidationException::withMessages(['telephone' => 'Le numéro de téléphone est obligatoire.']);
        }

        if ($classe::where('organization_id', $acteur->organization_id)->where('telephone_normalise', $normalise)->exists()) {
            throw ValidationException::withMessages(['telephone' => MessagesContacts::NUMERO_CONNU]);
        }
        // Lot 9b (décision 3) : un numéro secondaire n'ouvre ni création ni rattachement automatique.
        if ($classe::where('organization_id', $acteur->organization_id)->where('telephone_secondaire_normalise', $normalise)->exists()) {
            throw ValidationException::withMessages(['telephone' => MessagesContacts::NUMERO_SECONDAIRE_CONNU]);
        }

        try {
            return DB::transaction(function () use ($classe, $identite, $normalise, $fonction, $donneesFonction, $acteur) {
                $fiche = $classe::create([
                    ...$identite,
                    'organization_id' => $acteur->organization_id,
                    'telephone_normalise' => $normalise,
                ]);

                $this->fonctions->ajouter($fiche, $fonction, $donneesFonction, $acteur);

                return $fiche;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['telephone' => MessagesContacts::NUMERO_CONNU]);
        }
    }
}
