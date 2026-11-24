<?php

/**
 * Copy the finlight API v3 contract kit into conformance/.
 *
 * Usage (one command):
 *
 *     composer sync-conformance -- --from=PATH
 *     php scripts/sync-conformance.php --from=PATH
 *     php scripts/sync-conformance.php --verify      (only check conformance/ against its MANIFEST)
 *
 * PATH is the kit directory (shared/contracts/api-v3 in the finlight monorepo). Default:
 * $FINLIGHT_CONTRACT_KIT, else ../../../contracts/api-v3 (where the kit sits when this repo is the
 * monorepo submodule shared/php/packages/finlight-client).
 *
 * The source is checked against its own MANIFEST first. Then conformance/ is replaced by exactly the
 * files the MANIFEST lists, plus the MANIFEST itself, and checked again.
 */

declare(strict_types=1);

require __DIR__ . '/lib/kit.php';

$root = dirname(__DIR__);
$target = $root . '/conformance';

$options = getopt('', ['from:', 'verify']);
if (isset($options['verify'])) {
    $entries = finlight_kit_verify($target);
    $version = trim((string) file_get_contents($target . '/CONTRACT_VERSION'));
    fwrite(STDOUT, sprintf("conformance/ matches its MANIFEST: contract %s, %d files\n", $version, count($entries)));
    exit(0);
}

$from = $options['from'] ?? (getenv('FINLIGHT_CONTRACT_KIT') ?: $root . '/../../../contracts/api-v3');
if (!is_string($from)) {
    fwrite(STDERR, "pass --from once\n");
    exit(2);
}
$kit = realpath($from);
if ($kit === false || !is_file($kit . '/MANIFEST')) {
    fwrite(STDERR, "no contract kit at {$from} (pass --from=PATH)\n");
    exit(2);
}

$entries = finlight_kit_verify($kit);
finlight_kit_remove($target);
foreach (array_merge(array_keys($entries), ['MANIFEST']) as $rel) {
    $dest = $target . '/' . $rel;
    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0777, true) && !is_dir(dirname($dest))) {
        fwrite(STDERR, "cannot create " . dirname($dest) . "\n");
        exit(1);
    }
    if (!copy($kit . '/' . $rel, $dest)) {
        fwrite(STDERR, "cannot copy {$rel}\n");
        exit(1);
    }
}
finlight_kit_verify($target);
$version = trim((string) file_get_contents($target . '/CONTRACT_VERSION'));
fwrite(STDOUT, sprintf("conformance/ synced: contract %s, %d files\n", $version, count($entries)));
