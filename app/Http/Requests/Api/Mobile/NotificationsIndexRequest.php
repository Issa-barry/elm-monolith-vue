<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pagination de `GET /v1/mobile/notifications` — mirroring
 * DepensesMineRequest::perPage() (même borne, même défaut), pour rester
 * cohérent avec le reste de l'API Espace Client.
 */
class NotificationsIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 20);
    }
}
