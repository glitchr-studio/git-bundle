<?php

namespace Git\Repository;

/**
 * Brings the repositories that have a remote `url` up to date: clones one
 * whose `path` does not exist yet (a bare mirror), fetches one that has both
 * (`--all --prune`). It talks to the network, so it runs when asked - `git:sync`,
 * at a deploy or from a cron line - and never during `cache:warmup`: a warmup
 * builds the cache from what is on disk, and must not need the network.
 */
class RepositorySync
{
    /** @var ?\Closure(string): void where the progress of the current sync goes */
    private ?\Closure $write = null;

    /**
     * Clone a repository that has a `url` but no `path` yet, fetch one that
     * has both. Returns false when there is nothing to do (no url) or git
     * failed. What `git:sync` runs; $write receives each line of progress
     * (STDOUT when none is given).
     *
     * @param ?\Closure(string): void $write
     */
    public function sync(string $name, array $config, ?\Closure $write = null): bool
    {
        if (empty($config['url'])) {
            return false;
        }

        $this->write = $write;
        try {
            return is_dir($config['path'])
                ? $this->fetchUpdates($name, $config)
                : $this->cloneRepository($name, $config);
        } finally {
            $this->write = null;
        }
    }

    private function cloneRepository(string $name, array $config): bool
    {
        $url   = $config['url'];
        $path  = $config['path'];
        $label = $config['label'] ?? $name;

        $this->writeLine('');
        $this->writeLine("  \033[1;34m↓ Cloning\033[0m \033[1m{$label}\033[0m");
        $this->writeLine("    url  : {$url}");
        $this->writeLine("    into : {$path}");

        if (!is_dir(\dirname($path))) {
            @mkdir(\dirname($path), 0775, true);
        }

        $cmd = ['git', 'clone', '--mirror', '--progress', $url, $path];
        return $this->runWithProgress($cmd);
    }

    private function fetchUpdates(string $name, array $config): bool
    {
        $path  = $config['path'];
        $label = $config['label'] ?? $name;

        $this->writeLine('');
        $this->writeLine("  \033[1;36m↻ Fetching\033[0m \033[1m{$label}\033[0m");

        // --mirror repos are bare; use GIT_DIR env var + fetch --all --prune
        $cmd = ['git', 'fetch', '--all', '--prune', '--progress'];
        return $this->runWithProgress($cmd, ['GIT_DIR' => $path]);
    }

    private function runWithProgress(array $cmd, array $env = []): bool
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envVars = $env ? array_merge(getenv(), $env) : null;
        $proc = proc_open($cmd, $spec, $pipes, null, $envVars);
        if (!is_resource($proc)) {
            $this->writeLine("  \033[1;31m✗ Failed to start process\033[0m");
            return false;
        }

        fclose($pipes[0]);

        // git sends clone/fetch progress to stderr; stdout is usually silent
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $lastLine  = '';
        $startTime = microtime(true);

        while (true) {
            $status = proc_get_status($proc);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            // git uses \r for in-place progress updates — show only the last segment
            $raw = $stderr . $stdout;
            if ($raw !== '') {
                $segments = preg_split('/[\r\n]/', $raw);
                foreach ($segments as $seg) {
                    $seg = trim($seg);
                    if ($seg === '' || $seg === $lastLine) continue;
                    $lastLine = $seg;
                    // Colour "Receiving objects: 100%" differently from mid-progress
                    if (str_contains($seg, '100%') || str_ends_with($seg, 'done.')) {
                        $this->writeLine("    \033[1;32m✓\033[0m {$seg}");
                    } else {
                        $this->writeLine("    \033[2m{$seg}\033[0m");
                    }
                }
            }

            if (!$status['running']) {
                break;
            }

            usleep(50_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $elapsed = round(microtime(true) - $startTime, 1);

        if ($exit === 0) {
            $this->writeLine("    \033[1;32m✓ done\033[0m ({$elapsed}s)");
        } else {
            $this->writeLine("    \033[1;31m✗ exited with code {$exit}\033[0m");
        }

        return $exit === 0;
    }

    private function writeLine(string $line): void
    {
        null !== $this->write ? ($this->write)($line) : fwrite(\STDOUT, $line . "\n");
    }
}
