<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/lib/kit.php';

/**
 * R-TEST-1: the vendored kit matches its MANIFEST, and every kit case maps to a test that exists.
 */
final class CaseMapTest extends TestCase
{
    public function testManifestMatches(): void
    {
        $entries = finlight_kit_verify(Kit::dir());
        self::assertArrayHasKey('conformance.md', $entries);
        self::assertSame('3.0.0-rc.3', trim((string) file_get_contents(Kit::dir() . '/CONTRACT_VERSION')));
    }

    public function testEveryCaseIsMappedToAnExistingTest(): void
    {
        $cases = finlight_kit_case_ids(Kit::dir());
        $text = (string) file_get_contents(Kit::dir() . '/conformance.md');
        if (preg_match('/Total: (\d+) cases/', $text, $m) !== 1) {
            self::fail('no total in conformance.md');
        }
        self::assertCount((int) $m[1], $cases);
        self::assertSame($cases, array_values(array_unique($cases)));

        $map = self::map();
        self::assertSame($cases, array_keys($map), 'CONFORMANCE.md lists every case of the kit, in order');

        foreach ($map as $case => $target) {
            if (preg_match('/^(\w+)::(\w+)(?:#(.+))?$/', $target, $t) !== 1) {
                self::fail('bad target for ' . $case);
            }
            $class = 'Finlight\\Tests\\Conformance\\' . $t[1];
            self::assertTrue(class_exists($class), $case . ': ' . $class);
            $method = new \ReflectionMethod($class, $t[2]);
            $dataSet = $t[3] ?? '';
            $providers = $method->getAttributes(DataProvider::class);
            if ($dataSet === '') {
                self::assertSame([], $providers, $case . ' needs a data set name');
                continue;
            }
            self::assertCount(1, $providers, $case);
            $provider = $providers[0]->newInstance()->methodName();
            /** @var array<string, mixed> $rows */
            $rows = $class::$provider();
            if (str_ends_with($dataSet, '*')) {
                $prefix = substr($dataSet, 0, -1);
                self::assertNotEmpty(array_filter(array_keys($rows), static fn ($k) => str_starts_with((string) $k, $prefix)), $case);
            } else {
                self::assertArrayHasKey($dataSet, $rows, $case);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        $text = (string) file_get_contents(Kit::root() . '/CONFORMANCE.md');
        preg_match_all('/^\| ([A-Z]-\d+) \| [^|]+ \| `([^`]+)` \|$/m', $text, $m, \PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $out[$row[1]] = $row[2];
        }

        return $out;
    }
}
