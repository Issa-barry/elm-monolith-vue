<?php

namespace App\Services\Rh;

use App\Enums\StatutEmploye;
use App\Models\Client;
use App\Models\Employe;
use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Models\User;
use App\Services\PhoneCountryInfo;
use App\Support\Rh\PerimetreEmploye;

/**
 * « Nouvel employé » commence par le téléphone (docs/rh-employes-identite.md) : avant toute
 * saisie, on cherche si la personne existe déjà dans l'organisation, par téléphone normalisé —
 * la même clé que Personne::resoudreOuCreer() et que la contrainte unique
 * (organization_id, telephone_normalise). Trois issues :
 *
 * - `employe` : un dossier employé existe déjà (actif, suspendu ou sorti) → aucun nouveau dossier ;
 * - `personne` : le numéro identifie déjà une personne sous un autre rôle (livreur, utilisateur,
 *   propriétaire…) → le dossier employé lui sera rattaché, son identité n'est pas ressaisie ;
 * - `nouveau` : numéro inconnu → création d'un nouveau dossier.
 *
 * Simple aide à la saisie : le contrôle qui fait foi reste celui de EmployeController::store()
 * (même clé, sous verrou), car un dossier peut être créé entre la recherche et l'enregistrement.
 */
class RechercheEmployeParTelephone
{
    /**
     * Numéro saisi sous n'importe quelle forme (« +224 622 60 26 93 », « 00224622602693 »,
     * « 622602693 » ou « 0622602693 » avec l'indicatif du pays choisi) → format international
     * validé par libphonenumber, ou null s'il n'est pas valide.
     *
     * @return array{telephone: string, code_pays: string, indicatif: string, pays: string, devise: ?string, fuseau: ?string}|null
     */
    public static function normaliser(string $saisie, ?string $indicatif = null): ?array
    {
        $saisie = trim($saisie);
        if (str_starts_with($saisie, '00')) {
            $saisie = '+'.substr($saisie, 2);
        }
        $chiffres = preg_replace('/\D+/', '', $saisie) ?? '';
        if ($chiffres === '') {
            return null;
        }
        if (str_starts_with($saisie, '+')) {
            return PhoneCountryInfo::resolve('+'.$chiffres);
        }

        $codePays = preg_replace('/\D+/', '', (string) $indicatif) ?? '';
        $candidats = array_filter([
            $codePays !== '' ? '+'.$codePays.preg_replace('/^0/', '', $chiffres) : null,
            // Saisi avec son indicatif mais sans « + » (« 224622602693 »).
            '+'.$chiffres,
        ]);
        foreach ($candidats as $candidat) {
            if (($info = PhoneCountryInfo::resolve($candidat)) !== null) {
                return $info;
            }
        }

        return null;
    }

    /**
     * Téléphone d'un contact d'urgence : à qui appartient déjà ce numéro dans l'organisation ?
     * Toutes les correspondances (une personne et ses rôles, un client, une entreprise tierce),
     * jamais une seule choisie arbitrairement. Lecture seule : aucune fiche n'est créée ni
     * modifiée — le contact reste enregistré sur la fiche du salarié, son nom peut seulement être
     * repris d'une fiche existante (choix du 01/11/2026, sans migration).
     *
     * @return list<array{nom: string, role: string}>
     */
    public function proprietairesDuNumero(string $organizationId, string $telephone): array
    {
        $chiffres = Personne::normaliserTelephone($telephone);
        $resultats = [];

        $personne = Personne::where('organization_id', $organizationId)
            ->where('telephone_normalise', $chiffres)
            ->with(['employe', 'livreur', 'proprietaire', 'fournisseur', 'prestataire', 'user'])
            ->first();
        if ($personne !== null && filled($personne->nom_complet)) {
            $roles = array_filter([
                $personne->employe ? 'employé' : null,
                $personne->livreur ? 'livreur' : null,
                $personne->proprietaire ? 'propriétaire de véhicule' : null,
                $personne->fournisseur ? 'fournisseur' : null,
                $personne->prestataire ? 'prestataire' : null,
                $personne->user ? 'utilisateur de l’application' : null,
            ]);
            $resultats[] = ['nom' => (string) $personne->nom_complet, 'role' => $roles === [] ? 'contact' : implode(', ', $roles)];
        }

        // Client : îlot à part (téléphone stocké sur le client, cf. TelephoneOwnerLookupService).
        Client::where('organization_id', $organizationId)
            ->where('telephone', '+'.$chiffres)
            ->whereNull('deleted_at')
            ->get()
            ->each(function (Client $client) use (&$resultats) {
                if (filled($client->nom_complet)) {
                    $resultats[] = ['nom' => (string) $client->nom_complet, 'role' => 'client'];
                }
            });

        EntrepriseTierce::where('organization_id', $organizationId)
            ->where('telephone_normalise', $chiffres)
            ->get(['raison_sociale'])
            ->each(function (EntrepriseTierce $entreprise) use (&$resultats) {
                $resultats[] = ['nom' => (string) $entreprise->raison_sociale, 'role' => 'entreprise'];
            });

        // Un même nom sous plusieurs rôles n'est proposé qu'une fois.
        return collect($resultats)
            ->groupBy(fn (array $r) => mb_strtolower(trim($r['nom'])))
            ->map(fn ($groupe) => ['nom' => $groupe->first()['nom'], 'role' => $groupe->pluck('role')->unique()->implode(', ')])
            ->values()
            ->all();
    }

