<?php

declare(strict_types=1);

use Pohovor\AlzaScraper\ProductScraper;
use Pohovor\AlzaScraper\Http\CurlHttpClient;
use Pohovor\AlzaScraper\Http\ChromeHttpClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    exit('Spustite tento súbor cez PHP CLI.');
}

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    fwrite(STDERR, "Najskôr spustite composer install.\n");
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';

$arguments = array_slice($argv, 1);
$useCurl = in_array('--curl', $arguments, true);
$headless = in_array('--headless', $arguments, true);
$urls = array_values(array_filter($arguments, static fn (string $argument): bool => !str_starts_with($argument, '--')));
$url = $urls[0] ?? 'https://www.alza.sk/imac-24-m6-sk-modry-d13633777.htm#parameters';

try {
    if (!$useCurl) {
        fwrite(STDERR, $headless
            ? "Načítavam produkt cez Chrome bez okna.\n"
            : "Otváram Chrome. Prípadnú ochrannú výzvu potvrďte ručne (limit 120 sekúnd).\n");
        $client = new ChromeHttpClient(
            headless: $headless,
            nodeBinary: getenv('ALZA_NODE_BINARY') ?: 'node',
            executablePath: getenv('ALZA_CHROME_PATH') ?: null,
            profileDirectory: __DIR__ . '/.cache/chrome-profile',
        );
        echo (new ProductScraper($client))->scrape($url), PHP_EOL;
        exit(0);
    }
    // Respect configured trust first. WAMP may ship a CA bundle with a sibling
    // PHP version while leaving curl.cainfo empty in the active version.
    $caBundle = null;
    if (PHP_OS_FAMILY === 'Windows' && trim((string) ini_get('curl.cainfo')) === '') {
        $phpDirectory = dirname(PHP_BINARY);
        $candidates = [$phpDirectory . '/extras/ssl/cacert.pem'];
        if (preg_match('/^php\d+(?:\.\d+)+$/i', basename($phpDirectory))) {
            $siblings = glob(dirname($phpDirectory) . '/php*/extras/ssl/cacert.pem') ?: [];
            usort($siblings, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
            $candidates = array_merge($candidates, $siblings);
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $caBundle = $candidate;
                break;
            }
        }
    }

    echo (new ProductScraper(new CurlHttpClient(caBundle: $caBundle)))->scrape($url), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
    exit(1);
}
