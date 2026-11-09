<?php

namespace Git\Engine;

/** A git command failed, timed out, said too much, or was asked something invalid. */
class GitException extends \RuntimeException
{
    public static function invalid(string $what, string $value): self
    {
        return new self(sprintf('Invalid %s "%s".', $what, mb_strimwidth($value, 0, 80, '…')));
    }
}
