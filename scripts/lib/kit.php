<?php

/**
 * Helpers shared by the conformance scripts and the test suite: read and check a kit MANIFEST.
 */

declare(strict_types=1);

/**
 * @return array<string, string> relative path => sha256
 */
function finlight_kit_manifest(string $kit): array
{
    $text = file_get_contents($kit . '/MANIFEST');
    if ($text === false) {
        throw new RuntimeException("no MANIFEST in {$kit}");
    }
    $entries = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $parts = explode('  ', $line, 2);
        if (count($parts) !== 2 || strlen($parts[0]) !== 64) {
            throw new RuntimeException("malformed MANIFEST line: {$line}");
        }
        $entries[$parts[1]] = $parts[0];
    }

    return $entries;
}

/**
 * Check every file of $kit against its MANIFEST, and that no file is missing from it.
 *
 * @return array<string, string>
 */
function finlight_kit_verify(string $kit): array
{
    $entries = finlight_kit_manifest($kit);
    $problems = [];
    foreach ($entries as $rel => $digest) {
        $path = $kit . '/' . $rel;
        if (!is_file($path)) {
            $problems[] = "missing: {$rel}";
            continue;
        }
        if (hash_file('sha256', $path) !== $digest) {
            $problems[] = "checksum differs: {$rel}";
        }
    }
    $listed = $entries + ['MANIFEST' => ''];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kit, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($kit) + 1));
        if (!array_key_exists($rel, $listed)) {
            $problems[] = "not in MANIFEST: {$rel}";
        }
    }
    if ($problems !== []) {
        throw new RuntimeException("contract kit at {$kit} does not match its MANIFEST:\n  " . implode("\n  ", $problems));
    }

    return $entries;
}

function finlight_kit_remove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

/**
 * Case ids listed in conformance.md, in order.
 *
 * @return list<string>
 */
function finlight_kit_case_ids(string $kit): array
{
    $text = (string) file_get_contents($kit . '/conformance.md');
    preg_match_all('/^\| ([A-Z]-\d+) \|/m', $text, $matches);

    return $matches[1];
}
