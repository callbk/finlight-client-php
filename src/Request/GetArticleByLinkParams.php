<?php

declare(strict_types=1);

namespace Finlight\Request;

final class GetArticleByLinkParams
{
    public function __construct(
        public readonly string $link,
        public readonly ?bool $includeContent = null,
        public readonly ?bool $includeEntities = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'link' => $this->link,
            'includeContent' => $this->includeContent,
            'includeEntities' => $this->includeEntities,
        ];

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }
}
