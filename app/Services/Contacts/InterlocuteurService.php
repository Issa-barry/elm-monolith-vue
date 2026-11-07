<?php

namespace App\Services\Contacts;

use App\Enums\AuditEvent;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Interlocuteurs d'une entreprise du référentiel Contacts (ADR 0060, docs/contacts.md).
 *
 * - Un interlocuteur est une Personne du référentiel : téléphone obligatoire, une même personne
 *   peut représenter plusieurs entreprises (CONTACT-004).
 * - Une Personne déjà connue sous ce numéro n'est JAMAIS rattachée sans confirmation explicite
 *   (CONTACT-005, décision R4) : sans confirmation, l'ajout est refusé et la personne trouvée est
 *   proposée (personneConnue()).
 * - Cette confirmation porte sur le lien entreprise ↔ personne. Le rattachement d'un COMPTE à cet
 *   interlocuteur reste celui d'ADR 0056 (RattachementAutomatiqueObserver), inchangé.
 * - Au plus un principal actif par entreprise (CONTACT-006) : toute opération qui peut changer le
 *   principal verrouille la ligne de l'entreprise (SELECT ... FOR UPDATE) dans sa transaction, ce
 *   qui sérialise deux désignations concurrentes.
 * - On ne supprime jamais un interlocuteur : on le désactive, l'historique reste lisible.
 *
 * - Une entreprise désactivée ne reçoit plus d'interlocuteur ; ses liens existants restent gérables.
 * - Chaque opération est inscrite au journal d'audit existant, avec des identifiants et des
 *   indicateurs seulement : jamais de nom, de téléphone ni le texte des notes.
 *
 * Exposé au lot 4 par les contrôleurs App\Http\Controllers\Contacts\Interlocuteurs.
 */
final class InterlocuteurService
{
    public function __construct(private readonly AuditLogService $journal) {}

    /** Personne de l'organisation qui porte déjà ce numéro, à proposer à l'utilisateur. */
    public function personneConnue(EntrepriseTierce $entreprise, string $telephone): ?Personne
    {
        $normalise = Personne::normaliserTelephone($telephone);

        return $normalise === '' ? null : Personne::where('organization_id', $entreprise->organization_id)
            ->where('telephone_normalise', $normalise)
            ->first();
    }

