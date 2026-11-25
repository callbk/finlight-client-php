<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Decode;
use Finlight\Internal\Params;
use Finlight\Internal\Transport;

/**
 * $client->companies: search, getMany, get. Needs the entityAccess entitlement (ForbiddenException
 * otherwise). Each call costs 1 credit from the monthly quota, like an article request (getMany too,
 * however many ids); a 304 revalidation and errors cost 1 credit too. The monthly quota and the burst
 * limit apply (429).
 */
final class CompaniesService
{
    /**
     * @internal
     */
    public function __construct(private readonly Transport $transport)
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
     * The SDK keeps no cache: every call is a request and costs 1 credit. To save credits, keep the
     * records yourself and skip the call while the record is fresh (Cache-Control max-age).
     * cacheInfo() on the record gives its ETag and Cache-Control. Pass that ETag as ifNoneMatch to
     * send If-None-Match: a 304 answer then returns null ("not modified"). A 304 costs 1 credit too.
     *
     * @return ($ifNoneMatch is null ? CompanyRecord : CompanyRecord|null)
     */
    public function get(int $companyId, ?string $ifNoneMatch = null): ?CompanyRecord
    {
        if ($companyId < 1) {
            throw new FinlightValidationException('finlight: companyId must be an integer >= 1', 'companyId');
        }
        $headers = [];
        $okStatuses = [200];
        if ($ifNoneMatch !== null) {
            $headers['If-None-Match'] = $ifNoneMatch;
            $okStatuses[] = 304;
        }
        $response = $this->transport->call('GET', '/v3/companies/' . $companyId, [], null, $headers, $okStatuses);
        if ($response->status === 304) {
            return null;
        }
        $info = new CacheInfo($response->header('ETag'), $response->header('Cache-Control'));

        return CompanyRecord::fromJson($response->jsonObject('company')['company'] ?? null, 'company', $info);
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
