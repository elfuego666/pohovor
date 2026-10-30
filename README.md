# Alza Product Scraper

Composer knižnica pre PHP 8.1+ získava názov, parametre a popis z produktových URL na `alza.sk` / `www.alza.sk`. Obalová metóda `ProductScraper::scrape(string $url): string` vracia JSON. Konkrétny produkt nie je v implementácii napevno.

## Inštalácia a CLI

Vyžaduje rozšírenia `curl`, `dom` a `mbstring`. Chrome klient navyše potrebuje Node.js 20+ s npm, Google Chrome a povolenú PHP funkciu `proc_open`.

```sh
composer install
npm install
# Iba ak Google Chrome ešte nie je nainštalovaný:
npx playwright install chrome
php test.php
php test.php "https://www.alza.sk/imac-24-m6-sk-modry-d13633777.htm#parameters"
php test.php "https://www.alza.sk/ina-produktova-url-d123456.htm"
```

Bez argumentu sa použije zadaný iMac. Úspech vypíše JSON na štandardný výstup a skončí kódom 0. Chyba vypíše JSON s poľom `error` na STDERR a skončí kódom 1. Výsledok možno uložiť pomocou `php test.php "URL" > produkt.json`.

Predvolene CLI otvorí **Google Chrome** a počká na detail produktu najviac 120 sekúnd. Ak sa zobrazí CAPTCHA alebo iná ochranná výzva, potvrďte ju ručne v otvorenom okne. Po načítaní produktu sa HTML odovzdá PHP parseru, vypíše JSON a prehliadač sa zatvorí. Počas čakania Chrome nezatvárajte.

Cookies sa zachovávajú v samostatnom profile `.cache/chrome-profile`; nepoužíva sa váš osobný profil Chrome. Tento priečinok sa neukladá do Gitu. Nespúšťajte viac CLI procesov súčasne nad tým istým profilom.

```sh
php test.php "https://www.alza.sk/produkt-d123.htm" --headless
php test.php "https://www.alza.sk/produkt-d123.htm" --curl
```

`--headless` spustí Chrome bez okna, takže ochrannú výzvu nemožno ručne potvrdiť. `--curl` použije pôvodné priame HTTP sťahovanie. Pri neštandardnej inštalácii možno cez premenné prostredia `ALZA_NODE_BINARY` a `ALZA_CHROME_PATH` zadať absolútne cesty k `node.exe` a `chrome.exe`.

## Použitie v PHP

```php
require __DIR__ . '/vendor/autoload.php';

use Pohovor\AlzaScraper\ProductScraper;
use Pohovor\AlzaScraper\Exception\ScrapingException;
use Pohovor\AlzaScraper\Http\ChromeHttpClient;

try {
    $scraper = new ProductScraper(new ChromeHttpClient(
        timeout: 120,
        headless: false,
        profileDirectory: __DIR__ . '/.cache/chrome-profile',
    ));
    $json = $scraper->scrape(
        'https://www.alza.sk/imac-24-m6-sk-modry-d13633777.htm#parameters'
    );
    echo $json;
} catch (ScrapingException $e) {
    // Chyba siete, blokovanie, neplatná URL alebo nerozpoznaný detail produktu.
    error_log($e->getMessage());
}
```

Pri vložení do iného projektu možno tento adresár zaregistrovať ako Composer `path` repository a vyžiadať balíček `pohovor/alza-product-scraper` s verziou `@dev`. Balíček nie je publikovaný na Packagist.

## Výstup

Ilustračný formát (nie uložené výsledky živého sťahovania):

```json
{
    "url": "https://www.alza.sk/produkt-d123.htm",
    "name": "Názov produktu",
    "parameters": [
        {"group": "Použitie", "name": "Typ zostavy", "value": "All-in-one PC"}
    ],
    "description": "Textový popis produktu bez HTML."
}
```

Parametre sú zoznam, aby sa zachovali rovnaké názvy v rôznych skupinách. Nezaradené parametre majú `group: ""`. Chýbajúci popis je `""`, chýbajúce parametre `[]`. Prázdne údaje znamenajú, že neboli dostupné v stiahnutom HTML; nejde o potvrdenie, že ich produkt nemá.

## Štruktúra a spracovanie

