<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Tests\Fixture;

final class Invoice
{
    public int $total = 250;
    public string $note = 'internal note';
    public Owner $owner;

    public function __construct()
    {
        $this->owner = new Owner();
    }
}
