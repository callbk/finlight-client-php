<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\CursorMismatchException;
use Finlight\FinlightValidationException;
use Finlight\Tests\Support\Kit;
use Finlight\Tests\Support\MockHttpClient;
use Finlight\Tests\Support\TestClient;
use Finlight\Tests\Support\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P-01 .. P-06: the cursor walk. The pager freezes the first body and only adds the cursor.
 */
final class PaginationTest extends TestCase
{
    /** P-01 */
    public function testThreePageWalk(): void
    {
        $fx = Kit::load('fixtures/pagination/three-pages.json');
        $steps = Kit::list($fx['steps']);
        $t = new TestClient();
        foreach ($steps as $step) {
            $t->http->push(MockHttpClient::fromFixture(Kit::obj(Kit::obj($step)['response'])));
        }
        $args = Kit::obj(Kit::obj($fx['sdk'])['args']);
        $result = $t->client->articles->searchAll($args);
        $ids = [];
        foreach ($result as $article) {
            $ids[] = $article->id;
        }

        $want = [];
        foreach ($steps as $step) {
            foreach (Kit::list(Kit::obj(Kit::obj(Kit::obj($step)['response'])['body'])['articles']) as $a) {
                $want[] = Kit::obj($a)['id'];
            }
        }
        self::assertSame($want, $ids);
        self::assertCount(5, $ids);
        self::assertCount(3, $t->http->requests, 'stops after page 3');
        self::assertSame(3, $result->pagesFetched());
        self::assertFalse($result->truncated());
        self::assertNull($result->nextCursor());

        $first = (string) $t->http->requests[0]->getBody();
        $requestIds = [];
        foreach ($t->http->requests as $i => $request) {
            $wantBody = Kit::obj(Kit::obj(Kit::obj($steps[$i])['request'])['body']);
            self::assertSame(Wire::canonical($wantBody), Wire::canonical(Wire::body($request)));
            $text = (string) $request->getBody();
            if ($i > 0) {
                // Byte-equal to page 1 apart from the added cursor.
                self::assertStringStartsWith(substr($first, 0, -1) . ',"cursor":', $text);
            }
            $requestIds[] = $request->getHeaderLine('X-Request-Id');
        }
        self::assertCount(3, array_unique($requestIds), 'one X-Request-Id per page');
        self::assertCount(0, $t->clock->sleeps);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serverCases(): array
    {
        return Kit::cases('fixtures/pagination', ['three-pages.json']);
    }

    /**
     * P-02 .. P-06: the server's answer to a changed page 2. The SDK's own page 2 only adds the
     * cursor to the frozen page 1, so it never injects to, languages or another order.
     */
    #[DataProvider('serverCases')]
    public function testServerRule(string $rel): void
    {
        $fx = Kit::load($rel);
        $request = Kit::obj($fx['request']);
        $page2Body = Kit::obj($request['body']);
        $cursor = Kit::str($page2Body['cursor']);
        $page1Body = $page2Body;
        unset($page1Body['cursor'], $page1Body['to']);
        if ($fx['id'] === 'P-04') {
            unset($page1Body['languages']);
        }

        $t = new TestClient();
        $t->http->push(MockHttpClient::json(200, [
            'articles' => [],
            'pagination' => ['limit' => 2, 'returned' => 0, 'hasMore' => true, 'nextCursor' => $cursor],
        ]));
        $t->http->push(MockHttpClient::fromFixture(Kit::obj($fx['response'])));
        $result = $t->client->articles->searchAll($page1Body, maxPages: 2);
        $status = Kit::obj($fx['response'])['status'];

        if ($status === 400) {
            try {
                iterator_to_array($result, false);
                self::fail('no error');
            } catch (CursorMismatchException $e) {
                self::assertSame('cursor_mismatch', $e->apiKind());
                self::assertSame('CURSOR_MISMATCH', $e->code());
                self::assertSame('cursor', $e->errors()[0]->field);
            }
            self::assertCount(2, $t->http->requests, 'the pager stops; no retry');
            if ($fx['id'] === 'P-05') {
                self::assertStringContainsString("'", Kit::str(Kit::obj(Kit::obj($fx['response'])['body'])['detail']));
            }
        } else {
            $articles = iterator_to_array($result, false);
            $want = Kit::list(Kit::obj(Kit::obj($fx['response'])['body'])['articles']);
            self::assertCount(count($want), $articles);
        }

        $sent = Kit::obj(Wire::body($t->http->requests[1]));
        $expect = $page1Body + ['cursor' => $cursor];
        self::assertSame(Wire::canonical($expect), Wire::canonical($sent), 'page 2 = page 1 + cursor');
        self::assertArrayNotHasKey('to', $sent);
        if ($fx['id'] === 'P-04') {
            self::assertArrayNotHasKey('languages', $sent);
        }
    }

    public function testSecondIterationThrows(): void
    {
        $t = new TestClient();
        $t->http->always(static fn () => MockHttpClient::json(200, ['articles' => [], 'pagination' => ['limit' => 1, 'returned' => 0, 'hasMore' => false, 'nextCursor' => null]]));
        $result = $t->client->articles->searchAll(['query' => 'x']);
        iterator_to_array($result, false);
        $this->expectException(FinlightValidationException::class);
        iterator_to_array($result, false);
    }
}