- `ProductScraper`: obalová metóda, koordinácia HTTP klienta, parsera a JSON serializácie.
- `HttpClientInterface` / `CurlHttpClient`: vymeniteľné sťahovanie; overenie TLS, timeout a kontrola domény pri každom presmerovaní.
- `ChromeHttpClient`: PHP spustí Node.js bez shellu, Playwright načíta produkt v Chrome a vráti vykreslené HTML. Klient možno vložiť do existujúceho `ProductScraper` bez zmeny výstupného JSON. `new ProductScraper()` bez argumentov naďalej používa cURL.
- `browser/fetch-product.cjs`: spustenie Chrome, čakanie na produkt aj po manuálnom potvrdení výzvy, načítanie DOM a zatvorenie prehliadača. Vstupná a výsledná URL musia byť na doméne Alzy; externé zdroje a CAPTCHA rámce sú povolené. Kontrola URL nie je sieťový firewall: Chrome môže pri HTTP presmerovaní osloviť aj inú doménu, ale jej HTML sa nevráti parseru.
- `AlzaProductParser`: DOM/XPath pre HTML, záloha z JSON-LD `Product` vrátane `@graph`, následne meta popis.
- `Product`: nemenný výsledok s `JsonSerializable`.
- `AlzaUrl`: validácia domény, normalizácia na HTTPS a odstránenie fragmentu `#parameters`, ktorý sa serveru neposiela.

Chrome vykoná JavaScript stránky a PHP parser následne číta jeho výsledné HTML. CAPTCHA sa automaticky nerieši. Kategórie a iné neproduktové stránky nie sú platným vstupom. Ak Alza zmení štruktúru HTML, parser bude potrebovať úpravu. Spustenie Chrome samo osebe nezaručuje prístup pri blokovaní zo strany Alzy.

Pri overovaní v tomto prostredí Alza na zadanú URL odpovedala ochrannou stránkou. Priame získanie živých produktových dát preto nebolo možné potvrdiť. Ochranná stránka sa hlási ako chyba, nie ako produkt. Z rovnakej príčiny nemožno garantovať dostupnosť všetkých produktových URL z každej siete.

Pri voľbe `--curl` CLI `test.php` na Windows pri prázdnom `curl.cainfo` automaticky vyhľadá `extras/ssl/cacert.pem` pri aktívnom PHP a potom pri ostatných verziách PHP v rovnakom priečinku WAMP. Existujúcu konfiguráciu `curl.cainfo` rešpektuje. Chrome používa vlastné overovanie certifikátov. Samotný `CurlHttpClient` používa systémovú konfiguráciu alebo explicitný argument `new CurlHttpClient(caBundle: '/cesta/cacert.pem')`.

Ak CA balík nie je dostupný, nastavte `curl.cainfo` v CLI `php.ini` na platný CA balík. Alternatívou je jednorazový parameter:

```powershell
php -d curl.cainfo=C:\wamp64_337\bin\php\php8.4.1\extras\ssl\cacert.pem test.php --curl
```

S týmto nastavením živý PHP pokus skončil HTTP 403 od Alzy. Knižnica overovanie TLS nevypína. Systémová konfigurácia PHP nebola menená.

## Testy

```sh
composer test
npm test
```

Testy bežia bez siete nad ručne pripraveným HTML v `tests/fixtures/product.html`. Overujú obalovú metódu, JSON, diakritiku, skupiny a alternatívne HTML parametre, JSON-LD, chýbajúce údaje, neplatné URL, ochrannú stránku a prenos chýb. Fixture nie je kópia aktuálneho HTML Alzy; živé overenie je samostatné spustenie `php test.php`.

`npm test` vyžaduje nainštalovaný Chrome a overuje vykreslenie JavaScriptu, timeout pri ochrannej stránke, HTTP 404 a odmietnutie presmerovania mimo Alzy. Sieťové odpovede sú nahradené lokálnou fixture.

Overenie Chrome integrácie: prešlo 25 PHP kontrol a oba browser testy. Živý CLI pokus s `--headless` spustil Chrome, ale detail produktu sa v limite nenačítal. Úspešné získanie živých dát z Alzy preto zatiaľ nebolo potvrdené; na ručné potvrdenie prípadnej výzvy použite predvolený režim bez `--headless`.
