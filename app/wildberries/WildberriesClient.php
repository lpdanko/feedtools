<?php

final class WildberriesClient
{
    private string $apiToken;
    private string $contentToken;
    private string $promotionToken;
    private string $marketplaceToken;
    private string $commonBaseUrl;
    private string $contentBaseUrl;
    private string $marketplaceBaseUrl;
    private string $pricesBaseUrl;
    private string $analyticsBaseUrl;
    private string $statisticsBaseUrl;
    private string $promotionCalendarBaseUrl;
    private int $timeoutSec;
    private string $defaultLocale;
    private int $rateLimitMaxAttempts;
    private int $rateLimitBaseDelaySec;
    private int $rateLimitMaxDelaySec;
    private int $minRequestIntervalMs;
    private float $lastRequestAt = 0.0;
    /** @var callable|null */
    private $retryLogger = null;

    public function __construct(array $wbConfig)
    {
        $this->apiToken = trim((string)($wbConfig['api_token'] ?? ''));
        $this->contentToken = trim((string)($wbConfig['content_token'] ?? ''));
        $this->promotionToken = trim((string)($wbConfig['promotion_token'] ?? ''));
        $this->marketplaceToken = trim((string)($wbConfig['marketplace_token'] ?? ''));
        $this->commonBaseUrl = rtrim((string)($wbConfig['common_base_url'] ?? 'https://common-api.wildberries.ru'), '/');
        $this->contentBaseUrl = rtrim((string)($wbConfig['content_base_url'] ?? 'https://content-api.wildberries.ru'), '/');
        $this->marketplaceBaseUrl = rtrim((string)($wbConfig['marketplace_base_url'] ?? 'https://marketplace-api.wildberries.ru'), '/');
        $this->pricesBaseUrl = rtrim((string)($wbConfig['prices_base_url'] ?? 'https://discounts-prices-api.wildberries.ru'), '/');
        $this->analyticsBaseUrl = rtrim((string)($wbConfig['analytics_base_url'] ?? 'https://seller-analytics-api.wildberries.ru'), '/');
        $this->statisticsBaseUrl = rtrim((string)($wbConfig['statistics_base_url'] ?? 'https://statistics-api.wildberries.ru'), '/');
        $this->promotionCalendarBaseUrl = rtrim((string)($wbConfig['promotion_calendar_base_url'] ?? 'https://dp-calendar-api.wildberries.ru'), '/');
        $this->timeoutSec = max(1, (int)($wbConfig['timeout_sec'] ?? 30));
        $this->defaultLocale = trim((string)($wbConfig['default_locale'] ?? 'ru')) ?: 'ru';
        $this->rateLimitMaxAttempts = max(1, (int)($wbConfig['rate_limit_max_attempts'] ?? 8));
        $this->rateLimitBaseDelaySec = max(1, (int)($wbConfig['rate_limit_base_delay_sec'] ?? 12));
        $this->rateLimitMaxDelaySec = max($this->rateLimitBaseDelaySec, (int)($wbConfig['rate_limit_max_delay_sec'] ?? 120));
        $this->minRequestIntervalMs = max(0, (int)($wbConfig['min_request_interval_ms'] ?? 700));
    }

    public function setRetryLogger(?callable $logger): void
    {
        $this->retryLogger = $logger;
    }

    public function ping(string $api = 'content', ?string $tokenKind = null): array
    {
        $api = $this->normalizeApi($api);
        $tokenKind ??= $api;
        return $this->requestJson('GET', $api, '/ping', [], null, $tokenKind);
    }

    public function contentGet(string $path, array $query = [], string $tokenKind = 'content'): array
    {
        return $this->requestJson('GET', 'content', $path, $query, null, $tokenKind);
    }

    public function contentPost(string $path, array $payload = [], string $tokenKind = 'content'): array
    {
        return $this->requestJson('POST', 'content', $path, [], $payload, $tokenKind);
    }

    public function marketplaceGet(string $path, array $query = [], string $tokenKind = 'marketplace'): array
    {
        return $this->requestJson('GET', 'marketplace', $path, $query, null, $tokenKind);
    }

    public function marketplacePost(string $path, array $payload = [], string $tokenKind = 'marketplace'): array
    {
        return $this->requestJson('POST', 'marketplace', $path, [], $payload, $tokenKind);
    }

    public function marketplacePut(string $path, array $payload = [], string $tokenKind = 'marketplace'): array
    {
        return $this->requestJson('PUT', 'marketplace', $path, [], $payload, $tokenKind);
    }

    public function getSellerWarehouses(): array
    {
        return $this->marketplaceGet('/api/v3/warehouses');
    }

