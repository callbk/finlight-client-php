<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\Article;
use Finlight\FinlightDecodeException;
use Finlight\Frame;
use Finlight\Tests\Support\Kit;
use Finlight\Tests\Support\MemoryLogger;
use Finlight\Tests\Support\MockHttpClient;
use Finlight\Tests\Support\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * R-MODELS-1 .. R-MODELS-3, R-NULL-1: tolerant models.
 */
final class ModelsTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function article(array $over = []): array
    {
        return $over + ['id' => 'a1', 'link' => 'https://x.example/1', 'source' => ['domain' => 'x.example', 'type' => null], 'title' => 'T'];
    }

    public function testOnlyTheRequiredKeysAreRequired(): void
    {
        $a = Article::fromJson(self::article());
        self::assertSame([], $a->media);
        self::assertSame([], $a->countries);
        self::assertSame([], $a->categories);
        self::assertNull($a->summary);
        self::assertNull($a->firstSeenAt);
        self::assertFalse($a->contentPresent());
        self::assertNull($a->companies);
        $a = Article::fromJson(self::article(['media' => null, 'countries' => null, 'categories' => null]));
        self::assertSame([], $a->media);
        self::assertSame(['media' => null], array_intersect_key($a->toWire(), ['media' => 1]), 'null stays null when written back');
    }

    public function testABrokenEntrySkipsOnlyThatEntry(): void
    {
        $logger = new MemoryLogger();
        $t = new TestClient([], $logger);
        $broken = self::article(['link' => null]);
        $string = self::article(['id' => 'a3', 'sentiment' => ['label' => 'positive', 'confidence' => '0.95']]);
        $t->http->push(MockHttpClient::json(200, ['articles' => [self::article(), $broken, $string, self::article(['id' => 'a4'])], 'pagination' => ['limit' => 4, 'returned' => 4, 'hasMore' => false, 'nextCursor' => null]]));
        $page = $t->client->articles->search();
        self::assertSame(['a1', 'a4'], array_map(static fn ($a) => $a->id, $page->articles));
        self::assertCount(2, $page->skipped);
        self::assertSame(1, $page->skipped[0]->index);
        self::assertSame($broken, $page->skipped[0]->raw);
        self::assertStringContainsString('link', $page->skipped[0]->reason);
        self::assertSame(2, $page->skipped[1]->index, '"0.95" is not coerced to 0.95');
        self::assertCount(2, $logger->messages('warning'));
    }

    public function testASingleLookupWithoutLinkIsADecodeError(): void
    {
        $t = new TestClient();
        $t->http->push(MockHttpClient::json(200, ['article' => self::article(['link' => null])]));
        try {
            $t->client->articles->get('a1');
            self::fail('no error');
        } catch (FinlightDecodeException $e) {
            self::assertSame('article.link', $e->path());
        }
    }

    public function testValuesArriveAsSent(): void
    {
        $a = Article::fromJson(self::article([
            'publishedAt' => '1970-01-01T00:00:00.000Z',
            'firstSeenAt' => 'now',
            'revisedAt' => 'garbage',
            'availableAt' => '2026-11-23T21:04:05.123+02:00',
            'summary' => '',
            'sentiment' => ['label' => 'positive', 'confidence' => 0.912345678],
            'companies' => [['companyId' => 9007199254740991, 'name' => 'Big', 'confidence' => 1]],
        ]));
        self::assertSame('1970-01-01T00:00:00.000Z', $a->publishedAt);
        self::assertSame(0, $a->publishedAtTime()?->getTimestamp());
        self::assertSame('now', $a->firstSeenAt);
        self::assertNull($a->firstSeenAtTime());
        self::assertNull($a->revisedAtTime());
        self::assertSame('2026-11-23T19:04:05.123+00:00', $a->availableAtTime()?->format('Y-m-d\TH:i:s.vP'));
        self::assertSame('', $a->summary, '"" is not turned into null');
        self::assertSame(0.912345678, $a->sentiment?->confidence);
        self::assertSame(9007199254740991, ($a->companies ?? [])[0]->companyId);
        self::assertSame(1.0, ($a->companies ?? [])[0]->confidence);

        $frame = Frame::decode('{"event":"connection.admitted","payloadVersion":3,"data":{"leaseId":"l","serverNow":1763932800123,"clientNonce":null}}');
        self::assertSame(1763932800123, $frame->admitted()?->serverNow);
    }

    public function testStrictTimeParsing(): void
    {
        foreach (['1700000000', '2026-11-23', '2026-11-23T21:04:05', '2026-11-23 21:04:05Z', '2026-02-30T00:00:00Z', '2026-11-23T24:00:00Z', '2026-11-23T21:04:60Z', '2026-11-23T21:04:05+24:00', 'tomorrow', ''] as $bad) {
            self::assertNull(\Finlight\Internal\Time::parse($bad), $bad);
        }
        self::assertNotNull(\Finlight\Internal\Time::parse('2026-11-23t21:04:05.1234567z'));
    }

    public function testUnknownKeysStayReachableAndNeverBreakDecoding(): void
    {
        $a = Article::fromJson(self::article(['wordCount' => 5, 'source' => ['domain' => 'x', 'type' => null, 'name' => 'X'], 'media' => [['url' => 'u', 'type' => 'image']]]));
        self::assertSame(5, $a->rawJson()['wordCount']);
        self::assertArrayNotHasKey('wordCount', $a->toWire());
        self::assertSame(['url' => 'u'], $a->media[0]->toWire());
    }

    public function testWrongTypesFail(): void
    {
        foreach ([['id' => 1], ['title' => null], ['source' => 'x.example'], ['source' => ['type' => null]], ['media' => 'x'], ['companies' => [['companyId' => '1', 'name' => 'n']]], ['events' => [['direction' => 'up']]]] as $over) {
            try {
                Article::fromJson(self::article($over));
                self::fail('accepted ' . Kit::json($over));
            } catch (FinlightDecodeException $e) {
                self::assertSame('decode', $e->kind());
            }
        }
    }
}
