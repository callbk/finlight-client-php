<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\CompanyRole;
use Finlight\ConnectionErrorCode;
use Finlight\EventDirection;
use Finlight\EventType;
use Finlight\FinlightClient;
use Finlight\Importance;
use Finlight\IncludeBlock;
use Finlight\OrderBy;
use Finlight\PreemptReason;
use Finlight\ProblemCode;
use Finlight\RateLimitType;
use Finlight\SentimentLabel;
use Finlight\SortOrder;
use Finlight\SourceType;
use Finlight\StreamEventName;
use Finlight\Tests\Support\Kit;
use Finlight\WebhookEventName;
use PHPUnit\Framework\TestCase;

/**
 * V-01: every vocabulary constant equals vocab.json.
 */
final class VocabTest extends TestCase
{
    public function testKnownConstantsEqualVocab(): void
    {
        $vocab = Kit::load('vocab.json');
        $values = static function (string ...$path) use ($vocab): array {
            $node = $vocab;
            foreach ($path as $key) {
                $node = Kit::obj($node)[$key] ?? null;
            }

            return Kit::list(Kit::obj($node)['values'] ?? null);
        };

        self::assertSame(FinlightClient::PAYLOAD_VERSION, $vocab['payloadVersion']);
        self::assertSame($values('request', 'include'), IncludeBlock::ALL);
        self::assertSame($values('request', 'orderBy'), OrderBy::ALL);
        self::assertSame($values('request', 'order'), SortOrder::ALL);
        self::assertSame($values('request', 'roles'), CompanyRole::ALL);
        self::assertSame($values('request', 'eventTypes'), EventType::ALL);
        self::assertSame($values('request', 'eventDirections'), EventDirection::ALL);
        self::assertSame($values('request', 'minImportance'), Importance::ALL);
        self::assertSame($values('response', 'roles'), CompanyRole::ALL);
        self::assertSame($values('response', 'eventTypes'), EventType::ALL);
        self::assertSame($values('response', 'eventDirections'), EventDirection::ALL);
        self::assertSame($values('response', 'importance'), Importance::ALL);
        self::assertSame($values('response', 'sentimentLabels'), SentimentLabel::ALL);
        self::assertSame($values('response', 'sourceTypes'), SourceType::ALL);
        self::assertSame($values('websocket', 'events'), StreamEventName::ALL);
        self::assertSame($values('websocket', 'articleEvents'), StreamEventName::ARTICLE_EVENTS);
        self::assertSame($values('websocket', 'connectionEvents'), StreamEventName::CONNECTION_EVENTS);
        self::assertSame($values('websocket', 'connectionErrorCodes'), ConnectionErrorCode::ALL);
        self::assertSame($values('websocket', 'preemptReasons'), PreemptReason::ALL);
        self::assertSame($values('webhook', 'events'), WebhookEventName::ALL);
        self::assertSame($values('limitTypes'), RateLimitType::ALL);

        $codes = [];
        $types = [];
        foreach ($values('problemCodes') as $entry) {
            $entry = Kit::obj($entry);
            $codes[] = $entry['code'];
            $types[Kit::str($entry['code'])] = $entry['type'];
        }
        self::assertSame($codes, ProblemCode::ALL);
        self::assertSame($types, ProblemCode::TYPES);

        // The stream values the README gives for your own socket loop come from the kit too.
        $ws = Kit::obj($vocab['websocket']);
        $config = new \Finlight\FinlightConfig('k');
        self::assertSame($ws['heartbeatMs'], (int) ($config->pingInterval * 1000));
        self::assertSame($ws['staleMs'], (int) ($config->watchdogTimeout * 1000));
        self::assertLessThan($ws['maxLifetimeMs'], (int) ($config->rotationInterval * 1000));
    }
}
