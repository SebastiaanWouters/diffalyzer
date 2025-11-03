<?php

declare(strict_types=1);

namespace Diffalyzer\Tests;

use Diffalyzer\User;

class UserFixtureTest
{
    public function testLoadUsersFromFixture(): void
    {
        $data = json_decode(file_get_contents(__DIR__ . '/fixtures/users.json'), true);
        
        foreach ($data as $userData) {
            $user = new User($userData['name'], $userData['email']);
            assert($user->getName() !== '');
        }
    }
}
