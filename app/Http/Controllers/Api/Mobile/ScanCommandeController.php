<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\Client\ClientIdentity;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\Operations\OperationsCompte;
use App\Services\Client\Operations\PresentationOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Scan d'une référence : réservé au personnel habilité sur l'agence et aux comptes réellement liés à
 * l'opération (docs/api-espace-client-contract.md). Les références sont séquentielles : tout refus
 * répond exactement comme une référence inconnue, pour ne pas confirmer qu'une opération existe.
 */
class ScanCommandeController extends Controller
{
    /**
     * VTE-/DST- = nouvelles commandes (cf. NatureOperation::prefixeReference()) ; CMD- et VT- =
     * références émises avant le chantier du 31/08/2026, jamais renommées, toujours reconnues.
     */
    private const COMMANDE_PREFIXES = ['VTE-', 'DST-', 'CMD-', 'VT-'];

    /** TRF- = nouveaux transferts ; TR- = référence émise avant le 31/08/2026. */
    private const TRANSFERT_PREFIXES = ['TRF-', 'TR-'];

    public function __construct(private readonly ClientIdentityResolver $identityResolver) {}

    public function __invoke(string $reference): JsonResponse
    {
        $ref = strtoupper(trim($reference));

        /** @var User $user */
        $user = auth()->user();

        // Jamais d'organisation déduite du téléphone ici : le repli de ClientIdentityResolver ouvrirait
        // à un compte sans organisation, et au super admin via Gate::before, celle d'une fiche non réclamée.
        if ($user->organization_id === null) {
            return $this->introuvable();
        }

        $identity = $this->identityResolver->resolve($user);

        // Le ticket de vente et le ticket PDV portent l'identifiant de la vente, pas sa référence.
        if (Str::isUlid(trim($reference))) {
            return $this->scanCommande('id', strtolower(trim($reference)), $user, $identity);
        }

        if ($this->startsWithAny($ref, self::COMMANDE_PREFIXES)) {
            return $this->scanCommande('reference', $ref, $user, $identity);
        }

        if ($this->startsWithAny($ref, self::TRANSFERT_PREFIXES)) {
            return $this->scanTransfert($ref, $user, $identity);
        }

        return $this->introuvable();
    }

    private function introuvable(): JsonResponse
    {
        return response()->json(['message' => 'Référence introuvable.'], 404);
    }

    /** @param  list<string>  $prefixes */
    private function startsWithAny(string $ref, array $prefixes): bool
    {
        foreach ($prefixes as $prefixe) {
            if (str_starts_with($ref, $prefixe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La permission de lecture ne suffit pas : un agent ne lit que les opérations de ses agences, sauf
     * vision 360° (ADR 0025).
     *
     * @param  list<string|null>  $siteIds
     */
    private function couvreUneAgence(User $user, array $siteIds): bool
    {
        if ($user->voitToutesLesAgences()) {
            return true;
        }

        return $user->sites()->whereIn('sites.id', array_filter($siteIds))->exists();
    }

    /** @param  'id'|'reference'  $colonne */
    private function scanCommande(string $colonne, string $valeur, User $user, ClientIdentity $identity): JsonResponse
    {
        // La numérotation est scopée par organisation : la même référence peut exister ailleurs, toujours
        // filtrer sur l'organisation du compte, préfixes historiques compris.
        $commande = CommandeVente::with([
            'site:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'vehicule.equipe:id,vehicule_id',
            'client:id,nom,prenom,telephone,adresse,ville',
            'lignes:id,commande_vente_id,quantite_demandee',
        ])->where($colonne, $valeur)->where('organization_id', $identity->organizationId)->first();

        if (! $commande) {
            return $this->introuvable();
        }

        $personnel = $user->can('view', $commande) && $this->couvreUneAgence($user, [$commande->site_id]);
        $operations = new OperationsCompte($identity);

        if (! $personnel && ! $operations->ventes()->whereKey($commande->id)->exists()) {
            return $this->introuvable();
        }

        $liens = $personnel ? [] : $operations->liensVentes(new Collection([$commande]))[$commande->id];
        $client = $commande->client;
        $voitContact = $personnel || PresentationOperation::voitLeContact($commande, $liens);

        return response()->json([
            'type' => 'commande',
            'reference' => $commande->reference ?? '—',
            'statut' => $commande->statut?->value ?? '—',
            'statut_label' => $commande->statut?->label() ?? '—',
            'site_source' => $commande->site?->nom ?? '—',
            'client_nom' => $client?->nom_complet ?? 'Vente directe',
            'client_telephone' => $voitContact ? $client?->telephone : null,
            'client_adresse' => $voitContact ? PresentationOperation::adresse($client) : null,
            'vehicule' => $commande->vehicule ? [
                'nom' => $commande->vehicule->nom_vehicule,
                'immatriculation' => $commande->vehicule->immatriculation,
            ] : null,
            'equipe_nom' => $commande->vehicule?->nom_vehicule ?? '—',
            'date_commande' => $commande->validated_at?->toDateString(),
            'nb_packs' => (int) $commande->lignes->sum('quantite_demandee'),
            'total' => $personnel || PresentationOperation::voitLesPrix($liens) ? (float) $commande->total_commande : null,
        ]);
    }

    private function scanTransfert(string $reference, User $user, ClientIdentity $identity): JsonResponse
    {
        $transfert = TransfertLogistique::with([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes',
        ])->where('reference', $reference)->where('organization_id', $identity->organizationId)->first();

        if (! $transfert) {
            return $this->introuvable();
        }

        $personnel = $user->can('view', $transfert)
            && $this->couvreUneAgence($user, [$transfert->site_source_id, $transfert->site_destination_id]);

        if (! $personnel && ! (new OperationsCompte($identity))->transferts()->whereKey($transfert->id)->exists()) {
            return $this->introuvable();
        }

        return response()->json([
            'type' => 'transfert',
            'reference' => $transfert->reference,
            'statut' => $transfert->statut instanceof \BackedEnum ? $transfert->statut->value : $transfert->statut,
            'statut_label' => $transfert->statut instanceof \BackedEnum ? $transfert->statut->label() : '—',
            'site_source' => $transfert->siteSource?->nom ?? '—',
            'site_destination' => $transfert->siteDestination?->nom ?? '—',
            'vehicule' => $transfert->vehicule ? [
                'nom' => $transfert->vehicule->nom_vehicule,
                'immatriculation' => $transfert->vehicule->immatriculation,
            ] : null,
            'equipe_nom' => $transfert->equipeLivraison?->nom ?? '—',
            'date_depart' => $transfert->date_depart_reelle?->toDateString(),
            'date_arrivee_prevue' => $transfert->date_arrivee_prevue?->toDateString(),
            'nb_packs' => (int) $transfert->lignes->sum('quantite_chargee'),
        ]);
    }
}
