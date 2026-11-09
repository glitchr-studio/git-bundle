<?php

namespace Git\Engine;

/**
 * What may be handed to git as a ref, a revision or a path. Everything a
 * visitor types or a URL carries goes through here before it reaches a
 * command line; git itself never sees an unchecked value, and no value can
 * start with "-" (no option smuggled in as an argument).
 */
final class Names
{
    public const MAX_REF = 255;
    public const MAX_PATH = 4096;

    /**
     * A branch or tag name (without refs/heads/), as git check-ref-format
     * accepts it, a little stricter: no leading "-", printable ASCII and
     * UTF-8 letters only.
     */
    public static function isRefName(string $name): bool
    {
        if ('' === $name || \strlen($name) > self::MAX_REF || '@' === $name) {
            return false;
        }
        if (str_starts_with($name, '-') || str_starts_with($name, '/') || str_ends_with($name, '/') || str_ends_with($name, '.')) {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $name) || str_contains($name, '..') || str_contains($name, '//') || str_contains($name, '@{')) {
            return false;
        }
        if (!mb_check_encoding($name, 'UTF-8')) {
            return false;
        }
        foreach (explode('/', $name) as $part) {
            if (str_starts_with($part, '.') || str_ends_with($part, '.lock')) {
                return false;
            }
        }

        return true;
    }

    public static function refName(string $name): string
    {
        if (!self::isRefName($name)) {
            throw GitException::invalid('ref name', $name);
        }

        return $name;
    }

    /** An object id, abbreviated (7+) or full (SHA-1 or SHA-256). */
    public static function isObjectId(string $id): bool
    {
        return 1 === preg_match('/^[0-9a-f]{7,64}$/', $id);
    }

    public static function isFullObjectId(string $id): bool
    {
        return 1 === preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $id);
    }

    /** A revision a page may name: an object id or a ref name. */
    public static function revision(string $rev): string
    {
        if (self::isObjectId($rev) || self::isRefName($rev)) {
            return $rev;
        }

        throw GitException::invalid('revision', $rev);
    }

    /**
     * A path inside a tree, relative, normalised: no empty, "." or ".."
     * segment, no NUL, no leading "-" or "/". It is only ever resolved
     * through git's objects (rev:path), never on the disk.
     */
    public static function isPath(string $path): bool
    {
        if ('' === $path) {
            return true;
        }
        if (\strlen($path) > self::MAX_PATH || str_contains($path, "\0") || str_starts_with($path, '-') || !mb_check_encoding($path, 'UTF-8')) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part || '..' === $part || preg_match('/[\x00-\x1f\x7f]/', $part)) {
                return false;
            }
        }

        return true;
    }

    public static function path(string $path): string
    {
        $path = trim($path, '/');
        if (!self::isPath($path)) {
            throw GitException::invalid('path', $path);
        }

        return $path;
    }

    /** A repository's name on a page and in a URL: letters, digits, ".", "_", "-"; not ".git"-suffixed. */
    public static function isRepositoryName(string $name): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,99}$/', $name)
            && !str_ends_with(strtolower($name), '.git')
            && !\in_array($name, ['.', '..'], true);
    }
}
