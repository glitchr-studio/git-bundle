<?php

namespace Git\Engine;

use Git\Engine\Model\Diff;
use Git\Engine\Model\DiffFile;
use Git\Engine\Model\DiffHunk;
use Git\Engine\Model\DiffLine;

/** `git diff` (unified, no colour, renames found) read into files, hunks and numbered lines. */
final class DiffParser
{
    public function parse(string $text, bool $truncated = false): Diff
    {
        $files = [];
        $file = null;
        $hunk = null;
        $old = $new = 0;

        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                [$a, $b] = $this->pathsOfHeader(substr($line, 11));
                $file = new DiffFile($a, $b);
                $files[] = $file;
                $hunk = null;
                continue;
            }
            if (null === $file) {
                continue;
            }
            if (null === $hunk || !preg_match('/^[ +\-\\\\]/', $line)) {
                if (str_starts_with($line, 'new file mode')) {
                    $file->status = 'added';
                } elseif (str_starts_with($line, 'deleted file mode')) {
                    $file->status = 'deleted';
                } elseif (str_starts_with($line, 'rename from ')) {
                    $file->status = 'renamed';
                    $file->oldPath = $this->unquote(substr($line, 12));
                } elseif (str_starts_with($line, 'rename to ')) {
                    $file->newPath = $this->unquote(substr($line, 10));
                } elseif (str_starts_with($line, 'Binary files ')) {
                    $file->binary = true;
                } elseif (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/', $line, $m)) {
                    $old = (int) $m[1];
                    $new = (int) $m[2];
                    $hunk = new DiffHunk($line);
                    $file->hunks[] = $hunk;
                }
                continue;
            }
            $kind = $line[0] ?? ' ';
            $body = substr($line, 1);
            if ('\\' === $kind) {
                continue; // "\ No newline at end of file"
            }
            if ('+' === $kind) {
                $hunk->lines[] = new DiffLine('+', $body, null, $new++);
                ++$file->additions;
            } elseif ('-' === $kind) {
                $hunk->lines[] = new DiffLine('-', $body, $old++, null);
                ++$file->deletions;
            } else {
                $hunk->lines[] = new DiffLine(' ', $body, $old++, $new++);
            }
        }

        return new Diff($files, $truncated);
    }

    /** @return array{string, string} */
    private function pathsOfHeader(string $rest): array
    {
        if (preg_match('/^"?a\/(.+?)"? "?b\/(.+?)"?$/', $rest, $m)) {
            return [$this->unquote($m[1]), $this->unquote($m[2])];
        }

        return [$rest, $rest];
    }

    private function unquote(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, '"') && str_ends_with($path, '"')) {
            $path = stripcslashes(substr($path, 1, -1));
        }

        return $path;
    }
}
