<?php

/**
 * R-PKG-1: list what Composer would ship (git archive honours .gitattributes export-ignore) and fail
 * when development files or anything secret-looking is in it.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
exec('git -C ' . escapeshellarg($root) . ' archive --format=tar HEAD | tar -t', $files, $code);
if ($code !== 0) {
    fwrite(STDERR, "git archive failed\n");
    exit(1);
}
$bad = [];
foreach ($files as $file) {
    if (preg_match('#^(conformance|local|tests|examples|scripts|\.github)/#', $file) === 1
        || preg_match('#(^|/)\.env|\.(key|pem)$|CONFORMANCE\.md$#', $file) === 1) {
        $bad[] = $file;
    }
}
if ($bad !== []) {
    fwrite(STDERR, "not allowed in the package:\n  " . implode("\n  ", $bad) . "\n");
    exit(1);
}
if (!in_array('src/FinlightClient.php', $files, true)) {
    fwrite(STDERR, "src/ is missing from the package\n");
    exit(1);
}
fwrite(STDOUT, sprintf("package ok: %d entries\n", count($files)));
