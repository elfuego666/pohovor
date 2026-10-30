<?php

declare(strict_types=1);

namespace Pohovor\AlzaScraper\Http;

use Pohovor\AlzaScraper\AlzaUrl;
use Pohovor\AlzaScraper\Exception\ScrapingException;

final class ChromeHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeout = 120,
        private readonly bool $headless = false,
        private readonly string $nodeBinary = 'node',
        private readonly ?string $executablePath = null,
        private readonly ?string $profileDirectory = null,
    ) {
        if ($timeout < 1) {
            throw new \InvalidArgumentException('Timeout musí byť kladný.');
        }
    }

    public function get(string $url): string
    {
        $url = AlzaUrl::normalize($url);
        if (!function_exists('proc_open')) {
            throw new ScrapingException('Chrome klient vyžaduje povolenú PHP funkciu proc_open.');
        }
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/node_modules/playwright/package.json')) {
            throw new ScrapingException('Chýba Playwright. V adresári balíčka spustite npm install.');
        }
        // File-backed output prevents pipe deadlocks on Windows for large HTML.
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            if (is_resource($stdout)) { fclose($stdout); }
            if (is_resource($stderr)) { fclose($stderr); }
            throw new ScrapingException('Nepodarilo sa vytvoriť dočasné súbory pre Chrome.');
        }
        $process = null;
        $pipes = [];
        try {
            $process = @proc_open(
                [$this->nodeBinary, $root . '/browser/fetch-product.cjs'],
                [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr],
                $pipes,
                $root,
                null,
                ['bypass_shell' => true, 'suppress_errors' => true],
            );
            if (!is_resource($process)) {
                throw new ScrapingException('Nepodarilo sa spustiť Node.js. Nainštalujte Node.js 20+ alebo nastavte nodeBinary.');
            }
            $config = json_encode([
                'url' => $url,
                'timeout' => $this->timeout * 1000,
                'headless' => $this->headless,
                'executablePath' => $this->executablePath,
                'profileDirectory' => $this->profileDirectory,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($pipes[0], $config) !== strlen($config)) {
                throw new ScrapingException('Nepodarilo sa odovzdať konfiguráciu Chrome.');
            }
            fclose($pipes[0]);
            unset($pipes[0]);
            $deadline = microtime(true) + $this->timeout + 20;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new ScrapingException('Chrome prekročil časový limit.');
                }
                usleep(100000);
            } while (true);
            rewind($stdout);
            rewind($stderr);
            $output = stream_get_contents($stdout);
            $error = trim((string) stream_get_contents($stderr));
            if ($status['exitcode'] !== 0) {
                throw new ScrapingException('Chrome: ' . ($error !== '' ? $error : 'Proces zlyhal. Overte inštaláciu Node.js a Chrome.'));
            }
            try {
                $result = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new ScrapingException('Chrome nevrátil platnú odpoveď.', 0, $exception);
            }
            if (!is_string($result['html'] ?? null) || $result['html'] === '') {
                throw new ScrapingException('Chrome vrátil prázdne HTML.');
            }

            return $result['html'];
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) { fclose($pipe); }
            }
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) { proc_terminate($process); }
                proc_close($process);
            }
            fclose($stdout);
            fclose($stderr);
        }
    }
}
