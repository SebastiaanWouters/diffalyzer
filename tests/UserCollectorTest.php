<?php

declare(strict_types=1);

namespace Diffalyzer\Tests;

use Diffalyzer\User;
use Diffalyzer\UserCollector;

class UserCollectorTest
{
    public function testAddUser(): void
    {
        $collector = new UserCollector();
        $user = new User('Jane', 'jane@example.com');
        $collector->addUser($user);

        assert(count($collector->getUsers()) === 1);
    }

    public function testGetUserNames(): void
    {
        $collector = new UserCollector();
        $user = new User('Bob', 'bob@example.com');
        $collector->addUser($user);

        assert($collector->getUserNames() === ['Bob']);
    }
}
