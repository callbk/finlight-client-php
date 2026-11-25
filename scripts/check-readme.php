<?php

/**
 * R-DOCS-1: README.md has exactly the shared headings in order, the version table and the breaking
 * notice; its PHP code blocks lint; the translations have the same heading levels and code blocks.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
const SKELETON = [
    '# finlight client for PHP',
    '## Versions',
    '## Requirements',
    '## Installation',
    '## Quick start',
    '## Search articles',
    '## Look up articles, companies and sources',
    '## Absent, null and empty',
    '## Real-time streams',
    '## Webhooks',
    '## Errors and retries',
    '## Configuration',
    '## Logging',
    '## Data notes',
    '## Migrating from 1.x',
    '## Development',
    '## License',
];
const TRANSLATIONS = ['README.zh-CN.md', 'README.ja.md', 'README.ko.md'];
const FENCE = '/^```(\w*)\n(.*?)^```/sm';

/** @return list<string> */
function headings(string $text): array
{
    $body = (string) preg_replace(FENCE, '', $text);
    $out = [];
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        if (preg_match('/^#{1,6} /', $line) === 1) {
            $out[] = trim($line);
        }
    }

    return $out;
}

/** @return list<array{string, string}> */
function blocks(string $text): array
{
    preg_match_all(FENCE, $text, $m, PREG_SET_ORDER);

    return array_map(static fn (array $b): array => [$b[1], $b[2]], $m);
}

$problems = [];
$english = (string) file_get_contents($root . '/README.md');
if (headings($english) !== SKELETON) {
    $problems[] = 'README.md headings differ from the skeleton: ' . json_encode(headings($english));
}
if (!str_contains($english, '| SDK version | API version | Status |')) {
    $problems[] = 'README.md has no version table';
}
if (!str_contains(explode('## Versions', $english)[0], '**Breaking release.**')) {
    $problems[] = 'README.md must start with the breaking-change notice';
}
foreach (blocks($english) as $i => [$lang, $code]) {
    if ($lang !== 'php') {
        continue;
    }
    $file = tempnam(sys_get_temp_dir(), 'readme') . '.php';
    file_put_contents($file, "<?php\n" . $code);
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    unlink($file);
    if ($code !== 0) {
        $problems[] = sprintf('README.md php block %d does not lint: %s', $i, implode(' ', $out));
    }
}
$levels = array_map(static fn (string $h): string => explode(' ', $h)[0], SKELETON);
foreach (TRANSLATIONS as $name) {
    $text = (string) @file_get_contents($root . '/' . $name);
    if (array_map(static fn (string $h): string => explode(' ', $h)[0], headings($text)) !== $levels) {
        $problems[] = $name . ': heading structure differs from README.md';
    }
    if (blocks($text) !== blocks($english)) {
        $problems[] = $name . ': code blocks differ from README.md';
    }
    if (!str_contains(explode("\n## ", $text)[0], '**')) {
        $problems[] = $name . ': the breaking-change notice is missing';
    }
}
foreach ($problems as $problem) {
    fwrite(STDERR, $problem . "\n");
}
if ($problems !== []) {
    exit(1);
}
fwrite(STDOUT, "readme ok\n");