    public function getFbsOrders(int $limit, int $next, int $dateFrom, int $dateTo): array
    {
        return $this->marketplaceGet('/api/v3/orders', [
            'limit' => max(1, min(1000, $limit)),
            'next' => max(0, $next),
            'dateFrom' => max(0, $dateFrom),
            'dateTo' => max(0, $dateTo),
        ]);
    }

    public function getFbsNewOrders(): array
    {
        return $this->marketplaceGet('/api/v3/orders/new');
    }

    public function getFbsOrderStatuses(array $orderIds): array
    {
        $orderIds = $this->normalizeOrderIds($orderIds);
        if (!$orderIds) {
            return ['orders' => []];
        }

        return $this->marketplacePost('/api/v3/orders/status', [
            'orders' => array_slice(array_values(array_unique($orderIds)), 0, 1000),
        ]);
    }

    public function getDbwOrders(int $limit, int $next, int $dateFrom, int $dateTo): array
    {
        return $this->marketplaceGet('/api/v3/dbw/orders', [
            'limit' => max(1, min(1000, $limit)),
            'next' => max(0, $next),
            'dateFrom' => max(0, $dateFrom),
            'dateTo' => max(0, $dateTo),
        ]);
    }

    public function getDbwNewOrders(): array
    {
        return $this->marketplaceGet('/api/v3/dbw/orders/new');
    }

    public function getDbwOrderStatuses(array $orderIds): array
    {
        $orderIds = $this->normalizeOrderIds($orderIds);
        if (!$orderIds) {
            return ['orders' => []];
        }

        return $this->marketplacePost('/api/v3/dbw/orders/status', [
            'orders' => array_slice(array_values(array_unique($orderIds)), 0, 1000),
        ]);
    }

    public function getDbsOrders(int $limit, int $next, int $dateFrom, int $dateTo): array
    {
        return $this->marketplaceGet('/api/v3/dbs/orders', [
            'limit' => max(1, min(1000, $limit)),
            'next' => max(0, $next),
            'dateFrom' => max(0, $dateFrom),
            'dateTo' => max(0, $dateTo),
        ]);
    }

    public function getDbsNewOrders(): array
    {
        return $this->marketplaceGet('/api/v3/dbs/orders/new');
    }

    public function getDbsOrderStatuses(array $orderIds): array
    {
        $orderIds = $this->normalizeOrderIds($orderIds);
        if (!$orderIds) {
            return ['orders' => []];
        }

        return $this->marketplacePost('/api/marketplace/v3/dbs/orders/status/info', [
            'ordersIds' => array_slice(array_values(array_unique($orderIds)), 0, 1000),
        ]);
    }

    public function getWarehouseStocks(int $warehouseId, array $chrtIds): array
    {
        if ($warehouseId <= 0) {
            throw new InvalidArgumentException('warehouseId must be > 0');
        }
        $chrtIds = array_values(array_filter(array_map(
            static fn($value): int => is_numeric($value) ? (int)$value : 0,
            $chrtIds
        ), static fn(int $value): bool => $value > 0));
        if (!$chrtIds) {
            return ['stocks' => []];
        }

        return $this->marketplacePost('/api/v3/stocks/' . $warehouseId, [
            'chrtIds' => $chrtIds,
        ]);
    }

    public function updateWarehouseStocks(int $warehouseId, array $stocks): array
    {
        if ($warehouseId <= 0) {
            throw new InvalidArgumentException('warehouseId must be > 0');
        }
        $payloadStocks = [];
        foreach ($stocks as $stock) {
            if (!is_array($stock)) {
                continue;
            }
            $chrtId = isset($stock['chrtId']) && is_numeric($stock['chrtId']) ? (int)$stock['chrtId'] : 0;
            if ($chrtId <= 0) {
                continue;
            }
            $payloadStocks[] = [
                'chrtId' => $chrtId,
                'amount' => max(0, (int)($stock['amount'] ?? 0)),
            ];
        }
        if (!$payloadStocks) {
            return [];
        }

        return $this->marketplacePut('/api/v3/stocks/' . $warehouseId, [
            'stocks' => $payloadStocks,
        ]);
    }

    public function pricesGet(string $path, array $query = []): array
    {
        return $this->requestJson('GET', 'prices', $path, $query, null, 'api');
    }

    public function pricesPost(string $path, array $payload = []): array
    {
        return $this->requestJson('POST', 'prices', $path, [], $payload, 'api');
    }

    public function promotionCalendarGet(string $path, array $query = []): array
    {
        return $this->requestJson('GET', 'promotion_calendar', $path, $query, null, 'api');
    }

