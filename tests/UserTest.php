<?php

declare(strict_types=1);

namespace Diffalyzer\Tests;

use Diffalyzer\User;

class UserTest
{
    public function testGetName(): void
    {
        $user = new User('John', 'john@example.com');
        assert($user->getName() === 'John');
    }
}
