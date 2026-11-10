<?php

namespace App\Services\Contacts;

use App\Enums\AuditEvent;
use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Modification de l'identité d'une fiche depuis Contacts (ADR 0060, lot 7a, CONTACT-044) : la fiche
 * fait foi pour toutes ses fonctions. Une Personne modifiée recopie son identité sur ses clients si
 * la synchronisation est active (Personne::booted(), D16) ; une Entreprise renommée recopie sa raison
 * sociale sur son propriétaire (EntrepriseTierce::booted(), lot 5). Téléphone unique par
 * organisation : un numéro d'une fiche non visible reçoit le message neutre (D6). Journal : noms des
 * champs seulement.
 */
final class ModificationIdentiteContact
{
    public function __construct(
        private readonly AuditLogService $journal,
        private readonly VisibiliteContacts $visibilite,
    ) {}

    /**
     * @param  array<string, mixed>  $identite
     * @return list<string> champs modifiés
     */
    public function modifierPersonne(User $acteur, Personne $personne, array $identite): array
    {
        $this->assertMemeOrganisation($personne->organization_id, $acteur);

        return DB::transaction(function () use ($acteur, $personne, $identite) {
            Personne::whereKey($personne->id)->lockForUpdate()->first();
            $personne->refresh();

            $normalise = Personne::normaliserTelephone((string) $identite['telephone']);
            if ($normalise !== $personne->telephone_normalise) {
                $autre = Personne::where('organization_id', $personne->organization_id)->where('telephone_normalise', $normalise)->whereKeyNot($personne->id)->first();
                if ($autre !== null) {
                    throw ValidationException::withMessages(['telephone' => $this->visibilite->personneVisible($autre, $acteur)
                        ? 'Ce numéro est déjà celui d\'une autre personne.'
                        : MessagesContacts::NUMERO_NON_VISIBLE]);
                }
            }

            $personne->fill([...$identite, 'telephone_normalise' => $normalise]);

            return $this->enregistrer($personne, $acteur, 'Ce numéro vient d\'être enregistré pour une autre personne.');
        });
    }

    /**
     * @param  array<string, mixed>  $identite
     * @return list<string> champs modifiés
     */
    public function modifierEntreprise(User $acteur, EntrepriseTierce $entreprise, array $identite): array
    {
        $this->assertMemeOrganisation($entreprise->organization_id, $acteur);

        return DB::transaction(function () use ($acteur, $entreprise, $identite) {
            EntrepriseTierce::whereKey($entreprise->id)->lockForUpdate()->first();
            $entreprise->refresh();

            $normalise = $identite['telephone'] !== null ? Personne::normaliserTelephone((string) $identite['telephone']) : null;
            if ($normalise !== null && $normalise !== $entreprise->telephone_normalise) {
                $autre = EntrepriseTierce::where('organization_id', $entreprise->organization_id)->where('telephone_normalise', $normalise)->whereKeyNot($entreprise->id)->first();
                if ($autre !== null) {
                    throw ValidationException::withMessages(['telephone' => $this->visibilite->entrepriseVisible($autre, $acteur)
                        ? 'Ce numéro est déjà celui d\'une autre entreprise.'
                        : MessagesContacts::NUMERO_NON_VISIBLE]);
                }
            }

            $entreprise->fill([...$identite, 'telephone_normalise' => $normalise]);

            return $this->enregistrer($entreprise, $acteur, 'Ce numéro vient d\'être enregistré pour une autre entreprise.');
        });
    }

    /** @return list<string> */
    private function enregistrer(Personne|EntrepriseTierce $fiche, User $acteur, string $messageConcurrence): array
    {
        $champs = array_values(array_diff(array_keys($fiche->getDirty()), ['telephone_normalise']));
        if ($champs === []) {
            return [];
        }

        try {
            $fiche->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['telephone' => $messageConcurrence]);
        }

        $this->journal->record($fiche, AuditEvent::UPDATED, $acteur, null, null, ['origine' => 'contacts', 'champs_modifies' => $champs]);

        return $champs;
    }

    private function assertMemeOrganisation(?string $organizationId, User $acteur): void
    {
        if ($organizationId === null || $organizationId !== $acteur->organization_id) {
            throw new NotFoundHttpException;
        }
    }
}
