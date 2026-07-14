<?php

/**
 * Shared file-system helpers for the System admin surfaces
 * (`Admin\System`, `Admin\SystemLogs`). `log_dir_files()` is the single
 * source of truth for "what log files actually exist" — every filename
 * accepted from a request (preview/download/delete) MUST be checked for
 * membership in its result before any filesystem call, never trusted from
 * raw input (path traversal).
 */
if (! function_exists('log_dir_files')) {
    /**
     * List `*.log` files in `writable/logs/` (name, size, mtime), sorted
     * newest first. Directories, `index.html`, and non-`.log` files are
     * excluded. This is the allowlist for any log filename coming from a
     * request — never build a path from request input directly.
     *
     * @return list<array{name: string, size: int, mtime: int}>
     */
    function log_dir_files(): array
    {
        $dir = WRITEPATH . 'logs/';

        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir . $name;

            if (! is_file($path) || pathinfo($name, PATHINFO_EXTENSION) !== 'log') {
                continue;
            }

            $files[] = ['name' => $name, 'size' => filesize($path), 'mtime' => filemtime($path)];
        }

        usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $files;
    }
}

if (! function_exists('tail_lines')) {
    /**
     * Memory-bounded tail of a text file's last `$max` lines. Reads
     * line-by-line via `SplFileObject` (never `file()`/`file_get_contents()`
     * — a `.log` file here can be multi-MB) and periodically trims the
     * buffer instead of shifting on every line, so this stays roughly O(n)
     * rather than O(n * max).
     *
     * // ponytail: naive full-file line scan, fine up to a few hundred MB;
     * // switch to chunked reverse-read if logs ever exceed that.
     */
    function tail_lines(string $path, int $max = 2000): string
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::DROP_NEW_LINE);
        $buffer = [];

        foreach ($file as $line) {
            // SplFileObject's iterator yields one trailing "" line once it
            // hits true EOF (a quirk of feof()-style detection) — drop it,
            // it's not a real line in the file.
            if ($file->eof() && $line === '') {
                break;
            }

            $buffer[] = $line;

            if (count($buffer) >= $max * 2) {
                $buffer = array_slice($buffer, -$max);
            }
        }

        return implode("\n", array_slice($buffer, -$max));
    }
}
