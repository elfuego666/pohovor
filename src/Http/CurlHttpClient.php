<?php

declare(strict_types=1);

namespace Pohovor\AlzaScraper\Http;

use Pohovor\AlzaScraper\AlzaUrl;
use Pohovor\AlzaScraper\Exception\ScrapingException;

final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeout = 30,
        private readonly ?string $caBundle = null,
    )
    {
        if ($timeout < 1) {
            throw new \InvalidArgumentException('Timeout musí byť kladný.');
        }
        if ($caBundle !== null && (!is_file($caBundle) || !is_readable($caBundle))) {
            throw new \InvalidArgumentException('CA balík neexistuje alebo nie je čitateľný: ' . $caBundle);
        }
    }

    public function get(string $url): string
    {
        $url = AlzaUrl::normalize($url);
        for ($redirect = 0; $redirect <= 5; ++$redirect) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new ScrapingException('Nepodarilo sa inicializovať cURL.');
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_ENCODING => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'AlzaProductScraper/1.0',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: sk-SK,sk;q=0.9'],
            ]);
            if ($this->caBundle !== null) {
                curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle);
            }
            $body = curl_exec($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $location = (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL);
            $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            unset($handle);

            if ($body === false) {
                throw new ScrapingException('Stiahnutie produktu zlyhalo: ' . $error);
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                // Validate every destination before requesting it.
                $url = AlzaUrl::normalize($location);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new ScrapingException(sprintf('Alza vrátila HTTP %d (možné blokovanie alebo neexistujúci produkt).', $status));
            }
            if ($body === '' || !str_contains(strtolower($contentType), 'text/html')) {
                throw new ScrapingException('Server nevrátil neprázdnu HTML stránku.');
            }

            return $body;
        }

        throw new ScrapingException('Prekročený počet presmerovaní.');
    }
}
