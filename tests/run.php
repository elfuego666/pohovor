<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pohovor\AlzaScraper\AlzaUrl;
use Pohovor\AlzaScraper\Exception\ScrapingException;
use Pohovor\AlzaScraper\Http\HttpClientInterface;
use Pohovor\AlzaScraper\Http\ChromeHttpClient;
use Pohovor\AlzaScraper\Parser\AlzaProductParser;
use Pohovor\AlzaScraper\ProductScraper;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $action, string $message): void
{
    try {
        $action();
    } catch (ScrapingException) {
        check(true, $message);
        return;
    }
    check(false, $message);
}

$html = file_get_contents(__DIR__ . '/fixtures/product.html');
$client = new class($html) implements HttpClientInterface {
    public string $requestedUrl = '';
    public function __construct(private readonly string $html) {}
    public function get(string $url): string
    {
        $this->requestedUrl = $url;
        return $this->html;
    }
};
$scraper = new ProductScraper($client);
$result = json_decode($scraper->scrape('https://alza.sk/iny-produkt-d123.htm#parameters'), true, 512, JSON_THROW_ON_ERROR);
check($client->requestedUrl === 'https://www.alza.sk/iny-produkt-d123.htm', 'Normalizácia URL a odstránenie fragmentu.');
check($result['name'] === 'iMac 24" – skúšobný produkt', 'Názov a UTF-8.');
check($result['description'] === 'Popis produktu Prvý odsek. Druhý odsek & detaily.', 'Čistý popis bez skriptov.');
check(count($result['parameters']) === 4, 'Parametre HTML a JSON-LD bez duplicít.');
check($result['parameters'][0] === ['group' => 'Použitie', 'name' => 'Typ zostavy', 'value' => 'All-in-one PC'], 'Skupiny parametrov.');
check($result['parameters'][1]['value'] === '4 480 × 2 520', 'Alternatívne triedy parametrov.');
check($result['parameters'][2]['value'] === 'Lesklý', 'Tabuľkové parametre.');

$parser = new AlzaProductParser();
$jsonOnly = '<script type="application/ld+json">[{"@type":["Product"],"name":"Žltý produkt","description":"<p>A</p><p>B &amp; C</p>","additionalProperty":{"name":"Veľkosť","value":24}}]</script>';
$product = $parser->parse($jsonOnly, 'https://www.alza.sk/test.htm');
check($product->name === 'Žltý produkt' && $product->description === 'A B & C', 'JSON-LD fallback.');
check($product->parameters[0]['value'] === '24', 'Číselné hodnoty JSON-LD.');
$empty = $parser->parse('<h1>Produkt</h1><div id="parameters"></div>', 'https://www.alza.sk/test.htm');
check($empty->parameters === [] && $empty->description === '', 'Chýbajúce údaje sa nevymýšľajú.');
$meta = $parser->parse('<meta name="description" content="Krátky popis"><h1>Produkt</h1><div id="parameters"></div>', 'https://www.alza.sk/test.htm');
check($meta->description === 'Krátky popis', 'Meta description fallback.');

foreach (['https://example.com/a', 'https://www.alza.sk.evil.com/a', 'file:///etc/passwd', 'https://user@alza.sk/a', 'https://alza.sk:443/a', 'https://127.0.0.1/a', 'nonsense'] as $url) {
    fails(static fn () => $scraper->scrape($url), 'Odmietnutie cudzej alebo neplatnej URL: ' . $url);
}
check(AlzaUrl::normalize('http://ALZA.SK/a?x=1#parameters') === 'https://www.alza.sk/a?x=1', 'HTTPS, query a normalizácia hostiteľa.');
fails(static fn () => $parser->parse('<h1>Kategória</h1>', ''), 'Odmietnutie kategórie.');
fails(static fn () => $parser->parse('<h1>Please confirm you are human.</h1>', ''), 'Detekcia ochrany s HTTP 200.');
fails(static fn () => $parser->parse('', ''), 'Prázdne HTML.');
$brokenClient = new class implements HttpClientInterface {
    public function get(string $url): string { throw new ScrapingException('HTTP 503'); }
};
fails(static fn () => (new ProductScraper($brokenClient))->scrape('https://alza.sk/test.htm'), 'Prenos HTTP chyby.');
fails(static fn () => (new ChromeHttpClient())->get('https://example.com/product'), 'Chrome odmietne cudziu URL pred spustením procesu.');
fails(static fn () => (new ChromeHttpClient(nodeBinary: __DIR__ . '/nonexistent-node.exe'))->get('https://alza.sk/test.htm'), 'Chrome hlási chýbajúcu závislosť alebo zlyhanie procesu.');

echo sprintf("OK (%d kontrol)\n", $checks);
