<?php

declare(strict_types=1);

namespace Pohovor\AlzaScraper;

use Pohovor\AlzaScraper\Http\CurlHttpClient;
use Pohovor\AlzaScraper\Http\HttpClientInterface;
use Pohovor\AlzaScraper\Parser\AlzaProductParser;

final class ProductScraper
{
    public function __construct(
        private readonly HttpClientInterface $httpClient = new CurlHttpClient(),
        private readonly AlzaProductParser $parser = new AlzaProductParser(),
    ) {
    }

    public function scrape(string $url): string
    {
        $url = AlzaUrl::normalize($url);

        return $this->parser->parse($this->httpClient->get($url), $url)->toJson();
    }
}
