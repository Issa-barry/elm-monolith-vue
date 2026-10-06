<?php

namespace App\Http\Controllers\Clients;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Http\Controllers\Controller;
use App\Models\CategorieTarifGrossiste;
use App\Models\Client;
use App\Models\Parametre;
use App\Support\Clients\ClientCashbackSoldeFormatter;
use App\Traits\PhoneHandlerTrait;
use Inertia\Inertia;
use Inertia\Response;

class ShowClientController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Client $client): Response
    {
        $this->authorize('view', $client);

        [$telephone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $client->telephone,
            $client->code_phone_pays,
            $client->code_pays,
            $client->pays,
        );

        return Inertia::render('Clients/Show', [
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
            'cashback_solde' => ClientCashbackSoldeFormatter::pour($client),
            'seuil_global_impayes' => Parametre::getVentesSeuilImpayesMax($client->organization_id),
            // Tarifs propres à CE client — pas une grille organisation (cf. docs/grossiste.md).
            // Envoyée pour tout client (tableau vide/inutilisé hors Grossiste) plutôt que de
            // conditionner l'appel : la page décide déjà d'afficher ou non l'onglet.
            'tarifs_grossiste' => CategorieTarifGrossiste::gridForClient($client->organization_id, $client->id),
            'mode_remise_grossiste_options' => ModeRemiseGrossiste::options(),
        ]);
    }
}
