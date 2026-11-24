<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Decode;
use Finlight\Internal\Params;
use Finlight\Internal\Transport;

/**
 * $client->companies: search, getMany, get. Needs the entityAccess entitlement (ForbiddenException
 * otherwise). Each successful call costs 1 credit from the monthly quota (getMany too, however many ids);
 * a 304 revalidation and errors are free. The monthly quota and the burst limit apply (429).
 */
final class CompaniesService
{
    /** @var array<int, CompanyRecord> kept records for ETag revalidation, oldest first */
    private array $cache = [];

    /**
     * @internal
     */
    public function __construct(private readonly Transport $transport, private readonly int $cacheSize)
    {
    }

    /**
     * Resolve a ticker, name, alias, ISIN, pair form (XNAS:AMD) or vendor notation. At most 10
     * results, no pages. mic narrows the hits.
     */
    public function search(string $q, ?string $mic = null): CompanyList
    {
        if (Params::charLength($q) > 200) {
            throw new FinlightValidationException('finlight: q takes at most 200 characters', 'q');
        }
        $query = [['q', $q]];
        if ($mic !== null) {
            if (Params::charLength($mic) > 32) {
                throw new FinlightValidationException('finlight: mic takes at most 32 characters', 'mic');
            }
            $query[] = ['mic', $mic];
        }

        return $this->list($this->transport->call('GET', '/v3/companies', $query)->jsonObject('companies'));
    }

    /**
     * Resolve 1 to 10 company ids. Unknown ids are left out; the order is not guaranteed.
     *
     * @param list<int> $ids
     */
    public function getMany(array $ids): CompanyList
    {
        $checked = Params::companyIds($ids);
        $query = [['ids', implode(',', array_map('strval', $checked))]];

        return $this->list($this->transport->call('GET', '/v3/companies', $query)->jsonObject('companies'));
    }

    /**
     * GET /v3/companies/{companyId}. A miss throws NotFoundException.
     *
     * Without ifNoneMatch the client keeps the last records (companyCacheSize) and revalidates them
     * with their ETag; a 304 answer returns the kept record. With ifNoneMatch you manage the cache
     * yourself: a 304 answer returns null ("not modified"). cacheInfo() on the record gives its ETag.
     *
     * @return ($ifNoneMatch is null ? CompanyRecord : CompanyRecord|null)
     */
    public function get(int $companyId, ?string $ifNoneMatch = null): ?CompanyRecord
    {
        if ($companyId < 1) {
            throw new FinlightValidationException('finlight: companyId must be an integer >= 1', 'companyId');
        }
        $cached = null;
        $headers = [];
        if ($ifNoneMatch !== null) {
            $headers['If-None-Match'] = $ifNoneMatch;
        } else {
            $cached = $this->cache[$companyId] ?? null;
            $etag = $cached?->cacheInfo()?->etag;
            if ($etag !== null) {
                $headers['If-None-Match'] = $etag;
            }
        }
        $response = $this->transport->call('GET', '/v3/companies/' . $companyId, [], null, $headers, [200, 304]);
        if ($response->status === 304) {
            if ($cached === null) {
                return null;
            }
            $old = $cached->cacheInfo();
            $record = $cached->withCacheInfo(new CacheInfo(
                $response->header('ETag') ?? $old?->etag,
                $response->header('Cache-Control') ?? $old?->cacheControl,
                true,
            ));
            $this->remember($companyId, $record);

            return $record;
        }
        $info = new CacheInfo($response->header('ETag'), $response->header('Cache-Control'), false);
        $record = CompanyRecord::fromJson($response->jsonObject('company')['company'] ?? null, 'company', $info);
        if ($info->etag !== null) {
            $this->remember($companyId, $record);
        }

        return $record;
    }

    private function remember(int $companyId, CompanyRecord $record): void
    {
        if ($this->cacheSize <= 0) {
            return;
        }
        unset($this->cache[$companyId]);
        $this->cache[$companyId] = $record;
        while (count($this->cache) > $this->cacheSize) {
            unset($this->cache[array_key_first($this->cache)]);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function list(array $body): CompanyList
    {
        [$items, $skipped] = Decode::list($body, 'companies', static fn (array $o, string $p): CompanyRecord => CompanyRecord::fromWire($o, $p), $this->transport->logger());

        return new CompanyList($items, $skipped);
    }
}
