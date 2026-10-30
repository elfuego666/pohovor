<?php

declare(strict_types=1);

namespace Pohovor\AlzaScraper;

final class Product implements \JsonSerializable
{
    /** @param list<array{group: string, name: string, value: string}> $parameters */
    public function __construct(
        public readonly string $url,
        public readonly string $name,
        public readonly array $parameters,
        public readonly string $description,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'url' => $this->url,
            'name' => $this->name,
            'parameters' => $this->parameters,
            'description' => $this->description,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
