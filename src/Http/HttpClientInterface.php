<?php

declare(strict_types=1);

namespace Pohovor\AlzaScraper\Http;

interface HttpClientInterface
{
    public function get(string $url): string;
}
