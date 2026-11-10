<?php

namespace Git\Engine;

/**
 * What an SSH client asks a forced command to run, read from
 * SSH_ORIGINAL_COMMAND: one of git's three SSH services and one repository
 * path, nothing else. `git clone git@host:owner/name.git` sends
 * `git-upload-pack 'owner/name.git'`, `ssh://git@host/owner/name.git` the
 * same with a leading "/"; a push sends git-receive-pack, `git archive
 * --remote` git-upload-archive.
 *
 * Refused (GitException): any other program, a second argument, a shell
 * quote, a control character, an empty, "." or ".." segment, a segment
 * starting with "-" (no option smuggled in as the path), a name outside
 * Names::isRepositoryPath(). The path is returned without its leading "/"
 * and without ".git": the caller maps it to one of its repositories (and
 * says "not found" for one it may not show), never to the disk.
 */
final class SshCommand
{
    public const UPLOAD_PACK = 'git-upload-pack';
    public const RECEIVE_PACK = 'git-receive-pack';
    public const UPLOAD_ARCHIVE = 'git-upload-archive';
    public const SERVICES = [self::UPLOAD_PACK, self::RECEIVE_PACK, self::UPLOAD_ARCHIVE];

    public const MAX_LENGTH = 1024;

    private function __construct(
        public readonly string $service,
        public readonly string $path,
    ) {
    }

    /**
     * @param list<string> $services the services served (a read-only server leaves git-receive-pack out)
     *
     * @throws GitException
     */
    public static function parse(string $command, array $services = self::SERVICES): self
    {
        if ('' === $command || \strlen($command) > self::MAX_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $command) || !mb_check_encoding($command, 'UTF-8')) {
            throw GitException::invalid('SSH command', $command);
        }
        // "git-upload-pack <path>", or the older "git upload-pack <path>": one space, one argument.
        if (!preg_match('/^git[- ](upload-pack|receive-pack|upload-archive) (.+)$/', $command, $m)) {
            throw GitException::invalid('SSH command', $command);
        }
        $service = 'git-'.$m[1];
        if (!\in_array($service, $services, true)) {
            throw GitException::invalid('SSH service', $service);
        }
        // git quotes the path in single quotes (sq_quote_buf); a valid path holds no quote, no space, no backslash.
        $argument = $m[2];
        if (preg_match("/^'([^']*)'$/", $argument, $q)) {
            $argument = $q[1];
        }
        if (preg_match('/[\s\'"\\\\`$;&|<>*?!~]/', $argument)) {
            throw GitException::invalid('repository path', $argument);
        }
        $path = $argument;
        if (str_starts_with($path, '/')) {
            $path = substr($path, 1);
        }
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }
        if (!Names::isRepositoryPath($path)) {
            throw GitException::invalid('repository path', $argument);
        }

        return new self($service, $path);
    }

    /** @return list<string> the path's segments ("owner/name" -> ["owner", "name"]) */
    public function segments(): array
    {
        return explode('/', $this->path);
    }

    /** Whether the client means to write (a push). */
    public function isWrite(): bool
    {
        return self::RECEIVE_PACK === $this->service;
    }
}