    /**
     * Dossiers de l'organisation de l'acteur seulement. Les dossiers supprimés (archivés) ne sont
     * que comptés : ils n'empêchent pas une réembauche (règle de EmployeController::store()).
     *
     * @param  array{telephone: string, pays: string, code_pays: string, indicatif: string}  $numero
     * @return array<string, mixed>
     */
    public function rechercher(User $acteur, array $numero): array
    {
        $personne = Personne::where('organization_id', $acteur->organization_id)
            ->where('telephone_normalise', Personne::normaliserTelephone($numero['telephone']))
            ->with(['livreur', 'proprietaire', 'fournisseur', 'prestataire', 'user'])
            ->first();

        $dossiers = $personne === null ? collect() : Employe::withTrashed()
            ->with(['personne', 'site'])
            ->where('organization_id', $acteur->organization_id)
            ->where('personne_id', $personne->id)
            ->orderBy('created_at')
            ->get();
        $employes = $dossiers->filter(fn (Employe $e) => ! $e->trashed())->values();

        return [
            'statut' => match (true) {
                $employes->isNotEmpty() => 'employe',
                $personne !== null => 'personne',
                default => 'nouveau',
            },
            'telephone' => $numero['telephone'],
            'pays' => $numero['pays'],
            'code_pays' => $numero['code_pays'],
            'indicatif' => $numero['indicatif'],
            'employes' => $employes->map(fn (Employe $e) => [
                'id' => $e->id,
                'nom_complet' => $e->nom_complet,
                'matricule' => $e->matricule,
                'statut' => $e->statut->value,
                'statut_label' => $e->statut->label(),
                'site' => $e->site?->nom,
                // Lien seulement si la fiche est consultable (agence) ; l'existence est toujours dite.
                'peut_consulter' => $acteur->can('view', $e) && PerimetreEmploye::peutConsulter($acteur, $e),
                'peut_modifier' => $e->statut !== StatutEmploye::SORTI && $acteur->can('update', $e) && PerimetreEmploye::peutModifier($acteur, $e),
            ])->all(),
            'dossiers_archives' => $dossiers->count() - $employes->count(),
            'personne' => $personne === null || $employes->isNotEmpty() ? null : [
                'prenom' => $personne->prenom,
                'nom' => $personne->nom,
                'nom_complet' => $personne->nom_complet,
                'email' => $personne->email,
                'roles' => array_values(array_filter([
                    $personne->livreur ? 'livreur' : null,
                    $personne->proprietaire ? 'propriétaire de véhicule' : null,
                    $personne->fournisseur ? 'fournisseur' : null,
                    $personne->prestataire ? 'prestataire' : null,
                    $personne->user ? 'utilisateur de l’application' : null,
                ])),
            ],
        ];
    }
}
