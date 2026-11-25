<?php

/**
 * Walk up to 3 pages of a search. Every page costs quota.
 *
 *     FINLIGHT_API_KEY=... php examples/search-all.php
 */

declare(strict_types=1);

require __DIR__ . '/_client.php';

$client = example_client();
$params = ['query' => 'ticker:AAPL', 'languages' => ['en'], 'limit' => 20];
$result = $client->articles->searchAll($params, maxPages: 3);

$count = 0;
foreach ($result as $article) {
    ++$count;
}
printf("%d articles on %d pages\n", $count, $result->pagesFetched());
if ($result->truncated()) {
    printf("More results exist. Continue with the same parameters and cursor: %s\n", $result->nextCursor());
}
