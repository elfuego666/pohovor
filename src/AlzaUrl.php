<?php

declare(strict_types=1);

namespace Pohovor\AlzaScraper;

use Pohovor\AlzaScraper\Exception\ScrapingException;

final class AlzaUrl
{
    public static function normalize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !in_array(strtolower($parts['host'] ?? ''), ['alza.sk', 'www.alza.sk'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new ScrapingException('Očakávaná je URL na doméne alza.sk bez prihlasovacích údajov a portu.');
        }

        return 'https://www.alza.sk' . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
