<?php

declare(strict_types=1);

namespace Pohovor\AlzaScraper\Parser;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Pohovor\AlzaScraper\Exception\ScrapingException;
use Pohovor\AlzaScraper\Product;

final class AlzaProductParser
{
    public function parse(string $html, string $url): Product
    {
        // Alza can return its challenge with HTTP 200.
        if (preg_match('~Please confirm you are human|bezpečnostní systémy momentálně zablokovaly|bezpečnostné systémy momentálne zablokovali|id=["\']challenge-form["\']~iu', $html)) {
            throw new ScrapingException('Alza vrátila ochrannú stránku. Produkt sa nedá načítať z tejto siete.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new ScrapingException('Neplatné HTML produktu.');
        }
        $xpath = new DOMXPath($document);
        $structured = null;
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            try {
                $data = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
                $structured = $this->findProduct($data);
                if ($structured !== null) {
                    break;
                }
            } catch (\JsonException) {
                // A page can contain an unrelated malformed JSON-LD block.
            }
        }
        $name = $this->firstText($xpath, ['//h1[@itemprop="name"]', '//h1']);
        if ($name === '' && is_string($structured['name'] ?? null)) {
            $name = $this->plainText($structured['name']);
        }
        if ($name === '' || ($structured === null && !$xpath->query('//*[@id="parameters" or @id="detailDescription" or @id="detail" or @id="description"]')->length)) {
            throw new ScrapingException('Stránka neobsahuje rozpoznateľný detail produktu. Zadajte URL produktu, nie kategórie.');
        }

        $description = $this->firstText($xpath, [
            '//*[@id="detailDescription"]', '//*[@id="description"]',
            '//*[@itemprop="description"]',
        ]);
        if ($description === '' && is_string($structured['description'] ?? null)) {
            $description = $this->plainText($structured['description']);
        }
        if ($description === '') {
            $description = $this->firstText($xpath, ['//meta[@name="description"]/@content']);
        }

        $parameters = [];
        $seen = [];
        $add = static function (string $group, string $name, string $value) use (&$parameters, &$seen): void {
            $key = json_encode([$group, $name, $value]);
            if ($name !== '' && $value !== '' && !isset($seen[$key])) {
                $parameters[] = ['group' => $group, 'name' => $name, 'value' => $value];
                $seen[$key] = true;
            }
        };

        // Alza's parameter groups contain rows with name/value elements.
        $rowClass = $this->classTest('row');
        $paramClass = $this->classTest('param');
        $rows = $xpath->query('//*[@id="parameters"]//*[' . $rowClass . ' or ' . $paramClass . ' or self::tr]');
        foreach ($rows as $row) {
            $parameterName = $this->firstText($xpath, ['.//*[' . $this->classTest('name') . ' or ' . $this->classTest('paramName') . ']', './th', './td[1]'], $row);
            $value = $this->firstText($xpath, ['.//*[' . $this->classTest('value') . ' or ' . $this->classTest('paramValue') . ' or ' . $this->classTest('values') . ']', './th/following-sibling::td[1]', './td[2]'], $row);
            $group = $this->firstText($xpath, ['ancestor::*[.//h3][1]//h3[1]', 'ancestor::*[.//h4][1]//h4[1]'], $row);
            $add($group, $parameterName, $value);
        }
        $properties = $structured['additionalProperty'] ?? [];
        if (is_array($properties) && isset($properties['name'])) {
            $properties = [$properties];
        }
        if (is_array($properties)) {
            foreach ($properties as $property) {
                if (is_array($property) && is_string($property['name'] ?? null) && is_scalar($property['value'] ?? null)) {
                    $propertyName = $this->plainText($property['name']);
                    // HTML offers grouping; do not repeat the same JSON-LD value.
                    $value = $this->plainText((string) $property['value']);
                    $exists = array_filter($parameters, static fn (array $p): bool => $p['name'] === $propertyName && $p['value'] === $value);
                    if (!$exists) {
                        $add('', $propertyName, $value);
                    }
                }
            }
        }

        return new Product($url, $name, $parameters, $description);
    }

    private function findProduct(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        if (in_array('Product', (array) ($data['@type'] ?? []), true)) {
            return $data;
        }
        foreach ($data as $value) {
            if (is_array($value) && ($product = $this->findProduct($value)) !== null) {
                return $product;
            }
        }

        return null;
    }

    private function classTest(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
    }

    /** @param list<string> $queries */
    private function firstText(DOMXPath $xpath, array $queries, ?DOMNode $context = null): string
    {
        foreach ($queries as $query) {
            foreach ($xpath->query($query, $context) as $node) {
                $text = $node instanceof \DOMAttr ? $node->value : $this->nodeText($node);
                $text = $this->normalize($text);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function nodeText(DOMNode $node): string
    {
        if (in_array(strtolower($node->nodeName), ['script', 'style', 'noscript'], true)) {
            return '';
        }
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->nodeText($child);
        }

        return in_array(strtolower($node->nodeName), ['p', 'div', 'li', 'br', 'h2', 'h3', 'h4', 'tr'], true)
            ? ' ' . $text . ' ' : $text;
    }

    private function plainText(string $html): string
    {
        return $this->normalize(html_entity_decode(strip_tags(preg_replace('~<\s*(?:br|/?p|/?div|/?li)\b[^>]*>~i', ' ', $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $text) ?? $text);
    }
}
