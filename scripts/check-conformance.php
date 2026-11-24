<?php

/**
 * CI check: conformance/ matches its MANIFEST and CONFORMANCE.md maps every kit case.
 * (CaseMapTest checks that each mapped test exists.)
 */

declare(strict_types=1);

require __DIR__ . '/lib/kit.php';

$root = dirname(__DIR__);
try {
    $entries = finlight_kit_verify($root . '/conformance');
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$cases = finlight_kit_case_ids($root . '/conformance');
$map = (string) file_get_contents($root . '/CONFORMANCE.md');
$missing = [];
foreach ($cases as $case) {
    if (preg_match('/^\| ' . preg_quote($case, '/') . ' \| [^|]+ \| `[^`]+` \|$/m', $map) !== 1) {
        $missing[] = $case;
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'cases without a test in CONFORMANCE.md: ' . implode(', ', $missing) . "\n");
    exit(1);
}
fwrite(STDOUT, sprintf("conformance ok: %d files, %d cases mapped\n", count($entries), count($cases)));
