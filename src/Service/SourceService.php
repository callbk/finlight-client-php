<?php

declare(strict_types=1);

namespace Finlight\Service;

use Finlight\Http\ApiClient;
use Finlight\Model\Source;

final class SourceService
{
    public function __construct(private readonly ApiClient $apiClient)
    {
    }

    /**
     * @return list<Source>
     *
     * @throws \Finlight\Exception\FinlightException
     */
    public function getSources(): array
    {
        $data = $this->apiClient->request('GET', '/v2/sources');

        $sources = [];

        foreach ($data as $source) {
            if (is_array($source)) {
                /** @var array<string, mixed> $source */
                $sources[] = Source::fromArray($source);
            }
        }

        return $sources;
    }
}