    public function promotionCalendarPost(string $path, array $payload = []): array
    {
        return $this->requestJson('POST', 'promotion_calendar', $path, [], $payload, 'api');
    }

    public function analyticsGet(string $path, array $query = []): array
    {
        return $this->requestJson('GET', 'analytics', $path, $query, null, 'api');
    }

    public function analyticsPost(string $path, array $payload = []): array
    {
        return $this->requestJson('POST', 'analytics', $path, [], $payload, 'api');
    }

    public function statisticsGet(string $path, array $query = []): array
    {
        return $this->requestJson('GET', 'statistics', $path, $query, null, 'api');
    }

    public function getSupplierStocks(string $dateFrom = '2019-01-01'): array
    {
        $dateFrom = trim($dateFrom) !== '' ? trim($dateFrom) : '2019-01-01';
        return $this->statisticsGet('/api/v1/supplier/stocks', [
            'dateFrom' => $dateFrom,
        ]);
    }

    public function getWbWarehouseStocks(array $nmIds = [], array $chrtIds = [], int $limit = 250000, int $offset = 0): array
    {
        $normalizeIds = static function (array $values): array {
            $out = [];
            foreach ($values as $value) {
                if (is_numeric($value) && (int)$value > 0) {
                    $out[(int)$value] = (int)$value;
                }
            }
            return array_values($out);
        };

        return $this->analyticsPost('/api/analytics/v1/stocks-report/wb-warehouses', [
            'nmIds' => array_slice($normalizeIds($nmIds), 0, 1000),
            'chrtIds' => $normalizeIds($chrtIds),
            'limit' => max(1, min(250000, $limit)),
            'offset' => max(0, $offset),
        ]);
    }

    public function getSupplierOrders(string $dateFrom, int $flag = 0): array
    {
        $dateFrom = trim($dateFrom) !== '' ? trim($dateFrom) : gmdate('Y-m-d');
        return $this->statisticsGet('/api/v1/supplier/orders', [
            'dateFrom' => $dateFrom,
            'flag' => $flag > 0 ? 1 : 0,
        ]);
    }

    public function getSupplierSales(string $dateFrom, int $flag = 0): array
    {
        $dateFrom = trim($dateFrom) !== '' ? trim($dateFrom) : gmdate('Y-m-d');
        return $this->statisticsGet('/api/v1/supplier/sales', [
            'dateFrom' => $dateFrom,
            'flag' => $flag > 0 ? 1 : 0,
        ]);
    }

    public function analyticsGetRaw(string $path, array $query = []): array
    {
        return $this->requestRaw('GET', 'analytics', $path, $query, null, 'api');
    }

    public function getAllGoodsWithPrices(int $limit = 1000): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = 0;
        $items = [];
        for ($page = 1; $page <= 1000; $page++) {
            $response = $this->pricesGet('/api/v2/list/goods/filter', [
                'limit' => $limit,
                'offset' => $offset,
            ]);
            $list = $response['data']['listGoods'] ?? [];
            if (!is_array($list) || !$list) {
                break;
            }
            foreach ($list as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
            if (count($list) < $limit) {
                break;
            }
            $offset += $limit;
        }
        return $items;
    }

    public function getGoodsWithPricesByArticles(array $nmIds): array
    {
        $nmIds = array_values(array_filter(array_map(
            static fn($value): int => is_numeric($value) ? (int)$value : 0,
            $nmIds
        )));
        if (!$nmIds) {
            return [];
        }
        return $this->pricesPost('/api/v2/list/goods/filter', [
            'nmList' => $nmIds,
        ]);
    }

    public function getPromotionCalendar($startDateTime = '', string $endDateTime = '', bool $allPromo = false, int $limit = 1000, int $offset = 0): array
    {
        if (is_bool($startDateTime)) {
            $allPromo = $startDateTime;
            $startDateTime = '';
            $endDateTime = '';
        }

        $query = [
            'allPromo' => $allPromo ? 'true' : 'false',
            'limit' => max(1, min(1000, $limit)),
            'offset' => max(0, $offset),
        ];
        $startDateTime = trim((string)$startDateTime);
        $endDateTime = trim($endDateTime);
        if ($startDateTime !== '') {
            $query['startDateTime'] = $startDateTime;
        }
        if ($endDateTime !== '') {
            $query['endDateTime'] = $endDateTime;
        }

        return $this->promotionCalendarGet('/api/v1/calendar/promotions', $query);
    }

