<?php

/**
 * Look up articles, companies and sources.
 *
 *     FINLIGHT_API_KEY=... php examples/lookup.php
 */

declare(strict_types=1);

require __DIR__ . '/_client.php';

use Finlight\ForbiddenException;
use Finlight\NotFoundException;

$client = example_client();

// Take a recent link from a search, then read it again by link and by id.
$page = $client->articles->search(['query' => 'ticker:NVDA', 'limit' => 1]);
if ($page->articles === []) {
    print("no article found\n");
    exit(0);
}
$link = $page->articles[0]->link;

$article = $client->articles->getByLink($link, include: ['content']);
if (!$article->contentPresent()) {
    print("content: not sent (your plan has no full article access)\n");
} else {
    printf("content: %s\n", $article->content === null ? '(no text)' : substr($article->content, 0, 80) . '...');
}

$again = $client->articles->get($article->id);
printf("by id: %s\n", $again->title);

try {
    $client->articles->get('this-id-does-not-exist');
} catch (NotFoundException $e) {
    printf("not found: %s (request id %s)\n", $e->detail() ?? '', $e->requestId() ?? '-');
}

try {
    $companies = $client->companies->search('NVDA', mic: 'XNAS');
    foreach ($companies as $company) {
        printf("company %d: %s (%s)\n", $company->companyId, $company->name, $company->primaryListing->ticker ?? '-');
    }
    foreach ($companies as $company) {
        $record = $client->companies->get($company->companyId);
        printf("ETag %s, Cache-Control %s\n", $record->cacheInfo()->etag ?? '-', $record->cacheInfo()->cacheControl ?? '-');
        break;
    }
} catch (ForbiddenException) {
    print("company lookups need the entityAccess entitlement\n");
}

$sources = $client->sources->list();
printf("%d sources\n", count($sources));
