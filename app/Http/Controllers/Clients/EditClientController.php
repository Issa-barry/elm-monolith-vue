<?php

namespace App\Http\Controllers\Clients;

use App\Enums\ClientType;
use App\Enums\StatutCommandeVente;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Parametre;
use App\Services\Contacts\ProprietaireEntreprise;
use App\Support\Clients\ClientCashbackSoldeFormatter;
use App\Traits\PhoneHandlerTrait;
use Inertia\Inertia;
use Inertia\Response;

class EditClientController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Client $client, ProprietaireEntreprise $entreprises): Response
    {
        $this->authorize('update', $client);
        $entreprise = $client->entrepriseTierce;

        [$telephone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $client->telephone,
            $client->code_phone_pays,
            $client->code_pays,
            $client->pays,
        );

        return Inertia::render('Clients/Edit', [
            'client' => [
                'id' => $client->id,
                // Client professionnel (lot 7b) : le champ saisi est le nom du contact (sa Personne),
                // le nom principal du client étant la raison sociale de l'entreprise.
                'nom_complet' => $entreprise !== null ? ($client->personne?->nom_complet ?? '') : $client->nom_complet,
                'client_professionnel' => $entreprise !== null,
                'raison_sociale' => $entreprise?->raison_sociale,
                'telephone_entreprise' => $entreprise?->telephone,
                'identifiant_fiscal' => $entreprise?->identifiant_fiscal,
                'email' => $client->email,
                'telephone' => $telephone,
                'adresse' => $client->adresse,
                'ville' => $client->ville,
                'pays' => $pays,
                'code_pays' => $codePays,
                'code_phone_pays' => $codePhonePays,
                'is_active' => $client->is_active,
                'type' => $client->type->value,
                'type_label' => $client->type->label(),
                'cashback_eligible' => $client->cashback_eligible,
                'cashback_montant_par_pack' => $client->cashback_montant_par_pack,
                'derogation_impayes_autorisee' => $client->derogation_impayes_autorisee,
                'seuil_derogation_impayes' => $client->seuil_derogation_impayes,
            ],
            'types' => ClientType::options(),
            // Lot 7b (ADR 0060, D7) : entreprise reliée, partagée ou non, et droit d'en modifier l'identité.
            'entreprise' => $entreprise === null ? null : [
                'id' => $entreprise->id,
                'raison_sociale' => $entreprise->raison_sociale,
                'partagee' => $entreprises->estPartagee($entreprise, $client),
                'peut_modifier_identite' => $entreprises->peutModifierIdentite($client, auth()->user()),
                'lien' => auth()->user()->can('contacts.read') ? "/backoffice/contacts/entreprises/{$entreprise->id}" : null,
            ],
            'seuil_global_impayes' => Parametre::getVentesSeuilImpayesMax($client->organization_id),
            'vehicules' => $client->vehicules()->get(['id', 'nom_vehicule', 'immatriculation', 'chauffeur_nom', 'chauffeur_telephone', 'chauffeur_code_pays'])
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'chauffeur_nom' => $v->chauffeur_nom,
                    'chauffeur_telephone' => $v->chauffeur_telephone,
                    'chauffeur_code_pays' => $v->chauffeur_code_pays,
                ]),
            'cashback_solde' => ClientCashbackSoldeFormatter::pour($client),
            // D14 (ADR 0060, lot 7c) : commandes non terminées, qui garderont leur nature si elle change.
            'commandes_en_cours' => CommandeVente::where('organization_id', $client->organization_id)
                ->where('client_id', $client->id)
                ->whereNotIn('statut', array_map(fn (StatutCommandeVente $s) => $s->value, array_filter(StatutCommandeVente::cases(), fn (StatutCommandeVente $s) => $s->isTerminal())))
                ->orderByDesc('created_at')
                ->limit(10)
                ->pluck('reference')
                ->values(),
        ]);
    }
}
