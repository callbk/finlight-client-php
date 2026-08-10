<?php

declare(strict_types=1);

namespace Finlight\Tests\Model;

use Finlight\Exception\MalformedResponseException;
use Finlight\Model\Article;
use Finlight\Model\ArticleResponse;
use Finlight\Model\Source;
use Finlight\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ArticleTest extends TestCase
{
    public function testParsesDatesIntoImmutableObjects(): void
    {
        $article = Article::fromArray(Fixtures::article());

        self::assertSame('2026-08-10T09:30:00+00:00', $article->publishDate->format(\DATE_ATOM));
        self::assertNotNull($article->createdAt);
        self::assertSame('2026-08-10T09:31:00+00:00', $article->createdAt->format(\DATE_ATOM));
        self::assertNull($article->revisedDate);
    }

    public function testConvertsStringConfidenceToFloat(): void
    {
        $article = Article::fromArray(Fixtures::article());

        self::assertSame(0.87, $article->confidence);
        self::assertNotNull($article->companies);
        self::assertSame(0.95, $article->companies[0]->confidence);
    }

    public function testMapsCompaniesAndListings(): void
    {
        $article = Article::fromArray(Fixtures::article());

        self::assertNotNull($article->companies);
        self::assertCount(1, $article->companies);

        $company = $article->companies[0];
        self::assertSame(42, $company->companyId);
        self::assertSame('AAPL', $company->ticker);
        self::assertSame('US0378331005', $company->isin);
        self::assertNotNull($company->primaryListing);
        self::assertSame('XNAS', $company->primaryListing->exchangeCode);
        self::assertNotNull($company->otherListings);
        self::assertSame('XETR', $company->otherListings[0]->exchangeCode);
    }

    public function testOmittedOptionalFieldsBecomeNull(): void
    {
        $data = Fixtures::article();
        unset($data['companies'], $data['content'], $data['sentiment'], $data['confidence']);

        $article = Article::fromArray($data);

        self::assertNull($article->companies);
        self::assertNull($article->content);
        self::assertNull($article->sentiment);
        self::assertNull($article->confidence);
    }

    public function testCategoriesStayStringsSoNewValuesDoNotBreakParsing(): void
    {
        $data = Fixtures::article();
        $data['categories'] = ['markets', 'a-category-that-does-not-exist-yet'];

        $article = Article::fromArray($data);

        self::assertSame(['markets', 'a-category-that-does-not-exist-yet'], $article->categories);
    }

    public function testMissingRequiredFieldThrows(): void
    {
        $data = Fixtures::article();
        unset($data['title']);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('Article: expected string at "title", got null.');

        Article::fromArray($data);
    }

    public function testUnparseablePublishDateThrows(): void
    {
        $data = Fixtures::article();
        $data['publishDate'] = 'not-a-date';

        $this->expectException(MalformedResponseException::class);

        Article::fromArray($data);
    }

    public function testArticleResponseIsCountableAndIterable(): void
    {
        $response = ArticleResponse::fromArray(Fixtures::articleResponse());

        self::assertSame('ok', $response->status);
        self::assertSame(1, $response->page);
        self::assertSame(20, $response->pageSize);
        self::assertCount(1, $response);

        $titles = [];

        foreach ($response as $article) {
            $titles[] = $article->title;
        }

        self::assertSame(['Apple beats quarterly expectations'], $titles);
    }

    public function testArticleResponseRequiresItsEnvelopeFields(): void
    {
        $data = Fixtures::articleResponse();
        unset($data['pageSize']);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('ArticleResponse: expected int at "pageSize", got null.');

        ArticleResponse::fromArray($data);
    }

    public function testSourceDefaultsMissingFlagsToNull(): void
    {
        $source = Source::fromArray(Fixtures::sources()[1]);

        self::assertSame('www.handelsblatt.com', $source->domain);
        self::assertFalse($source->isDefaultSource);
        self::assertNull($source->isContentAvailable);
        self::assertTrue($source->isCustomSource);
        self::assertSame(['de'], $source->languages);
    }
}
