<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\FinlightClient;
use Finlight\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

/**
 * Repository checks that belong to the normal test command: public names (R-NAMES-1), the README
 * skeleton (R-DOCS-1), no v1/v2 leftovers (R-SCOPE-1), the version (R-VER-1) and the package content
 * (R-PKG-1).
 */
final class RepoTest extends TestCase
{
    /** The public types of sdk-style for PHP, plus the PHP decoder types (Subscribe, Frame, PreemptedInfo). */
    private const PUBLIC = [
        'AdmittedInfo', 'Article', 'ArticlePage', 'ArticleSource', 'ArticlesService', 'CacheInfo', 'Company',
        'CompaniesService', 'CompanyList', 'CompanyRecord', 'CompanyRole', 'ConnectionErrorCode',
        'CursorInvalidException', 'CursorMismatchException', 'EventDirection', 'EventTag', 'EventType',
        'FieldIssue', 'FinlightApiException', 'FinlightClient', 'FinlightConfig', 'FinlightDecodeException',
        'FinlightException', 'FinlightNetworkException', 'FinlightTimeoutException', 'FinlightValidationException',
        'ForbiddenException', 'Frame', 'Importance', 'IncludeBlock', 'InternalServerException', 'Listing', 'Media',
        'NotFoundException', 'OrderBy', 'Pagination', 'PreemptReason', 'PreemptedInfo', 'ProblemCode',
        'RateLimitType', 'RateLimitedException', 'SearchAllResult', 'Sentiment', 'SentimentLabel', 'SkippedEntry',
        'SortOrder', 'Source', 'SourceList', 'SourceType', 'SourcesService', 'StreamEvent', 'StreamEventKind',
        'StreamEventName', 'Subscribe', 'UnauthorizedException', 'ValidationFailedException', 'WebhookEvent',
        'WebhookEventName', 'WebhookVerificationException', 'Webhooks', 'WrongPayloadVersion',
    ];

    public function testPublicNamesMatchTheTable(): void
    {
        $names = [];
        foreach (glob(Kit::root() . '/src/*.php') ?: [] as $file) {
            $names[] = basename($file, '.php');
        }
        sort($names);
        $want = self::PUBLIC;
        sort($want);
        self::assertSame($want, $names);
        foreach (glob(Kit::root() . '/src/Internal/*.php') ?: [] as $file) {
            self::assertStringContainsString('@internal', (string) file_get_contents($file), basename($file));
        }
    }

    public function testReadmeFollowsTheSkeleton(): void
    {
        exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(Kit::root() . '/scripts/check-readme.php') . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    public function testNoV1OrV2Leftovers(): void
    {
        exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(Kit::root() . '/scripts/check-scope.php') . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    public function testVersionIsOneSourceAndTheChangelogStartsWithIt(): void
    {
        self::assertSame('2.0.0', FinlightClient::VERSION);
        $changelog = (string) file_get_contents(Kit::root() . '/CHANGELOG.md');
        self::assertMatchesRegularExpression('/^## \[?2\.0\.0\]?/m', $changelog);
        self::assertStringContainsString('**Breaking release.**', explode('## [1.0.1]', $changelog)[0]);
        $composer = json_decode((string) file_get_contents(Kit::root() . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayNotHasKey('version', $composer, 'the version comes from the git tag');
        self::assertIsString($composer['description'] ?? null);
        self::assertStringStartsWith('BREAKING', $composer['description']);
    }

    public function testDevFilesStayOutOfThePackage(): void
    {
        $attributes = (string) file_get_contents(Kit::root() . '/.gitattributes');
        foreach (['/conformance', '/local', '/tests', '/examples', '/scripts', '/.github'] as $path) {
            self::assertMatchesRegularExpression('#^' . preg_quote($path, '#') . '\s+export-ignore$#m', $attributes, $path);
        }
    }
}