    public function getPromotionDetails(array $promotionIds): array
    {
        $promotionIds = array_values(array_filter(array_map(
            static fn($value): int => is_numeric($value) ? (int)$value : 0,
            $promotionIds
        ), static fn(int $value): bool => $value > 0));
        if (!$promotionIds) {
            return ['data' => ['promotions' => []]];
        }

        $query = [];
        foreach (array_slice($promotionIds, 0, 100) as $id) {
            $query[] = 'promotionIDs=' . rawurlencode((string)$id);
        }

        return $this->promotionCalendarGet('/api/v1/calendar/promotions/details?' . implode('&', $query));
    }

    public function getPromotionNomenclatures(int $promotionId, bool $inAction = false, int $limit = 1000, int $offset = 0): array
    {
        if ($promotionId <= 0) {
            throw new InvalidArgumentException('promotionId must be > 0');
        }

        return $this->promotionCalendarGet('/api/v1/calendar/promotions/nomenclatures', [
            'promotionID' => $promotionId,
            'inAction' => $inAction ? 'true' : 'false',
            'limit' => max(1, min(1000, $limit)),
            'offset' => max(0, $offset),
        ]);
    }

    public function uploadPromotionNomenclatures(int $promotionId, array $nmIds, bool $uploadNow = true): array
    {
        if ($promotionId <= 0) {
            throw new InvalidArgumentException('promotionId must be > 0');
        }
        $nmIds = array_values(array_filter(array_map(
            static fn($value): int => is_numeric($value) ? (int)$value : 0,
            $nmIds
        ), static fn(int $value): bool => $value > 0));
        if (!$nmIds) {
            return ['data' => ['alreadyExists' => false, 'uploadID' => 0]];
        }

        return $this->promotionCalendarPost('/api/v1/calendar/promotions/upload', [
            'data' => [
                'promotionID' => $promotionId,
                'uploadNow' => $uploadNow,
                'nomenclatures' => array_values(array_unique($nmIds)),
            ],
        ]);
    }

    public function uploadPricesAndDiscounts(array $items): array
    {
        return $this->pricesPost('/api/v2/upload/task', [
            'data' => array_values($items),
        ]);
    }

    public function uploadClubDiscounts(array $items): array
    {
        return $this->pricesPost('/api/v2/upload/task/club-discount', [
            'data' => array_values($items),
        ]);
    }

    public function getProcessedUploadState(int $uploadId): array
    {
        return $this->pricesGet('/api/v2/history/tasks', [
            'uploadID' => $uploadId,
        ]);
    }

    public function getProcessedUploadDetails(int $uploadId, int $limit = 1000, int $offset = 0): array
    {
        return $this->pricesGet('/api/v2/history/goods/task', [
            'uploadID' => $uploadId,
            'limit' => max(1, min(1000, $limit)),
            'offset' => max(0, $offset),
        ]);
    }

    public function getUnprocessedUploadState(int $uploadId): array
    {
        return $this->pricesGet('/api/v2/buffer/tasks', [
            'uploadID' => $uploadId,
        ]);
    }

    public function getUnprocessedUploadDetails(int $uploadId, int $limit = 1000, int $offset = 0): array
    {
        return $this->pricesGet('/api/v2/buffer/goods/task', [
            'uploadID' => $uploadId,
            'limit' => max(1, min(1000, $limit)),
            'offset' => max(0, $offset),
        ]);
    }

    private function normalizeOrderIds(array $orderIds): array
    {
        return array_values(array_filter(array_map(
            static fn($value): int => is_numeric($value) ? (int)$value : 0,
            $orderIds
        ), static fn(int $value): bool => $value > 0));
    }

    public function getParentCategories(?string $locale = null): array
    {
        return $this->contentGet('/content/v2/object/parent/all', [
            'locale' => $locale ?: $this->defaultLocale,
        ]);
    }

    public function getSubjects(array $filters = []): array
    {
        $query = array_filter([
            'locale' => $filters['locale'] ?? $this->defaultLocale,
            'name' => $filters['name'] ?? null,
            'limit' => isset($filters['limit']) ? (int)$filters['limit'] : null,
            'offset' => isset($filters['offset']) ? (int)$filters['offset'] : null,
            'parentID' => isset($filters['parentID']) ? (int)$filters['parentID'] : null,
        ], static fn($value) => $value !== null && $value !== '');

        return $this->contentGet('/content/v2/object/all', $query);
    }

    public function getSubjectCharacteristics(int $subjectId, ?string $locale = null): array
    {
        if ($subjectId <= 0) {
            throw new InvalidArgumentException('subjectId must be > 0');
        }

        return $this->contentGet('/content/v2/object/charcs/' . $subjectId, [
            'locale' => $locale ?: $this->defaultLocale,
        ]);
    }

