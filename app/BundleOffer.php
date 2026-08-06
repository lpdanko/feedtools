<?php
declare(strict_types=1);

/**
 * Bundle offer id format:
 *   3##106741__16 = bundle of 3 units of base offer 106741__16.
 *
 * The bundle offer is still a separate marketplace offer. This helper only
 * describes how to resolve its base item for stock, cost and order operations.
 */

function bundle_offer_parse(string|int $offerId): array
{
    $offerId = trim((string)$offerId);
    static $cache = [];
    if (isset($cache[$offerId])) {
        return $cache[$offerId];
    }
    if (count($cache) > 50000) {
        $cache = [];
    }

    $qty = 1;
    $baseOfferId = $offerId;
    $isBundle = false;
    $formatValid = true;

    if (preg_match('~^([1-9][0-9]*)##(.+)$~u', $offerId, $m)) {
        $candidateQty = (int)$m[1];
        $candidateBase = trim((string)$m[2]);
        if ($candidateQty > 1 && $candidateBase !== '' && !str_contains($candidateBase, '##')) {
            $qty = $candidateQty;
            $baseOfferId = $candidateBase;
            $isBundle = true;
        } else {
            $formatValid = false;
        }
    } elseif (str_contains($offerId, '##')) {
        $formatValid = false;
    }

    $supplierCode = bundle_offer_supplier_code($baseOfferId);
    $baseArticle = bundle_offer_article_without_supplier_code($baseOfferId);

    return $cache[$offerId] = [
        'offer_id' => $offerId,
        'is_bundle' => $isBundle,
        'bundle_qty' => $qty,
        'base_offer_id' => $baseOfferId,
        'base_supplier_article' => $baseArticle,
        'supplier_code' => $supplierCode,
        'format_valid' => $formatValid,
    ];
}

function bundle_offer_build(string|int $baseOfferId, int $qty): string
{
    $baseOfferId = trim((string)$baseOfferId);
    if ($baseOfferId === '' || $qty < 2 || str_contains($baseOfferId, '##')) {
        return '';
    }
    return $qty . '##' . $baseOfferId;
}

function bundle_offer_format_valid(string|int $offerId): bool
{
    return (bool)bundle_offer_parse($offerId)['format_valid'];
}

function bundle_offer_is_bundle(string|int $offerId): bool
{
    return (bool)bundle_offer_parse($offerId)['is_bundle'];
}

function bundle_offer_quantity(string|int $offerId): int
{
    return (int)bundle_offer_parse($offerId)['bundle_qty'];
}

function bundle_offer_base_offer_id(string|int $offerId): string
{
    return (string)bundle_offer_parse($offerId)['base_offer_id'];
}

function bundle_offer_moysklad_quantity(string|int $offerId, int|float $orderedQty = 1): float
{
    return max(0.0, (float)$orderedQty) * bundle_offer_quantity($offerId);
}

function bundle_offer_bundle_units_from_base(string|int $offerId, int $baseUnits): int
{
    return intdiv(max(0, $baseUnits), max(1, bundle_offer_quantity($offerId)));
}

function bundle_offer_unit_price_from_bundle_price(string|int $offerId, int|float $bundlePrice): float
{
    return ((float)$bundlePrice) / max(1, bundle_offer_quantity($offerId));
}

function bundle_offer_supplier_code(string|int $offerId): string
{
    $offerId = trim((string)$offerId);
    $pos = strrpos($offerId, '__');
    if ($pos === false) {
        return '';
    }
    return trim(substr($offerId, $pos + 2));
}

function bundle_offer_article_without_supplier_code(string|int $offerId): string
{
    $offerId = trim((string)$offerId);
    $pos = strrpos($offerId, '__');
    if ($pos === false) {
        return $offerId;
    }
    return trim(substr($offerId, 0, $pos));
}

function bundle_offer_yandex_offer_id_to_marketplace(string|int $offerId): string
{
    $offerId = trim((string)$offerId);
    $pos = strrpos($offerId, '__');
    if ($pos === false) {
        return $offerId;
    }
    return substr($offerId, 0, $pos) . '000' . substr($offerId, $pos + 2);
}

function bundle_offer_yandex_offer_id_to_internal(string|int $offerId, array $supplierCodes = []): string
{
    $offerId = trim((string)$offerId);
    if ($offerId === '' || str_contains($offerId, '__')) {
        return $offerId;
    }

    $supplierCodes = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $supplierCodes
    ), static fn(string $value): bool => $value !== '')));
    usort($supplierCodes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($supplierCodes as $supplierCode) {
        $suffix = '000' . $supplierCode;
        if (str_ends_with($offerId, $suffix)) {
            return substr($offerId, 0, -strlen($suffix)) . '__' . $supplierCode;
        }
    }

    $pos = strrpos($offerId, '000');
    if ($pos === false) {
        return $offerId;
    }
    $prefix = substr($offerId, 0, $pos);
    $suffix = substr($offerId, $pos + 3);
    if ($prefix === '' || $suffix === '') {
        return $offerId;
    }
    return $prefix . '__' . $suffix;
}
