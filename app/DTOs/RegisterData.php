<?php

namespace App\DTOs;

readonly class RegisterData
{
    public function __construct(
        public string $telephone,
        public string $prenom,
        public string $nom,
        public string $email,
        public string $password,
    ) {}
}