    public function getDirectory(string $directory, ?string $locale = null): array
    {
        $allowed = ['colors', 'kinds', 'countries', 'seasons', 'vat', 'tnved'];
        $directory = trim($directory);
        if (!in_array($directory, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported WB directory: ' . $directory);
        }

        return $this->contentGet('/content/v2/directory/' . $directory, [
            'locale' => $locale ?: $this->defaultLocale,
        ]);
    }

    public function getBrands(int $subjectId, int $limit = 1000, int $offset = 0, string $name = ''): array
    {
        if ($subjectId <= 0) {
            throw new InvalidArgumentException('subjectId must be > 0');
        }

        $query = [
            'subjectId' => $subjectId,
        ];

        // The current WB docs use `next`; older responses accepted limit/offset/name.
        // Keep the method signature stable, but send only compatible optional filters.
        if ($offset > 0) {
            $query['next'] = max(0, $offset);
        }
        if ($limit > 0 && $limit < 1000) {
            $query['limit'] = max(1, min(1000, $limit));
        }
        if (trim($name) !== '') {
            $query['name'] = trim($name);
        }

        return $this->contentGet('/api/content/v1/brands', $query);
    }

    public function getCardsList(array $settings): array
    {
        $filter = [];
        if (isset($settings['filter'])) {
            if (is_array($settings['filter'])) {
                $filter = $settings['filter'];
            } elseif (is_object($settings['filter'])) {
                $filter = (array)$settings['filter'];
            }
        }
        if (!array_key_exists('withPhoto', $filter)) {
            $filter['withPhoto'] = -1;
        }
        $settings['filter'] = $filter ?: (object)[];
        return $this->contentPost('/content/v2/get/cards/list', [
            'settings' => $settings,
        ], 'promotion');
    }

    public function updateCards(array $cards): array
    {
        return $this->contentPost('/content/v2/cards/update', array_values($cards), 'content');
    }

    public function createCards(array $cards): array
    {
        return $this->contentPost('/content/v2/cards/upload', array_values($cards), 'content');
    }

    public function generateBarcodes(int $count = 1): array
    {
        $count = max(1, min(5000, $count));
        return $this->contentPost('/content/v2/barcodes', [
            'count' => $count,
        ], 'content');
    }

    public function saveMediaLinks(int $nmId, array $links): array
    {
        if ($nmId <= 0) {
            throw new InvalidArgumentException('nmId must be > 0');
        }

        $clean = [];
        $seen = [];
        foreach ($links as $link) {
            $link = trim((string)$link);
            if ($link === '' || isset($seen[$link])) {
                continue;
            }
            $seen[$link] = true;
            $clean[] = $link;
        }

        return $this->contentPost('/content/v3/media/save', [
            'nmId' => $nmId,
            'data' => $clean,
        ], 'content');
    }

    public function getAllCards(array $filter = [], int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $cards = [];
        $cursor = ['limit' => $limit];

        for ($page = 1; $page <= 1000; $page++) {
            $settings = [
                'sort' => ['ascending' => true],
                'cursor' => $cursor,
                'filter' => array_filter($filter, static fn($value) => $value !== null && $value !== '' && $value !== []),
            ];
            if (!$settings['filter']) {
                $settings['filter'] = (object)[];
            }

            $response = $this->getCardsList($settings);
            $batch = is_array($response['cards'] ?? null) ? $response['cards'] : [];
            if (!$batch) {
                break;
            }

            foreach ($batch as $card) {
                if (is_array($card)) {
                    $cards[] = $card;
                }
            }

            $batchCursor = is_array($response['cursor'] ?? null) ? $response['cursor'] : [];
            if (count($batch) < $limit || empty($batchCursor['updatedAt']) || empty($batchCursor['nmID'])) {
                break;
            }

            $cursor = [
                'limit' => $limit,
                'updatedAt' => (string)$batchCursor['updatedAt'],
                'nmID' => (int)$batchCursor['nmID'],
            ];
        }

        return $cards;
    }

    public function getCommissions(?string $locale = null): array
    {
        return $this->requestJson('GET', 'common', '/api/v1/tariffs/commission', [
            'locale' => trim((string)($locale ?? '')) ?: $this->defaultLocale,
        ], null, 'api');
    }

    public function getBoxTariffs(string $date): array
    {
        return $this->requestJson('GET', 'common', '/api/v1/tariffs/box', [
            'date' => $date,
        ], null, 'api');
    }

    public function getPalletTariffs(string $date): array
    {
        return $this->requestJson('GET', 'common', '/api/v1/tariffs/pallet', [
            'date' => $date,
        ], null, 'api');
    }

    public function getReturnTariffs(string $date): array
    {
        return $this->requestJson('GET', 'common', '/api/v1/tariffs/return', [
            'date' => $date,
        ], null, 'api');
    }

    public function getCardsLimits(): array
    {
        return $this->contentGet('/content/v2/cards/limits');
    }

    private function requestJson(
        string $method,
        string $api,
        string $path,
        array $query = [],
        ?array $jsonBody = null,
        string $tokenKind = 'content'
    ): array {
        $api = $this->normalizeApi($api);
        $token = $this->resolveToken($tokenKind);
        $baseUrl = $this->resolveBaseUrl($api);
        $url = $baseUrl . $this->normalizePath($path);

        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $payload = null;
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Failed to encode Wildberries request payload');
            }
        }

