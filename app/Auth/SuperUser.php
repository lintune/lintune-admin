<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

class SuperUser implements Authenticatable
{
    public function __construct(
        public readonly string $username,
    ) {}

    public function getAuthIdentifierName(): string  { return 'username'; }
    public function getAuthIdentifier(): mixed        { return $this->username; }
    public function getAuthPasswordName(): string     { return 'password'; }
    public function getAuthPassword(): string         { return ''; }
    public function getRememberToken(): ?string       { return null; }
    public function setRememberToken($value): void    {}
    public function getRememberTokenName(): string    { return ''; }
}
