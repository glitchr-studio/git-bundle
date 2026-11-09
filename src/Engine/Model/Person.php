<?php

namespace Git\Engine\Model;

/** Who wrote or committed: a name, an address, a moment. */
final class Person
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly \DateTimeImmutable $date,
    ) {
    }
}
