<?php

/**
 * Release check: the git tag (v2.0.0) equals FinlightClient::VERSION (2.0.0), without a leading v.
 *
 *     php scripts/check-version.php v2.0.0
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$tag = $argv[1] ?? '';
$version = Finlight\FinlightClient::VERSION;
if (str_starts_with($version, 'v')) {
    fwrite(STDERR, "VERSION must not start with v\n");
    exit(1);
}
if ($tag !== 'v' . $version) {
    fwrite(STDERR, sprintf("tag %s does not match FinlightClient::VERSION %s\n", $tag, $version));
    exit(1);
}
fwrite(STDOUT, "version ok: {$version}\n");