    /**
     * @param  array{telephone?: ?string, nom?: ?string, prenom?: ?string, nom_complet?: ?string, email?: ?string, pays?: ?string, code_pays?: ?string, code_phone_pays?: ?string, ville?: ?string, quartier?: ?string, adresse?: ?string, latitude?: float|string|null, longitude?: float|string|null, fonction?: ?string, notes?: ?string}  $donnees
     * @param  ?string  $personneConfirmeeId  id de la Personne déjà connue que l'utilisateur a confirmée
     */
    public function ajouter(
        EntrepriseTierce $entreprise,
        array $donnees,
        User $acteur,
        ?string $personneConfirmeeId = null,
        bool $principal = false,
    ): EntrepriseInterlocuteur {
        $this->assertMemeOrganisation($entreprise, $acteur);
        // Strictement false : un modèle tout juste créé n'a pas encore lu le défaut (actif) de la base.
        if ($entreprise->is_active === false) {
            throw ValidationException::withMessages(['entreprise' => 'Cette entreprise est désactivée : aucun interlocuteur ne peut lui être ajouté.']);
        }

        $telephone = trim((string) ($donnees['telephone'] ?? ''));
        if (Personne::normaliserTelephone($telephone) === '') {
            throw ValidationException::withMessages(['telephone' => "Le numéro de téléphone de l'interlocuteur est obligatoire."]);
        }

        try {
            return DB::transaction(function () use ($entreprise, $donnees, $acteur, $personneConfirmeeId, $principal, $telephone) {
                $this->verrouiller($entreprise);

                $personne = $this->resoudrePersonne($entreprise, $donnees, $telephone, $personneConfirmeeId);

                $existant = EntrepriseInterlocuteur::where('entreprise_tierce_id', $entreprise->id)
                    ->where('personne_id', $personne->id)
                    ->first();
                if ($existant) {
                    throw ValidationException::withMessages(['telephone' => $existant->is_active
                        ? 'Cette personne est déjà interlocuteur de cette entreprise.'
                        : 'Cette personne est déjà interlocuteur de cette entreprise (désactivé) : réactivez-le.']);
                }

                $interlocuteur = EntrepriseInterlocuteur::create([
                    'organization_id' => $entreprise->organization_id,
                    'entreprise_tierce_id' => $entreprise->id,
                    'personne_id' => $personne->id,
                    'fonction' => $this->texte($donnees['fonction'] ?? null),
                    'notes' => $this->texte($donnees['notes'] ?? null),
                    'is_active' => true,
                    'created_by' => $acteur->id,
                ]);

                $anciens = $principal ? $this->marquerPrincipal($entreprise, $interlocuteur) : [];

                $this->journal->record($interlocuteur, AuditEvent::CREATED, $acteur, null,
                    ['entreprise_tierce_id' => $entreprise->id, 'est_principal' => $principal],
                    ['personne_existante' => $personneConfirmeeId !== null, 'ancien_principal_ids' => $anciens]);

                return $interlocuteur->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // Deux ajouts simultanés du même numéro : le second se heurte à l'unicité de la Personne
            // ou du lien, jamais à un doublon silencieux.
            throw ValidationException::withMessages(['telephone' => 'Ce numéro vient d\'être enregistré par un autre utilisateur. Recommencez pour confirmer la personne.']);
        }
    }

    /**
     * Fonction et notes du lien. Les coordonnées de la personne se modifient sur sa fiche Personne :
     * elles sont communes à toutes ses fonctions.
     *
     * @param  array{fonction?: ?string, notes?: ?string}  $donnees
     */
    public function modifier(EntrepriseInterlocuteur $interlocuteur, array $donnees, User $acteur): EntrepriseInterlocuteur
    {
        $this->assertMemeOrganisation($interlocuteur->entrepriseTierce, $acteur);

        $interlocuteur->fill(array_filter([
            'fonction' => array_key_exists('fonction', $donnees) ? $this->texte($donnees['fonction']) : false,
            'notes' => array_key_exists('notes', $donnees) ? $this->texte($donnees['notes']) : false,
        ], fn ($valeur) => $valeur !== false));

        $champs = array_keys($interlocuteur->getDirty());
        if ($champs !== []) {
            $interlocuteur->save();
            // Noms des champs seulement : les notes sont du texte libre.
            $this->journal->record($interlocuteur, AuditEvent::UPDATED, $acteur, null, null, ['champs_modifies' => $champs]);
        }

        return $interlocuteur->refresh();
    }

    public function definirPrincipal(EntrepriseInterlocuteur $interlocuteur, User $acteur): EntrepriseInterlocuteur
    {
        $entreprise = $interlocuteur->entrepriseTierce;
        $this->assertMemeOrganisation($entreprise, $acteur);

        return DB::transaction(function () use ($entreprise, $interlocuteur, $acteur) {
            $this->verrouiller($entreprise);

            $interlocuteur->refresh();
            if (! $interlocuteur->is_active) {
                throw ValidationException::withMessages(['interlocuteur' => 'Un interlocuteur désactivé ne peut pas être principal : réactivez-le d\'abord.']);
            }

            if ($interlocuteur->est_principal) {
                return $interlocuteur;
            }

            $anciens = $this->marquerPrincipal($entreprise, $interlocuteur);
            $this->journal->record($interlocuteur, AuditEvent::UPDATED, $acteur, ['est_principal' => false], ['est_principal' => true], ['ancien_principal_ids' => $anciens]);

            return $interlocuteur->refresh();
        });
    }

    /** Le lien est conservé (historique, accès aux commissions tracé) ; il perd le rôle de principal. */
    public function desactiver(EntrepriseInterlocuteur $interlocuteur, User $acteur): EntrepriseInterlocuteur
    {
        $entreprise = $interlocuteur->entrepriseTierce;
        $this->assertMemeOrganisation($entreprise, $acteur);

        return DB::transaction(function () use ($entreprise, $interlocuteur, $acteur) {
            $this->verrouiller($entreprise);

            $interlocuteur->refresh();
            if (! $interlocuteur->is_active) {
                return $interlocuteur;
            }
            $etaitPrincipal = $interlocuteur->est_principal;

            $interlocuteur->forceFill([
                'is_active' => false,
                'est_principal' => false,
                'desactive_le' => now(),
                'desactive_par' => $acteur->id,
            ])->save();

            $this->journal->record($interlocuteur, AuditEvent::STATUS_CHANGED, $acteur, ['is_active' => true], ['is_active' => false], ['etait_principal' => $etaitPrincipal]);

            return $interlocuteur->refresh();
        });
    }

    /** Réactivé sans redevenir principal : la désignation reste un geste explicite. */
    public function reactiver(EntrepriseInterlocuteur $interlocuteur, User $acteur): EntrepriseInterlocuteur
    {
        $this->assertMemeOrganisation($interlocuteur->entrepriseTierce, $acteur);
        if ($interlocuteur->is_active) {
            return $interlocuteur;
        }

        $interlocuteur->forceFill([
            'is_active' => true,
            'desactive_le' => null,
            'desactive_par' => null,
        ])->save();

        $this->journal->record($interlocuteur, AuditEvent::STATUS_CHANGED, $acteur, ['is_active' => false], ['is_active' => true]);

        return $interlocuteur->refresh();
    }

    private function resoudrePersonne(EntrepriseTierce $entreprise, array $donnees, string $telephone, ?string $personneConfirmeeId): Personne
    {
        $connue = $this->personneConnue($entreprise, $telephone);

        if ($connue) {
            if ($personneConfirmeeId !== $connue->id) {
                throw ValidationException::withMessages(['telephone' => "Ce numéro est déjà celui de {$connue->nom_complet}. Confirmez qu'il s'agit de la même personne avant de la rattacher."]);
            }

            // Reprise telle quelle : ses coordonnées appartiennent à toutes ses fonctions et ne sont
            // jamais écrasées par une saisie d'interlocuteur.
            return $connue;
        }

        if ($personneConfirmeeId !== null) {
            throw ValidationException::withMessages(['telephone' => 'La personne confirmée ne porte pas ce numéro. Recommencez la recherche.']);
        }

        $nomComplet = $this->texte($donnees['nom_complet'] ?? null);
        $nom = $this->texte($donnees['nom'] ?? null);
        $prenom = $this->texte($donnees['prenom'] ?? null);
        if ($nomComplet === null && $nom === null && $prenom === null) {
            throw ValidationException::withMessages(['nom' => "Le nom de l'interlocuteur est obligatoire."]);
        }

        return Personne::create([
            'organization_id' => $entreprise->organization_id,
            'telephone' => $telephone,
            'telephone_normalise' => Personne::normaliserTelephone($telephone),
            'nom' => $nom,
            'prenom' => $prenom,
            'nom_complet' => $nomComplet,
            'email' => $donnees['email'] ?? null,
            'pays' => $donnees['pays'] ?? null,
            'code_pays' => $donnees['code_pays'] ?? null,
            'code_phone_pays' => $donnees['code_phone_pays'] ?? null,
            'ville' => $this->texte($donnees['ville'] ?? null),
            'quartier' => $this->texte($donnees['quartier'] ?? null),
            'adresse' => $this->texte($donnees['adresse'] ?? null),
            'latitude' => $donnees['latitude'] ?? null,
            'longitude' => $donnees['longitude'] ?? null,
        ]);
    }

    /**
     * À appeler dans une transaction, après verrouiller().
     *
     * @return list<string> ids des interlocuteurs qui perdent le rôle de principal
     */
    private function marquerPrincipal(EntrepriseTierce $entreprise, EntrepriseInterlocuteur $interlocuteur): array
    {
        $ids = EntrepriseInterlocuteur::where('entreprise_tierce_id', $entreprise->id)
            ->where('id', '!=', $interlocuteur->id)
            ->where('est_principal', true)
            ->pluck('id')
            ->all();
        if ($ids !== []) {
            EntrepriseInterlocuteur::whereIn('id', $ids)->update(['est_principal' => false]);
        }

        $interlocuteur->forceFill(['est_principal' => true])->save();

        return $ids;
    }

    private function verrouiller(EntrepriseTierce $entreprise): void
    {
        EntrepriseTierce::whereKey($entreprise->id)->lockForUpdate()->first();
    }

    private function assertMemeOrganisation(EntrepriseTierce $entreprise, User $acteur): void
    {
        if ($entreprise->organization_id === null || $entreprise->organization_id !== $acteur->organization_id) {
            throw new AuthorizationException("Cette entreprise n'appartient pas à votre organisation.");
        }
    }

    private function texte(mixed $valeur): ?string
    {
        $v = $valeur === null ? '' : trim((string) $valeur);

        return $v === '' ? null : $v;
    }
}
