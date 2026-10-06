<?php

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\SiteCentralTresorerieResolver;
use Illuminate\Validation\ValidationException;

/**
 * Normalisation et messages de validation partagés entre la création et la modification d'un
 * site — extrait de SiteController::normalizeStrings()/messages().
 */
final class SiteFormSupport
{
    public const MESSAGE_RETRAIT_TRESORERIE_PRINCIPALE = 'La trésorerie principale ne peut pas être retirée : activez-la sur un autre site pour la transférer.';

    /**
     * Trésorerie principale demandée par le formulaire (ADR 0017) : sans changement, le champ est
     * ignoré ; un changement exige `tresorerie.designer_principale`. On la transfère en l'activant
     * sur un autre site (Site::saving() la retire à l'ancien), jamais en la désactivant : une
     * organisation ne se retrouve pas sans trésorerie principale par un simple décochage.
     */
    public static function resoudreTresoreriePrincipale(array $data, User $user, ?Site $site = null): array
    {
        if (! array_key_exists('is_central_tresorerie', $data)) {
            return $data;
        }

        $demandee = (bool) $data['is_central_tresorerie'];
        if ($demandee === (bool) $site?->isCentralTresorerie()) {
            unset($data['is_central_tresorerie']);

            return $data;
        }

        abort_unless($user->can('tresorerie.designer_principale'), 403, "Vous n'avez pas le droit de désigner la trésorerie principale.");

        if (! $demandee) {
            throw ValidationException::withMessages(['is_central_tresorerie' => self::MESSAGE_RETRAIT_TRESORERIE_PRINCIPALE]);
        }

        return $data;
    }

    /** Trésorerie principale actuelle de l'organisation, pour la confirmation de transfert du formulaire. */
    public static function tresoreriePrincipale(SiteCentralTresorerieResolver $resolver, string $organizationId): ?array
    {
        $site = $resolver->centralOuNull($organizationId);

        return $site ? ['id' => $site->id, 'nom' => $site->nom] : null;
    }

    public static function normalizeStrings(array $data): array
    {
        if (! empty($data['code'])) {
            $data['code'] = mb_strtoupper(trim($data['code']), 'UTF-8');
        }
        if (! empty($data['nom'])) {
            $data['nom'] = mb_convert_case(mb_strtolower($data['nom']), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['ville'])) {
            $data['ville'] = mb_convert_case(mb_strtolower($data['ville']), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['quartier'])) {
            $data['quartier'] = mb_convert_case(mb_strtolower($data['quartier']), MB_CASE_TITLE, 'UTF-8');
        }

        return $data;
    }

    public static function messages(): array
    {
        return [
            'nom.required' => 'Le nom du site est obligatoire.',
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'code.required' => 'Le code du site est obligatoire.',
            'code.max' => 'Le code ne peut pas dépasser 50 caractères.',
            'code.regex' => 'Le code ne peut contenir que des majuscules, chiffres, tirets et underscores.',
            'code.unique' => 'Ce code est déjà utilisé par un autre site de votre organisation.',
            'type.required' => 'Le type de site est obligatoire.',
            'type.in' => 'Type de site invalide.',
            'statut.in' => 'Statut invalide.',
            'commissions_active.boolean' => 'La valeur du réglage Commissions est invalide.',
            'is_central_tresorerie.boolean' => 'La valeur du réglage Trésorerie principale est invalide.',
            'localisation.required' => "L'adresse du site est obligatoire.",
            'parent_id.exists' => 'Le site parent sélectionné est introuvable.',
        ];
    }
}