        $headerSets = $this->buildHeaderSets($api, $token, $payload !== null);
        $lastStatus = 0;
        $lastDecoded = [];
        $lastBodyStr = '';
        $lastCurlError = '';
        $lastHeaders = [];

        for ($attempt = 1; $attempt <= $this->rateLimitMaxAttempts; $attempt++) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }

            foreach ($headerSets as $headers) {
                $options = [
                    CURLOPT_CUSTOMREQUEST => strtoupper($method),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_TIMEOUT => $this->timeoutSec,
                    CURLOPT_HTTPHEADER => $headers,
                ];

                if ($payload !== null) {
                    $options[CURLOPT_POSTFIELDS] = $payload;
                }

                curl_setopt_array($ch, $options);

                $this->waitForRequestSlot();
                $raw = curl_exec($ch);
                if ($raw === false) {
                    $lastCurlError = curl_error($ch);
                    continue;
                }

                $lastStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $lastHeaders = $this->parseResponseHeaders(substr($raw, 0, $headerSize));
                $lastBodyStr = substr($raw, $headerSize);
                $lastDecoded = ($lastBodyStr !== '') ? json_decode($lastBodyStr, true) : [];

                if ($lastBodyStr !== '' && !is_array($lastDecoded)) {
                    if ($lastStatus === 429 && $attempt < $this->rateLimitMaxAttempts) {
                        break;
                    }
                    if (PHP_VERSION_ID < 80500) {
                        curl_close($ch);
                    }
                    throw new RuntimeException(sprintf(
                        'Wildberries returned non-JSON response (HTTP %d): %s',
                        $lastStatus,
                        substr($lastBodyStr, 0, 1000)
                    ));
                }

                if ($lastStatus >= 400 || (is_array($lastDecoded) && (($lastDecoded['error'] ?? false) === true))) {
                    if (in_array($api, ['prices', 'analytics', 'statistics'], true) && in_array($lastStatus, [401, 403], true)) {
                        continue;
                    }
                    break;
                }

                if (PHP_VERSION_ID < 80500) {
                    curl_close($ch);
                }
                return is_array($lastDecoded) ? $lastDecoded : [];
            }

            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }

            $transientStatus = in_array($lastStatus, [408, 425, 500, 502, 503, 504], true);
            $transientAttemptLimit = min($this->rateLimitMaxAttempts, 3);
            if (($lastCurlError !== '' || $transientStatus) && $attempt < $transientAttemptLimit) {
                $delaySec = min(10, 2 * $attempt + random_int(0, 2));
                sleep($delaySec);
                continue;
            }

            if ($lastStatus === 429 && $attempt < $this->rateLimitMaxAttempts) {
                $delaySec = $this->rateLimitDelaySec($attempt, $lastHeaders, is_array($lastDecoded) ? $lastDecoded : [], $lastBodyStr);
                $this->logRateLimitRetry($attempt, $delaySec, strtoupper($method), $api, $path);
                sleep($delaySec);
                continue;
            }

            break;
        }

        if ($lastCurlError !== '' && $lastStatus === 0) {
            throw new RuntimeException('Wildberries request failed: ' . $lastCurlError);
        }

        if ($lastStatus >= 400) {
            throw new RuntimeException(sprintf(
                'Wildberries HTTP %d: %s',
                $lastStatus,
                $this->extractErrorMessage(is_array($lastDecoded) ? $lastDecoded : [], $lastBodyStr)
            ));
        }

        if (is_array($lastDecoded) && (($lastDecoded['error'] ?? false) === true)) {
            throw new RuntimeException('Wildberries API error: ' . $this->extractErrorMessage($lastDecoded, $lastBodyStr));
        }

        return is_array($lastDecoded) ? $lastDecoded : [];
    }

    private function requestRaw(
        string $method,
        string $api,
        string $path,
        array $query = [],
        ?array $jsonBody = null,
        string $tokenKind = 'api'
    ): array {
        $api = $this->normalizeApi($api);
        $token = $this->resolveToken($tokenKind);
        $baseUrl = $this->resolveBaseUrl($api);
        $url = $baseUrl . $this->normalizePath($path);

        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $payload = null;
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Failed to encode Wildberries request payload');
            }
        }

        $headerSets = $this->buildHeaderSets($api, $token, $payload !== null);
        $lastStatus = 0;
        $lastBodyStr = '';
        $lastCurlError = '';
        $lastHeaders = [];

        for ($attempt = 1; $attempt <= $this->rateLimitMaxAttempts; $attempt++) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }

            foreach ($headerSets as $headers) {
                $options = [
                    CURLOPT_CUSTOMREQUEST => strtoupper($method),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_TIMEOUT => $this->timeoutSec,
                    CURLOPT_HTTPHEADER => $headers,
                ];

                if ($payload !== null) {
                    $options[CURLOPT_POSTFIELDS] = $payload;
                }

                curl_setopt_array($ch, $options);

                $this->waitForRequestSlot();
                $raw = curl_exec($ch);
                if ($raw === false) {
                    $lastCurlError = curl_error($ch);
                    continue;
                }

                $lastStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $lastHeaders = $this->parseResponseHeaders(substr($raw, 0, $headerSize));
                $lastBodyStr = substr($raw, $headerSize);

                if ($lastStatus >= 400) {
                    if (in_array($api, ['prices', 'analytics', 'statistics'], true) && in_array($lastStatus, [401, 403], true)) {
                        continue;
                    }
                    break;
                }

                if (PHP_VERSION_ID < 80500) {
                    curl_close($ch);
                }
                return [
                    'status' => $lastStatus,
                    'headers' => $lastHeaders,
                    'body' => $lastBodyStr,
                ];
            }

            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }

            if ($lastStatus === 429 && $attempt < $this->rateLimitMaxAttempts) {
                $decoded = json_decode($lastBodyStr, true);
                $delaySec = $this->rateLimitDelaySec($attempt, $lastHeaders, is_array($decoded) ? $decoded : [], $lastBodyStr);
                $this->logRateLimitRetry($attempt, $delaySec, strtoupper($method), $api, $path);
                sleep($delaySec);
                continue;
            }

            $transientStatus = in_array($lastStatus, [408, 425, 500, 502, 503, 504], true);
            if (($lastCurlError !== '' || $transientStatus) && $attempt < min($this->rateLimitMaxAttempts, 3)) {
                sleep(min(10, 2 * $attempt + random_int(0, 2)));
                continue;
            }

            break;
        }

        if ($lastCurlError !== '' && $lastStatus === 0) {
            throw new RuntimeException('Wildberries request failed: ' . $lastCurlError);
        }

        if ($lastStatus >= 400) {
            $decoded = json_decode($lastBodyStr, true);
            throw new RuntimeException(sprintf(
                'Wildberries HTTP %d: %s',
                $lastStatus,
                $this->extractErrorMessage(is_array($decoded) ? $decoded : [], $lastBodyStr)
            ));
        }

        return [
            'status' => $lastStatus,
            'headers' => $lastHeaders,
            'body' => $lastBodyStr,
        ];
    }

    private function waitForRequestSlot(): void
    {
        if ($this->minRequestIntervalMs <= 0) {
            return;
        }
        $now = microtime(true);
        if ($this->lastRequestAt > 0) {
            $elapsedMs = ($now - $this->lastRequestAt) * 1000;
            if ($elapsedMs < $this->minRequestIntervalMs) {
                usleep((int)round(($this->minRequestIntervalMs - $elapsedMs) * 1000));
            }
        }
        $this->lastRequestAt = microtime(true);
    }

    private function parseResponseHeaders(string $headersRaw): array
    {
        $headers = [];
        $headersRaw = trim($headersRaw);
        if ($headersRaw === '') {
            return $headers;
        }
        $blocks = preg_split("/\r\n\r\n|\n\n|\r\r/", $headersRaw) ?: [];
        $lastBlock = trim((string)end($blocks));
        foreach (preg_split("/\r\n|\n|\r/", $lastBlock) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            if ($name !== '' && $value !== '') {
                $headers[$name][] = $value;
            }
        }
        return $headers;
    }

    private function rateLimitDelaySec(int $attempt, array $headers, array $decoded, string $body): int
    {
        $retryAfter = $this->retryAfterHeaderSec($headers['retry-after'][0] ?? '');
        if ($retryAfter > 0) {
            return min($this->rateLimitMaxDelaySec, $retryAfter) + random_int(0, 2);
        }

        foreach (['retryAfter', 'retry_after', 'retry_after_seconds'] as $key) {
            if (isset($decoded[$key]) && is_numeric($decoded[$key]) && (int)$decoded[$key] > 0) {
                return min($this->rateLimitMaxDelaySec, (int)$decoded[$key]) + random_int(0, 2);
            }
        }

        if (preg_match('/retry[-_ ]?after["\':\s]+(\d+)/i', $body, $m)) {
            return min($this->rateLimitMaxDelaySec, max(1, (int)$m[1])) + random_int(0, 2);
        }

        $delay = $this->rateLimitBaseDelaySec * max(1, $attempt);
        return min($this->rateLimitMaxDelaySec, $delay) + random_int(0, 3);
    }

    private function retryAfterHeaderSec(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (ctype_digit($value)) {
            return max(0, (int)$value);
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return 0;
        }
        return max(0, $timestamp - time());
    }

    private function logRateLimitRetry(int $attempt, int $delaySec, string $method, string $api, string $path): void
    {
        if ($this->retryLogger === null) {
            return;
        }
        ($this->retryLogger)($attempt, $delaySec, $method, $api, $this->normalizePath($path));
    }

    private function resolveToken(string $tokenKind): string
    {
        $tokenKind = trim(strtolower($tokenKind));
        $token = '';

        if ($tokenKind === 'content') {
            $token = $this->contentToken !== '' ? $this->contentToken : $this->apiToken;
        } elseif ($tokenKind === 'promotion') {
            $token = $this->promotionToken !== '' ? $this->promotionToken : ($this->contentToken !== '' ? $this->contentToken : $this->apiToken);
        } elseif ($tokenKind === 'marketplace') {
            $token = $this->marketplaceToken !== '' ? $this->marketplaceToken : $this->apiToken;
        } else {
            $token = $this->apiToken;
        }

        if ($token === '') {
            throw new RuntimeException('Wildberries token is not configured for token kind: ' . $tokenKind);
        }

        return $token;
    }

    private function resolveBaseUrl(string $api): string
    {
        if ($api === 'content') {
            return $this->contentBaseUrl;
        }
        if ($api === 'common') {
            return $this->commonBaseUrl;
        }
        if ($api === 'marketplace') {
            return $this->marketplaceBaseUrl;
        }
        if ($api === 'prices') {
            return $this->pricesBaseUrl;
        }
        if ($api === 'analytics') {
            return $this->analyticsBaseUrl;
        }
        if ($api === 'statistics') {
            return $this->statisticsBaseUrl;
        }
        if ($api === 'promotion_calendar') {
            return $this->promotionCalendarBaseUrl;
        }

        throw new RuntimeException('Unsupported Wildberries API host: ' . $api);
    }

    private function normalizeApi(string $api): string
    {
        $api = trim(strtolower($api));
        if (!in_array($api, ['content', 'common', 'marketplace', 'prices', 'analytics', 'statistics', 'promotion_calendar'], true)) {
            throw new InvalidArgumentException('Unsupported Wildberries API host: ' . $api);
        }
        return $api;
    }

    private function buildHeaderSets(string $api, string $token, bool $hasJsonBody): array
    {
        $baseHeaders = ['Accept: application/json'];
        if ($hasJsonBody) {
            $baseHeaders[] = 'Content-Type: application/json';
        }

        if (!in_array($api, ['prices', 'common', 'analytics', 'statistics', 'promotion_calendar'], true)) {
            return [[
                'Authorization: Bearer ' . $token,
                ...$baseHeaders,
            ]];
        }

        return [
            ['Authorization: ' . $token, ...$baseHeaders],
            ['Authorization: Bearer ' . $token, ...$baseHeaders],
            ['HeaderApiKey: ' . $token, ...$baseHeaders],
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        return ($path[0] === '/') ? $path : '/' . $path;
    }

    private function extractErrorMessage(array $decoded, string $rawBody = ''): string
    {
        $parts = [];
        foreach (['code', 'errorText', 'message', 'detail', 'title', 'additionalErrors', 'errors'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }

            $value = $decoded[$key];
            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
            if (is_array($value) && $value) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded) && $encoded !== '') {
                    $parts[] = $encoded;
                }
            }
        }

        $parts = array_values(array_unique(array_filter($parts, static fn(string $value): bool => $value !== '')));
        if ($parts) {
            return implode(' · ', $parts);
        }

        $rawBody = trim($rawBody);
        if ($rawBody !== '') {
            return substr($rawBody, 0, 1000);
        }

        return 'Unknown Wildberries error: empty response body';
    }
}
