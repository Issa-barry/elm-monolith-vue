<?php

namespace App\Traits;

use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Résout les champs d'identité soumis par les formulaires Fournisseur/Prestataire (nom, prenom,
 * raison_sociale, email, phone...) vers une Personne (cas physique) ou une EntrepriseTierce (cas
 * moral), au lieu de les stocker directement sur le rôle. À l'édition d'une fiche déjà rattachée,
 * met à jour l'identité existante en place plutôt que de re-résoudre par téléphone.
 *
 * Référentiel Contacts (ADR 0060, lot 3, CONTACT-020) : un numéro déjà connu n'est plus jamais
 * repris en silence. Créer une fonction avec un numéro déjà enregistré est refusé avec un message
 * neutre (aucune information sur la fiche existante) qui renvoie vers Contacts, où la fonction
 * s'ajoute à la fiche après confirmation explicite. À l'édition, un numéro déjà porté par une
 * autre fiche est refusé par un message clair au lieu d'une erreur SQL.
 */
trait ResolutionIdentiteTiersTrait
{
    /**
     * @return array{personne_id: ?string, entreprise_tierce_id: ?string}
     */
    private function resoudreIdentite(string $organizationId, array $data, Fournisseur|Prestataire|null $existant = null): array
    {
        $attributsCommuns = [
            'telephone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'pays' => $data['pays'] ?? null,
            'code_pays' => $data['code_pays'] ?? null,
            'code_phone_pays' => $data['code_phone_pays'] ?? null,
            'ville' => $data['ville'] ?? null,
            'adresse' => $data['adresse'] ?? null,
        ];
        $normalise = ! empty($attributsCommuns['telephone']) ? Personne::normaliserTelephone($attributsCommuns['telephone']) : null;

        if (! empty($data['raison_sociale'])) {
            $attributs = ['raison_sociale' => $data['raison_sociale'], ...$attributsCommuns, 'telephone_normalise' => $normalise];

            if ($existant?->entreprise_tierce_id) {
                $this->assertNumeroLibre(EntrepriseTierce::class, $organizationId, $normalise, $existant->entreprise_tierce_id, 'Ce numéro est déjà celui d\'une autre entreprise.');
                $existant->entrepriseTierce->update($attributs);

                return ['personne_id' => null, 'entreprise_tierce_id' => $existant->entreprise_tierce_id];
            }

            $this->assertNumeroLibre(EntrepriseTierce::class, $organizationId, $normalise, null, MessagesContacts::NUMERO_CONNU);
            $entreprise = $this->creerIdentite(fn () => EntrepriseTierce::create(['organization_id' => $organizationId, ...$attributs]));

            return ['personne_id' => null, 'entreprise_tierce_id' => $entreprise->id];
        }

        $attributs = ['nom' => $data['nom'] ?? null, 'prenom' => $data['prenom'] ?? null, ...$attributsCommuns];

        if ($existant?->personne_id) {
            $this->assertNumeroLibre(Personne::class, $organizationId, $normalise, $existant->personne_id, "Ce numéro est déjà celui d'une autre personne.");
            $existant->personne->update([...$attributs, 'telephone_normalise' => $normalise]);

            return ['personne_id' => $existant->personne_id, 'entreprise_tierce_id' => null];
        }

        $this->assertNumeroLibre(Personne::class, $organizationId, $normalise, null, MessagesContacts::NUMERO_CONNU);
        $personne = $this->creerIdentite(fn () => Personne::create(['organization_id' => $organizationId, ...$attributs, 'telephone_normalise' => $normalise]));

        return ['personne_id' => $personne->id, 'entreprise_tierce_id' => null];
    }

    /** @param class-string<Personne|EntrepriseTierce> $classe */
    private function assertNumeroLibre(string $classe, string $organizationId, ?string $normalise, ?string $ignorerId, string $message): void
    {
        if ($normalise === null || $normalise === '') {
            return;
        }

        $existe = $classe::where('organization_id', $organizationId)
            ->where('telephone_normalise', $normalise)
            ->when($ignorerId, fn ($q, string $id) => $q->whereKeyNot($id))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['phone' => $message]);
        }
    }

    /** Deux créations simultanées du même numéro : la seconde reçoit le même message, jamais un doublon. */
    private function creerIdentite(callable $creation): Personne|EntrepriseTierce
    {
        try {
            return $creation();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => MessagesContacts::NUMERO_CONNU]);
        }
    }
}
