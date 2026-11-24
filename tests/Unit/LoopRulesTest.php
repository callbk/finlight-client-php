<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\FinlightConfig;
use Finlight\Tests\Support\Kit;
use Finlight\Tests\Support\SessionModel;
use PHPUnit\Framework\TestCase;

/**
 * The README's rules for your own socket loop that no kit session covers, run against the
 * reference loop in tests/Support/SessionModel.php: when the reconnect backoff starts over, and a
 * planned rotation that cannot dial.
 */
final class LoopRulesTest extends TestCase
{
    private const ADMITTED = '{"event":"connection.admitted","payloadVersion":3,"data":{"leaseId":"l","serverNow":1,"clientNonce":"n"}}';
    private const PONG = '{"event":"connection.pong","payloadVersion":3,"data":{"t":1,"serverNow":1}}';
    private const SEARCH_FAILED = '{"event":"connection.error","payloadVersion":3,"data":{"code":"SEARCH_FAILED","message":"x"}}';
    private const TOO_MANY = '{"message":"Too many concurrent connections for this API key."}';

    public function testOnlyAPongOrAnArticleStartsTheBackoffOver(): void
    {
        $model = new SessionModel(new FinlightConfig(apiKey: Kit::KEY));
        $article = Kit::json(['event' => 'article.created', 'payloadVersion' => 3, 'data' => Kit::obj(Kit::load('fixtures/decode/full-article.json')['wire'])]);

        // connection.admitted comes before the server checks the subscribe: admitted followed by
        // SEARCH_FAILED or a close must not start the backoff over, or such a loop never slows down.
        self::assertSame(1000, $this->round($model, [self::ADMITTED, self::SEARCH_FAILED]));
        self::assertSame(2000, $this->round($model, [self::ADMITTED, self::SEARCH_FAILED]));
        self::assertSame(4000, $this->round($model, [self::ADMITTED]));
        self::assertSame(1000, $this->round($model, [self::ADMITTED, self::PONG]));
        self::assertSame(2000, $this->round($model, [self::ADMITTED]));
        self::assertSame(1000, $this->round($model, [self::ADMITTED, $article]));
        for ($i = 0; $i < 10; ++$i) {
            $last = $this->round($model, [self::ADMITTED]);
        }
        self::assertSame(30000, $last, 'capped at reconnectMaxDelay');
    }

    public function testARefusedRotationDialClosesTheOldConnectionAndDialsAgainAtOnce(): void
    {
        $model = $this->rotating();
        $model->dialRefused(403, self::TOO_MANY); // a plan with a connection limit of 1
        self::assertSame([
            ['outcome' => 'closeOld', 'waitForCloseReplyMs' => 5000],
            ['outcome' => 'reconnect', 'fired' => true, 'reason' => 'rotation'],
        ], $model->outcomes, 'no report, no wait');

        // Only when the dial after the close is refused too does the usual long wait apply.
        $model->outcomes = [];
        $model->dialRefused(403, self::TOO_MANY);
        self::assertSame('connection_limit', $model->outcomes[0]['reason'] ?? null);
        self::assertGreaterThanOrEqual(60000, $model->outcomes[0]['delayMs'] ?? 0);
    }

    public function testAFailedRotationDialDialsAgainAtOnce(): void
    {
        foreach ([fn (SessionModel $m) => $m->dialFailed(), fn (SessionModel $m) => $m->dialRefused(503, '')] as $fail) {
            $model = $this->rotating();
            $fail($model);
            self::assertSame(['closeOld', 'reconnect'], array_column($model->outcomes, 'outcome'));
            self::assertTrue($model->outcomes[1]['fired'] ?? false);
        }

        // The second failure in a row waits with backoff and is reported.
        $model->outcomes = [];
        $model->dialFailed();
        self::assertSame(['reported', 'reconnect'], array_column($model->outcomes, 'outcome'));
        self::assertGreaterThan(0, $model->outcomes[1]['delayMs'] ?? 0);
    }

    public function testRefusalsThatEndAStreamStillEndItDuringRotation(): void
    {
        foreach ([[401, '', true], [403, '{"message":"Your subscription does not include WebSockets."}', true],
            [403, '{"message":"WebSocket quota exceeded for this month."}', false]] as [$status, $body, $quotaReconnect]) {
            $model = $this->rotating($quotaReconnect);
            $model->dialRefused($status, $body);
            self::assertTrue($model->ended, (string) $status);
            self::assertSame('terminalError', $model->outcomes[0]['outcome'] ?? null);
        }
    }

    /**
     * One connection: dial, the frames, then the server closes. Returns the wait before the next dial.
     *
     * @param list<string> $frames
     */
    private function round(SessionModel $model, array $frames): int
    {
        $model->dialed();
        $model->outcomes = [];
        foreach ($frames as $frame) {
            $model->serverSends($frame);
        }
        $model->serverCloses();
        $reconnects = array_values(array_filter($model->outcomes, static fn (array $o): bool => $o['outcome'] === 'reconnect'));
        self::assertCount(1, $reconnects);
        $delay = $reconnects[0]['delayMs'] ?? null;
        self::assertIsInt($delay);

        return $delay;
    }

    /** A model whose connection reached the planned rotation; the old connection is still open. */
    private function rotating(bool $quotaReconnect = true): SessionModel
    {
        $config = new FinlightConfig(apiKey: Kit::KEY, quotaReconnect: $quotaReconnect);
        $model = new SessionModel($config, autoPong: true);
        $model->dialed();
        $model->serverSends(self::ADMITTED);
        $model->advance((int) ($config->rotationInterval * 1000));
        self::assertSame('rotation', end($model->outcomes)['reason'] ?? null);
        $model->outcomes = [];

        return $model;
    }
}
