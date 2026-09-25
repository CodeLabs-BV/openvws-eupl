<?php

declare(strict_types=1);

namespace Shared\Tooi;

use Shared\Domain\WooIndex\Tooi\InformatieCategorie;

use function array_values;
use function mb_stripos;
use function rtrim;
use function strtolower;

/**
 * Canonical TOOI lookups for the OpenVWS admin.
 *
 * TOOI (Thesaurus en Ontologie voor de Officiële Informatie van de
 * Nederlandse overheid) is the controlled vocabulary for subjects and
 * information categories (Woo art. 3.3) — see
 * https://identifier.overheid.nl/tooi/def/ont.
 *
 * The Woo information categories are defined in the application as an enum
 * with their canonical kern/c_* URIs; this service exposes them for the
 * subject picker and the API & AI TOOI tab. Extendable with live KOOP
 * set-fetches later (standaarden.overheid.nl/resolve/tooi/set/...).
 */
final readonly class TooiService
{
    public const string SET_WOO_CATEGORIES = 'scw_woo_informatiecategorieen';
    public const string BASE_URI = 'https://identifier.overheid.nl/tooi/def/thes/kern/';

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
                if (\count($matches) >= $limit) {
                    break;
                }
            }
        }

        return array_values($matches);
    }
}