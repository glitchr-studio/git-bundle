<?php

namespace Git\Engine;

/**
 * Git over SSH, the server's side: what a forced command runs once the
 * caller has read SSH_ORIGINAL_COMMAND (Git\Engine\SshCommand) and decided
 * who may read or write. It starts `git upload-pack`, `git receive-pack` or
 * `git upload-archive` on one bare repository of the store, an argument
 * array (no shell), and hands it the caller's own standard input, output and
 * error: the pack goes between git and the SSH client without being copied
 * through PHP. It waits for git to end (the caller has work to do after a
 * push: release a lock, write a feed) and gives git's exit code.
 *
 * The caller is the only gate, as for HttpBackend; this checks what is asked
 * (the repository's key, the protocol) and bounds the time.
 */
class SshBackend
{
    public function __construct(
        private readonly Git $git,
        private readonly RepositoryStore $store,
        private readonly float $timeout = 600.0,
    ) {
    }

    /** The program and arguments that serve $command on the repository $key, as run. */
    public function command(SshCommand $command, string $key): array
    {
        $path = $this->store->pathOf($key);

        return match ($command->service) {
            // --strict: $path itself must be the repository, never $path/.git.
            SshCommand::UPLOAD_PACK => [$this->git->binary(), 'upload-pack', '--strict', '--timeout='.(int) ceil($this->timeout), $path],
            SshCommand::RECEIVE_PACK => [$this->git->binary(), 'receive-pack', $path],
            SshCommand::UPLOAD_ARCHIVE => [$this->git->binary(), 'upload-archive', $path],
        };
    }

    /**
     * @param resource    $input        the client's stream (STDIN of the forced command)
     * @param resource    $output       to the client (STDOUT)
     * @param resource    $error        the client's stderr (STDERR): git's messages, never the protocol
     * @param string|null $gitProtocol  GIT_PROTOCOL as the client sent it (protocol v2), kept only when well-formed
     * @param int|null    $maxPushBytes git's own receive.maxInputSize for a push
     *
     * @return int git's exit code (124 when it ran past the time allowed)
     */
    public function serve(SshCommand $command, string $key, mixed $input, mixed $output, mixed $error, ?string $gitProtocol = null, ?int $maxPushBytes = null): int
    {
        foreach ([$input, $output, $error] as $stream) {
            if (!\is_resource($stream)) {
                throw new GitException('SshBackend::serve() takes three open streams.');
            }
        }
        $argv = $this->command($command, $key);
        if (!is_file(end($argv).'/HEAD')) {
            throw new GitException('No repository "'.$key.'".');
        }

        $env = [];
        if (null !== $gitProtocol && preg_match('/^[a-z0-9=:.,_-]{1,100}$/i', $gitProtocol)) {
            $env['GIT_PROTOCOL'] = $gitProtocol;
        }
        $config = $command->isWrite() && null !== $maxPushBytes ? ['receive.maxInputSize' => (string) max(1, $maxPushBytes)] : [];
        // Only what git needs: none of the forced command's own variables (credentials, SSH_*) reach it.
        $environment = $this->git->environment($env, $config) + ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];

        $process = proc_open($argv, [0 => $input, 1 => $output, 2 => $error], $pipes, null, $environment);
        if (!\is_resource($process)) {
            throw new GitException('git '.$command->service.' could not be started.');
        }

        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $code = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
                proc_close($process);

                return $code >= 0 ? $code : 1;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                proc_close($process);

                return 124;
            }
            usleep(20000);
        }
    }
}
