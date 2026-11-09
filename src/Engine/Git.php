<?php

namespace Git\Engine;

use Symfony\Component\Process\Process;

/**
 * The git binary, run through symfony/process: an argument array, never a
 * shell string; a bounded time and a bounded output; no system or global
 * configuration, no prompt, no pager, the C locale. Every repository it is
 * pointed at is a bare one, named by --git-dir.
 */
class Git
{
    /** Output past this is refused (a page never needs more). */
    public const MAX_OUTPUT = 16 * 1024 * 1024;

    public function __construct(
        private readonly string $binary = '/usr/bin/git',
        private readonly float $timeout = 30.0,
    ) {
    }

    public function binary(): string
    {
        return $this->binary;
    }

    /**
     * The environment every command runs in, $extra over it; $config adds
     * configuration for this command only (GIT_CONFIG_KEY_n / VALUE_n).
     */
    public function environment(array $extra = [], array $config = []): array
    {
        $config = ['safe.directory' => '*'] + $config;
        $env = ['GIT_CONFIG_COUNT' => (string) \count($config)];
        $i = 0;
        foreach ($config as $key => $value) {
            $env['GIT_CONFIG_KEY_'.$i] = (string) $key;
            $env['GIT_CONFIG_VALUE_'.$i] = (string) $value;
            ++$i;
        }

        return $extra + $env + [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_PAGER' => 'cat',
            'PAGER' => 'cat',
            'LC_ALL' => 'C',
            'LANG' => 'C',
            // safe.directory=* (above): the repositories belong to the server whatever the volume's owner says.
            'HOME' => sys_get_temp_dir(),
        ];
    }

    /** A process for `git --git-dir=<dir> <args...>`, not started. */
    public function process(?string $gitDir, array $args, array $env = [], ?float $timeout = null, array $config = []): Process
    {
        foreach ($args as $arg) {
            if (!\is_string($arg) || str_contains($arg, "\0")) {
                throw new GitException('A git argument must be a string without NUL.');
            }
        }
        $command = [$this->binary];
        if (null !== $gitDir) {
            $command[] = '--git-dir='.$gitDir;
        }
        $process = new Process([...$command, ...$args], null, $this->environment($env, $config), null, $timeout ?? $this->timeout);

        return $process;
    }

    /**
     * Runs and returns stdout. A non-zero exit throws, unless it is one of
     * $allowedExits (then null is returned).
     */
    public function run(?string $gitDir, array $args, ?string $input = null, array $env = [], int $maxOutput = self::MAX_OUTPUT, array $allowedExits = [], ?float $timeout = null): ?string
    {
        $process = $this->process($gitDir, $args, $env, $timeout);
        if (null !== $input) {
            $process->setInput($input);
        }
        $output = '';
        $process->start();
        foreach ($process->getIterator($process::ITER_SKIP_ERR) as $chunk) {
            $output .= $chunk;
            if (\strlen($output) > $maxOutput) {
                $process->stop(0);
                throw new GitException(sprintf('git %s said more than %d bytes.', $args[0] ?? '', $maxOutput));
            }
        }
        $process->wait();
        $code = $process->getExitCode();
        if (0 !== $code) {
            if (\in_array($code, $allowedExits, true)) {
                return null;
            }
            throw new GitException(sprintf('git %s failed (%s): %s', $args[0] ?? '', (string) $code, trim(mb_strimwidth($process->getErrorOutput(), 0, 500))));
        }

        return $output;
    }
}
