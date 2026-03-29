<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('prenom') && $this->prenom !== null) {
            $lower = mb_strtolower($this->prenom, 'UTF-8');
            $data['prenom'] = preg_replace_callback(
                '/(^|[\s-])(\pL)/u',
                fn ($m) => $m[1].mb_strtoupper($m[2], 'UTF-8'),
                $lower,
            );
        }

        if ($this->has('nom') && $this->nom !== null) {
            $data['nom'] = mb_strtoupper($this->nom, 'UTF-8');
        }

        if ($this->has('email') && $this->email !== null) {
            $data['email'] = mb_strtolower($this->email, 'UTF-8');
        }

        if ($data) {
            $this->merge($data);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'prenom' => ['required', 'string', 'min:2', 'max:100'],
            'nom' => ['required', 'string', 'min:2', 'max:100'],
            'email' => [
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];

        if ($this->user()->hasRole('super_admin')) {
            $rules['telephone'] = ['nullable', 'string', 'max:30'];
        }

        return $rules;
    }
}
