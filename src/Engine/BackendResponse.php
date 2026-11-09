<?php

namespace Git\Engine;

/** What `git http-backend` answered: its status, its headers, its body as it comes. */
final class BackendResponse
{
    /** @param iterable<string> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly iterable $body,
    ) {
    }
}
