<?php

declare(strict_types=1);

namespace Diffalyzer;

use Diffalyzer\User;

class UserCollector
{
    private array $users = [];

    public function addUser(User $user): void
    {
        $this->users[] = $user;
    }

    public function getUsers(): array
    {
        return $this->users;
    }

    public function getUserNames(): array
    {
        return array_map(fn(User $user) => $user->getName(), $this->users);
    }
}
