<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Decode;
use Finlight\Internal\Transport;

/**
 * $client->sources: the source catalog.
 */
final class SourcesService
{
    /**
     * @internal
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * GET /v3/sources: every source your plan can use, sorted by domain. Each call costs 1 credit
     * from the monthly quota, errors included. The monthly quota and the burst limit apply (429).
     */
    public function list(): SourceList
    {
        $body = $this->transport->call('GET', '/v3/sources')->jsonObject('sources');
        [$items, $skipped] = Decode::list($body, 'sources', static fn (array $o, string $p): Source => Source::fromWire($o, $p), $this->transport->logger());

        return new SourceList($items, $skipped);
    }
}
