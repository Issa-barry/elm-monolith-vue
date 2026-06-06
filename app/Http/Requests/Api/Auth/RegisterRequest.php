<?php

namespace App\Http\Requests\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'telephone' => ['required', 'string', 'max:30'],
            'prenom'    => ['required', 'string', 'min:2', 'max:100'],
            'nom'       => ['required', 'string', 'min:2', 'max:100'],
            'email'     => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class, 'email'),
            ],
            'password'  => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'telephone.required'  => 'Le numéro de téléphone est obligatoire.',
            'prenom.required'     => 'Le prénom est obligatoire.',
            'prenom.min'          => 'Le prénom doit contenir au moins :min caractères.',
            'nom.required'        => 'Le nom est obligatoire.',
            'nom.min'             => 'Le nom doit contenir au moins :min caractères.',
            'email.required'      => 'L\'adresse email est obligatoire.',
            'email.email'         => 'L\'adresse email n\'est pas valide.',
            'email.unique'        => 'Un compte existe déjà avec cette adresse email. Veuillez vous connecter.',
            'password.required'   => 'Le mot de passe est obligatoire.',
            'password.confirmed'  => 'Les mots de passe ne correspondent pas.',
            'password.min'        => 'Le mot de passe doit contenir au moins :min caractères.',
        ];
    }
}
