<?php

/**
 * One page of articles about NVIDIA, with companies and events.
 *
 *     FINLIGHT_API_KEY=... php examples/search.php
 */

declare(strict_types=1);

require __DIR__ . '/_client.php';

use Finlight\FinlightApiException;

$client = example_client();

try {
    $page = $client->articles->search([
        'query' => 'ticker:NVDA',
        'languages' => ['en'],
        'include' => ['companies', 'events'],
        'limit' => 10,
    ]);
} catch (FinlightApiException $e) {
    fprintf(STDERR, "API error %s %s (request id %s): %s\n", $e->status() ?? '-', $e->code() ?? '-', $e->requestId() ?? '-', $e->detail() ?? $e->getMessage());
    exit(1);
}

foreach ($page->articles as $article) {
    $time = $article->publishedAtTime()?->format('Y-m-d H:i') ?? 'unknown time';
    printf("%s  %s  %s\n", $time, $article->source->domain, $article->title);
    if ($article->companiesPresent()) {
        $names = array_map(static fn ($c) => $c->name, $article->companies ?? []);
        printf("    companies: %s\n", $names === [] ? '(tagged, none found)' : implode(', ', $names));
    } else {
        print("    companies: not sent (not requested or not in your plan)\n");
    }
}
printf("hasMore: %s\n", $page->pagination->hasMore ? 'yes' : 'no');
