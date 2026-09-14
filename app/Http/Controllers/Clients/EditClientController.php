<?php

namespace App\Http\Controllers\Clients;

use App\Enums\ClientType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Parametre;
use App\Support\Clients\ClientCashbackSoldeFormatter;
use App\Traits\PhoneHandlerTrait;
use Inertia\Inertia;
use Inertia\Response;

class EditClientController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Client $client): Response
    {
        $this->authorize('update', $client);

        [$telephone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $client->telephone,
            $client->code_phone_pays,
            $client->code_pays,
            $client->pays,
        );

        return Inertia::render('Clients/Edit', [
            'client' => [
                'id' => $client->id,
                'nom_complet' => $client->nom_complet,
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
        ]);
    }
}
