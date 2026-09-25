<?php

declare(strict_types=1);

namespace Shared\Tooi;

use Shared\Domain\WooIndex\Tooi\InformatieCategorie;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_values;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function mb_stripos;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function rtrim;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function time;

/**
 * Canonical TOOI lookups for the OpenVWS admin.
 *
 * TOOI (Thesaurus en Ontologie voor de Officiële Informatie van de
 * Nederlandse overheid) is the controlled vocabulary for subjects,
 * information categories (Woo art. 3.3) and administrative bodies — see
 * https://identifier.overheid.nl/tooi/def/ont.
 *
 * Information categories come from the bundled enum (canonical kern/c_*
 * URIs); administrative bodies are fetched live from the KOOP resolve
 * endpoint (rwc_* registers, text/turtle) and cached for 24h.
 */
final class TooiService
{
    public const string SET_WOO_CATEGORIES = 'scw_woo_informatiecategorieen';
    public const string BASE_URI = 'https://identifier.overheid.nl/tooi/def/thes/kern/';
    public const string RESOLVE_URL = 'https://standaarden.overheid.nl/resolve/tooi/set/';
    public const string ID_BASE = 'https://identifier.overheid.nl/tooi/id/';

    /** Register slugs (rwc_*) that make sense as Woo bestuursorganen. */
    private const array ORGANISATION_SETS = [
        'rwc_gemeenten_compleet' => 'gemeente',
        'rwc_provincies_compleet' => 'provincie',
        'rwc_waterschappen_compleet' => 'waterschap',
        'rwc_ministeries_compleet' => 'ministerie',
        'rwc_zbo_compleet' => 'zbo',
        'rwc_overige_overheidsorganisaties_compleet' => 'oorg',
    ];

    private const int MAX_VERSION = 15;

    public function __construct(
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $cacheDir,
    ) {
    }

    /**
     * @return list<array{label: string, uri: string, set: string}>
     */
    public function allInformationCategories(): array
    {
        $result = [];
        foreach (InformatieCategorie::cases() as $case) {
            $result[] = [
                'label' => $case->value,
                'uri' => $case->getResource(),
                'set' => self::SET_WOO_CATEGORIES,
            ];
        }

        return $result;
    }

    /**
     * Search information categories by label (case-insensitive, substring).
     *
     * @return list<array{label: string, uri: string, set: string}>
     */
    public function search(string $query, int $limit = 25): array
    {
        $needle = strtolower(rtrim($query));
        if ($needle === '') {
            return $this->allInformationCategories();
        }

        $matches = [];
        foreach ($this->allInformationCategories() as $item) {
            if (mb_stripos($item['label'], $needle) !== false) {
                $matches[] = $item;
                if (count($matches) >= $limit) {
                    break;
                }
            }
        }

        return array_values($matches);
    }

    /**
     * Search administrative bodies from the live TOOI registers.
     *
     * @return list<array{label: string, uri: string, set: string}>
     */
    public function searchOrganisations(string $query, int $limit = 50): array
    {
        $needle = strtolower(rtrim($query));

        $matches = [];
        foreach ($this->loadBestuursorganen() as $item) {
            if ($needle === '' || mb_stripos($item['label'], $needle) !== false) {
                $matches[] = $item;
                if (count($matches) >= $limit) {
                    break;
                }
            }
        }

        return array_values($matches);
    }

    /**
     * Administrative bodies from TOOI registers, cached 24 hours.
     *
     * @return list<array{label: string, uri: string, set: string}>
     */
    private function loadBestuursorganen(): array
    {
        $cacheFile = $this->cacheDir . '/tooi_bestuursorganen.json';

        if (file_exists($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['ts']) && time() - (int) $cached['ts'] < 86400) {
                return $cached['items'] ?? [];
            }
        }

        $items = [];

        foreach (self::ORGANISATION_SETS as $slug => $prefix) {
            $ttl = $this->fetchSet($slug);
            if ($ttl === null) {
                continue;
            }

            foreach ($this->parseOrganisations($ttl, $slug, $prefix) as $item) {
                $items[] = $item;
            }
        }

        if (count($items) > 0) {
            $this->writeCache($cacheFile, $items);
        }

        return $items;
    }

    private function fetchSet(string $slug): ?string
    {
        $client = $this->client();

        // Version probe: GET with a tiny Range (HEAD is 405 on KOOP).
        // Lower versions may 404 for a slug whose current version is high —
        // keep probing until MAX_VERSION, the first 200 is the latest < MAX.
        $latest = null;
        for ($v = 1; $v <= self::MAX_VERSION; ++$v) {
            try {
                $response = $client->request('GET', self::RESOLVE_URL . $slug . '/' . $v, [
                    'headers' => ['Accept' => 'text/turtle', 'Range' => 'bytes=0-100'],
                    'timeout' => 8,
                ]);
                $status = $response->getStatusCode();
                if ($status === 200 || $status === 206) {
                    $latest = $v;
                    break;
                }
            } catch (\Throwable) {
                break;
            }
        }

        if ($latest === null) {
            return null;
        }

        try {
            $response = $client->request('GET', self::RESOLVE_URL . $slug . '/' . $latest, [
                'headers' => ['Accept' => 'text/turtle'],
                'timeout' => 25,
            ]);

            return $response->getStatusCode() === 200 ? $response->getContent() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse an id/{prefix}/{code} register block for the preferred name label.
     *
     * @return list<array{label: string, uri: string, set: string}>
     */
    private function parseOrganisations(string $ttl, string $set, string $urlPrefix): array
    {
        $items = [];
        $uriPattern = '/<(' . preg_quote(self::ID_BASE . $urlPrefix, '/') . '\/[^>]+)>/';
        $start = 0;

        while (preg_match($uriPattern, $ttl, $m, PREG_OFFSET_CAPTURE, $start)) {
            $uri = $m[1][0];
            $blockStart = $m[0][1] + strlen($m[0][0]);

            $next = strpos($ttl, "\n<" . self::ID_BASE, $blockStart);
            $block = $next === false
                ? substr($ttl, $blockStart)
                : substr($ttl, $blockStart, $next - $blockStart);

            if (preg_match('/tooiont:voorkeursnaamInclSoort\s+"([^"]+)"/', $block, $labelMatch) === 1) {
                $label = $labelMatch[1];
            } elseif (preg_match('/tooiont:voorkeursnaamExclSoort\s+"([^"]+)"/', $block, $labelMatch) === 1) {
                $label = $labelMatch[1];
            } else {
                $label = $uri;
            }

            $items[] = [
                'label' => $label,
                'uri' => $uri,
                'set' => $set,
            ];

            $start = $next === false ? strlen($ttl) : $next;
        }

        return $items;
    }

    /**
     * @param list<array{label: string, uri: string, set: string}> $items
     */
    private function writeCache(string $cacheFile, array $items): void
    {
        $dir = \dirname($cacheFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($cacheFile, json_encode(['ts' => time(), 'items' => $items], JSON_UNESCAPED_UNICODE));
    }

    private function client(): HttpClientInterface
    {
        return HttpClient::create();
    }
}