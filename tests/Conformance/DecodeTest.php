<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\Article;
use Finlight\Tests\Support\Kit;
use Finlight\Tests\Support\Presence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D-01 .. D-11: decoding articles, the presence of every key.
 */
final class DecodeTest extends TestCase
{
    private const GATED = ['content', 'sentiment', 'companies', 'events'];

    /**
     * @return array<string, array{string}>
     */
    public static function decodeCases(): array
    {
        return Kit::cases('fixtures/decode', ['matrix.json']);
    }

    #[DataProvider('decodeCases')]
    public function testDecodeFixture(string $rel): void
    {
        $fx = Kit::load($rel);
        $article = Article::fromJson($fx['wire']);
        $this->assertPresence($article, Kit::obj(Kit::obj($fx['expect'])['presence']));
        // Values are kept as sent, and the model writes back exactly the wire it was read from.
        self::assertSame($fx['wire'], $article->toWire());
        self::assertSame($fx['wire'], $article->rawJson());

        $wire = Kit::obj($fx['wire']);
        switch ($fx['id']) {
            case 'D-01':
                self::assertCount(3, $article->media);
                self::assertNotNull($article->revisedAt);
                $private = array_values(array_filter($article->companies ?? [], static fn ($c) => $c->primaryListing === null));
                self::assertNotEmpty($private);
                foreach ($article->events ?? [] as $event) {
                    self::assertNotNull($event->companyIds);
                }
                break;
            case 'D-02':
                foreach (self::GATED as $key) {
                    self::assertSame('absent', Presence::helper($article, $key));
                }
                self::assertNull($article->availableAt);
                break;
            case 'D-07':
                self::assertSame('1970-01-01T00:00:00.000Z', $article->publishedAt);
                self::assertSame('1970-01-01T00:00:00+00:00', $article->publishedAtTime()?->format(\DATE_ATOM));
                self::assertNull($article->summary);
                self::assertSame([], $article->events);
                self::assertNull($article->content);
                self::assertTrue($article->contentPresent());
                self::assertNull(($article->companies ?? [])[0]->role);
                self::assertNull(($article->companies ?? [])[0]->relevance);
                break;
            case 'D-08':
                self::assertSame($wire['title'], $article->title);
                self::assertSame($wire['summary'], $article->summary);
                break;
            case 'D-09':
                self::assertNull($article->sentiment?->label);
                self::assertNotNull($article->sentiment?->confidence);
                self::assertSame([], $article->companies);
                self::assertTrue($article->companiesPresent());
                break;
            case 'D-11':
                self::assertNull($article->summary);
                self::assertNull($article->content);
                self::assertSame($wire['title'], $article->title);
                break;
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function matrixCases(): array
    {
        $out = [];
        foreach (Kit::list(Kit::load('fixtures/decode/matrix.json')['cases']) as $case) {
            $case = Kit::obj($case);
            $out[Kit::str($case['id'])] = [$case];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('matrixCases')]
    public function testMatrix(array $case): void
    {
        $article = Article::fromJson($case['wire']);
        $presence = Kit::obj(Kit::obj($case['expect'])['presence']);
        $this->assertPresence($article, $presence);
        foreach (self::GATED as $key) {
            if (array_key_exists($key, $presence)) {
                self::assertSame($presence[$key], Presence::helper($article, $key), $key);
            }
        }
        // An absent events[].companyIds reads as null, never [].
        if ($article->events !== null && !$article->companiesPresent()) {
            foreach ($article->events as $event) {
                self::assertNull($event->companyIds);
            }
        }
        self::assertSame($case['wire'], $article->toWire());
    }

    /**
     * @param array<string, mixed> $presence
     */
    private function assertPresence(Article $article, array $presence): void
    {
        foreach ($presence as $path => $want) {
            self::assertSame($want, Presence::of($article, $path), 'presence of ' . $path);
        }
    }
}
