<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

class SuperSessionGuard implements Guard
{
    private ?SuperUser $resolvedUser = null;

    public function check(): bool
    {
        return (bool) session('super_access_token');
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if (! $this->check()) {
            return null;
        }

        if ($this->resolvedUser === null) {
            $this->resolvedUser = new SuperUser(
                username: session('super_username', 'admin'),
            );
        }

        return $this->resolvedUser;
    }

    public function id(): mixed
    {
        return session('super_username');
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->resolvedUser !== null;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->resolvedUser = $user instanceof SuperUser ? $user : null;
    }
}
