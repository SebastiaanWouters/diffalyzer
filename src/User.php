<?php

declare(strict_types=1);

namespace Diffalyzer;

class User
{
    public function __construct(
        private readonly string $name,
        private readonly string $email,
        private readonly int $age = 0
    ) {
    }

    public function getName(): string
    {
        return strtoupper($this->name);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAge(): int
    {
        return $this->age;
    }
}

    public function getFullInfo(): string
    {
        return $this->name . ' - ' . $this->email;
    }
