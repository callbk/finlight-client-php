<?php

/**
 * R-SCOPE-1: the source has no v1/v2 paths, keys or frames. Searches src/ (not tests/, not
 * conformance/), ignoring case, for /v1/, /v2/, sendArticle, admin_kick and the old keys in any
 * spelling. Every hit must be in the v2 frame fallback (Frame.php, R-WS-1) or in the webhook
 * version check (Webhooks.php, R-HOOK-2).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$words = ['/v1/', '/v2/', 'sendarticle', 'admin_kick', 'adminkick'];
foreach (['includeContent', 'includeEntities', 'extended', 'pageSize', 'publishDate', 'isUpdate'] as $key) {
    $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    $words[] = strtolower($key);   // camelCase and PascalCase
    $words[] = $snake;             // snake_case
}
$allowed = ['src/Frame.php', 'src/Webhooks.php'];
$hits = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $rel = substr($file->getPathname(), strlen($root) + 1);
    foreach (file($file->getPathname()) ?: [] as $n => $line) {
        $lower = strtolower($line);
        foreach (array_unique($words) as $word) {
            if (str_contains($lower, $word) && !in_array($rel, $allowed, true)) {
                $hits[] = sprintf('%s:%d: %s', $rel, $n + 1, trim($line));
            }
        }
    }
}
if ($hits !== []) {
    fwrite(STDERR, "v1/v2 leftovers outside the allowed places:\n  " . implode("\n  ", $hits) . "\n");
    exit(1);
}
fwrite(STDOUT, "scope ok: no v1/v2 leftovers in src/\n");
