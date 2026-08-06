<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/BundleOffer.php';
require_once __DIR__ . '/ozon_products.php';
require_once __DIR__ . '/suppliers.php';
require_once __DIR__ . '/supplier_products.php';
require_once __DIR__ . '/wildberries/WildberriesClient.php';
require_once __DIR__ . '/wildberries/WildberriesPriceTool.php';
require_once __DIR__ . '/wb_promotions.php';
require_once __DIR__ . '/yandex/YandexPriceTool.php';

function ozon_price_cfg_fallback(array $cfg = []): array
{
    if ($cfg) {
        return $cfg;
    }
    return (isset($GLOBALS['cfg']) && is_array($GLOBALS['cfg'])) ? $GLOBALS['cfg'] : [];
}

function price_tool_marketplace_definitions(): array
{
    return [
        'ozon' => [
            'label' => 'Ozon',
            'client_id_label' => 'Client ID',
            'api_key_label' => 'API key',
            'base_url' => 'https://api-seller.ozon.ru',
            'requires_client_id' => true,
            'requires_api_key' => true,
            'status' => 'ready',
            'supports' => ['price_tool', 'feeds', 'automations', 'actions', 'push', 'orders_sync', 'stocks_tool', 'stock_tool', 'fbo_tool'],
            'note' => 'Полная поддержка расчёта, акций, выгрузки и автоматизации.',
        ],
        'wb' => [
            'label' => 'WB',
            'client_id_label' => 'Seller ID',
            'api_key_label' => 'API token',
            'base_url' => 'https://suppliers-api.wildberries.ru',
            'requires_client_id' => false,
            'requires_api_key' => true,
            'status' => 'ready',
            'supports' => ['price_tool', 'feeds', 'automations', 'actions', 'push', 'stocks_tool', 'orders_sync', 'fbo_tool'],
            'note' => 'Для подключения нужен API token Wildberries. Seller ID можно не вводить вручную: система определит его по токену при проверке или сохранении.',
        ],
        'yandex_market' => [
            'label' => 'Яндекс Маркет',
            'client_id_label' => 'Campaign ID',
            'api_key_label' => 'API-Key',
            'base_url' => 'https://api.partner.market.yandex.ru',
            'requires_client_id' => false,
            'requires_api_key' => true,
            'status' => 'ready',
            'supports' => ['price_tool', 'feeds', 'automations', 'push', 'orders_sync', 'stocks_tool'],
            'note' => 'Для Price Tool, Orders Sync и Stocks Tool нужен API-Key токен с доступом к товарам, ценам, тарифам, заказам, складам и остаткам. Если у токена доступ только к одному магазину, Campaign ID можно не вводить вручную.',
        ],
    ];
}

function price_tool_marketplace_meta(string $marketplace): array
{
    $definitions = price_tool_marketplace_definitions();
    return $definitions[$marketplace] ?? $definitions['ozon'];
}

function price_tool_marketplace_label(string $marketplace): string
{
    return (string)(price_tool_marketplace_meta($marketplace)['label'] ?? strtoupper($marketplace));
}

function price_tool_marketplace_supports(string $marketplace, string $feature): bool
{
    $meta = price_tool_marketplace_meta($marketplace);
    $supports = is_array($meta['supports'] ?? null) ? $meta['supports'] : [];
    return in_array($feature, $supports, true);
}

function price_tool_connection_supports(?array $connection, string $feature): bool
{
    if (!is_array($connection)) {
        return false;
    }
    return price_tool_marketplace_supports((string)($connection['marketplace'] ?? ''), $feature);
}

function ozon_price_feed_push_op_types(): array
{
    return ['ozon_push_selected_feeds', 'wb_push_selected_feeds', 'yandex_push_selected_feeds'];
}

function ozon_price_marketplace_push_op_type(string $marketplace): string
{
    if ($marketplace === 'wb') {
        return 'wb_push_selected_feeds';
    }
    if ($marketplace === 'yandex_market') {
        return 'yandex_push_selected_feeds';
    }
    return 'ozon_push_selected_feeds';
}

function ozon_price_connection_push_op_type(?array $connection): string
{
    return ozon_price_marketplace_push_op_type((string)($connection['marketplace'] ?? 'ozon'));
}

function ozon_price_connections_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cfg = ozon_price_cfg_fallback($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_marketplace_connections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            marketplace VARCHAR(32) NOT NULL DEFAULT 'ozon',
            title VARCHAR(190) NOT NULL,
            client_id VARCHAR(64) NOT NULL DEFAULT '',
            api_key VARCHAR(4096) NOT NULL DEFAULT '',
            base_url VARCHAR(255) NOT NULL DEFAULT 'https://api-seller.ozon.ru',
            timeout_sec INT NOT NULL DEFAULT 30,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            notes TEXT NULL,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_marketplace_active (marketplace, is_active, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (ozon_products_table_character_max_length(db(), 'feedtools_marketplace_connections', 'api_key') > 0
        && ozon_products_table_character_max_length(db(), 'feedtools_marketplace_connections', 'api_key') < 4096) {
        db()->exec("ALTER TABLE feedtools_marketplace_connections MODIFY api_key VARCHAR(4096) NOT NULL DEFAULT ''");
    }

    if (!ozon_price_table_has_index('feedtools_marketplace_connections', 'uniq_marketplace_client')) {
        $dupRow = db()->query("
            SELECT marketplace, client_id, COUNT(*) AS qty
            FROM feedtools_marketplace_connections
            WHERE client_id <> ''
            GROUP BY marketplace, client_id
            HAVING COUNT(*) > 1
            LIMIT 1
        ")->fetch();
        if (!$dupRow) {
            db()->exec("ALTER TABLE feedtools_marketplace_connections ADD UNIQUE KEY uniq_marketplace_client (marketplace, client_id)");
        }
    }

    $defaultOzon = (array)($cfg['ozon'] ?? []);
    $clientId = trim((string)($defaultOzon['client_id'] ?? ''));
    $apiKey = trim((string)($defaultOzon['api_key'] ?? ''));
    if ($clientId !== '' && $apiKey !== '') {
        $st = db()->prepare("
            SELECT id
            FROM feedtools_marketplace_connections
            WHERE marketplace = 'ozon' AND client_id = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $st->execute([$clientId]);
        $existingId = (int)($st->fetchColumn() ?: 0);
        if ($existingId <= 0) {
            $insert = db()->prepare("
                INSERT INTO feedtools_marketplace_connections (
                    marketplace, title, client_id, api_key, base_url, timeout_sec, is_active, sort_order, notes, created_by, updated_by
                ) VALUES (
                    'ozon', ?, ?, ?, ?, ?, 1, 100, ?, ?, ?
                )
            ");
            $insert->execute([
                'Ozon — основной аккаунт',
                $clientId,
                $apiKey,
                (string)($defaultOzon['base_url'] ?? 'https://api-seller.ozon.ru'),
                (int)($defaultOzon['timeout_sec'] ?? 30),
                'Автоматически создано из текущих настроек приложения.',
                'system',
                'system',
            ]);
        }
    }

    $done = true;
}

function ozon_price_connection_default_id(array $cfg = []): int
{
    $cfg = ozon_price_cfg_fallback($cfg);
    ozon_price_connections_table_ensure($cfg);
    $st = db()->query("
        SELECT id
        FROM feedtools_marketplace_connections
        WHERE marketplace = 'ozon'
        ORDER BY is_active DESC, sort_order ASC, id ASC
        LIMIT 1
    ");
    return (int)($st->fetchColumn() ?: 0);
}

function ozon_price_connection_list(array $cfg = [], ?string $marketplace = 'ozon'): array
{
    $cfg = ozon_price_cfg_fallback($cfg);
    ozon_price_connections_table_ensure($cfg);
    if ($marketplace === null || trim($marketplace) === '') {
        $st = db()->query("
            SELECT *
            FROM feedtools_marketplace_connections
            ORDER BY marketplace ASC, is_active DESC, sort_order ASC, id ASC
        ");
        return $st->fetchAll() ?: [];
    }
    $st = db()->prepare("
        SELECT *
        FROM feedtools_marketplace_connections
        WHERE marketplace = ?
        ORDER BY is_active DESC, sort_order ASC, id ASC
    ");
    $st->execute([$marketplace]);
    return $st->fetchAll() ?: [];
}

function ozon_price_connection_get(int $id, array $cfg = []): ?array
{
    $cfg = ozon_price_cfg_fallback($cfg);
    ozon_price_connections_table_ensure($cfg);
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM feedtools_marketplace_connections WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function ozon_price_connection_resolve(?int $requestedId, array $cfg = []): ?array
{
    $cfg = ozon_price_cfg_fallback($cfg);
    ozon_price_connections_table_ensure($cfg);
    if (($requestedId ?? 0) > 0) {
        $row = ozon_price_connection_get((int)$requestedId, $cfg);
        if (is_array($row)) {
            return $row;
        }
    }
    $defaultId = ozon_price_connection_default_id($cfg);
    return $defaultId > 0 ? ozon_price_connection_get($defaultId, $cfg) : null;
}

function ozon_price_connection_default(string $marketplace = 'ozon'): array
{
    $marketplace = strtolower(trim($marketplace));
    if (!isset(price_tool_marketplace_definitions()[$marketplace])) {
        $marketplace = 'ozon';
    }
    $meta = price_tool_marketplace_meta($marketplace);
    return [
        'id' => 0,
        'marketplace' => $marketplace,
        'title' => '',
        'client_id' => '',
        'api_key' => '',
        'base_url' => (string)($meta['base_url'] ?? 'https://api-seller.ozon.ru'),
        'timeout_sec' => 30,
        'is_active' => 1,
        'sort_order' => 100,
        'notes' => '',
    ];
}

function ozon_price_connection_normalize_input(array $input): array
{
    $id = (int)($input['id'] ?? 0);
    $marketplace = strtolower(trim((string)($input['marketplace'] ?? 'ozon')));
    if (!in_array($marketplace, ['ozon', 'wb', 'yandex_market'], true)) {
        $marketplace = 'ozon';
    }
    $meta = price_tool_marketplace_meta($marketplace);

    return [
        'id' => $id,
        'marketplace' => $marketplace,
        'title' => trim((string)($input['title'] ?? '')),
        'client_id' => trim((string)($input['client_id'] ?? '')),
        'api_key' => trim((string)($input['api_key'] ?? '')),
        'base_url' => trim((string)($meta['base_url'] ?? '')),
        'timeout_sec' => max(5, (int)($input['timeout_sec'] ?? 30)),
        'is_active' => 1,
        'sort_order' => max(1, (int)($input['sort_order'] ?? 100)),
        'notes' => trim((string)($input['notes'] ?? '')),
    ];
}

function marketplace_connection_http_json(
    string $method,
    string $url,
    array $headers = [],
    $payload = null,
    int $timeoutSec = 30
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $httpHeaders = array_merge(['Accept: application/json'], $headers);
    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(5, $timeoutSec),
        CURLOPT_HTTPHEADER => $httpHeaders,
    ];

    if ($payload !== null) {
        if (is_array($payload) || is_object($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Не удалось подготовить JSON payload.');
            }
            $httpHeaders[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $httpHeaders;
        }
        $options[CURLOPT_POSTFIELDS] = (string)$payload;
    }

    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        throw new RuntimeException('Запрос к API не выполнен: ' . $err);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }

    $body = substr($raw, $headerSize);
    $decoded = [];
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'API вернул не-JSON ответ (HTTP %d): %s',
                $status,
                substr($body, 0, 1000)
            ));
        }
    }

    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => $body,
    ];
}

function marketplace_connection_extract_error_message(array $body): string
{
    $messages = [];

    if (isset($body['message']) && trim((string)$body['message']) !== '') {
        $messages[] = trim((string)$body['message']);
    }
    if (isset($body['errorText']) && trim((string)$body['errorText']) !== '') {
        $messages[] = trim((string)$body['errorText']);
    }
    if (isset($body['error']) && is_string($body['error']) && trim($body['error']) !== '') {
        $messages[] = trim($body['error']);
    }
    if (isset($body['errors']) && is_array($body['errors'])) {
        foreach ($body['errors'] as $errorRow) {
            if (!is_array($errorRow)) {
                continue;
            }
            $code = trim((string)($errorRow['code'] ?? ''));
            $message = trim((string)($errorRow['message'] ?? ''));
            $messages[] = trim(($code !== '' ? ($code . ': ') : '') . $message);
        }
    }

    $messages = array_values(array_filter(array_unique($messages), static fn(string $value): bool => $value !== ''));
    return $messages ? implode(' | ', $messages) : 'Неизвестная ошибка API';
}

function marketplace_connection_wb_fetch_seller_info(array $row): array
{
    $response = marketplace_connection_http_json(
        'GET',
        'https://common-api.wildberries.ru/api/v1/seller-info',
        ['Authorization: Bearer ' . (string)$row['api_key']],
        null,
        (int)($row['timeout_sec'] ?? 30)
    );

    $status = (int)($response['status'] ?? 0);
    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    if ($status >= 400) {
        throw new RuntimeException(sprintf('Wildberries HTTP %d: %s', $status, marketplace_connection_extract_error_message($body)));
    }

    return $body;
}

function marketplace_connection_yandex_request(array $row, string $method, string $path, array $query = [], $payload = null): array
{
    $baseUrl = rtrim((string)($row['base_url'] ?? 'https://api.partner.market.yandex.ru'), '/');
    $url = $baseUrl . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $response = marketplace_connection_http_json(
        $method,
        $url,
        ['Api-Key: ' . (string)$row['api_key']],
        $payload,
        (int)($row['timeout_sec'] ?? 30)
    );

    $status = (int)($response['status'] ?? 0);
    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    if ($status >= 400) {
        throw new RuntimeException(sprintf('Яндекс Маркет HTTP %d: %s', $status, marketplace_connection_extract_error_message($body)));
    }

    return $body;
}

function marketplace_connection_yandex_list_campaigns(array $row): array
{
    $campaigns = [];
    $pageToken = '';
    $guard = 0;

    do {
        $query = ['limit' => 100];
        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        $body = marketplace_connection_yandex_request($row, 'GET', '/v2/campaigns', $query);
        $pageItems = is_array($body['campaigns'] ?? null) ? $body['campaigns'] : [];
        foreach ($pageItems as $item) {
            if (is_array($item)) {
                $campaigns[] = $item;
            }
        }
        $pageToken = trim((string)($body['paging']['nextPageToken'] ?? ''));
        $guard++;
    } while ($pageToken !== '' && $guard < 20);

    return $campaigns;
}

function marketplace_connection_yandex_resolve_campaign(array $row): array
{
    $campaigns = marketplace_connection_yandex_list_campaigns($row);
    $requestedCampaignId = (int)trim((string)($row['client_id'] ?? ''));

    if ($requestedCampaignId > 0) {
        foreach ($campaigns as $campaign) {
            if ((int)($campaign['id'] ?? 0) === $requestedCampaignId) {
                return [
                    'campaign_id' => $requestedCampaignId,
                    'campaign' => $campaign,
                    'campaigns' => $campaigns,
                ];
            }
        }
        throw new RuntimeException('Указанный Campaign ID не найден среди магазинов, доступных по этому API-Key токену.');
    }

    if (count($campaigns) === 1) {
        return [
            'campaign_id' => (int)($campaigns[0]['id'] ?? 0),
            'campaign' => $campaigns[0],
            'campaigns' => $campaigns,
        ];
    }

    if (!$campaigns) {
        throw new RuntimeException('По этому API-Key не найдено ни одной кампании Яндекс Маркета.');
    }

    $samples = array_slice(array_map(
        static fn(array $campaign): string => (string)($campaign['id'] ?? 0),
        $campaigns
    ), 0, 5);

    throw new RuntimeException(
        'API-Key валиден, но в кабинете доступно несколько магазинов. Укажи Campaign ID вручную. Примеры Campaign ID: ' . implode(', ', $samples)
    );
}

function ozon_price_connection_test(array $input): array
{
    $row = ozon_price_connection_normalize_input($input);
    $marketplace = (string)$row['marketplace'];
    $meta = price_tool_marketplace_meta($marketplace);

    if (!empty($meta['requires_client_id']) && $row['client_id'] === '') {
        throw new RuntimeException('Для проверки укажи ' . ($meta['client_id_label'] ?? 'Client ID') . '.');
    }
    if (!empty($meta['requires_api_key']) && $row['api_key'] === '') {
        throw new RuntimeException('Для проверки укажи ' . ($meta['api_key_label'] ?? 'API key') . '.');
    }

    if ($marketplace === 'ozon') {
        $oz = [
            'client_id' => $row['client_id'],
            'api_key' => $row['api_key'],
            'base_url' => $row['base_url'],
            'timeout_sec' => $row['timeout_sec'],
        ];
        $response = ozon_post_json($oz, '/v3/product/list', [
            'filter' => new stdClass(),
            'last_id' => '',
            'limit' => 1,
        ]);
        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $firstItem = is_array($items[0] ?? null) ? $items[0] : [];

        return [
            'ok' => true,
            'title' => 'Соединение с Ozon успешно проверено.',
            'details' => array_values(array_filter([
                'Client ID: ' . $row['client_id'],
                isset($result['total']) ? 'Товаров в ответе API: ' . (int)$result['total'] : '',
                !empty($firstItem['offer_id']) ? 'Пример offer_id: ' . (string)$firstItem['offer_id'] : '',
            ])),
        ];
    }

    if ($marketplace === 'wb') {
        $response = marketplace_connection_wb_fetch_seller_info($row);
        $sellerId = trim((string)($response['sid'] ?? ''));
        $enteredSellerId = trim((string)($row['client_id'] ?? ''));
        if ($enteredSellerId !== '' && $sellerId !== '' && strcasecmp($enteredSellerId, $sellerId) !== 0) {
            throw new RuntimeException('Указанный Seller ID не совпадает с кабинетом, который определяется по токену WB.');
        }

        return [
            'ok' => true,
            'title' => 'Соединение с Wildberries успешно проверено.',
            'resolved_client_id' => $sellerId,
            'details' => array_values(array_filter([
                $sellerId !== '' ? 'Seller ID: ' . $sellerId : '',
                !empty($response['name']) ? 'Кабинет: ' . (string)$response['name'] : '',
                !empty($response['tradeMark']) ? 'Бренд: ' . (string)$response['tradeMark'] : '',
                !empty($response['tin']) ? 'ИНН: ' . (string)$response['tin'] : '',
            ])),
        ];
    }

    if ($marketplace === 'yandex_market') {
        $resolved = marketplace_connection_yandex_resolve_campaign($row);
        $campaign = is_array($resolved['campaign'] ?? null) ? $resolved['campaign'] : [];
        $campaignId = (int)($resolved['campaign_id'] ?? 0);

        return [
            'ok' => true,
            'title' => 'Соединение с Яндекс Маркетом успешно проверено.',
            'resolved_client_id' => $campaignId > 0 ? (string)$campaignId : '',
            'details' => array_values(array_filter([
                $campaignId > 0 ? 'Campaign ID: ' . $campaignId : '',
                !empty($campaign['business']['name']) ? 'Кабинет: ' . (string)$campaign['business']['name'] : '',
                !empty($campaign['domain']) ? 'Магазин: ' . (string)$campaign['domain'] : '',
                !empty($campaign['placementType']) ? 'Модель: ' . (string)$campaign['placementType'] : '',
                !empty($campaign['apiAvailability']) ? 'Доступность API: ' . (string)$campaign['apiAvailability'] : '',
            ])),
        ];
    }

    throw new RuntimeException('Проверка подключения для этого маркетплейса пока не поддержана.');
}

function ozon_price_cfg_with_connection(array $cfg, ?array $connection): array
{
    if (!is_array($connection)) {
        return $cfg;
    }
    $marketplace = trim((string)($connection['marketplace'] ?? ''));
    if ($marketplace === 'wb') {
        $cfg['wildberries'] = (array)($cfg['wildberries'] ?? []);
        $wbToken = trim((string)($connection['api_key'] ?? ''));
        $cfg['wildberries']['api_token'] = $wbToken;
        $cfg['wildberries']['content_token'] = $wbToken;
        $cfg['wildberries']['promotion_token'] = $wbToken;
        $cfg['wildberries']['marketplace_token'] = $wbToken;
        $cfg['wildberries']['base_url'] = trim((string)($connection['base_url'] ?? '')) ?: 'https://suppliers-api.wildberries.ru';
        $cfg['wildberries']['timeout_sec'] = max(5, (int)($connection['timeout_sec'] ?? 30));
        $cfg['price_tool_connection'] = [
            'id' => (int)($connection['id'] ?? 0),
            'marketplace' => 'wb',
            'title' => (string)($connection['title'] ?? ''),
        ];
        return $cfg;
    }
    if ($marketplace === 'yandex_market') {
        $cfg['yandex_market'] = (array)($cfg['yandex_market'] ?? []);
        $cfg['yandex_market']['campaign_id'] = trim((string)($connection['client_id'] ?? ''));
        $cfg['yandex_market']['api_key'] = trim((string)($connection['api_key'] ?? ''));
        $cfg['yandex_market']['base_url'] = trim((string)($connection['base_url'] ?? '')) ?: 'https://api.partner.market.yandex.ru';
        $cfg['yandex_market']['timeout_sec'] = max(5, (int)($connection['timeout_sec'] ?? 30));
        $cfg['price_tool_connection'] = [
            'id' => (int)($connection['id'] ?? 0),
            'marketplace' => 'yandex_market',
            'title' => (string)($connection['title'] ?? ''),
        ];
        return $cfg;
    }
    if ($marketplace !== 'ozon') {
        return $cfg;
    }
    $cfg['ozon'] = (array)($cfg['ozon'] ?? []);
    $cfg['ozon']['client_id'] = trim((string)($connection['client_id'] ?? ''));
    $cfg['ozon']['api_key'] = trim((string)($connection['api_key'] ?? ''));
    $cfg['ozon']['base_url'] = trim((string)($connection['base_url'] ?? '')) ?: 'https://api-seller.ozon.ru';
    $cfg['ozon']['timeout_sec'] = max(5, (int)($connection['timeout_sec'] ?? 30));
    $cfg['price_tool_connection'] = [
        'id' => (int)($connection['id'] ?? 0),
        'marketplace' => (string)($connection['marketplace'] ?? 'ozon'),
        'title' => (string)($connection['title'] ?? ''),
    ];
    return $cfg;
}

function ozon_price_connection_save(array $input, ?string $actor = null): int
{
    ozon_price_connections_table_ensure();
    $row = ozon_price_connection_normalize_input($input);
    $id = (int)$row['id'];
    $marketplace = (string)$row['marketplace'];
    $title = (string)$row['title'];
    if ($title === '') {
        throw new RuntimeException('Укажи название подключения.');
    }
    $clientId = (string)$row['client_id'];
    $apiKey = (string)$row['api_key'];
    $baseUrl = (string)$row['base_url'];
    $timeoutSec = (int)$row['timeout_sec'];
    $isActive = 1;
    $sortOrder = (int)$row['sort_order'];
    $notes = (string)$row['notes'];
    $meta = price_tool_marketplace_meta($marketplace);

    if (!empty($meta['requires_client_id']) && $clientId === '') {
        throw new RuntimeException('Для подключения ' . price_tool_marketplace_label($marketplace) . ' укажи ' . ($meta['client_id_label'] ?? 'Client ID') . '.');
    }
    if (!empty($meta['requires_api_key']) && $apiKey === '') {
        throw new RuntimeException('Для подключения ' . price_tool_marketplace_label($marketplace) . ' укажи ' . ($meta['api_key_label'] ?? 'API key') . '.');
    }

    if ($marketplace === 'wb' && $apiKey !== '') {
        $wbInfo = marketplace_connection_wb_fetch_seller_info($row);
        $sellerId = trim((string)($wbInfo['sid'] ?? ''));
        if ($sellerId === '') {
            throw new RuntimeException('Не удалось определить Seller ID по токену WB.');
        }
        if ($clientId !== '' && strcasecmp($clientId, $sellerId) !== 0) {
            throw new RuntimeException('Указанный Seller ID не совпадает с кабинетом, который определяется по токену WB.');
        }
        $clientId = $sellerId;
    }

    if ($marketplace === 'yandex_market' && $apiKey !== '') {
        $resolved = marketplace_connection_yandex_resolve_campaign(array_replace($row, ['client_id' => $clientId]));
        $clientId = (string)((int)($resolved['campaign_id'] ?? 0));
        if ($clientId === '' || $clientId === '0') {
            throw new RuntimeException('Не удалось определить Campaign ID для Яндекс Маркета.');
        }
    }

    if ($clientId !== '') {
        $sql = "
            SELECT id
            FROM feedtools_marketplace_connections
            WHERE marketplace = ? AND client_id = ?
        ";
        $args = [$marketplace, $clientId];
        if ($id > 0) {
            $sql .= " AND id <> ?";
            $args[] = $id;
        }
        $sql .= " LIMIT 1";
        $st = db()->prepare($sql);
        $st->execute($args);
        $existingId = (int)($st->fetchColumn() ?: 0);
        if ($existingId > 0) {
            throw new RuntimeException('Подключение для этого аккаунта маркетплейса уже существует. Используй существующую запись вместо дубликата.');
        }
    }

    if ($id > 0) {
        $st = db()->prepare("
            UPDATE feedtools_marketplace_connections
            SET marketplace = ?, title = ?, client_id = ?, api_key = ?, base_url = ?, timeout_sec = ?, is_active = ?, sort_order = ?, notes = ?, updated_by = ?
            WHERE id = ?
        ");
        $st->execute([$marketplace, $title, $clientId, $apiKey, $baseUrl, $timeoutSec, $isActive, $sortOrder, $notes, $actor, $id]);
        return $id;
    }

    $st = db()->prepare("
        INSERT INTO feedtools_marketplace_connections (
            marketplace, title, client_id, api_key, base_url, timeout_sec, is_active, sort_order, notes, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([$marketplace, $title, $clientId, $apiKey, $baseUrl, $timeoutSec, $isActive, $sortOrder, $notes, $actor, $actor]);
    return (int)db()->lastInsertId();
}

function ozon_price_connection_title_short(?array $connection): string
{
    if (!is_array($connection)) {
        return 'Подключение не выбрано';
    }
    $marketplace = price_tool_marketplace_label(trim((string)($connection['marketplace'] ?? 'ozon')));
    $title = trim((string)($connection['title'] ?? ''));
    return $title !== '' ? ($marketplace . ' / ' . $title) : $marketplace;
}

function ozon_price_feeds_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ozon_price_connections_table_ensure($cfg);
    suppliers_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_price_feeds (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(190) NOT NULL,
            feed_url TEXT NOT NULL,
            cost_tag VARCHAR(190) NOT NULL,
            supplier_code VARCHAR(64) NOT NULL DEFAULT '',
            fulfillment_scheme VARCHAR(16) NOT NULL DEFAULT 'fbs',
            wb_commission_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            wb_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            wb_club_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            wb_promotion_pricing_enabled TINYINT(1) NOT NULL DEFAULT 0,
            wb_promotion_max_plan_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 60.00,
            wb_promotion_min_margin_percent DECIMAL(10,2) NOT NULL DEFAULT 5.00,
            wb_future_promo_discount_mode VARCHAR(16) NOT NULL DEFAULT 'auto',
            wb_future_promo_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            wb_future_promo_discount_buffer_percent DECIMAL(10,2) NOT NULL DEFAULT 2.00,
            wb_future_promo_prepare_days INT NOT NULL DEFAULT 0,
            wb_promotion_action_upload_enabled TINYINT(1) NOT NULL DEFAULT 1,
            wb_expenses_mode VARCHAR(16) NOT NULL DEFAULT 'api',
            wb_tariff_warehouse_name VARCHAR(190) NOT NULL DEFAULT '',
            yandex_expenses_mode VARCHAR(16) NOT NULL DEFAULT 'api',
            yandex_payment_frequency VARCHAR(16) NOT NULL DEFAULT 'DAILY',
            yandex_payment_delay_weeks INT NOT NULL DEFAULT 0,
            yandex_boost_bid_enabled TINYINT(1) NOT NULL DEFAULT 0,
            yandex_boost_bid_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            target_profit_percent DECIMAL(10,2) NOT NULL DEFAULT 20.00,
            min_target_profit_percent DECIMAL(10,2) NOT NULL DEFAULT 10.00,
            target_profit_min_rub DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            min_target_profit_min_rub DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            min_price_index_step_enabled TINYINT(1) NOT NULL DEFAULT 0,
            action_pricing_enabled TINYINT(1) NOT NULL DEFAULT 1,
            target_profit_ranges_json LONGTEXT NULL,
            min_target_profit_ranges_json LONGTEXT NULL,
            rounding_mode VARCHAR(32) NOT NULL DEFAULT 'rub',
            price_modifier_mode VARCHAR(16) NOT NULL DEFAULT 'none',
            price_modifier_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            price_modifier_min_mode VARCHAR(16) NOT NULL DEFAULT 'none',
            price_modifier_min_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            fulfillment_markup_rub DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            fulfillment_markup_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            shipment_processing_rub DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            nonbuyout_processing_rub DECIMAL(12,2) NOT NULL DEFAULT 50.00,
            return_processing_rub DECIMAL(12,2) NOT NULL DEFAULT 50.00,
            ship_0_12_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ship_12_24_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ship_24_36_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ship_36_48_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ship_48_plus_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            nonbuyout_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            return_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            include_returns_in_cost TINYINT(1) NOT NULL DEFAULT 0,
            promotion_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            credit_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            delayed_shipment_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            delayed_shipment_min_rub DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            extra_expenses_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            strike_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tax_mode VARCHAR(32) NOT NULL DEFAULT 'none',
            tax_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            vat_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            profit_tax_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            insurance_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_by VARCHAR(190) NULL DEFAULT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_connection_updated (connection_id, updated_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'connection_id',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST"
    );

    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'supplier_id',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER connection_id"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'supplier_code',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN supplier_code VARCHAR(64) NOT NULL DEFAULT '' AFTER cost_tag"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_commission_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_commission_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fulfillment_scheme"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_discount_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER wb_commission_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_club_discount_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_club_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER wb_discount_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_promotion_pricing_enabled',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_promotion_pricing_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER wb_club_discount_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_promotion_max_plan_discount_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_promotion_max_plan_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 60.00 AFTER wb_promotion_pricing_enabled"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_promotion_min_margin_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_promotion_min_margin_percent DECIMAL(10,2) NOT NULL DEFAULT 5.00 AFTER wb_promotion_max_plan_discount_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_future_promo_discount_mode',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_future_promo_discount_mode VARCHAR(16) NOT NULL DEFAULT 'auto' AFTER wb_promotion_min_margin_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_future_promo_discount_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_future_promo_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER wb_future_promo_discount_mode"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_future_promo_discount_buffer_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_future_promo_discount_buffer_percent DECIMAL(10,2) NOT NULL DEFAULT 2.00 AFTER wb_future_promo_discount_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_future_promo_prepare_days',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_future_promo_prepare_days INT NOT NULL DEFAULT 0 AFTER wb_future_promo_discount_buffer_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_promotion_action_upload_enabled',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_promotion_action_upload_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER wb_future_promo_prepare_days"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_expenses_mode',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_expenses_mode VARCHAR(16) NOT NULL DEFAULT 'api' AFTER wb_promotion_action_upload_enabled"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'wb_tariff_warehouse_name',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN wb_tariff_warehouse_name VARCHAR(190) NOT NULL DEFAULT '' AFTER wb_expenses_mode"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'yandex_expenses_mode',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN yandex_expenses_mode VARCHAR(16) NOT NULL DEFAULT 'api' AFTER wb_tariff_warehouse_name"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'yandex_payment_frequency',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN yandex_payment_frequency VARCHAR(16) NOT NULL DEFAULT 'DAILY' AFTER yandex_expenses_mode"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'yandex_payment_delay_weeks',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN yandex_payment_delay_weeks INT NOT NULL DEFAULT 0 AFTER yandex_payment_frequency"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'yandex_boost_bid_enabled',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN yandex_boost_bid_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER yandex_payment_delay_weeks"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'yandex_boost_bid_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN yandex_boost_bid_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER yandex_boost_bid_enabled"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'price_modifier_mode',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN price_modifier_mode VARCHAR(16) NOT NULL DEFAULT 'none' AFTER rounding_mode"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'price_modifier_value',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN price_modifier_value DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER price_modifier_mode"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'price_modifier_min_mode',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN price_modifier_min_mode VARCHAR(16) NOT NULL DEFAULT 'none' AFTER price_modifier_value"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'price_modifier_min_value',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN price_modifier_min_value DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER price_modifier_min_mode"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'fulfillment_markup_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN fulfillment_markup_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fulfillment_markup_rub"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'nonbuyout_processing_rub',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN nonbuyout_processing_rub DECIMAL(12,2) NOT NULL DEFAULT 50.00 AFTER shipment_processing_rub"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'return_processing_rub',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN return_processing_rub DECIMAL(12,2) NOT NULL DEFAULT 50.00 AFTER nonbuyout_processing_rub"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'ship_0_12_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN ship_0_12_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER shipment_processing_rub"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'ship_12_24_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN ship_12_24_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ship_0_12_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'ship_24_36_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN ship_24_36_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ship_12_24_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'ship_36_48_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN ship_36_48_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ship_24_36_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'ship_48_plus_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN ship_48_plus_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ship_36_48_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'nonbuyout_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN nonbuyout_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER shipment_processing_rub"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'return_resellable_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN return_resellable_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER nonbuyout_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'return_nonresellable_percent',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN return_nonresellable_percent DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER return_resellable_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'target_profit_min_rub',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN target_profit_min_rub DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER min_target_profit_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'min_target_profit_min_rub',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN min_target_profit_min_rub DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER target_profit_min_rub"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'min_price_index_step_enabled',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN min_price_index_step_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER min_target_profit_min_rub"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'action_pricing_enabled',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN action_pricing_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER min_price_index_step_enabled"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'target_profit_ranges_json',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN target_profit_ranges_json LONGTEXT NULL AFTER min_target_profit_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_feeds',
        'min_target_profit_ranges_json',
        "ALTER TABLE feedtools_ozon_price_feeds ADD COLUMN min_target_profit_ranges_json LONGTEXT NULL AFTER target_profit_ranges_json"
    );

    $defaultConnectionId = ozon_price_connection_default_id($cfg);
    if ($defaultConnectionId > 0) {
        $st = db()->prepare("UPDATE feedtools_ozon_price_feeds SET connection_id = ? WHERE connection_id = 0");
        $st->execute([$defaultConnectionId]);
    }

    if (!ozon_price_table_has_index('feedtools_ozon_price_feeds', 'idx_connection_updated')) {
        db()->exec("ALTER TABLE feedtools_ozon_price_feeds ADD KEY idx_connection_updated (connection_id, updated_at, id)");
    }
    if (!ozon_price_table_has_index('feedtools_ozon_price_feeds', 'idx_supplier')) {
        db()->exec("ALTER TABLE feedtools_ozon_price_feeds ADD KEY idx_supplier (supplier_id, connection_id, id)");
    }

    suppliers_migrate_from_price_feeds($cfg);

    $done = true;
}

function ozon_price_global_settings_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ozon_price_connections_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_price_tool_settings (
            connection_id BIGINT UNSIGNED NOT NULL,
            logistics_moscow_share_percent DECIMAL(10,2) NOT NULL DEFAULT 25.00,
            logistics_spb_share_percent DECIMAL(10,2) NOT NULL DEFAULT 25.00,
            logistics_regions_share_percent DECIMAL(10,2) NOT NULL DEFAULT 50.00,
            logistics_spb_multiplier_percent DECIMAL(10,2) NOT NULL DEFAULT 130.00,
            price_force_enabled TINYINT(1) NOT NULL DEFAULT 0,
            price_force_rules_text LONGTEXT NULL,
            price_force_category_rules_text LONGTEXT NULL,
            price_force_brand_rules_text LONGTEXT NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (connection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_tool_settings',
        'connection_id',
        "ALTER TABLE feedtools_ozon_price_tool_settings ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id"
    );

    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_tool_settings',
        'price_force_enabled',
        "ALTER TABLE feedtools_ozon_price_tool_settings ADD COLUMN price_force_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER logistics_spb_multiplier_percent"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_tool_settings',
        'price_force_rules_text',
        "ALTER TABLE feedtools_ozon_price_tool_settings ADD COLUMN price_force_rules_text LONGTEXT NULL AFTER price_force_enabled"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_tool_settings',
        'price_force_category_rules_text',
        "ALTER TABLE feedtools_ozon_price_tool_settings ADD COLUMN price_force_category_rules_text LONGTEXT NULL AFTER price_force_rules_text"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_tool_settings',
        'price_force_brand_rules_text',
        "ALTER TABLE feedtools_ozon_price_tool_settings ADD COLUMN price_force_brand_rules_text LONGTEXT NULL AFTER price_force_category_rules_text"
    );

    $defaultConnectionId = ozon_price_connection_default_id($cfg);
    if ($defaultConnectionId > 0) {
        $st = db()->prepare("UPDATE feedtools_ozon_price_tool_settings SET connection_id = ? WHERE connection_id = 0");
        $st->execute([$defaultConnectionId]);

        $st = db()->prepare("
            INSERT IGNORE INTO feedtools_ozon_price_tool_settings (
                connection_id,
                logistics_moscow_share_percent,
                logistics_spb_share_percent,
                logistics_regions_share_percent,
                logistics_spb_multiplier_percent,
                price_force_enabled,
                price_force_rules_text,
                price_force_category_rules_text,
                price_force_brand_rules_text
            )
            VALUES (?, 25.00, 25.00, 50.00, 130.00, 0, '', '', '')
        ");
        $st->execute([$defaultConnectionId]);
    }

    $idExistsSt = db()->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'feedtools_ozon_price_tool_settings'
          AND COLUMN_NAME = 'id'
    ");
    $idExistsSt->execute();
    if ((int)$idExistsSt->fetchColumn() > 0) {
        db()->exec("
            ALTER TABLE feedtools_ozon_price_tool_settings
            DROP PRIMARY KEY,
            DROP COLUMN id,
            ADD PRIMARY KEY (connection_id)
        ");
    } elseif (!ozon_price_table_has_index('feedtools_ozon_price_tool_settings', 'PRIMARY')) {
        db()->exec("ALTER TABLE feedtools_ozon_price_tool_settings ADD PRIMARY KEY (connection_id)");
    }

    if (ozon_price_table_has_index('feedtools_ozon_price_tool_settings', 'uniq_connection')) {
        db()->exec("ALTER TABLE feedtools_ozon_price_tool_settings DROP INDEX uniq_connection");
    }

    $done = true;
}

function ozon_price_tool_settings_default(): array
{
    return [
        'connection_id' => 0,
        'logistics_moscow_share_percent' => '25.00',
        'logistics_spb_share_percent' => '25.00',
        'logistics_regions_share_percent' => '50.00',
        'logistics_spb_multiplier_percent' => '130.00',
        'price_force_enabled' => '0',
        'price_force_rules_text' => '',
        'price_force_category_rules_text' => '',
        'price_force_brand_rules_text' => '',
    ];
}

function ozon_price_tool_settings_get(?int $connectionId = null, array $cfg = []): array
{
    ozon_price_global_settings_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    if ($connectionId <= 0) {
        return ozon_price_tool_settings_default();
    }
    $st = db()->prepare("SELECT * FROM feedtools_ozon_price_tool_settings WHERE connection_id = ? LIMIT 1");
    $st->execute([$connectionId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        $default = ozon_price_tool_settings_default();
        $default['connection_id'] = $connectionId;
        return $default;
    }
    return $row + ozon_price_tool_settings_default();
}

function ozon_price_tool_settings_save(array $input, ?string $actor, ?int $connectionId = null, array $cfg = []): void
{
    ozon_price_global_settings_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    if ($connectionId <= 0) {
        throw new RuntimeException('Не удалось определить подключение для сохранения настроек.');
    }
    $current = ozon_price_tool_settings_get($connectionId, $cfg);
    $normalize = static function (string $key, float $fallback = 0.0) use ($input, $current): float {
        $fallbackValue = array_key_exists($key, $current) ? (float)$current[$key] : $fallback;
        return max(0.0, (float)ozon_price_parse_decimal((string)($input[$key] ?? $fallbackValue)));
    };

    $moscow = $normalize('logistics_moscow_share_percent', 25.0);
    $spb = $normalize('logistics_spb_share_percent', 25.0);
    $regions = $normalize('logistics_regions_share_percent', 50.0);
    $multiplier = $normalize('logistics_spb_multiplier_percent', 130.0);
    $priceForceEnabled = array_key_exists('price_force_enabled', $input)
        ? (!empty($input['price_force_enabled']) ? 1 : 0)
        : (int)($current['price_force_enabled'] ?? 0);
    $priceForceRulesText = array_key_exists('price_force_rules_text', $input)
        ? trim((string)$input['price_force_rules_text'])
        : (string)($current['price_force_rules_text'] ?? '');
    $priceForceCategoryRulesText = array_key_exists('price_force_category_rules_text', $input)
        ? trim((string)$input['price_force_category_rules_text'])
        : (string)($current['price_force_category_rules_text'] ?? '');
    $priceForceBrandRulesText = array_key_exists('price_force_brand_rules_text', $input)
        ? trim((string)$input['price_force_brand_rules_text'])
        : (string)($current['price_force_brand_rules_text'] ?? '');

    $st = db()->prepare("
        INSERT INTO feedtools_ozon_price_tool_settings (
            connection_id,
            logistics_moscow_share_percent,
            logistics_spb_share_percent,
            logistics_regions_share_percent,
            logistics_spb_multiplier_percent,
            price_force_enabled,
            price_force_rules_text,
            price_force_category_rules_text,
            price_force_brand_rules_text,
            updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            logistics_moscow_share_percent = VALUES(logistics_moscow_share_percent),
            logistics_spb_share_percent = VALUES(logistics_spb_share_percent),
            logistics_regions_share_percent = VALUES(logistics_regions_share_percent),
            logistics_spb_multiplier_percent = VALUES(logistics_spb_multiplier_percent),
            price_force_enabled = VALUES(price_force_enabled),
            price_force_rules_text = VALUES(price_force_rules_text),
            price_force_category_rules_text = VALUES(price_force_category_rules_text),
            price_force_brand_rules_text = VALUES(price_force_brand_rules_text),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        $connectionId,
        $moscow,
        $spb,
        $regions,
        $multiplier,
        $priceForceEnabled,
        $priceForceRulesText,
        $priceForceCategoryRulesText,
        $priceForceBrandRulesText,
        $actor,
    ]);
}

function ozon_price_apply_global_settings(array $settings, ?int $connectionId = null, array $cfg = []): array
{
    static $cache = [];
    $cacheKey = (string)(($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg));
    if (!isset($cache[$cacheKey])) {
        $cache[$cacheKey] = ozon_price_tool_settings_get((int)$cacheKey, $cfg);
    }
    $global = $cache[$cacheKey];
    $settings += [
        'logistics_moscow_share_percent' => $global['logistics_moscow_share_percent'] ?? 25.00,
        'logistics_spb_share_percent' => $global['logistics_spb_share_percent'] ?? 25.00,
        'logistics_regions_share_percent' => $global['logistics_regions_share_percent'] ?? 50.00,
        'logistics_spb_multiplier_percent' => $global['logistics_spb_multiplier_percent'] ?? 130.00,
        'price_force_enabled' => $global['price_force_enabled'] ?? '0',
        'price_force_rules_text' => $global['price_force_rules_text'] ?? '',
        'price_force_category_rules_text' => $global['price_force_category_rules_text'] ?? '',
        'price_force_brand_rules_text' => $global['price_force_brand_rules_text'] ?? '',
    ];
    return $settings;
}

function ozon_price_automation_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ozon_price_connections_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_price_tool_automations (
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            automation_key VARCHAR(64) NOT NULL,
            title VARCHAR(190) NOT NULL,
            op_type VARCHAR(64) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            frequency_key VARCHAR(32) NOT NULL DEFAULT 'daily',
            run_hour_msk TINYINT UNSIGNED NOT NULL DEFAULT 3,
            run_minute_msk TINYINT UNSIGNED NOT NULL DEFAULT 30,
            feed_ids_json LONGTEXT NULL,
            params_json LONGTEXT NULL,
            last_run_at DATETIME NULL,
            last_run_msk_date DATE NULL,
            last_run_slot_key VARCHAR(64) NULL,
            last_op_id BIGINT UNSIGNED NULL,
            updated_by VARCHAR(190) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (automation_key),
            KEY idx_connection_key (connection_id, automation_key),
            KEY idx_connection_op_type (connection_id, op_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $pkRows = db()->query("SHOW INDEX FROM feedtools_ozon_price_tool_automations WHERE Key_name = 'PRIMARY'")->fetchAll() ?: [];
        $pkColumns = array_map(static fn(array $row): string => (string)($row['Column_name'] ?? ''), $pkRows);
        if ($pkColumns !== ['connection_id', 'automation_key']) {
            db()->exec("
                ALTER TABLE feedtools_ozon_price_tool_automations
                DROP PRIMARY KEY,
                ADD PRIMARY KEY (connection_id, automation_key)
            ");
        }
    } catch (Throwable $e) {
        // Leave existing structure as-is if the migration cannot be applied automatically.
    }

    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_tool_automations',
        'connection_id',
        "ALTER TABLE feedtools_ozon_price_tool_automations ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST"
    );

    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_tool_automations',
        'frequency_key',
        "ALTER TABLE feedtools_ozon_price_tool_automations ADD COLUMN frequency_key VARCHAR(32) NOT NULL DEFAULT 'daily' AFTER enabled"
    );
    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_tool_automations',
        'last_run_slot_key',
        "ALTER TABLE feedtools_ozon_price_tool_automations ADD COLUMN last_run_slot_key VARCHAR(64) NULL AFTER last_run_msk_date"
    );

    $defaultConnectionId = ozon_price_connection_default_id($cfg);
    if ($defaultConnectionId > 0) {
        $st = db()->prepare("
            INSERT IGNORE INTO feedtools_ozon_price_tool_automations (
                connection_id,
                automation_key,
                title,
                op_type,
                enabled,
                frequency_key,
                run_hour_msk,
                run_minute_msk,
                feed_ids_json,
                params_json,
                last_run_at,
                last_run_msk_date,
                last_run_slot_key,
                last_op_id,
                updated_by,
                created_at,
                updated_at
            )
            SELECT
                ?,
                legacy.automation_key,
                legacy.title,
                legacy.op_type,
                legacy.enabled,
                legacy.frequency_key,
                legacy.run_hour_msk,
                legacy.run_minute_msk,
                legacy.feed_ids_json,
                legacy.params_json,
                legacy.last_run_at,
                legacy.last_run_msk_date,
                legacy.last_run_slot_key,
                legacy.last_op_id,
                legacy.updated_by,
                legacy.created_at,
                legacy.updated_at
            FROM feedtools_ozon_price_tool_automations legacy
            WHERE legacy.connection_id = 0
        ");
        $st->execute([$defaultConnectionId]);

        $st = db()->prepare("
            DELETE legacy
            FROM feedtools_ozon_price_tool_automations legacy
            WHERE legacy.connection_id = 0
        ");
        $st->execute();
    }

    $defaults = [
        [
            'connection_id' => $defaultConnectionId,
            'automation_key' => 'ozon_sync_actions_nightly',
            'title' => 'Синхронизация акций Ozon',
            'op_type' => 'ozon_sync_actions',
            'enabled' => 1,
            'frequency_key' => 'daily',
            'run_hour_msk' => 3,
            'run_minute_msk' => 30,
            'feed_ids_json' => '',
            'params_json' => json_encode(['limit' => '100', 'sync_products' => '1', 'sync_candidates' => '1'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ],
    ];

    $st = db()->prepare("
        INSERT INTO feedtools_ozon_price_tool_automations (
            connection_id, automation_key, title, op_type, enabled, frequency_key, run_hour_msk, run_minute_msk, feed_ids_json, params_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            op_type = VALUES(op_type)
    ");
    foreach ($defaults as $row) {
        $st->execute([
            $row['connection_id'],
            $row['automation_key'],
            $row['title'],
            $row['op_type'],
            $row['enabled'],
            $row['frequency_key'],
            $row['run_hour_msk'],
            $row['run_minute_msk'],
            $row['feed_ids_json'],
            $row['params_json'],
        ]);
    }

    $done = true;
}

function ozon_price_automation_defaults(?int $connectionId = null, array $cfg = []): array
{
    ozon_price_automation_table_ensure($cfg);
    ozon_price_feeds_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $connection = null;
    try {
        $connection = $connectionId > 0 ? ozon_price_connection_resolve($connectionId, $cfg) : null;
    } catch (Throwable $e) {
        $connection = null;
    }
    $marketplace = (string)($connection['marketplace'] ?? 'ozon');
    $pushOpType = ozon_price_marketplace_push_op_type($marketplace);
    $defaults = [];

    if ($marketplace === 'ozon') {
        $defaults['ozon_sync_actions_nightly'] = [
            'connection_id' => $connectionId,
            'automation_key' => 'ozon_sync_actions_nightly',
            'title' => 'Синхронизация акций Ozon',
            'op_type' => 'ozon_sync_actions',
            'enabled' => '0',
            'frequency_key' => 'daily',
            'run_hour_msk' => '3',
            'run_minute_msk' => '30',
            'feed_ids_json' => '',
            'feed_ids' => [],
            'params_json' => json_encode(['limit' => '100', 'sync_products' => '1', 'sync_candidates' => '1'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_run_at' => null,
            'last_run_msk_date' => null,
            'last_run_slot_key' => null,
            'last_op_id' => null,
            'is_saved' => false,
        ];
    } elseif ($marketplace === 'wb') {
        $defaults['wb_sync_promotions_nightly'] = [
            'connection_id' => $connectionId,
            'automation_key' => 'wb_sync_promotions_nightly',
            'title' => 'Синхронизация акций WB',
            'op_type' => 'wb_sync_promotions',
            'enabled' => '0',
            'frequency_key' => 'daily',
            'run_hour_msk' => '3',
            'run_minute_msk' => '35',
            'feed_ids_json' => '',
            'feed_ids' => [],
            'params_json' => json_encode([
                'days_back' => '7',
                'days_ahead' => '45',
                'all_promo' => '0',
                'limit' => '1000',
                'sync_products' => '1',
                'sync_candidates' => '1',
                'danger_plan_discount_percent' => '60',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_run_at' => null,
            'last_run_msk_date' => null,
            'last_run_slot_key' => null,
            'last_op_id' => null,
            'is_saved' => false,
        ];
        $defaults['wb_import_promotion_xlsx_folder_hourly'] = [
            'connection_id' => $connectionId,
            'automation_key' => 'wb_import_promotion_xlsx_folder_hourly',
            'title' => 'Импорт XLSX автоакций WB из папки',
            'op_type' => 'wb_import_promotion_xlsx_folder',
            'enabled' => '0',
            'frequency_key' => 'hourly',
            'run_hour_msk' => '3',
            'run_minute_msk' => '40',
            'feed_ids_json' => '',
            'feed_ids' => [],
            'params_json' => json_encode([
                'inbox_dir' => wb_promotions_default_import_inbox_dir($connectionId),
                'max_files' => '20',
                'min_age_sec' => '10',
                'move_processed' => '1',
                'move_failed' => '1',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_run_at' => null,
            'last_run_msk_date' => null,
            'last_run_slot_key' => null,
            'last_op_id' => null,
            'is_saved' => false,
        ];
        $defaults['wb_download_promotion_xlsx_hourly'] = [
            'connection_id' => $connectionId,
            'automation_key' => 'wb_download_promotion_xlsx_hourly',
            'title' => 'Скачать и импортировать XLS автоакций WB',
            'op_type' => 'wb_download_promotion_xlsx',
            'enabled' => '0',
            'frequency_key' => 'hourly',
            'run_hour_msk' => '3',
            'run_minute_msk' => '45',
            'feed_ids_json' => '',
            'feed_ids' => [],
            'params_json' => json_encode([
                'inbox_dir' => wb_promotions_default_import_inbox_dir($connectionId),
                'days_ahead' => '45',
                'max_promotions' => '20',
                'only_auto' => '1',
                'import_after_download' => '1',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_run_at' => null,
            'last_run_msk_date' => null,
            'last_run_slot_key' => null,
            'last_op_id' => null,
            'is_saved' => false,
        ];
    }

    foreach (ozon_price_feed_list($connectionId, $cfg) as $feed) {
        $feedId = (int)($feed['id'] ?? 0);
        if ($feedId <= 0) {
            continue;
        }
        $key = ozon_price_feed_automation_key($feedId);
        $defaults[$key] = [
            'connection_id' => $connectionId,
            'automation_key' => $key,
            'title' => 'Обновление цен: ' . (string)($feed['name'] ?? ('Фид #' . $feedId)),
            'op_type' => $pushOpType,
            'enabled' => '0',
            'frequency_key' => $pushOpType === 'wb_push_selected_feeds' ? 'hourly' : 'daily',
            'run_hour_msk' => '4',
            'run_minute_msk' => '00',
            'feed_ids_json' => json_encode([$feedId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'feed_ids' => [$feedId],
            'feed_id' => $feedId,
            'feed_name' => (string)($feed['name'] ?? ''),
            'feed_cost_tag' => (string)($feed['cost_tag'] ?? ''),
            'feed_scheme' => (string)($feed['fulfillment_scheme'] ?? ''),
            'feed_supplier_code' => (string)($feed['supplier_code'] ?? ''),
            'params_json' => json_encode(
                $pushOpType === 'wb_push_selected_feeds'
                    ? ['force_refresh' => '1', 'push_all' => '1']
                    : [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'last_run_at' => null,
            'last_run_msk_date' => null,
            'last_run_slot_key' => null,
            'last_op_id' => null,
            'is_saved' => false,
        ];
    }

    return $defaults;
}

function ozon_price_feed_automation_key(int $feedId): string
{
    return 'ozon_push_feed_' . max(0, $feedId);
}

function ozon_price_automation_list(?int $connectionId = null, array $cfg = []): array
{
    ozon_price_automation_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $defaults = ozon_price_automation_defaults($connectionId, $cfg);
    $st = db()->prepare("SELECT * FROM feedtools_ozon_price_tool_automations WHERE connection_id = ? ORDER BY title ASC");
    $st->execute([$connectionId]);
    $rows = $st->fetchAll() ?: [];
    $result = [];
    foreach ($defaults as $key => $default) {
        $result[$key] = $default;
    }
    foreach ($rows as $row) {
        $key = (string)$row['automation_key'];
        if (!isset($defaults[$key])) {
            continue;
        }
        $row = $row + ($defaults[$key] ?? []);
        $row['is_saved'] = true;
        if (isset($defaults[$key]['title'])) {
            $row['title'] = $defaults[$key]['title'];
        }
        if (isset($defaults[$key]['op_type'])) {
            $row['op_type'] = $defaults[$key]['op_type'];
        }
        if (isset($defaults[$key]['feed_id'])) {
            $row['feed_id'] = $defaults[$key]['feed_id'];
            $row['feed_name'] = $defaults[$key]['feed_name'];
            $row['feed_cost_tag'] = $defaults[$key]['feed_cost_tag'];
            $row['feed_scheme'] = $defaults[$key]['feed_scheme'];
            $row['feed_supplier_code'] = $defaults[$key]['feed_supplier_code'];
            $row['feed_ids_json'] = $defaults[$key]['feed_ids_json'];
            $row['feed_ids'] = $defaults[$key]['feed_ids'];
        }
        $row['frequency_key'] = ozon_price_automation_frequency_normalize((string)($row['frequency_key'] ?? 'daily'));
        $feedIds = json_decode((string)($row['feed_ids_json'] ?? '[]'), true);
        $row['feed_ids'] = is_array($feedIds) ? array_values(array_unique(array_filter(array_map(static fn($v): int => (int)$v, $feedIds), static fn(int $v): bool => $v > 0))) : [];
        $result[$key] = $row;
    }
    return $result;
}

function ozon_price_automation_get(string $automationKey, ?int $connectionId = null, array $cfg = []): ?array
{
    $rows = ozon_price_automation_list($connectionId, $cfg);
    return $rows[$automationKey] ?? null;
}

function ozon_price_automation_save(array $input, ?string $actor, ?int $connectionId = null, array $cfg = []): void
{
    ozon_price_automation_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $defaults = ozon_price_automation_defaults($connectionId, $cfg);
    $knownKeys = array_keys($defaults);
    $st = db()->prepare("
        INSERT INTO feedtools_ozon_price_tool_automations (
            connection_id, automation_key, title, op_type, enabled, frequency_key, run_hour_msk, run_minute_msk, feed_ids_json, params_json, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled),
            frequency_key = VALUES(frequency_key),
            run_hour_msk = VALUES(run_hour_msk),
            run_minute_msk = VALUES(run_minute_msk),
            feed_ids_json = VALUES(feed_ids_json),
            params_json = VALUES(params_json),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($knownKeys as $key) {
        $default = $defaults[$key];
        $enabled = !empty($input[$key . '_enabled']) ? 1 : 0;
        $frequencyKey = ozon_price_automation_frequency_normalize((string)($input[$key . '_frequency_key'] ?? $default['frequency_key']));
        $runHour = max(0, min(23, (int)($input[$key . '_run_hour_msk'] ?? $default['run_hour_msk'])));
        $runMinute = max(0, min(59, (int)($input[$key . '_run_minute_msk'] ?? $default['run_minute_msk'])));
        $feedIds = [];
        if (isset($default['feed_id'])) {
            $feedIds = (array)($default['feed_ids'] ?? []);
        }
        $params = $default['params_json'];
        $st->execute([
            $connectionId,
            $key,
            $default['title'],
            $default['op_type'],
            $enabled,
            $frequencyKey,
            $runHour,
            $runMinute,
            json_encode($feedIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $params,
            $actor,
        ]);
    }
}

function ozon_price_automation_frequency_options(): array
{
    return [
        'daily' => ['label' => 'Раз в день', 'interval_minutes' => 1440],
        'hourly' => ['label' => 'Каждый час', 'interval_minutes' => 60],
        '3_per_day' => ['label' => '3 раза в день', 'interval_minutes' => 480],
        '4_per_day' => ['label' => '4 раза в день', 'interval_minutes' => 360],
        '6_per_day' => ['label' => '6 раз в день', 'interval_minutes' => 240],
    ];
}

function ozon_price_automation_frequency_normalize(string $value): string
{
    $value = trim($value);
    $options = ozon_price_automation_frequency_options();
    return isset($options[$value]) ? $value : 'daily';
}

function ozon_price_automation_frequency_label(string $value): string
{
    $value = ozon_price_automation_frequency_normalize($value);
    $options = ozon_price_automation_frequency_options();
    return (string)$options[$value]['label'];
}

function ozon_price_automation_slot_info(array $automation, DateTimeImmutable $nowMsk): array
{
    $frequencyKey = ozon_price_automation_frequency_normalize((string)($automation['frequency_key'] ?? 'daily'));
    $options = ozon_price_automation_frequency_options();
    $intervalMinutes = (int)($options[$frequencyKey]['interval_minutes'] ?? 1440);
    $runHour = max(0, min(23, (int)($automation['run_hour_msk'] ?? 0)));
    $runMinute = max(0, min(59, (int)($automation['run_minute_msk'] ?? 0)));
    $anchor = new DateTimeImmutable('2000-01-01 ' . sprintf('%02d:%02d:00', $runHour, $runMinute), new DateTimeZone('Europe/Moscow'));
    $diffSeconds = $nowMsk->getTimestamp() - $anchor->getTimestamp();
    if ($diffSeconds < 0) {
        $diffSeconds = 0;
    }
    $intervalSeconds = max(60, $intervalMinutes * 60);
    $slotIndex = (int)floor($diffSeconds / $intervalSeconds);
    $slotStart = $anchor->modify('+' . ($slotIndex * $intervalMinutes) . ' minutes');
    $slotEnd = $slotStart->modify('+' . $intervalMinutes . ' minutes');
    return [
        'frequency_key' => $frequencyKey,
        'interval_minutes' => $intervalMinutes,
        'slot_index' => $slotIndex,
        'slot_key' => $frequencyKey . ':' . $slotStart->format('Y-m-d H:i'),
        'slot_start' => $slotStart,
        'slot_end' => $slotEnd,
    ];
}

function ozon_price_automation_build_params(array $automation): array
{
    $params = json_decode((string)($automation['params_json'] ?? '{}'), true);
    if (!is_array($params)) {
        $params = [];
    }
    if (!empty($automation['connection_id'])) {
        $params['connection_id'] = (int)$automation['connection_id'];
    }
    $opType = (string)($automation['op_type'] ?? '');
    if (in_array($opType, ozon_price_feed_push_op_types(), true)) {
        $feedIds = $automation['feed_ids'] ?? json_decode((string)($automation['feed_ids_json'] ?? '[]'), true);
        if (!is_array($feedIds)) {
            $feedIds = [];
        }
        $feedIds = array_values(array_unique(array_filter(array_map(static fn($v): int => (int)$v, $feedIds), static fn(int $v): bool => $v > 0)));
        $params['feed_ids_json'] = json_encode($feedIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($opType === 'wb_push_selected_feeds') {
        $params['force_refresh'] = '1';
        $params['push_all'] = '1';
    }
    return $params;
}

function ozon_price_automation_run_due(callable $log, ?int $connectionId = null, array $cfg = []): array
{
    ozon_price_automation_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $automations = ozon_price_automation_list($connectionId, $cfg);
    $nowMsk = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
    $todayMsk = $nowMsk->format('Y-m-d');

    $summary = [
        'connection_id' => $connectionId,
        'now_msk' => $nowMsk->format('Y-m-d H:i:s'),
        'checked' => 0,
        'queued' => 0,
        'skipped' => 0,
        'items' => [],
    ];

    foreach ($automations as $automation) {
        $summary['checked']++;
        $key = (string)$automation['automation_key'];
        $item = [
            'automation_key' => $key,
            'title' => (string)($automation['title'] ?? $key),
            'status' => 'skipped',
            'message' => '',
            'op_id' => null,
        ];

        if (empty($automation['enabled'])) {
            $item['message'] = 'Выключено.';
            $summary['skipped']++;
            $summary['items'][] = $item;
            continue;
        }

        if (empty($automation['is_saved'])) {
            $item['message'] = 'Настройки автоматизации ещё не сохранены для этого подключения.';
            $summary['skipped']++;
            $summary['items'][] = $item;
            continue;
        }

        $slot = ozon_price_automation_slot_info($automation, $nowMsk);
        $item['frequency'] = ozon_price_automation_frequency_label((string)$slot['frequency_key']);
        $item['slot_start'] = $slot['slot_start']->format('Y-m-d H:i:s');
        $item['slot_end'] = $slot['slot_end']->format('Y-m-d H:i:s');

        if ((string)($automation['last_run_slot_key'] ?? '') === (string)$slot['slot_key']) {
            $item['message'] = 'В текущем интервале уже запускалось.';
            $item['op_id'] = !empty($automation['last_op_id']) ? (int)$automation['last_op_id'] : null;
            $summary['skipped']++;
            $summary['items'][] = $item;
            continue;
        }

        $params = ozon_price_automation_build_params($automation);
        if (in_array((string)($automation['op_type'] ?? ''), ozon_price_feed_push_op_types(), true)) {
            $feedIds = json_decode((string)($params['feed_ids_json'] ?? '[]'), true);
            if (!is_array($feedIds) || !$feedIds) {
                $item['message'] = 'Не выбраны фиды для автоматического обновления цен.';
                $summary['skipped']++;
                $summary['items'][] = $item;
                continue;
            }
        }

        $datasetId = feedtools_global_ops_dataset_id();
        $intervalSeconds = max(60, (int)($slot['interval_minutes'] ?? 1440) * 60);
        $duplicateLookbackSeconds = 6 * 3600;
        if ($intervalSeconds < $duplicateLookbackSeconds) {
            $duplicateLookbackSeconds = max(60, $intervalSeconds - 60);
        }
        $existing = ops_find_recent_duplicate($datasetId, (string)$automation['op_type'], $params, $duplicateLookbackSeconds, 'cron');
        if ($existing) {
            $opId = (int)($existing['id'] ?? 0);
            db()->prepare("
                UPDATE feedtools_ozon_price_tool_automations
                SET last_run_at = NOW(), last_run_msk_date = ?, last_run_slot_key = ?, last_op_id = ?, updated_at = CURRENT_TIMESTAMP
                WHERE connection_id = ? AND automation_key = ?
            ")->execute([$todayMsk, $slot['slot_key'], $opId, $connectionId, $key]);
            $item['status'] = 'duplicate';
            $item['message'] = 'Недавняя такая же операция уже есть.';
            $item['op_id'] = $opId;
            $summary['queued']++;
            $summary['items'][] = $item;
            $log("[{$key}] duplicate op #{$opId}\n");
            continue;
        }

        $opId = ops_create($datasetId, (string)$automation['op_type'], $params, 'cron');
        ops_append_log_tail($opId, "Queued by Ozon Price Tool automation.\n", 200000);
        db()->prepare("
            UPDATE feedtools_ozon_price_tool_automations
            SET last_run_at = NOW(), last_run_msk_date = ?, last_run_slot_key = ?, last_op_id = ?, updated_at = CURRENT_TIMESTAMP
            WHERE connection_id = ? AND automation_key = ?
        ")->execute([$todayMsk, $slot['slot_key'], $opId, $connectionId, $key]);
        $item['status'] = 'queued';
        $item['message'] = 'Операция поставлена в очередь.';
        $item['op_id'] = $opId;
        $summary['queued']++;
        $summary['items'][] = $item;
        $log("[{$key}] queued op #{$opId}\n");
    }

    return $summary;
}

function ozon_price_automation_summary_is_significant(array $summary): bool
{
    if ((int)($summary['queued'] ?? 0) > 0) {
        return true;
    }

    $items = is_array($summary['items'] ?? null) ? $summary['items'] : [];
    foreach ($items as $item) {
        $status = (string)($item['status'] ?? '');
        if (in_array($status, ['queued', 'duplicate', 'busy', 'error'], true)) {
            return true;
        }
    }

    return false;
}

function ozon_price_automation_run_log_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ozon_price_connections_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_price_tool_automation_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(64) NOT NULL DEFAULT 'cron',
            status VARCHAR(32) NOT NULL DEFAULT 'running',
            summary_json LONGTEXT NULL,
            log_text LONGTEXT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_connection_started (connection_id, started_at),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_tool_automation_runs',
        'connection_id',
        "ALTER TABLE feedtools_ozon_price_tool_automation_runs ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id"
    );

    $defaultConnectionId = ozon_price_connection_default_id($cfg);
    if ($defaultConnectionId > 0) {
        $st = db()->prepare("UPDATE feedtools_ozon_price_tool_automation_runs SET connection_id = ? WHERE connection_id = 0");
        $st->execute([$defaultConnectionId]);
    }

    if (!ozon_price_table_has_index('feedtools_ozon_price_tool_automation_runs', 'idx_connection_started')) {
        db()->exec("ALTER TABLE feedtools_ozon_price_tool_automation_runs ADD KEY idx_connection_started (connection_id, started_at)");
    }

    $done = true;
}

function ozon_price_automation_run_log_create(string $source = 'cron', ?int $connectionId = null, array $cfg = []): int
{
    ozon_price_automation_run_log_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $st = db()->prepare("
        INSERT INTO feedtools_ozon_price_tool_automation_runs (connection_id, source, status, log_text)
        VALUES (?, ?, 'running', '')
    ");
    $st->execute([$connectionId, $source]);
    return (int)db()->lastInsertId();
}

function ozon_price_automation_run_log_append(int $runId, string $message): void
{
    ozon_price_automation_run_log_table_ensure();
    $st = db()->prepare("
        UPDATE feedtools_ozon_price_tool_automation_runs
        SET log_text = CONCAT(COALESCE(log_text, ''), ?)
        WHERE id = ?
    ");
    $st->execute([$message, $runId]);
}

function ozon_price_automation_run_log_finish(int $runId, string $status, array $summary, array $cfg = []): void
{
    ozon_price_automation_run_log_table_ensure($cfg);
    $st = db()->prepare("
        UPDATE feedtools_ozon_price_tool_automation_runs
        SET status = ?, summary_json = ?, finished_at = NOW()
        WHERE id = ?
    ");
    $st->execute([
        $status,
        json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $runId,
    ]);
}

function ozon_price_automation_run_log_recent(int $limit = 20, ?int $connectionId = null, array $cfg = []): array
{
    ozon_price_automation_run_log_table_ensure($cfg);
    $limit = max(1, min(100, $limit));
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $st = db()->prepare("
        SELECT *
        FROM feedtools_ozon_price_tool_automation_runs
        WHERE connection_id = ?
        ORDER BY id DESC
        LIMIT {$limit}
    ");
    $st->execute([$connectionId]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $summary = json_decode((string)($row['summary_json'] ?? ''), true);
        $row['summary'] = is_array($summary) ? $summary : [];
    }
    unset($row);
    return $rows;
}

function ozon_price_push_log_table_ensure(array $cfg = []): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ozon_price_connections_table_ensure($cfg);

    db()->exec("
        CREATE TABLE IF NOT EXISTS feedtools_ozon_price_push_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actor VARCHAR(190) NULL DEFAULT NULL,
            offer_id VARCHAR(191) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            desired_state_json LONGTEXT NULL,
            result_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_connection_feed_created (connection_id, feed_id, created_at),
            KEY idx_offer_created (offer_id, created_at),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ozon_price_table_add_column_if_missing(
        'feedtools_ozon_price_push_log',
        'connection_id',
        "ALTER TABLE feedtools_ozon_price_push_log ADD COLUMN connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id"
    );

    $defaultConnectionId = ozon_price_connection_default_id($cfg);
    if ($defaultConnectionId > 0) {
        $st = db()->prepare("UPDATE feedtools_ozon_price_push_log SET connection_id = ? WHERE connection_id = 0");
        $st->execute([$defaultConnectionId]);
    }

    if (!ozon_price_table_has_index('feedtools_ozon_price_push_log', 'idx_connection_feed_created')) {
        db()->exec("ALTER TABLE feedtools_ozon_price_push_log ADD KEY idx_connection_feed_created (connection_id, feed_id, created_at)");
    }
    if (!ozon_price_table_has_index('feedtools_ozon_price_push_log', 'idx_created')) {
        db()->exec("ALTER TABLE feedtools_ozon_price_push_log ADD KEY idx_created (created_at)");
    }

    $done = true;
}

function ozon_price_push_log_cleanup_old(array $cfg = [], int $limit = 5000): int
{
    $cfg = $cfg ?: ((isset($GLOBALS['cfg']) && is_array($GLOBALS['cfg'])) ? $GLOBALS['cfg'] : []);
    $days = (int)($cfg['retention']['price_push_days'] ?? 1);
    $days = max(1, min(3650, $days));
    $limit = max(1, min(50000, $limit));
    ozon_price_push_log_table_ensure($cfg);
    $st = db()->prepare("
        DELETE FROM feedtools_ozon_price_push_log
        WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)
        LIMIT {$limit}
    ");
    $st->execute();
    return (int)$st->rowCount();
}

function ozon_price_push_log_write(int $feedId, ?string $actor, string $offerId, int $productId, string $status, array $desiredState, array $result, ?int $connectionId = null, array $cfg = []): int
{
    ozon_price_push_log_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $st = db()->prepare("
        INSERT INTO feedtools_ozon_price_push_log (
            connection_id, feed_id, actor, offer_id, product_id, status, desired_state_json, result_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
        $connectionId,
        $feedId,
        $actor,
        $offerId,
        $productId,
        $status,
        json_encode($desiredState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $id = (int)db()->lastInsertId();

    static $cleanupDone = false;
    if (!$cleanupDone) {
        $cleanupDone = true;
        try {
            ozon_price_push_log_cleanup_old($cfg);
        } catch (Throwable $e) {
            // Очистка истории пушей не должна мешать самой отправке цен.
        }
    }

    return $id;
}

function ozon_price_push_log_recent_for_feed(int $feedId, int $limit = 10, ?int $connectionId = null, array $cfg = []): array
{
    ozon_price_push_log_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $st = db()->prepare("
        SELECT *
        FROM feedtools_ozon_price_push_log
        WHERE connection_id = ? AND feed_id = ?
        ORDER BY id DESC
        LIMIT " . max(1, (int)$limit)
    );
    $st->execute([$connectionId, $feedId]);
    return $st->fetchAll() ?: [];
}

function ozon_price_force_rules_parse(string $raw, string $targetType = 'offer'): array
{
    $targetType = in_array($targetType, ['offer', 'category', 'brand'], true) ? $targetType : 'offer';
    $rules = [];
    foreach (preg_split('~\R+~u', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('~^(.+?)\s*=\s*([+-]?\d+(?:[.,]\d+)?)\s*(%?)\s*$~u', $line, $m)) {
            continue;
        }
        $target = trim((string)$m[1]);
        $valueRaw = str_replace(',', '.', (string)$m[2]);
        $value = (float)$valueRaw;
        $hasPercent = ((string)$m[3]) === '%';
        $hasSign = str_starts_with($valueRaw, '+') || str_starts_with($valueRaw, '-');
        if ($target === '') {
            continue;
        }
        if ($hasPercent && !$hasSign) {
            continue;
        }
        if ($hasPercent) {
            $mode = 'delta_percent';
            $label = ($value >= 0 ? '+' : '') . number_format($value, 2, '.', '') . '%';
        } elseif ($hasSign) {
            $mode = 'delta_fixed';
            $label = ($value >= 0 ? '+' : '') . number_format($value, 2, '.', '') . ' ₽';
        } else {
            $mode = 'set_fixed';
            $label = '= ' . number_format($value, 2, '.', '') . ' ₽';
        }
        $rules[$target] = [
            'offer_id' => $targetType === 'offer' ? $target : '',
            'target' => $target,
            'target_type' => $targetType,
            'mode' => $mode,
            'value' => round($value, 2),
            'label' => $label,
            'source_line' => $line,
        ];
    }
    return $rules;
}

function ozon_price_force_normalize_match_key(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace('ё', 'е', mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('~\s+~u', ' ', $value) ?? $value;
    return trim($value);
}

function ozon_price_force_rules_normalized_index(array $rules): array
{
    $index = [];
    foreach ($rules as $target => $rule) {
        $key = ozon_price_force_normalize_match_key((string)$target);
        if ($key === '') {
            continue;
        }
        $index[$key] = $rule;
    }
    return $index;
}

function ozon_price_force_attach_match(array $rule, string $matchedBy, string $matchedValue): array
{
    $rule['matched_by'] = $matchedBy;
    $rule['matched_value'] = $matchedValue;
    return $rule;
}

function ozon_price_fbo_rule_is_active_candidate(?array $rule): bool
{
    if (!is_array($rule) || (int)($rule['id'] ?? 0) <= 0) {
        return false;
    }
    return strtolower(trim((string)($rule['status'] ?? ''))) === 'active'
        && (float)($rule['target_price'] ?? 0) > 0;
}

function ozon_price_fbo_stock_is_fresh(string $lastRefreshedAt, int $freshSeconds): bool
{
    if ($lastRefreshedAt === '') {
        return false;
    }
    $lastTs = strtotime($lastRefreshedAt);
    return $lastTs !== false && $lastTs >= time() - $freshSeconds;
}

function ozon_price_fbo_stock_rows_by_offer(int $connectionId, array $offerIds): array
{
    if ($connectionId <= 0 || !$offerIds || !ozon_price_table_exists('feedtools_ozon_fbo_items')) {
        return [];
    }
    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if (!$offerIds) {
        return [];
    }

    $out = [];
    foreach (array_chunk($offerIds, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("
            SELECT
                i.offer_id,
                CASE
                  WHEN COALESCE(c.marketplace, 'ozon') = 'wb' THEN COALESCE(i.fbo_present, 0)
                  ELSE COALESCE(i.fbo_present, 0) + COALESCE(i.fbo_reserved, 0)
                END AS fbo_units,
                i.last_refreshed_at
            FROM feedtools_ozon_fbo_items i
            LEFT JOIN feedtools_marketplace_connections c
              ON c.id = i.connection_id
            WHERE i.connection_id = ?
              AND i.offer_id IN ({$placeholders})
              AND (COALESCE(c.marketplace, 'ozon') <> 'wb' OR i.warehouse_key = 'wb:fbo')
            ORDER BY
              CASE WHEN COALESCE(c.marketplace, 'ozon') = 'wb' AND i.warehouse_key = 'wb:fbo' THEN 0 ELSE 1 END ASC,
              i.last_refreshed_at DESC
        ");
        $st->execute(array_merge([$connectionId], $chunk));
        foreach ($st->fetchAll() ?: [] as $row) {
            $offerId = trim((string)($row['offer_id'] ?? ''));
            if ($offerId !== '') {
                $out[$offerId] = $row;
            }
        }
    }
    return $out;
}

function ozon_price_refresh_fbo_force_rules_for_offers(int $connectionId, array $offerIds, array $cfg = [], ?callable $log = null): array
{
    $summary = [
        'active_rules' => 0,
        'refresh_needed' => 0,
        'refreshed' => 0,
        'fbo_active' => 0,
        'rules_annulled' => 0,
        'error' => '',
    ];

    $offerIds = array_values(array_filter(array_unique(array_map(
        static fn($value): string => trim((string)$value),
        $offerIds
    ))));
    if ($connectionId <= 0 || !$offerIds) {
        return $summary;
    }

    try {
        require_once __DIR__ . '/ozon_fbo_tool.php';
        if (!function_exists('ozon_fbo_tool_price_rules_by_offer') || !function_exists('ozon_fbo_tool_refresh_offer_stocks')) {
            return $summary;
        }
        if (function_exists('ozon_fbo_tool_tables_ensure')) {
            ozon_fbo_tool_tables_ensure($cfg);
        }

        $rules = ozon_fbo_tool_price_rules_by_offer($connectionId, $offerIds, $cfg);
        $activeOfferIds = [];
        foreach ($offerIds as $offerId) {
            $rule = is_array($rules[$offerId] ?? null) ? (array)$rules[$offerId] : null;
            if (ozon_price_fbo_rule_is_active_candidate($rule)) {
                $activeOfferIds[] = $offerId;
            }
        }
        $summary['active_rules'] = count($activeOfferIds);
        if (!$activeOfferIds) {
            return $summary;
        }

        $freshSeconds = max(60, (int)($cfg['ozon_fbo_tool']['stock_fresh_seconds'] ?? $cfg['fbo_stock_fresh_seconds'] ?? 1800));
        $stockRows = ozon_price_fbo_stock_rows_by_offer($connectionId, $activeOfferIds);
        $refreshOfferIds = [];
        foreach ($activeOfferIds as $offerId) {
            $row = is_array($stockRows[$offerId] ?? null) ? (array)$stockRows[$offerId] : null;
            $units = is_array($row) ? max(0, (int)($row['fbo_units'] ?? 0)) : 0;
            $fresh = is_array($row)
                && ozon_price_fbo_stock_is_fresh(trim((string)($row['last_refreshed_at'] ?? '')), $freshSeconds);
            if ($units <= 0 || !$fresh) {
                $refreshOfferIds[] = $offerId;
            }
        }
        $summary['refresh_needed'] = count($refreshOfferIds);
        if (!$refreshOfferIds) {
            return $summary;
        }

        $log = $log ?: static function (string $line): void {};
        $log('[fbo price guard] refreshing active FBO rules: offers=' . count($refreshOfferIds) . "\n");
        $refresh = ozon_fbo_tool_refresh_offer_stocks($connectionId, $refreshOfferIds, $cfg, $log);
        $summary['refreshed'] = (int)($refresh['requested'] ?? 0);
        $summary['fbo_active'] = (int)($refresh['fbo_active'] ?? 0);
        $summary['rules_annulled'] = (int)($refresh['rules_annulled'] ?? 0);
    } catch (Throwable $e) {
        $summary['error'] = $e->getMessage();
        if ($log) {
            $log('[fbo price guard] refresh failed: ' . $e->getMessage() . "\n");
        }
    }

    return $summary;
}

function ozon_price_fbo_force_rule_for_offer(string $offerId, ?int $connectionId = null, array $cfg = []): ?array
{
    static $refreshAttempted = [];

    $offerId = trim($offerId);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    if ($offerId === '' || $connectionId <= 0) {
        return null;
    }

    require_once __DIR__ . '/ozon_fbo_tool.php';
    if (!function_exists('ozon_fbo_tool_force_rule_for_offer') || !function_exists('ozon_fbo_tool_price_rules_by_offer')) {
        return null;
    }

    try {
        if (function_exists('ozon_fbo_tool_tables_ensure')) {
            ozon_fbo_tool_tables_ensure($cfg);
        }

        $rules = ozon_fbo_tool_price_rules_by_offer($connectionId, [$offerId], $cfg);
        $rule = is_array($rules[$offerId] ?? null) ? (array)$rules[$offerId] : null;
        if (!ozon_price_fbo_rule_is_active_candidate($rule)) {
            return null;
        }

        $freshSeconds = max(60, (int)($cfg['ozon_fbo_tool']['stock_fresh_seconds'] ?? $cfg['fbo_stock_fresh_seconds'] ?? 1800));
        $stockRows = ozon_price_fbo_stock_rows_by_offer($connectionId, [$offerId]);
        $row = is_array($stockRows[$offerId] ?? null) ? (array)$stockRows[$offerId] : null;
        $units = is_array($row) ? max(0, (int)($row['fbo_units'] ?? 0)) : 0;
        $fresh = is_array($row)
            && ozon_price_fbo_stock_is_fresh(trim((string)($row['last_refreshed_at'] ?? '')), $freshSeconds);

        $cacheKey = $connectionId . ':' . $offerId;
        if (($units <= 0 || !$fresh) && empty($refreshAttempted[$cacheKey])) {
            $refreshAttempted[$cacheKey] = true;
            ozon_price_refresh_fbo_force_rules_for_offers($connectionId, [$offerId], $cfg);
            $rules = ozon_fbo_tool_price_rules_by_offer($connectionId, [$offerId], $cfg);
            $rule = is_array($rules[$offerId] ?? null) ? (array)$rules[$offerId] : null;
            if (!ozon_price_fbo_rule_is_active_candidate($rule)) {
                return null;
            }
            $stockRows = ozon_price_fbo_stock_rows_by_offer($connectionId, [$offerId]);
            $row = is_array($stockRows[$offerId] ?? null) ? (array)$stockRows[$offerId] : null;
            $units = is_array($row) ? max(0, (int)($row['fbo_units'] ?? 0)) : 0;
        }

        return ozon_fbo_tool_force_rule_for_offer($connectionId, $offerId, $units, $cfg);
    } catch (Throwable $e) {
        return null;
    }
}

function ozon_price_force_rule_for_offer(string $offerId, ?int $connectionId = null, array $cfg = [], array $offerContext = []): ?array
{
    static $cache = null;
    $cacheKey = (string)(($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg));
    if (!is_array($cache)) {
        $cache = [];
    }
    if (!isset($cache[$cacheKey])) {
        $global = ozon_price_tool_settings_get((int)$cacheKey, $cfg);
        $categoryRules = ozon_price_force_rules_parse((string)($global['price_force_category_rules_text'] ?? ''), 'category');
        $brandRules = ozon_price_force_rules_parse((string)($global['price_force_brand_rules_text'] ?? ''), 'brand');
        $cache[$cacheKey] = [
            'enabled' => !empty($global['price_force_enabled']),
            'offer_rules' => ozon_price_force_rules_parse((string)($global['price_force_rules_text'] ?? ''), 'offer'),
            'category_rules' => $categoryRules,
            'category_rules_norm' => ozon_price_force_rules_normalized_index($categoryRules),
            'brand_rules' => $brandRules,
            'brand_rules_norm' => ozon_price_force_rules_normalized_index($brandRules),
        ];
    }
    if (empty($cache[$cacheKey]['enabled'])) {
        return null;
    }
    if (isset($cache[$cacheKey]['offer_rules'][$offerId])) {
        return ozon_price_force_attach_match($cache[$cacheKey]['offer_rules'][$offerId], 'offer_id', $offerId);
    }

    $categoryCandidates = [];
    foreach (['category_id', 'category_path', 'category_name'] as $field) {
        $value = trim((string)($offerContext[$field] ?? ''));
        if ($value !== '') {
            $categoryCandidates[] = $value;
        }
    }
    foreach (array_values(array_unique($categoryCandidates)) as $candidate) {
        if (isset($cache[$cacheKey]['category_rules'][$candidate])) {
            return ozon_price_force_attach_match($cache[$cacheKey]['category_rules'][$candidate], 'category', $candidate);
        }
        $norm = ozon_price_force_normalize_match_key($candidate);
        if ($norm !== '' && isset($cache[$cacheKey]['category_rules_norm'][$norm])) {
            return ozon_price_force_attach_match($cache[$cacheKey]['category_rules_norm'][$norm], 'category', $candidate);
        }
    }

    $brandCandidates = [];
    foreach (['brand', 'vendor'] as $field) {
        $value = trim((string)($offerContext[$field] ?? ''));
        if ($value !== '') {
            $brandCandidates[] = $value;
        }
    }
    foreach (array_values(array_unique($brandCandidates)) as $candidate) {
        if (isset($cache[$cacheKey]['brand_rules'][$candidate])) {
            return ozon_price_force_attach_match($cache[$cacheKey]['brand_rules'][$candidate], 'brand', $candidate);
        }
        $norm = ozon_price_force_normalize_match_key($candidate);
        if ($norm !== '' && isset($cache[$cacheKey]['brand_rules_norm'][$norm])) {
            return ozon_price_force_attach_match($cache[$cacheKey]['brand_rules_norm'][$norm], 'brand', $candidate);
        }
    }

    return null;
}

function ozon_price_tool_cache_dir(): string
{
    return dirname(__DIR__) . '/storage/cache';
}

function ozon_price_tool_cache_path(string $cacheKey): string
{
    return ozon_price_tool_cache_dir() . '/ozon_price_tool_' . preg_replace('~[^0-9A-Za-z_.-]+~', '_', $cacheKey) . '.json';
}

function ozon_price_tool_cache_read(string $cacheKey, int $ttlSeconds = 300): ?array
{
    $path = ozon_price_tool_cache_path($cacheKey);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return null;
    }
    $ts = (int)($data['ts'] ?? 0);
    if ($ttlSeconds > 0 && $ts > 0 && (time() - $ts) > $ttlSeconds) {
        return null;
    }
    return is_array($data['payload'] ?? null) ? $data['payload'] : null;
}

function ozon_price_tool_cache_write(string $cacheKey, array $payload): void
{
    $dir = ozon_price_tool_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(ozon_price_tool_cache_path($cacheKey), json_encode([
        'ts' => time(),
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function ozon_price_table_exists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $st = db()->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    $cache[$table] = (int)$st->fetchColumn() > 0;
    return $cache[$table];
}

function ozon_price_force_rule_options_from_supplier_db(int $supplierId): array
{
    static $cache = [];
    if (isset($cache[$supplierId])) {
        return $cache[$supplierId];
    }
    if ($supplierId <= 0 || !ozon_price_table_exists('feedtools_supplier_products')) {
        return ['categories' => [], 'brands' => []];
    }

    $categories = [];
    $brands = [];

    $st = db()->prepare("
        SELECT category_id, category_path, COUNT(*) AS qty
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
          AND (category_id <> '' OR (category_path IS NOT NULL AND category_path <> ''))
        GROUP BY category_id, category_path
        ORDER BY category_path ASC, category_id ASC
        LIMIT 2000
    ");
    $st->execute([$supplierId]);
    foreach ($st->fetchAll() ?: [] as $row) {
        $categoryId = trim((string)($row['category_id'] ?? ''));
        $categoryPath = trim((string)($row['category_path'] ?? ''));
        $target = $categoryPath !== '' ? $categoryPath : $categoryId;
        if ($target === '') {
            continue;
        }
        $categories[$target] = [
            'value' => $target,
            'label' => $target,
            'id' => $categoryId,
            'count' => (int)($row['qty'] ?? 0),
        ];
    }

    $st = db()->prepare("
        SELECT brand, COUNT(*) AS qty
        FROM feedtools_supplier_products
        WHERE supplier_id = ?
          AND brand <> ''
        GROUP BY brand
        ORDER BY brand ASC
        LIMIT 2000
    ");
    $st->execute([$supplierId]);
    foreach ($st->fetchAll() ?: [] as $row) {
        $brand = trim((string)($row['brand'] ?? ''));
        if ($brand === '') {
            continue;
        }
        $brands[$brand] = [
            'value' => $brand,
            'label' => $brand,
            'count' => (int)($row['qty'] ?? 0),
        ];
    }

    $cache[$supplierId] = [
        'categories' => array_values($categories),
        'brands' => array_values($brands),
    ];
    return $cache[$supplierId];
}

function ozon_price_parse_feed_force_rule_options(string $xmlPath): array
{
    $reader = new XMLReader();
    if (!$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Не удалось открыть XML-фид поставщика.');
    }

    $categoryMap = [];
    $categoryCounts = [];
    $brandCounts = [];
    $inOffer = false;
    $offerDepth = -1;
    $currentCategoryId = '';
    $currentBrand = '';
    $currentVendor = '';

    try {
        while ($reader->read()) {
            if (!$inOffer && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'category') {
                $categoryId = trim((string)$reader->getAttribute('id'));
                if ($categoryId !== '') {
                    $categoryMap[$categoryId] = [
                        'id' => $categoryId,
                        'name' => trim((string)$reader->readString()),
                        'parentId' => trim((string)$reader->getAttribute('parentId')),
                    ];
                }
                continue;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'offer') {
                $inOffer = true;
                $offerDepth = $reader->depth;
                $currentCategoryId = '';
                $currentBrand = '';
                $currentVendor = '';
                continue;
            }
            if ($inOffer && $reader->nodeType === XMLReader::ELEMENT && ($reader->name === 'categoryId' || $reader->name === 'category')) {
                $value = $reader->isEmptyElement ? '' : trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($currentCategoryId === '' && $value !== '') {
                    $currentCategoryId = $value;
                }
                continue;
            }
            if ($inOffer && $reader->nodeType === XMLReader::ELEMENT && ($reader->name === 'brand' || $reader->name === 'vendor')) {
                $value = $reader->isEmptyElement ? '' : trim(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($reader->name === 'brand') {
                    $currentBrand = $value;
                } elseif ($currentVendor === '') {
                    $currentVendor = $value;
                }
                continue;
            }
            if ($inOffer && $reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $offerDepth) {
                if ($currentCategoryId !== '') {
                    $categoryCounts[$currentCategoryId] = (int)($categoryCounts[$currentCategoryId] ?? 0) + 1;
                }
                $brand = $currentBrand !== '' ? $currentBrand : $currentVendor;
                if ($brand !== '') {
                    $brandCounts[$brand] = (int)($brandCounts[$brand] ?? 0) + 1;
                }
                $inOffer = false;
                $offerDepth = -1;
            }
        }
    } finally {
        $reader->close();
    }

    $categories = [];
    foreach ($categoryCounts as $categoryId => $count) {
        $categoryId = trim((string)$categoryId);
        if ($categoryId === '') {
            continue;
        }
        $path = ozon_price_feed_build_category_path($categoryId, $categoryMap);
        $target = $path !== '' ? $path : $categoryId;
        $categories[] = [
            'value' => $target,
            'label' => $target,
            'id' => $categoryId,
            'count' => (int)$count,
        ];
    }
    usort($categories, static fn(array $a, array $b): int => strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? '')));

    $brands = [];
    foreach ($brandCounts as $brand => $count) {
        $brand = trim((string)$brand);
        if ($brand !== '') {
            $brands[] = ['value' => $brand, 'label' => $brand, 'count' => (int)$count];
        }
    }
    usort($brands, static fn(array $a, array $b): int => strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? '')));

    return ['categories' => $categories, 'brands' => $brands];
}

function ozon_price_force_rule_options_for_feed(array $feed, array $cfg = [], bool $allowRemoteRefresh = true): array
{
    $supplierId = (int)($feed['supplier_id'] ?? 0);
    $fromDb = ozon_price_force_rule_options_from_supplier_db($supplierId);
    if (!empty($fromDb['categories']) || !empty($fromDb['brands'])) {
        return $fromDb;
    }

    $feedUrl = trim((string)($feed['feed_url'] ?? ''));
    if ($feedUrl === '') {
        return ['categories' => [], 'brands' => []];
    }

    $cacheKey = 'force_rule_options_' . sha1(json_encode([
        'supplier_id' => $supplierId,
        'feed_url' => $feedUrl,
        'supplier_code' => (string)($feed['supplier_code'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cached = ozon_price_tool_cache_read($cacheKey, 3600);
    if (is_array($cached)) {
        return [
            'categories' => array_values(array_filter((array)($cached['categories'] ?? []), 'is_array')),
            'brands' => array_values(array_filter((array)($cached['brands'] ?? []), 'is_array')),
        ];
    }
    if (!$allowRemoteRefresh) {
        $stale = ozon_price_tool_cache_read($cacheKey, 0);
        if (is_array($stale)) {
            return [
                'categories' => array_values(array_filter((array)($stale['categories'] ?? []), 'is_array')),
                'brands' => array_values(array_filter((array)($stale['brands'] ?? []), 'is_array')),
            ];
        }
        return ['categories' => [], 'brands' => []];
    }

    try {
        $download = ozon_price_feed_fetch_remote_xml($feedUrl);
        try {
            $options = ozon_price_parse_feed_force_rule_options((string)$download['path']);
        } finally {
            @unlink((string)$download['path']);
        }
        ozon_price_tool_cache_write($cacheKey, $options);
        return $options;
    } catch (Throwable $e) {
        return ['categories' => [], 'brands' => []];
    }
}

function ozon_price_force_rule_options_for_feeds(array $feeds, array $cfg = [], bool $allowRemoteRefresh = true): array
{
    $categories = [];
    $brands = [];

    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $feedName = trim((string)($feed['name'] ?? ''));
        $supplierName = trim((string)($feed['supplier_name'] ?? ''));
        $sourceName = $supplierName !== '' ? $supplierName : ($feedName !== '' ? $feedName : ('feed #' . (int)($feed['id'] ?? 0)));
        $options = ozon_price_force_rule_options_for_feed($feed, $cfg, $allowRemoteRefresh);

        foreach ((array)($options['categories'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = trim((string)($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (!isset($categories[$value])) {
                $categories[$value] = [
                    'value' => $value,
                    'label' => trim((string)($option['label'] ?? $value)) ?: $value,
                    'id' => trim((string)($option['id'] ?? '')),
                    'count' => 0,
                    'sources' => [],
                ];
            }
            $categories[$value]['count'] += (int)($option['count'] ?? 0);
            if ($sourceName !== '') {
                $categories[$value]['sources'][$sourceName] = true;
            }
            $id = trim((string)($option['id'] ?? ''));
            if ($id !== '' && trim((string)($categories[$value]['id'] ?? '')) === '') {
                $categories[$value]['id'] = $id;
            }
        }

        foreach ((array)($options['brands'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = trim((string)($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $key = ozon_price_force_normalize_match_key($value);
            if ($key === '') {
                continue;
            }
            if (!isset($brands[$key])) {
                $brands[$key] = [
                    'value' => $value,
                    'label' => trim((string)($option['label'] ?? $value)) ?: $value,
                    'count' => 0,
                    'sources' => [],
                ];
            }
            $brands[$key]['count'] += (int)($option['count'] ?? 0);
            if ($sourceName !== '') {
                $brands[$key]['sources'][$sourceName] = true;
            }
        }
    }

    $prepare = static function (array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $sources = array_keys((array)($row['sources'] ?? []));
            sort($sources, SORT_NATURAL | SORT_FLAG_CASE);
            $row['sources'] = $sources;
            $row['source_label'] = count($sources) <= 2
                ? implode(', ', $sources)
                : ($sources[0] . ', ' . $sources[1] . ' +' . (count($sources) - 2));
            $out[] = $row;
        }
        usort($out, static fn(array $a, array $b): int => strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? '')));
        return $out;
    };

    return [
        'categories' => $prepare($categories),
        'brands' => $prepare($brands),
    ];
}

function ozon_price_apply_force_rule(float $price, array $rule): float
{
    $mode = (string)($rule['mode'] ?? '');
    $value = (float)($rule['value'] ?? 0);
    $result = $price;
    if ($mode === 'set_fixed') {
        $result = $value;
    } elseif ($mode === 'delta_fixed') {
        $result = $price + $value;
    } elseif ($mode === 'delta_percent') {
        $result = $price * (1 + ($value / 100.0));
    }
    return round(max(0.0, $result), 2);
}

function ozon_price_table_add_column_if_missing(string $table, string $column, string $alterSql): void
{
    $st = db()->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    db()->exec($alterSql);
}

function ozon_price_table_has_index(string $table, string $indexName): bool
{
    $st = db()->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $st->execute([$table, $indexName]);
    return (int)$st->fetchColumn() > 0;
}

function ozon_price_parse_decimal(string $value): float
{
    $normalized = trim(str_replace([' ', ','], ['', '.'], $value));
    if ($normalized === '') {
        return 0.0;
    }
    if (!preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
        return 0.0;
    }
    return (float)$normalized;
}

function ozon_price_feed_default(): array
{
    return [
        'id' => 0,
        'connection_id' => 0,
        'supplier_id' => 0,
        'supplier_name' => '',
        'supplier_feed_url' => '',
        'supplier_is_active' => 1,
        'supplier_is_archived' => 0,
        'name' => '',
        'feed_url' => '',
        'cost_tag' => '',
        'supplier_code' => '',
        'fulfillment_scheme' => 'fbs',
        'wb_commission_percent' => '0.00',
        'wb_discount_percent' => '0.00',
        'wb_club_discount_percent' => '0.00',
        'wb_promotion_pricing_enabled' => '0',
        'wb_promotion_max_plan_discount_percent' => '60.00',
        'wb_promotion_min_margin_percent' => '5.00',
        'wb_future_promo_discount_mode' => 'auto',
        'wb_future_promo_discount_percent' => '0.00',
        'wb_future_promo_discount_buffer_percent' => '2.00',
        'wb_future_promo_prepare_days' => '0',
        'wb_promotion_action_upload_enabled' => '1',
        'wb_expenses_mode' => 'api',
        'wb_tariff_warehouse_name' => '',
        'yandex_expenses_mode' => 'api',
        'yandex_payment_frequency' => 'DAILY',
        'yandex_payment_delay_weeks' => '0',
        'yandex_boost_bid_enabled' => '0',
        'yandex_boost_bid_percent' => '0.00',
        'target_profit_percent' => '20.00',
        'min_target_profit_percent' => '10.00',
        'target_profit_min_rub' => '0.00',
        'min_target_profit_min_rub' => '0.00',
        'min_price_index_step_enabled' => '0',
        'action_pricing_enabled' => '1',
        'target_profit_ranges_json' => '',
        'min_target_profit_ranges_json' => '',
        'target_profit_ranges' => [],
        'min_target_profit_ranges' => [],
        'rounding_mode' => 'rub',
        'price_modifier_mode' => 'none',
        'price_modifier_value' => '0.00',
        'price_modifier_min_mode' => 'none',
        'price_modifier_min_value' => '0.00',
        'fulfillment_markup_rub' => '0.00',
        'fulfillment_markup_percent' => '0.00',
        'shipment_processing_rub' => '0.00',
        'nonbuyout_processing_rub' => '50.00',
        'return_processing_rub' => '50.00',
        'ship_0_12_percent' => '0.00',
        'ship_12_24_percent' => '0.00',
        'ship_24_36_percent' => '0.00',
        'ship_36_48_percent' => '0.00',
        'ship_48_plus_percent' => '0.00',
        'nonbuyout_percent' => '0.00',
        'return_resellable_percent' => '0.00',
        'return_nonresellable_percent' => '0.00',
        'return_percent' => '0.00',
        'include_returns_in_cost' => '0',
        'promotion_percent' => '0.00',
        'credit_percent' => '0.00',
        'delayed_shipment_percent' => '0.00',
        'delayed_shipment_min_rub' => '0.00',
        'extra_expenses_percent' => '0.00',
        'strike_discount_percent' => '0.00',
        'tax_mode' => 'none',
        'tax_percent' => '0.00',
        'vat_percent' => '0.00',
        'profit_tax_percent' => '0.00',
        'insurance_percent' => '0.00',
    ];
}

function ozon_price_feed_hydrate_supplier(array $row, array $cfg = []): array
{
    $row = array_replace(ozon_price_feed_default(), $row);
    $supplierId = (int)($row['supplier_id'] ?? 0);
    if ($supplierId <= 0) {
        return $row;
    }

    $supplier = suppliers_get($supplierId, $cfg);
    if (!is_array($supplier)) {
        return $row;
    }

    $supplierName = trim((string)($supplier['name'] ?? ''));
    $supplierFeedUrl = trim((string)($supplier['feed_url'] ?? ''));
    $supplierCode = trim((string)($supplier['supplier_code'] ?? ''));

    $row['supplier_id'] = $supplierId;
    $row['supplier_name'] = $supplierName;
    $row['supplier_feed_url'] = $supplierFeedUrl;
    $row['supplier_is_active'] = !empty($supplier['is_active']) ? 1 : 0;
    $row['supplier_is_archived'] = !empty($supplier['is_archived']) ? 1 : 0;
    if ($supplierFeedUrl !== '') {
        $row['feed_url'] = $supplierFeedUrl;
    }
    if ($supplierCode !== '') {
        $row['supplier_code'] = $supplierCode;
    }
    if (trim((string)($row['name'] ?? '')) === '' && $supplierName !== '') {
        $row['name'] = $supplierName;
    }
    return $row;
}

function ozon_price_feed_supplier_is_archived(array $feed): bool
{
    return (int)($feed['supplier_id'] ?? 0) > 0 && (int)($feed['supplier_is_archived'] ?? 0) === 1;
}

function ozon_price_feed_list(?int $connectionId = null, array $cfg = []): array
{
    ozon_price_feeds_table_ensure($cfg);
    suppliers_table_ensure($cfg);
    $connectionId = ($connectionId ?? 0) > 0 ? (int)$connectionId : ozon_price_connection_default_id($cfg);
    $st = db()->prepare("
        SELECT f.*
        FROM feedtools_ozon_price_feeds f
        LEFT JOIN feedtools_suppliers s ON s.id = f.supplier_id
        WHERE f.connection_id = ?
          AND (f.supplier_id = 0 OR COALESCE(s.is_archived, 0) = 0)
        ORDER BY f.updated_at DESC, f.id DESC
    ");
    $st->execute([$connectionId]);
    $rows = $st->fetchAll() ?: [];
    return array_map(static fn(array $row): array => ozon_price_feed_expand_ranges(ozon_price_feed_hydrate_supplier($row, $cfg)), $rows);
}

function ozon_price_feed_counts_by_connection(array $cfg = []): array
{
    ozon_price_feeds_table_ensure($cfg);
    suppliers_table_ensure($cfg);
    $st = db()->query("
        SELECT f.connection_id, COUNT(*) AS qty
        FROM feedtools_ozon_price_feeds f
        LEFT JOIN feedtools_suppliers s ON s.id = f.supplier_id
        WHERE f.supplier_id = 0 OR COALESCE(s.is_archived, 0) = 0
        GROUP BY f.connection_id
    ");
    $map = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $map[(int)($row['connection_id'] ?? 0)] = (int)($row['qty'] ?? 0);
    }
    return $map;
}

function ozon_price_feed_get(int $id, ?int $connectionId = null, array $cfg = []): ?array
{
    ozon_price_feeds_table_ensure($cfg);
    if ($connectionId !== null && $connectionId > 0) {
        $st = db()->prepare("SELECT * FROM feedtools_ozon_price_feeds WHERE id = ? AND connection_id = ? LIMIT 1");
        $st->execute([$id, $connectionId]);
    } else {
        $st = db()->prepare("SELECT * FROM feedtools_ozon_price_feeds WHERE id = ? LIMIT 1");
        $st->execute([$id]);
    }
    $row = $st->fetch();
    return is_array($row) ? ozon_price_feed_expand_ranges(ozon_price_feed_hydrate_supplier($row, $cfg)) : null;
}

function ozon_price_feed_delete(int $id, ?int $connectionId = null, array $cfg = []): void
{
    ozon_price_feeds_table_ensure($cfg);
    if ($id <= 0) {
        throw new RuntimeException('Не удалось определить профиль для удаления.');
    }
    if ($connectionId !== null && $connectionId > 0) {
        $st = db()->prepare("DELETE FROM feedtools_ozon_price_feeds WHERE id = ? AND connection_id = ? LIMIT 1");
        $st->execute([$id, $connectionId]);
    } else {
        $st = db()->prepare("DELETE FROM feedtools_ozon_price_feeds WHERE id = ? LIMIT 1");
        $st->execute([$id]);
    }
}

function ozon_price_feed_save(array $input, ?string $actor = null, ?int $connectionId = null, array $cfg = []): int
{
    ozon_price_feeds_table_ensure($cfg);

    $connectionId = ($connectionId ?? (int)($input['connection_id'] ?? 0)) > 0
        ? (int)($connectionId ?? (int)($input['connection_id'] ?? 0))
        : ozon_price_connection_default_id($cfg);
    $connection = ozon_price_connection_get($connectionId, $cfg);
    $row = ozon_price_feed_normalize_input($input, $connection);
    $row['connection_id'] = $connectionId;
    $id = (int)($input['id'] ?? 0);

    if ($id > 0) {
        $sql = "
            UPDATE feedtools_ozon_price_feeds
            SET connection_id = ?, supplier_id = ?, name = ?, feed_url = ?, cost_tag = ?, supplier_code = ?, fulfillment_scheme = ?,
                wb_commission_percent = ?, wb_discount_percent = ?, wb_club_discount_percent = ?, wb_promotion_pricing_enabled = ?, wb_promotion_max_plan_discount_percent = ?, wb_promotion_min_margin_percent = ?, wb_future_promo_discount_mode = ?, wb_future_promo_discount_percent = ?, wb_future_promo_discount_buffer_percent = ?, wb_future_promo_prepare_days = ?, wb_promotion_action_upload_enabled = ?, wb_expenses_mode = ?, wb_tariff_warehouse_name = ?,
                yandex_expenses_mode = ?, yandex_payment_frequency = ?, yandex_payment_delay_weeks = ?, yandex_boost_bid_enabled = ?, yandex_boost_bid_percent = ?,
                target_profit_percent = ?, min_target_profit_percent = ?, target_profit_min_rub = ?, min_target_profit_min_rub = ?, min_price_index_step_enabled = ?, action_pricing_enabled = ?, target_profit_ranges_json = ?, min_target_profit_ranges_json = ?, rounding_mode = ?, price_modifier_mode = ?, price_modifier_value = ?, price_modifier_min_mode = ?, price_modifier_min_value = ?,
                fulfillment_markup_rub = ?, fulfillment_markup_percent = ?, shipment_processing_rub = ?, nonbuyout_processing_rub = ?, return_processing_rub = ?, ship_0_12_percent = ?, ship_12_24_percent = ?, ship_24_36_percent = ?, ship_36_48_percent = ?, ship_48_plus_percent = ?, nonbuyout_percent = ?, return_resellable_percent = ?, return_nonresellable_percent = ?, return_percent = ?,
                include_returns_in_cost = ?, promotion_percent = ?, credit_percent = ?,
                delayed_shipment_percent = ?, delayed_shipment_min_rub = ?, extra_expenses_percent = ?,
                strike_discount_percent = ?, tax_mode = ?, tax_percent = ?, vat_percent = ?,
                profit_tax_percent = ?, insurance_percent = ?, updated_by = ?
            WHERE id = ? AND connection_id = ?
        ";
        $args = [
            $row['connection_id'],
            $row['supplier_id'],
            $row['name'], $row['feed_url'], $row['cost_tag'], $row['supplier_code'], $row['fulfillment_scheme'],
            $row['wb_commission_percent'], $row['wb_discount_percent'], $row['wb_club_discount_percent'], $row['wb_promotion_pricing_enabled'], $row['wb_promotion_max_plan_discount_percent'], $row['wb_promotion_min_margin_percent'], $row['wb_future_promo_discount_mode'], $row['wb_future_promo_discount_percent'], $row['wb_future_promo_discount_buffer_percent'], $row['wb_future_promo_prepare_days'], $row['wb_promotion_action_upload_enabled'], $row['wb_expenses_mode'], $row['wb_tariff_warehouse_name'],
            $row['yandex_expenses_mode'], $row['yandex_payment_frequency'], $row['yandex_payment_delay_weeks'], $row['yandex_boost_bid_enabled'], $row['yandex_boost_bid_percent'],
            $row['target_profit_percent'], $row['min_target_profit_percent'], $row['target_profit_min_rub'], $row['min_target_profit_min_rub'], $row['min_price_index_step_enabled'], $row['action_pricing_enabled'], $row['target_profit_ranges_json'], $row['min_target_profit_ranges_json'], $row['rounding_mode'], $row['price_modifier_mode'], $row['price_modifier_value'], $row['price_modifier_min_mode'], $row['price_modifier_min_value'],
            $row['fulfillment_markup_rub'], $row['fulfillment_markup_percent'], $row['shipment_processing_rub'], $row['nonbuyout_processing_rub'], $row['return_processing_rub'], $row['ship_0_12_percent'], $row['ship_12_24_percent'], $row['ship_24_36_percent'], $row['ship_36_48_percent'], $row['ship_48_plus_percent'], $row['nonbuyout_percent'], $row['return_resellable_percent'], $row['return_nonresellable_percent'], $row['return_percent'],
            $row['include_returns_in_cost'], $row['promotion_percent'], $row['credit_percent'],
            $row['delayed_shipment_percent'], $row['delayed_shipment_min_rub'], $row['extra_expenses_percent'],
            $row['strike_discount_percent'], $row['tax_mode'], $row['tax_percent'], $row['vat_percent'],
            $row['profit_tax_percent'], $row['insurance_percent'], $actor, $id, $row['connection_id'],
        ];
        db()->prepare($sql)->execute($args);
        return $id;
    }

    $args = [
        $row['connection_id'],
        $row['supplier_id'],
        $row['name'], $row['feed_url'], $row['cost_tag'], $row['supplier_code'], $row['fulfillment_scheme'],
        $row['wb_commission_percent'], $row['wb_discount_percent'], $row['wb_club_discount_percent'], $row['wb_promotion_pricing_enabled'], $row['wb_promotion_max_plan_discount_percent'], $row['wb_promotion_min_margin_percent'], $row['wb_future_promo_discount_mode'], $row['wb_future_promo_discount_percent'], $row['wb_future_promo_discount_buffer_percent'], $row['wb_future_promo_prepare_days'], $row['wb_promotion_action_upload_enabled'], $row['wb_expenses_mode'], $row['wb_tariff_warehouse_name'],
        $row['yandex_expenses_mode'], $row['yandex_payment_frequency'], $row['yandex_payment_delay_weeks'], $row['yandex_boost_bid_enabled'], $row['yandex_boost_bid_percent'],
        $row['target_profit_percent'], $row['min_target_profit_percent'], $row['target_profit_min_rub'], $row['min_target_profit_min_rub'], $row['min_price_index_step_enabled'], $row['action_pricing_enabled'], $row['target_profit_ranges_json'], $row['min_target_profit_ranges_json'], $row['rounding_mode'], $row['price_modifier_mode'], $row['price_modifier_value'], $row['price_modifier_min_mode'], $row['price_modifier_min_value'],
        $row['fulfillment_markup_rub'], $row['fulfillment_markup_percent'], $row['shipment_processing_rub'], $row['nonbuyout_processing_rub'], $row['return_processing_rub'], $row['ship_0_12_percent'], $row['ship_12_24_percent'], $row['ship_24_36_percent'], $row['ship_36_48_percent'], $row['ship_48_plus_percent'], $row['nonbuyout_percent'], $row['return_resellable_percent'], $row['return_nonresellable_percent'], $row['return_percent'],
        $row['include_returns_in_cost'], $row['promotion_percent'], $row['credit_percent'],
        $row['delayed_shipment_percent'], $row['delayed_shipment_min_rub'], $row['extra_expenses_percent'],
        $row['strike_discount_percent'], $row['tax_mode'], $row['tax_percent'], $row['vat_percent'],
        $row['profit_tax_percent'], $row['insurance_percent'], $actor, $actor,
    ];
    $sql = "
        INSERT INTO feedtools_ozon_price_feeds (
            connection_id, supplier_id, name, feed_url, cost_tag, supplier_code, fulfillment_scheme,
            wb_commission_percent, wb_discount_percent, wb_club_discount_percent, wb_promotion_pricing_enabled, wb_promotion_max_plan_discount_percent, wb_promotion_min_margin_percent, wb_future_promo_discount_mode, wb_future_promo_discount_percent, wb_future_promo_discount_buffer_percent, wb_future_promo_prepare_days, wb_promotion_action_upload_enabled, wb_expenses_mode, wb_tariff_warehouse_name,
            yandex_expenses_mode, yandex_payment_frequency, yandex_payment_delay_weeks, yandex_boost_bid_enabled, yandex_boost_bid_percent,
            target_profit_percent, min_target_profit_percent, target_profit_min_rub, min_target_profit_min_rub, min_price_index_step_enabled, action_pricing_enabled, target_profit_ranges_json, min_target_profit_ranges_json, rounding_mode, price_modifier_mode, price_modifier_value, price_modifier_min_mode, price_modifier_min_value,
            fulfillment_markup_rub, fulfillment_markup_percent, shipment_processing_rub, nonbuyout_processing_rub, return_processing_rub, ship_0_12_percent, ship_12_24_percent, ship_24_36_percent, ship_36_48_percent, ship_48_plus_percent, nonbuyout_percent, return_resellable_percent, return_nonresellable_percent, return_percent, include_returns_in_cost,
            promotion_percent, credit_percent, delayed_shipment_percent, delayed_shipment_min_rub,
            extra_expenses_percent, strike_discount_percent, tax_mode, tax_percent, vat_percent,
            profit_tax_percent, insurance_percent, created_by, updated_by
        ) VALUES (" . implode(', ', array_fill(0, count($args), '?')) . ")
    ";
    db()->prepare($sql)->execute($args);
    return (int)db()->lastInsertId();
}

function ozon_price_feed_normalize_input(array $input, ?array $connection = null): array
{
    $row = ozon_price_feed_default();
    $marketplace = strtolower(trim((string)($connection['marketplace'] ?? 'ozon')));
    $row['supplier_id'] = max(0, (int)($input['supplier_id'] ?? 0));
    $row['name'] = trim((string)($input['name'] ?? ''));
    $row['feed_url'] = trim((string)($input['feed_url'] ?? ''));
    $row['cost_tag'] = trim((string)($input['cost_tag'] ?? ''));
    $row['supplier_code'] = preg_replace('~[^A-Za-z0-9_-]+~u', '', trim((string)($input['supplier_code'] ?? ''))) ?: '';
    if ($row['supplier_id'] > 0) {
        $supplier = suppliers_get((int)$row['supplier_id']);
        if (!is_array($supplier)) {
            throw new RuntimeException('Выбранный поставщик не найден.');
        }
        if (!empty($supplier['is_archived'])) {
            throw new RuntimeException('Выбранный поставщик находится в архиве.');
        }
        $row['supplier_name'] = (string)($supplier['name'] ?? '');
        $row['supplier_feed_url'] = (string)($supplier['feed_url'] ?? '');
        $row['supplier_is_active'] = !empty($supplier['is_active']) ? 1 : 0;
        $row['supplier_is_archived'] = 0;
        $row['feed_url'] = trim((string)($supplier['feed_url'] ?? ''));
        $row['supplier_code'] = suppliers_normalize_code((string)($supplier['supplier_code'] ?? ''));
        if ($row['name'] === '') {
            $row['name'] = trim((string)($supplier['name'] ?? ''));
        }
    }
    $row['fulfillment_scheme'] = strtolower(trim((string)($input['fulfillment_scheme'] ?? 'fbs')));
    $allowedFulfillmentSchemes = $marketplace === 'yandex_market'
        ? ['fbs', 'fby', 'dbs', 'express']
        : ['fbs', 'fbo'];
    if (!in_array($row['fulfillment_scheme'], $allowedFulfillmentSchemes, true)) {
        $row['fulfillment_scheme'] = 'fbs';
    }

    $row['rounding_mode'] = strtolower(trim((string)($input['rounding_mode'] ?? 'rub')));
    if ($row['rounding_mode'] === 'none') {
        $row['rounding_mode'] = 'rub';
    }
    if (!in_array($row['rounding_mode'], ['rub', '5rub', '10rub', 'end9', 'end90', 'end99'], true)) {
        $row['rounding_mode'] = 'rub';
    }
    $row['price_modifier_mode'] = strtolower(trim((string)($input['price_modifier_mode'] ?? 'none')));
    if (!in_array($row['price_modifier_mode'], ['none', 'percent', 'fixed'], true)) {
        $row['price_modifier_mode'] = 'none';
    }
    $row['price_modifier_min_mode'] = strtolower(trim((string)($input['price_modifier_min_mode'] ?? 'none')));
    if (!in_array($row['price_modifier_min_mode'], ['none', 'percent', 'fixed'], true)) {
        $row['price_modifier_min_mode'] = 'none';
    }

    $row['tax_mode'] = strtolower(trim((string)($input['tax_mode'] ?? 'none')));
    if (!in_array($row['tax_mode'], ['none', 'usn_income', 'usn_income_expense'], true)) {
        $row['tax_mode'] = 'none';
    }

    foreach ([
        'wb_commission_percent',
        'wb_discount_percent',
        'wb_club_discount_percent',
        'wb_promotion_max_plan_discount_percent',
        'wb_promotion_min_margin_percent',
        'wb_future_promo_discount_percent',
        'wb_future_promo_discount_buffer_percent',
        'yandex_boost_bid_percent',
        'target_profit_percent',
        'min_target_profit_percent',
        'target_profit_min_rub',
        'min_target_profit_min_rub',
        'price_modifier_value',
        'price_modifier_min_value',
        'fulfillment_markup_rub',
        'fulfillment_markup_percent',
        'shipment_processing_rub',
        'nonbuyout_processing_rub',
        'return_processing_rub',
        'ship_0_12_percent',
        'ship_12_24_percent',
        'ship_24_36_percent',
        'ship_36_48_percent',
        'ship_48_plus_percent',
        'nonbuyout_percent',
        'return_resellable_percent',
        'return_nonresellable_percent',
        'return_percent',
        'promotion_percent',
        'credit_percent',
        'delayed_shipment_percent',
        'delayed_shipment_min_rub',
        'extra_expenses_percent',
        'strike_discount_percent',
        'tax_percent',
        'vat_percent',
        'profit_tax_percent',
        'insurance_percent',
    ] as $key) {
        $row[$key] = number_format(ozon_price_to_float($input[$key] ?? 0), 2, '.', '');
    }

    $row['target_profit_ranges_json'] = ozon_price_encode_profit_ranges($input, 'target');
    $row['min_target_profit_ranges_json'] = ozon_price_encode_profit_ranges($input, 'min_target');
    $row['target_profit_ranges'] = ozon_price_decode_profit_ranges($row['target_profit_ranges_json']);
    $row['min_target_profit_ranges'] = ozon_price_decode_profit_ranges($row['min_target_profit_ranges_json']);

    if (($input['return_resellable_percent'] ?? '') === '' && ($input['return_nonresellable_percent'] ?? '') === '' && isset($input['return_percent'])) {
        $legacy = number_format(ozon_price_to_float($input['return_percent']), 2, '.', '');
        $row['return_resellable_percent'] = $legacy;
        $row['return_nonresellable_percent'] = '0.00';
        $row['return_percent'] = $legacy;
    } else {
        $combined = (float)$row['return_resellable_percent'] + (float)$row['return_nonresellable_percent'];
        $row['return_percent'] = number_format($combined, 2, '.', '');
    }

    $row['include_returns_in_cost'] = !empty($input['include_returns_in_cost']) ? '1' : '0';
    $row['min_price_index_step_enabled'] = !empty($input['min_price_index_step_enabled']) ? '1' : '0';
    $row['action_pricing_enabled'] = !empty($input['action_pricing_enabled']) ? '1' : '0';
    $row['wb_promotion_pricing_enabled'] = !empty($input['wb_promotion_pricing_enabled']) ? '1' : '0';
    $row['wb_promotion_action_upload_enabled'] = !empty($input['wb_promotion_action_upload_enabled']) ? '1' : '0';
    $row['wb_future_promo_discount_mode'] = strtolower(trim((string)($input['wb_future_promo_discount_mode'] ?? 'auto')));
    if (!in_array($row['wb_future_promo_discount_mode'], ['auto', 'manual'], true)) {
        $row['wb_future_promo_discount_mode'] = 'auto';
    }
    $row['wb_future_promo_prepare_days'] = (string)max(0, min(60, (int)round(ozon_price_to_float($input['wb_future_promo_prepare_days'] ?? 0))));
    $row['yandex_expenses_mode'] = 'api';
    $row['yandex_payment_frequency'] = 'DAILY';
    $row['yandex_payment_delay_weeks'] = '0';
    $row['yandex_boost_bid_enabled'] = '0';
    $row['yandex_boost_bid_percent'] = '0.00';

    if ($row['name'] === '') {
        throw new RuntimeException('Укажи название профиля.');
    }
    if ($row['feed_url'] === '') {
        throw new RuntimeException('Выбери поставщика с заполненной ссылкой на источник данных.');
    }
    if ($row['cost_tag'] === '') {
        throw new RuntimeException('Укажи название тега закупочной цены.');
    }

    if ($marketplace === 'wb') {
        $row['wb_commission_percent'] = '0.00';
        $row['wb_discount_percent'] = number_format(max(0.0, min(99.0, (float)$row['wb_discount_percent'])), 2, '.', '');
        $row['wb_club_discount_percent'] = number_format(max(0.0, min(31.0, (float)$row['wb_club_discount_percent'])), 2, '.', '');
        $row['wb_promotion_max_plan_discount_percent'] = number_format(max(0.0, min(99.0, (float)$row['wb_promotion_max_plan_discount_percent'])), 2, '.', '');
        $row['wb_promotion_min_margin_percent'] = number_format(max(0.0, min(50.0, (float)$row['wb_promotion_min_margin_percent'])), 2, '.', '');
        $row['wb_future_promo_discount_percent'] = number_format(max(0.0, min(85.0, (float)$row['wb_future_promo_discount_percent'])), 2, '.', '');
        $row['wb_future_promo_discount_buffer_percent'] = number_format(max(0.0, min(20.0, (float)$row['wb_future_promo_discount_buffer_percent'])), 2, '.', '');
        $row['wb_future_promo_prepare_days'] = (string)max(0, min(60, (int)$row['wb_future_promo_prepare_days']));
        if ((float)$row['wb_club_discount_percent'] > 0 && (float)$row['wb_club_discount_percent'] < 3.0) {
            throw new RuntimeException('Скидка WB Клуба должна быть 0 или от 3% до 31%.');
        }
        $row['wb_expenses_mode'] = 'api';
        $row['wb_tariff_warehouse_name'] = trim((string)($input['wb_tariff_warehouse_name'] ?? ''));
        return $row;
    }

    if ($marketplace === 'yandex_market') {
        $row['action_pricing_enabled'] = '0';
        return $row;
    }

    $shipmentDistributionTotal = 0.0;
    foreach ([
        'ship_0_12_percent',
        'ship_12_24_percent',
        'ship_24_36_percent',
        'ship_36_48_percent',
        'ship_48_plus_percent',
    ] as $key) {
        $shipmentDistributionTotal += (float)$row[$key];
    }
    if ($shipmentDistributionTotal > 0.0001 && abs($shipmentDistributionTotal - 100.0) > 0.05) {
        throw new RuntimeException('Распределение заказов по окнам отгрузки должно в сумме давать 100%.');
    }

    return $row;
}

function ozon_price_feed_expand_ranges(array $row): array
{
    $row = array_replace(ozon_price_feed_default(), $row);
    $row['target_profit_ranges'] = ozon_price_decode_profit_ranges($row['target_profit_ranges_json'] ?? '');
    $row['min_target_profit_ranges'] = ozon_price_decode_profit_ranges($row['min_target_profit_ranges_json'] ?? '');
    return $row;
}

function ozon_price_encode_profit_ranges(array $input, string $prefix): string
{
    $fromList = (array)($input[$prefix . '_range_from'] ?? []);
    $toList = (array)($input[$prefix . '_range_to'] ?? []);
    $percentList = (array)($input[$prefix . '_range_percent'] ?? []);
    $count = max(count($fromList), count($toList), count($percentList));
    $rules = [];

    for ($i = 0; $i < $count; $i++) {
        $fromRaw = trim((string)($fromList[$i] ?? ''));
        $toRaw = trim((string)($toList[$i] ?? ''));
        $percentRaw = trim((string)($percentList[$i] ?? ''));
        if ($fromRaw === '' && $toRaw === '' && $percentRaw === '') {
            continue;
        }
        if ($percentRaw === '') {
            throw new RuntimeException('Укажи процент дохода для каждой заполненной строки диапазона.');
        }

        $from = $fromRaw === '' ? 0.0 : ozon_price_to_float($fromRaw);
        $to = $toRaw === '' ? null : ozon_price_to_float($toRaw);
        $percent = ozon_price_to_float($percentRaw);

        if ($from < 0) {
            throw new RuntimeException('Левая граница диапазона не может быть отрицательной.');
        }
        if ($to !== null && $to < $from) {
            throw new RuntimeException('Правая граница диапазона не может быть меньше левой.');
        }
        if ($percent < 0) {
            throw new RuntimeException('Процент дохода не может быть отрицательным.');
        }

        $rules[] = [
            'from' => round($from, 2),
            'to' => $to !== null ? round($to, 2) : null,
            'percent' => round($percent, 2),
        ];
    }

    return $rules ? (string)json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
}

function ozon_price_decode_profit_ranges($raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map(static function ($rule): ?array {
            if (!is_array($rule)) {
                return null;
            }
            $from = max(0.0, ozon_price_to_float($rule['from'] ?? 0));
            $toRaw = $rule['to'] ?? null;
            $to = ($toRaw === null || $toRaw === '') ? null : ozon_price_to_float($toRaw);
            $percent = max(0.0, ozon_price_to_float($rule['percent'] ?? 0));
            return [
                'from' => round($from, 2),
                'to' => $to !== null ? round($to, 2) : null,
                'percent' => round($percent, 2),
            ];
        }, $raw)));
    }

    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return ozon_price_decode_profit_ranges($decoded);
}

function ozon_price_resolve_target_profit_percent(array $settings, float $purchaseCost, bool $forMinPrice = false): float
{
    $fallbackKey = $forMinPrice ? 'min_target_profit_percent' : 'target_profit_percent';
    $rangesKey = $forMinPrice ? 'min_target_profit_ranges' : 'target_profit_ranges';
    $fallback = max(0.0, (float)($settings[$fallbackKey] ?? 0));
    $ranges = $settings[$rangesKey] ?? [];
    if (!is_array($ranges)) {
        $ranges = ozon_price_decode_profit_ranges($settings[$forMinPrice ? 'min_target_profit_ranges_json' : 'target_profit_ranges_json'] ?? '');
    }

    foreach ($ranges as $rule) {
        $from = max(0.0, (float)($rule['from'] ?? 0));
        $to = array_key_exists('to', $rule) ? $rule['to'] : null;
        $percent = max(0.0, (float)($rule['percent'] ?? 0));
        if ($purchaseCost < $from) {
            continue;
        }
        if ($to !== null && $purchaseCost > (float)$to) {
            continue;
        }
        return $percent;
    }

    return $fallback;
}

function ozon_price_index_metric_candidates(array $priceIndexes): array
{
    $sources = [
        'external' => (float)($priceIndexes['external_index_data']['price_index_value'] ?? 0),
        'self_marketplaces' => (float)($priceIndexes['self_marketplaces_index_data']['price_index_value'] ?? 0),
        'ozon' => (float)($priceIndexes['ozon_index_data']['price_index_value'] ?? 0),
    ];
    $result = [];
    foreach ($sources as $source => $value) {
        if ($value > 0) {
            $result[$source] = round($value, 2);
        }
    }
    return $result;
}

function ozon_price_effective_index_metric(array $priceIndexes): ?array
{
    $candidates = ozon_price_index_metric_candidates($priceIndexes);
    if (!$candidates) {
        return null;
    }
    arsort($candidates, SORT_NUMERIC);
    $source = (string)array_key_first($candidates);
    $value = (float)$candidates[$source];
    return [
        'source' => $source,
        'value' => round($value, 2),
        'all' => $candidates,
    ];
}

function ozon_price_index_data_for_source(array $priceIndexes, string $source): array
{
    $key = match ($source) {
        'external' => 'external_index_data',
        'self_marketplaces' => 'self_marketplaces_index_data',
        'ozon' => 'ozon_index_data',
        default => '',
    };
    $data = $key !== '' && is_array($priceIndexes[$key] ?? null) ? (array)$priceIndexes[$key] : [];
    return $data;
}

function ozon_price_index_competing_price(array $priceIndexes, string $source): ?float
{
    $data = ozon_price_index_data_for_source($priceIndexes, $source);
    foreach (['min_price', 'minimal_price', 'price', 'minimalPrice'] as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $value = (float)$data[$key];
        if ($value > 0) {
            return round($value, 2);
        }
    }
    return null;
}

function ozon_price_index_effective_price_from_benchmark(float $comparisonPrice, float $indexValue): ?float
{
    if ($comparisonPrice <= 0 || $indexValue <= 0 || $indexValue >= 2.0) {
        return null;
    }
    if ($indexValue <= 1.0) {
        return round($comparisonPrice * $indexValue, 2);
    }
    $denominator = 2.0 - $indexValue;
    if ($denominator <= 0) {
        return null;
    }
    return round($comparisonPrice / $denominator, 2);
}

function ozon_price_index_benchmark_from_metric(array $priceIndexes, ?array $metric, float $sellerReferencePrice): ?array
{
    if (!is_array($metric) || (float)($metric['value'] ?? 0) <= 0) {
        return null;
    }
    $source = (string)($metric['source'] ?? '');
    $indexValue = (float)($metric['value'] ?? 0);
    $comparisonPrice = $source !== '' ? ozon_price_index_competing_price($priceIndexes, $source) : null;
    $method = 'api_min_price';
    if ($comparisonPrice === null || $comparisonPrice <= 0) {
        $comparisonPrice = ozon_price_index_comparison_price($sellerReferencePrice, $indexValue);
        $method = 'inverse_from_reference';
    }
    if ($comparisonPrice === null || $comparisonPrice <= 0) {
        return null;
    }
    return [
        'source' => $source,
        'value' => round((float)$comparisonPrice, 2),
        'index_value' => round($indexValue, 4),
        'method' => $method,
    ];
}

function ozon_price_index_discount_factor(float $sellerReferencePrice, ?float $effectivePrice): ?float
{
    if ($sellerReferencePrice <= 0 || $effectivePrice === null || $effectivePrice <= 0) {
        return null;
    }
    $factor = (float)$effectivePrice / $sellerReferencePrice;
    if ($factor <= 0) {
        return null;
    }
    return round(min(1.0, max(0.05, $factor)), 6);
}

function ozon_price_index_effective_price_for_seller_price(float $sellerPrice, ?float $discountFactor): float
{
    $factor = $discountFactor !== null && $discountFactor > 0 ? $discountFactor : 1.0;
    return round(max(0.0, $sellerPrice) * $factor, 2);
}

function ozon_price_index_seller_price_for_effective_price(float $effectivePrice, ?float $discountFactor): ?float
{
    $factor = $discountFactor !== null && $discountFactor > 0 ? $discountFactor : 1.0;
    if ($effectivePrice <= 0 || $factor <= 0) {
        return null;
    }
    return round($effectivePrice / $factor, 2);
}

function ozon_price_index_source_label(string $source): string
{
    return match ($source) {
        'ozon' => 'конкуренты на Ozon',
        'self_marketplaces' => 'наша цена на других площадках',
        'external' => 'конкуренты на других площадках',
        default => $source !== '' ? $source : 'источник индекса',
    };
}

function ozon_price_index_rank_level(int $rank): string
{
    return match ($rank) {
        4 => 'super',
        3 => 'good',
        2 => 'moderate',
        1 => 'bad',
        default => 'without_index',
    };
}

function ozon_price_index_aggregate_level_from_levels(array $levels): string
{
    $ranks = [];
    foreach ($levels as $level) {
        $rank = ozon_price_index_level_rank((string)$level);
        if ($rank > 0) {
            $ranks[] = $rank;
        }
    }
    if (!$ranks) {
        return 'without_index';
    }
    sort($ranks, SORT_NUMERIC);
    $index = (int)floor((count($ranks) - 1) / 2);
    return ozon_price_index_rank_level((int)$ranks[$index]);
}

function ozon_price_index_metric_rows(array $priceIndexes, float $sellerReferencePrice): array
{
    $candidates = ozon_price_index_metric_candidates($priceIndexes);
    if (!$candidates) {
        return [];
    }

    $rows = [];
    foreach ($candidates as $source => $value) {
        $metric = [
            'source' => (string)$source,
            'value' => (float)$value,
        ];
        $benchmark = ozon_price_index_benchmark_from_metric($priceIndexes, $metric, $sellerReferencePrice);
        if (!is_array($benchmark) || (float)($benchmark['value'] ?? 0) <= 0) {
            continue;
        }
        $effectivePrice = ozon_price_index_effective_price_from_benchmark(
            (float)$benchmark['value'],
            (float)$value
        );
        $level = ozon_price_index_level_from_value((float)$value);
        $rows[] = [
            'source' => (string)$source,
            'label' => ozon_price_index_source_label((string)$source),
            'comparison_price' => round((float)$benchmark['value'], 2),
            'benchmark_method' => (string)($benchmark['method'] ?? ''),
            'api_index_value' => round((float)$value, 4),
            'api_level' => $level,
            'api_level_label' => ozon_price_index_level_label($level),
            'api_effective_price' => $effectivePrice !== null ? round($effectivePrice, 2) : null,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $order = ['ozon' => 0, 'external' => 1, 'self_marketplaces' => 2];
        return ($order[(string)($a['source'] ?? '')] ?? 99) <=> ($order[(string)($b['source'] ?? '')] ?? 99);
    });
    return $rows;
}

function ozon_price_index_discount_factor_from_metrics(array $metrics, float $sellerReferencePrice): ?float
{
    if ($sellerReferencePrice <= 0) {
        return null;
    }
    $effectivePrices = [];
    foreach ($metrics as $metric) {
        $effective = (float)($metric['api_effective_price'] ?? 0);
        if ($effective > 0) {
            $effectivePrices[] = $effective;
        }
    }
    if (!$effectivePrices) {
        return null;
    }
    sort($effectivePrices, SORT_NUMERIC);
    $count = count($effectivePrices);
    $middle = intdiv($count, 2);
    $effective = $count % 2 === 1
        ? (float)$effectivePrices[$middle]
        : (((float)$effectivePrices[$middle - 1] + (float)$effectivePrices[$middle]) / 2.0);
    return ozon_price_index_discount_factor($sellerReferencePrice, $effective);
}

function ozon_price_eval_index_for_metrics(float $sellerPrice, array $metrics, ?float $discountFactor): array
{
    if ($sellerPrice <= 0 || !$metrics) {
        return [
            'value' => null,
            'level' => 'without_index',
            'label' => ozon_price_index_level_label('without_index'),
            'rank' => 0,
            'seller_price' => round(max(0.0, $sellerPrice), 2),
            'effective_price' => null,
            'sources' => [],
        ];
    }
    $effectivePrice = ozon_price_index_effective_price_for_seller_price($sellerPrice, $discountFactor);
    $sources = [];
    $levels = [];
    $values = [];
    foreach ($metrics as $metric) {
        $comparisonPrice = (float)($metric['comparison_price'] ?? 0);
        if ($comparisonPrice <= 0) {
            continue;
        }
        $value = ozon_price_index_value_for_price($effectivePrice, $comparisonPrice);
        $level = ozon_price_index_level_from_value($value);
        $levels[] = $level;
        $values[] = $value;
        $sources[] = [
            'source' => (string)($metric['source'] ?? ''),
            'label' => (string)($metric['label'] ?? ''),
            'comparison_price' => round($comparisonPrice, 2),
            'index_value' => round($value, 4),
            'level' => $level,
            'level_label' => ozon_price_index_level_label($level),
        ];
    }
    $level = ozon_price_index_aggregate_level_from_levels($levels);
    sort($values, SORT_NUMERIC);
    $value = null;
    if ($values) {
        $count = count($values);
        $middle = intdiv($count, 2);
        $value = $count % 2 === 1
            ? (float)$values[$middle]
            : (((float)$values[$middle - 1] + (float)$values[$middle]) / 2.0);
    }

    return [
        'value' => $value !== null ? round($value, 4) : null,
        'level' => $level,
        'label' => ozon_price_index_level_label($level),
        'rank' => ozon_price_index_level_rank($level),
        'seller_price' => round($sellerPrice, 2),
        'effective_price' => $effectivePrice,
        'sources' => $sources,
    ];
}

function ozon_price_find_seller_price_for_aggregate_level(float $maxSellerPrice, array $metrics, ?float $discountFactor, string $targetLevel): ?float
{
    $targetRank = ozon_price_index_level_rank($targetLevel);
    if ($maxSellerPrice <= 0 || !$metrics || $targetRank <= 0) {
        return null;
    }
    $current = ozon_price_eval_index_for_metrics($maxSellerPrice, $metrics, $discountFactor);
    if ((int)($current['rank'] ?? 0) >= $targetRank) {
        return round($maxSellerPrice, 2);
    }

    $comparisonPrices = array_values(array_filter(array_map(
        static fn(array $metric): float => (float)($metric['comparison_price'] ?? 0),
        $metrics
    ), static fn(float $value): bool => $value > 0));
    $minComparisonPrice = $comparisonPrices ? min($comparisonPrices) : 0.0;
    $factor = $discountFactor !== null && $discountFactor > 0 ? $discountFactor : 1.0;
    $safeLow = $minComparisonPrice > 0
        ? max(1.0, ($minComparisonPrice / $factor) * 0.01)
        : 1.0;
    $low = min($maxSellerPrice, $safeLow);
    $lowEval = ozon_price_eval_index_for_metrics($low, $metrics, $discountFactor);
    if ((int)($lowEval['rank'] ?? 0) < $targetRank) {
        return null;
    }
    $high = $maxSellerPrice;
    for ($i = 0; $i < 60; $i++) {
        $mid = ($low + $high) / 2.0;
        $eval = ozon_price_eval_index_for_metrics($mid, $metrics, $discountFactor);
        if ((int)($eval['rank'] ?? 0) >= $targetRank) {
            $low = $mid;
        } else {
            $high = $mid;
        }
    }
    return round($low, 2);
}

function ozon_price_eval_calc_index_for_price(float $sellerPrice, array $calc): array
{
    $metrics = is_array($calc['breakdown']['price_index_metrics'] ?? null)
        ? (array)$calc['breakdown']['price_index_metrics']
        : [];
    if ($metrics) {
        $discountFactor = isset($calc['breakdown']['price_index_discount_factor'])
            ? (float)$calc['breakdown']['price_index_discount_factor']
            : 1.0;
        if ($discountFactor <= 0) {
            $discountFactor = 1.0;
        }
        return ozon_price_eval_index_for_metrics($sellerPrice, $metrics, $discountFactor);
    }

    $comparisonPrice = isset($calc['breakdown']['price_index_benchmark']) ? (float)$calc['breakdown']['price_index_benchmark'] : 0.0;
    $discountFactor = isset($calc['breakdown']['price_index_discount_factor'])
        ? (float)$calc['breakdown']['price_index_discount_factor']
        : 1.0;
    if ($discountFactor <= 0) {
        $discountFactor = 1.0;
    }
    return ozon_price_eval_index_for_price($sellerPrice, $comparisonPrice > 0 ? $comparisonPrice : null, $discountFactor);
}

function ozon_price_index_level_from_value(float $indexValue): string
{
    if ($indexValue <= 0) {
        return 'without_index';
    }
    if ($indexValue <= 0.95) {
        return 'super';
    }
    if ($indexValue <= 1.01) {
        return 'good';
    }
    if ($indexValue <= 1.05) {
        return 'moderate';
    }
    return 'bad';
}

function ozon_price_index_level_from_api_color(string $colorIndex): string
{
    return match (strtoupper(trim($colorIndex))) {
        'SUPER' => 'super',
        'GREEN' => 'good',
        'YELLOW' => 'moderate',
        'RED' => 'bad',
        default => 'without_index',
    };
}

function ozon_price_index_level_label(string $level): string
{
    return match ($level) {
        'super' => 'супер-выгодный',
        'good' => 'выгодный',
        'moderate' => 'умеренный',
        'bad' => 'невыгодный',
        default => 'без индекса',
    };
}

function ozon_price_index_level_rank(string $level): int
{
    return match ($level) {
        'super' => 4,
        'good' => 3,
        'moderate' => 2,
        'bad' => 1,
        default => 0,
    };
}

function ozon_price_index_comparison_price(float $referencePrice, float $indexValue): ?float
{
    if ($referencePrice <= 0 || $indexValue <= 0) {
        return null;
    }
    if ($indexValue <= 1.0) {
        return $indexValue > 0 ? round($referencePrice / $indexValue, 2) : null;
    }
    if ($indexValue >= 2.0) {
        return null;
    }
    return round($referencePrice * (2.0 - $indexValue), 2);
}

function ozon_price_index_value_for_price(float $price, float $comparisonPrice): float
{
    if ($price <= 0 || $comparisonPrice <= 0) {
        return 0.0;
    }
    if ($price <= $comparisonPrice) {
        return round($price / $comparisonPrice, 4);
    }
    return round(2.0 - ($comparisonPrice / $price), 4);
}

function ozon_price_price_for_target_index(float $comparisonPrice, float $targetIndex): ?float
{
    if ($comparisonPrice <= 0 || $targetIndex <= 0 || $targetIndex >= 2.0) {
        return null;
    }
    if ($targetIndex <= 1.0) {
        return round($comparisonPrice * $targetIndex, 2);
    }
    $denominator = 2.0 - $targetIndex;
    if ($denominator <= 0) {
        return null;
    }
    return round($comparisonPrice / $denominator, 2);
}

function ozon_price_eval_index_for_price(float $price, ?float $comparisonPrice, ?float $discountFactor = null): array
{
    if ($price <= 0 || $comparisonPrice === null || $comparisonPrice <= 0) {
        return [
            'value' => null,
            'level' => 'without_index',
            'label' => ozon_price_index_level_label('without_index'),
            'rank' => 0,
        ];
    }
    $effectivePrice = ozon_price_index_effective_price_for_seller_price($price, $discountFactor);
    $value = ozon_price_index_value_for_price($effectivePrice, $comparisonPrice);
    $level = ozon_price_index_level_from_value($value);
    return [
        'value' => round($value, 4),
        'level' => $level,
        'label' => ozon_price_index_level_label($level),
        'rank' => ozon_price_index_level_rank($level),
        'seller_price' => round($price, 2),
        'effective_price' => $effectivePrice,
    ];
}

function ozon_price_build_index_only_strategy(array $calc): array
{
    $baseRegular = (float)($calc['recommended_price'] ?? 0);
    $baseMin = (float)($calc['breakdown']['recommended_min_price_before_index_step'] ?? ($calc['recommended_min_price'] ?? 0));
    $comparisonPrice = isset($calc['breakdown']['price_index_benchmark']) ? (float)$calc['breakdown']['price_index_benchmark'] : 0.0;
    $metrics = is_array($calc['breakdown']['price_index_metrics'] ?? null) ? (array)$calc['breakdown']['price_index_metrics'] : [];
    $discountFactor = isset($calc['breakdown']['price_index_discount_factor'])
        ? (float)$calc['breakdown']['price_index_discount_factor']
        : 1.0;
    if ($discountFactor <= 0) {
        $discountFactor = 1.0;
    }
    $floor = $baseMin > 0 ? round($baseMin * 0.95, 2) : 0.0;

    $candidates = [];
    $pushCandidate = static function (string $source, float $price) use (&$candidates, $comparisonPrice, $discountFactor, $metrics): void {
        if ($price <= 0) {
            return;
        }
        $key = number_format($price, 2, '.', '');
        $candidates[$key] = [
            'source' => $source,
            'price' => round($price, 2),
            'index' => $metrics
                ? ozon_price_eval_index_for_metrics($price, $metrics, $discountFactor)
                : ozon_price_eval_index_for_price($price, $comparisonPrice > 0 ? $comparisonPrice : null, $discountFactor),
        ];
    };

    $pushCandidate('Экономическая расчётная цена', $baseRegular);
    $pushCandidate('Расчётная минимальная цена', $baseMin);
    if (!empty($calc['breakdown']['budget_goods_candidate_price'])) {
        $pushCandidate('Льготный тариф Ozon до 300 ₽', (float)$calc['breakdown']['budget_goods_candidate_price']);
    }
    if ($metrics) {
        foreach (['moderate' => 'Порог умеренного индекса', 'good' => 'Порог выгодного индекса', 'super' => 'Порог супер-выгодного индекса'] as $level => $label) {
            $threshold = ozon_price_find_seller_price_for_aggregate_level($baseRegular, $metrics, $discountFactor, $level);
            if ($threshold === null || $threshold <= 0) {
                continue;
            }
            $effectiveThreshold = ozon_price_index_effective_price_for_seller_price((float)$threshold, $discountFactor);
            $target = ozon_price_index_seller_price_for_effective_price(max(0.0, $effectiveThreshold - 10.0), $discountFactor);
            if ($target !== null) {
                $pushCandidate($label, $target);
            }
        }
    } elseif ($comparisonPrice > 0) {
        $goodThreshold = ozon_price_price_for_target_index($comparisonPrice, 1.01);
        $moderateThreshold = ozon_price_price_for_target_index($comparisonPrice, 1.05);
        $superThreshold = ozon_price_price_for_target_index($comparisonPrice, 0.95);
        if ($moderateThreshold !== null) {
            $target = ozon_price_index_seller_price_for_effective_price(max(0.0, $moderateThreshold - 10.0), $discountFactor);
            if ($target !== null) {
                $pushCandidate('Порог умеренного индекса', $target);
            }
        }
        if ($goodThreshold !== null) {
            $target = ozon_price_index_seller_price_for_effective_price(max(0.0, $goodThreshold - 10.0), $discountFactor);
            if ($target !== null) {
                $pushCandidate('Порог выгодного индекса', $target);
            }
        }
        if ($superThreshold !== null) {
            $target = ozon_price_index_seller_price_for_effective_price(max(0.0, $superThreshold - 10.0), $discountFactor);
            if ($target !== null) {
                $pushCandidate('Порог супер-выгодного индекса', $target);
            }
        }
    }

    $allowed = [];
    foreach ($candidates as $candidate) {
        if ($candidate['price'] > $baseRegular + 0.01) {
            continue;
        }
        if ($floor > 0 && $candidate['price'] < $floor - 0.01) {
            continue;
        }
        $allowed[] = $candidate;
    }
    if (!$allowed) {
        $index = $metrics
            ? ozon_price_eval_index_for_metrics($baseRegular, $metrics, $discountFactor)
            : ozon_price_eval_index_for_price($baseRegular, $comparisonPrice > 0 ? $comparisonPrice : null, $discountFactor);
        return [
            'mode' => 'base',
            'final_price' => round($baseRegular, 2),
            'final_min_price' => (float)($calc['recommended_min_price'] ?? 0),
            'comparison_price' => $comparisonPrice > 0 ? round($comparisonPrice, 2) : null,
            'best_candidate' => [
                'source' => 'Экономическая расчётная цена',
                'price' => round($baseRegular, 2),
                'index' => $index,
            ],
            'reason' => 'По индексу не удалось собрать допустимые пороги, оставляем экономическую цену.',
        ];
    }

    $atOrAboveMin = array_values(array_filter($allowed, static fn(array $candidate): bool => $baseMin <= 0 || $candidate['price'] >= $baseMin - 0.01));
    $sortFn = static function (array $a, array $b): int {
        $rankCmp = ($b['index']['rank'] ?? 0) <=> ($a['index']['rank'] ?? 0);
        if ($rankCmp !== 0) {
            return $rankCmp;
        }
        return ($b['price'] ?? 0) <=> ($a['price'] ?? 0);
    };
    usort($allowed, $sortFn);
    if ($atOrAboveMin) {
        usort($atOrAboveMin, $sortFn);
    }

    $bestAny = $allowed[0];
    $bestSafe = $atOrAboveMin[0] ?? null;
    $chosen = $bestAny;
    if (
        $bestSafe !== null
        && $bestAny['price'] < $baseMin - 0.01
        && ($bestAny['index']['rank'] ?? 0) <= ($bestSafe['index']['rank'] ?? 0)
    ) {
        $chosen = $bestSafe;
    }

    $mode = $chosen['index']['rank'] > 0 ? 'index' : 'base';
    $reason = $mode === 'index'
        ? 'Акций с подходящим сценарием нет, поэтому подбираем самую высокую обычную цену, которая даёт лучший доступный уровень индекса.'
        : 'Нет ни акции, ни надёжного перехода по индексу — оставляем экономическую расчётную цену.';

    return [
        'mode' => $mode,
        'final_price' => round((float)$chosen['price'], 2),
        'final_min_price' => (float)($calc['recommended_min_price'] ?? 0),
        'comparison_price' => $comparisonPrice > 0 ? round($comparisonPrice, 2) : null,
        'best_candidate' => $chosen,
        'safe_candidate' => $bestSafe,
        'all_candidates' => $allowed,
        'reason' => $reason,
    ];
}

function ozon_price_compare_action_plans(array $a, array $b, bool $comparisonPriceAvailable): int
{
    if ($comparisonPriceAvailable) {
        $rankCmp = ozon_price_index_level_rank((string)($b['recommended_action_index_level_key'] ?? 'without_index'))
            <=> ozon_price_index_level_rank((string)($a['recommended_action_index_level_key'] ?? 'without_index'));
        if ($rankCmp !== 0) {
            return $rankCmp;
        }
    }

    $boostCmp = ((float)($b['recommended_action_boost'] ?? 0)) <=> ((float)($a['recommended_action_boost'] ?? 0));
    if ($boostCmp !== 0) {
        return $boostCmp;
    }

    return ((float)($b['recommended_action_price'] ?? 0)) <=> ((float)($a['recommended_action_price'] ?? 0));
}

function ozon_price_is_elastic_boosting_action(array $promoRow): bool
{
    $title = mb_strtolower(trim((string)($promoRow['title'] ?? '')));
    return $title !== '' && str_contains($title, 'эластичный бустинг');
}

function ozon_price_interpolate_elastic_price(float $targetBoost, float $minBoost, float $maxBoost, float $priceAtMinBoost, float $priceAtMaxBoost): ?float
{
    if ($targetBoost <= 0 || $minBoost <= 0 || $maxBoost <= 0 || $priceAtMinBoost <= 0 || $priceAtMaxBoost <= 0) {
        return null;
    }
    if ($maxBoost <= $minBoost || $priceAtMinBoost <= $priceAtMaxBoost) {
        return null;
    }
    $ratio = ($targetBoost - $minBoost) / ($maxBoost - $minBoost);
    $ratio = max(0.0, min(1.0, $ratio));
    $price = $priceAtMinBoost - (($priceAtMinBoost - $priceAtMaxBoost) * $ratio);
    return ozon_price_round_rub($price);
}

function ozon_price_elastic_action_candidates(array $promoRow, float $baseRegular): array
{
    $minBoost = (float)($promoRow['min_boost'] ?? 0);
    $maxBoost = (float)($promoRow['max_boost'] ?? 0);
    $priceAtMinBoost = (float)($promoRow['price_min_elastic'] ?? 0);
    $priceAtMaxBoost = (float)($promoRow['price_max_elastic'] ?? 0);

    $candidates = [
        ['field' => 'action_price', 'label' => 'Текущая цена в акции', 'boost' => (float)($promoRow['current_boost'] ?? 0), 'confidence_adjust' => false],
        ['field' => 'max_action_price', 'label' => 'Макс. цена входа', 'boost' => $minBoost, 'confidence_adjust' => true],
        ['field' => 'price_min_elastic', 'label' => 'Цена для мин. бустинга', 'boost' => $minBoost, 'confidence_adjust' => true],
        ['field' => 'price_max_elastic', 'label' => 'Цена для макс. бустинга', 'boost' => $maxBoost, 'confidence_adjust' => true],
    ];

    if ($minBoost > 0 && $maxBoost > $minBoost && $priceAtMinBoost > 0 && $priceAtMaxBoost > 0) {
        for ($boost = (int)ceil($minBoost / 5) * 5; $boost <= (int)floor($maxBoost / 5) * 5; $boost += 5) {
            if ($boost <= $minBoost + 0.01 || $boost >= $maxBoost - 0.01) {
                continue;
            }
            $price = ozon_price_interpolate_elastic_price((float)$boost, $minBoost, $maxBoost, $priceAtMinBoost, $priceAtMaxBoost);
            if ($price === null) {
                continue;
            }
            $candidates[] = [
                'field' => '',
                'label' => 'Цена для бустинга ' . $boost . '%',
                'boost' => (float)$boost,
                'price' => $price,
                'confidence_adjust' => true,
            ];
        }
    }

    $result = [];
    $regularCap = $baseRegular > 1 ? ozon_price_round_rub($baseRegular - 1.0) : ozon_price_round_rub($baseRegular);
    foreach ($candidates as $candidate) {
        $price = isset($candidate['price'])
            ? (float)$candidate['price']
            : (isset($promoRow[$candidate['field']]) ? (float)$promoRow[$candidate['field']] : 0.0);
        if ($price <= 0) {
            continue;
        }
        if (!empty($candidate['confidence_adjust'])) {
            $price = max(0.0, ozon_price_round_rub($price - 10.0));
        }
        if ($regularCap > 0 && $price > $regularCap + 0.01) {
            $price = $regularCap;
        }
        if ($price <= 0) {
            continue;
        }
        $key = number_format($price, 2, '.', '');
        $existing = $result[$key] ?? null;
        $entry = [
            'label' => (string)$candidate['label'],
            'price' => ozon_price_round_rub($price),
            'boost' => round((float)($candidate['boost'] ?? 0), 2),
        ];
        if ($existing === null || $entry['boost'] > ($existing['boost'] ?? 0)) {
            $result[$key] = $entry;
        }
    }
    return array_values($result);
}

function ozon_price_action_processed_candidates(array $promoRow, array $calc): array
{
    $baseRegular = (float)($calc['recommended_price'] ?? 0);
    $baseMin = (float)($calc['breakdown']['recommended_min_price_before_index_step'] ?? ($calc['recommended_min_price'] ?? 0));
    $floor = $baseMin > 0 ? round($baseMin * 0.95, 2) : 0.0;
    $comparisonPrice = isset($calc['breakdown']['price_index_benchmark']) ? (float)$calc['breakdown']['price_index_benchmark'] : 0.0;
    $metrics = is_array($calc['breakdown']['price_index_metrics'] ?? null) ? (array)$calc['breakdown']['price_index_metrics'] : [];
    $indexAvailable = $comparisonPrice > 0 || !empty($metrics);

    $rawCandidates = ozon_price_is_elastic_boosting_action($promoRow)
        ? ozon_price_elastic_action_candidates($promoRow, $baseRegular)
        : [
            ['field' => 'max_action_price', 'label' => 'Макс. цена входа', 'boost' => (float)($promoRow['min_boost'] ?? 0), 'confidence_adjust' => true],
            ['field' => 'action_price', 'label' => 'Текущая цена в акции', 'boost' => (float)($promoRow['current_boost'] ?? 0), 'confidence_adjust' => false],
            ['field' => 'price_min_elastic', 'label' => 'Цена для мин. бустинга', 'boost' => (float)($promoRow['min_boost'] ?? 0), 'confidence_adjust' => true],
            ['field' => 'price_max_elastic', 'label' => 'Цена для макс. бустинга', 'boost' => (float)($promoRow['max_boost'] ?? 0), 'confidence_adjust' => true],
        ];

    $candidates = [];
    foreach ($rawCandidates as $item) {
        $price = isset($item['price'])
            ? (float)$item['price']
            : (isset($promoRow[$item['field']]) ? (float)$promoRow[$item['field']] : 0.0);
        if ($price <= 0) {
            continue;
        }
        if (!empty($item['confidence_adjust'])) {
            $price = max(0.0, ozon_price_round_rub($price - 10.0));
        }
        if ($baseRegular > 0 && $price >= $baseRegular - 0.001) {
            continue;
        }
        if ($floor > 0 && $price < $floor - 0.01) {
            continue;
        }
        $key = number_format($price, 2, '.', '');
        $existing = $candidates[$key] ?? null;
        $candidate = [
            'source' => (string)$item['label'],
            'price' => ozon_price_round_rub($price),
            'boost_percent' => round((float)$item['boost'], 2),
            'index' => ozon_price_eval_calc_index_for_price($price, $calc),
            'below_base_min' => $baseMin > 0 && $price < $baseMin - 0.01,
        ];
        if ($existing === null || ($candidate['boost_percent'] > ($existing['boost_percent'] ?? 0))) {
            $candidates[$key] = $candidate;
        }
    }

    return array_values($candidates);
}

function ozon_price_action_boost_for_price(array $promoRow, float $price): float
{
    if ($price <= 0) {
        return 0.0;
    }

    if (ozon_price_is_elastic_boosting_action($promoRow)) {
        $minBoost = (float)($promoRow['min_boost'] ?? 0);
        $maxBoost = (float)($promoRow['max_boost'] ?? 0);
        $safeMinPrice = max(0.0, ozon_price_round_rub((float)($promoRow['price_min_elastic'] ?? 0) - 10.0));
        $safeMaxPrice = max(0.0, ozon_price_round_rub((float)($promoRow['price_max_elastic'] ?? 0) - 10.0));
        if ($minBoost > 0 && $maxBoost >= $minBoost && $safeMinPrice > 0 && $safeMaxPrice > 0) {
            if ($price >= $safeMinPrice - 0.01) {
                return round($minBoost, 2);
            }
            if ($price <= $safeMaxPrice + 0.01) {
                return round($maxBoost, 2);
            }
            if ($safeMinPrice > $safeMaxPrice + 0.01) {
                $ratio = ($safeMinPrice - $price) / ($safeMinPrice - $safeMaxPrice);
                $ratio = max(0.0, min(1.0, $ratio));
                return round($minBoost + (($maxBoost - $minBoost) * $ratio), 2);
            }
        }
    }

    $current = (float)($promoRow['current_boost'] ?? 0);
    $min = (float)($promoRow['min_boost'] ?? 0);
    $max = (float)($promoRow['max_boost'] ?? 0);
    return round(max($current, $min, $max), 2);
}

function ozon_price_action_state_for_price(array $promoRow, array $calc, float $price): ?array
{
    $price = ozon_price_round_rub($price);
    if ($price <= 0) {
        return null;
    }

    $processedCandidates = ozon_price_action_processed_candidates($promoRow, $calc);
    if (!$processedCandidates) {
        return null;
    }

    $baseRegular = (float)($calc['recommended_price'] ?? 0);
    $baseMin = (float)($calc['breakdown']['recommended_min_price_before_index_step'] ?? ($calc['recommended_min_price'] ?? 0));
    $floor = $baseMin > 0 ? round($baseMin * 0.95, 2) : 0.0;
    $comparisonPrice = isset($calc['breakdown']['price_index_benchmark']) ? (float)$calc['breakdown']['price_index_benchmark'] : 0.0;
    $metrics = is_array($calc['breakdown']['price_index_metrics'] ?? null) ? (array)$calc['breakdown']['price_index_metrics'] : [];
    $indexAvailable = $comparisonPrice > 0 || !empty($metrics);

    $maxCompatiblePrice = 0.0;
    $source = 'Совместимая цена общей стратегии';
    foreach ($processedCandidates as $candidate) {
        $candidatePrice = (float)($candidate['price'] ?? 0);
        if ($candidatePrice > $maxCompatiblePrice) {
            $maxCompatiblePrice = $candidatePrice;
        }
        if (abs($candidatePrice - $price) < 0.01) {
            $source = (string)($candidate['source'] ?? $source);
        }
    }

    if ($baseRegular > 0 && $price >= $baseRegular - 0.001) {
        return null;
    }
    if ($floor > 0 && $price < $floor - 0.01) {
        return null;
    }
    if ($maxCompatiblePrice > 0 && $price > $maxCompatiblePrice + 0.01) {
        return null;
    }

    $index = ozon_price_eval_calc_index_for_price($price, $calc);
    $boost = ozon_price_action_boost_for_price($promoRow, $price);
    $productId = (int)($promoRow['product_id'] ?? 0);

    return [
        'title' => (string)($promoRow['title'] ?? 'Акция Ozon'),
        'action_id' => (int)($promoRow['action_id'] ?? 0),
        'source_type' => (string)($promoRow['source_type'] ?? ''),
        'recommended_action_price' => $price,
        'recommended_action_source' => $source,
        'recommended_action_boost' => $boost,
        'recommended_action_index_value' => $index['value'],
        'recommended_action_index_level' => (string)($index['label'] ?? ozon_price_index_level_label('without_index')),
        'recommended_action_index_level_key' => (string)($index['level'] ?? 'without_index'),
        'recommended_action_below_min' => $baseMin > 0 && $price < $baseMin - 0.01,
        'base_min_price' => ozon_price_round_rub($baseMin),
        'floor_price' => ozon_price_round_rub($floor),
        'comparison_price' => $indexAvailable ? round($comparisonPrice, 2) : null,
        'index_available' => $indexAvailable,
        'best_safe_price' => $maxCompatiblePrice > 0 ? ozon_price_round_rub(min($maxCompatiblePrice, $baseRegular > 1 ? ozon_price_round_rub($baseRegular - 1.0) : $price)) : null,
        'best_safe_index_level' => (string)($index['label'] ?? ozon_price_index_level_label('without_index')),
        'best_safe_boost' => $boost,
        'reason' => $indexAvailable
            ? 'Цена совместима с итоговой стратегией и проходит в эту акцию по порогу входа.'
            : 'Цена совместима с итоговой стратегией и проходит в эту акцию по порогу входа.',
        'raw' => $promoRow,
    ];
}

function ozon_price_forced_action_state_for_price(array $promoRow, array $calc, float $price, array $forceRule = []): ?array
{
    $price = ozon_price_round_rub($price);
    if ($price <= 0) {
        return null;
    }

    $actionId = (int)($promoRow['action_id'] ?? 0);
    $productId = (int)($promoRow['product_id'] ?? 0);
    if ($actionId <= 0 || $productId <= 0) {
        return null;
    }

    $sourceType = (string)($promoRow['source_type'] ?? '');
    $isElastic = ozon_price_is_elastic_boosting_action($promoRow);
    if (!$isElastic && $sourceType !== 'participating') {
        return null;
    }

    $maxActionPrice = (float)($promoRow['max_action_price'] ?? 0);
    $priceMinElastic = (float)($promoRow['price_min_elastic'] ?? 0);
    $upperLimit = max($maxActionPrice, $priceMinElastic);
    if ($upperLimit <= 0 || $price > $upperLimit + 0.01) {
        return null;
    }

    $baseRegular = (float)($calc['recommended_price'] ?? 0);
    if ($baseRegular > 0 && $price >= $baseRegular - 0.001) {
        return null;
    }

    $baseMin = (float)($calc['breakdown']['recommended_min_price_before_index_step'] ?? ($calc['recommended_min_price'] ?? 0));
    $floor = $baseMin > 0 ? round($baseMin * 0.95, 2) : 0.0;
    $comparisonPrice = isset($calc['breakdown']['price_index_benchmark']) ? (float)$calc['breakdown']['price_index_benchmark'] : 0.0;
    $metrics = is_array($calc['breakdown']['price_index_metrics'] ?? null) ? (array)$calc['breakdown']['price_index_metrics'] : [];
    $indexAvailable = $comparisonPrice > 0 || !empty($metrics);
    $index = ozon_price_eval_calc_index_for_price($price, $calc);
    $boost = ozon_price_action_boost_for_price($promoRow, $price);
    $forceLabel = trim((string)($forceRule['label'] ?? 'FBO Tool'));

    return [
        'title' => (string)($promoRow['title'] ?? 'Акция Ozon'),
        'action_id' => $actionId,
        'source_type' => $sourceType,
        'recommended_action_price' => $price,
        'recommended_action_source' => ($forceLabel !== '' ? $forceLabel : 'FBO Tool') . ' · цена проходит в акцию',
        'recommended_action_boost' => $boost,
        'recommended_action_index_value' => $index['value'],
        'recommended_action_index_level' => (string)($index['label'] ?? ozon_price_index_level_label('without_index')),
        'recommended_action_index_level_key' => (string)($index['level'] ?? 'without_index'),
        'recommended_action_below_min' => $baseMin > 0 && $price < $baseMin - 0.01,
        'base_min_price' => ozon_price_round_rub($baseMin),
        'floor_price' => ozon_price_round_rub($floor),
        'comparison_price' => $indexAvailable ? round($comparisonPrice, 2) : null,
        'index_available' => $indexAvailable,
        'best_safe_price' => $upperLimit > 0 ? ozon_price_round_rub($upperLimit) : null,
        'best_safe_index_level' => (string)($index['label'] ?? ozon_price_index_level_label('without_index')),
        'best_safe_boost' => $boost,
        'reason' => 'Сниженная FBO-цена проходит в акцию по максимальной допустимой цене маркетплейса.',
        'raw' => $promoRow,
    ];
}

function ozon_price_action_plan_for_row(array $promoRow, array $calc): ?array
{
    $processedCandidates = ozon_price_action_processed_candidates($promoRow, $calc);
    $baseMin = (float)($calc['breakdown']['recommended_min_price_before_index_step'] ?? ($calc['recommended_min_price'] ?? 0));
    $floor = $baseMin > 0 ? round($baseMin * 0.95, 2) : 0.0;
    $comparisonPrice = isset($calc['breakdown']['price_index_benchmark']) ? (float)$calc['breakdown']['price_index_benchmark'] : 0.0;
    $metrics = is_array($calc['breakdown']['price_index_metrics'] ?? null) ? (array)$calc['breakdown']['price_index_metrics'] : [];
    $indexAvailable = $comparisonPrice > 0 || !empty($metrics);
    $candidates = [];
    foreach ($processedCandidates as $candidate) {
        $candidates[number_format((float)$candidate['price'], 2, '.', '')] = $candidate;
    }
    if (!$candidates) {
        return null;
    }

    $allowed = array_values($candidates);
    $sortFn = static function (array $a, array $b) use ($indexAvailable): int {
        $left = [
            'recommended_action_index_level_key' => (string)($a['index']['level'] ?? 'without_index'),
            'recommended_action_boost' => (float)($a['boost_percent'] ?? 0),
            'recommended_action_price' => (float)($a['price'] ?? 0),
        ];
        $right = [
            'recommended_action_index_level_key' => (string)($b['index']['level'] ?? 'without_index'),
            'recommended_action_boost' => (float)($b['boost_percent'] ?? 0),
            'recommended_action_price' => (float)($b['price'] ?? 0),
        ];
        return ozon_price_compare_action_plans($left, $right, $indexAvailable);
    };
    usort($allowed, $sortFn);
    $safe = array_values(array_filter($allowed, static fn(array $candidate): bool => !$candidate['below_base_min']));
    if ($safe) {
        usort($safe, $sortFn);
    }

    $bestAny = $allowed[0];
    $bestSafe = $safe[0] ?? null;
    $chosen = $bestAny;
    if ($bestSafe !== null && $bestAny['below_base_min']) {
        $improves = $indexAvailable
            ? (($bestAny['index']['rank'] ?? 0) > ($bestSafe['index']['rank'] ?? 0))
            : (($bestAny['boost_percent'] ?? 0) > ($bestSafe['boost_percent'] ?? 0));
        if (!$improves) {
            $chosen = $bestSafe;
        }
    }

    $reason = $indexAvailable
        ? 'Для акции сначала ищем лучший доступный уровень индекса, затем внутри него выбираем максимальный доступный бустинг, а при равных условиях оставляем самую высокую цену ниже обычной.'
        : 'Индекс для товара не определён, поэтому внутри акции выбираем максимальный бустинг и при равном бустинге оставляем самую высокую цену ниже обычной.';

    return [
        'title' => (string)($promoRow['title'] ?? 'Акция Ozon'),
        'action_id' => (int)($promoRow['action_id'] ?? 0),
        'source_type' => (string)($promoRow['source_type'] ?? ''),
        'recommended_action_price' => ozon_price_round_rub((float)$chosen['price']),
        'recommended_action_source' => (string)$chosen['source'],
        'recommended_action_boost' => round((float)($chosen['boost_percent'] ?? 0), 2),
        'recommended_action_index_value' => $chosen['index']['value'],
        'recommended_action_index_level' => (string)($chosen['index']['label'] ?? ozon_price_index_level_label('without_index')),
        'recommended_action_index_level_key' => (string)($chosen['index']['level'] ?? 'without_index'),
        'recommended_action_below_min' => !empty($chosen['below_base_min']),
        'base_min_price' => ozon_price_round_rub($baseMin),
        'floor_price' => ozon_price_round_rub($floor),
        'comparison_price' => $indexAvailable ? round($comparisonPrice, 2) : null,
        'index_available' => $indexAvailable,
        'best_safe_price' => $bestSafe['price'] ?? null,
        'best_safe_index_level' => $bestSafe['index']['label'] ?? null,
        'best_safe_boost' => $bestSafe['boost_percent'] ?? null,
        'reason' => $reason,
        'raw' => $promoRow,
    ];
}

function ozon_price_build_promotion_strategy(array $calc, array $promoRows, array $settings = []): array
{
    $baseRegular = (float)($calc['recommended_price'] ?? 0);
    $baseMin = (float)($calc['recommended_min_price'] ?? 0);
    $baseMinBeforeIndex = (float)($calc['breakdown']['recommended_min_price_before_index_step'] ?? $baseMin);
    $actionsEnabled = !array_key_exists('action_pricing_enabled', $settings) || !empty($settings['action_pricing_enabled']);

    $actionPlans = [];
    if ($actionsEnabled) {
        foreach ($promoRows as $promoRow) {
            $plan = ozon_price_action_plan_for_row($promoRow, $calc);
            if ($plan !== null) {
                $actionPlans[] = $plan;
            }
        }
    }

    $comparisonPriceAvailable = !empty($calc['breakdown']['price_index_benchmark'])
        || !empty($calc['breakdown']['price_index_metrics']);
    if ($actionPlans) {
        usort($actionPlans, static fn(array $a, array $b): int => ozon_price_compare_action_plans($a, $b, $comparisonPriceAvailable));
        $primary = $actionPlans[0];
        $selectedPrice = (float)($primary['recommended_action_price'] ?? 0);
        $selectedProfitRub = null;
        if (!empty($calc['ozon_item']) && (float)($calc['purchase_cost'] ?? 0) > 0 && $selectedPrice > 0) {
            $selectedSnapshot = ozon_price_profit_snapshot($settings, (array)$calc['ozon_item'], (float)$calc['purchase_cost'], $selectedPrice);
            $selectedProfitRub = is_array($selectedSnapshot) ? (float)($selectedSnapshot['profit_rub'] ?? 0) : null;
        }
        $actionSortFn = static fn(array $a, array $b): int => ozon_price_compare_action_plans($a, $b, $comparisonPriceAvailable);
        $buildCompatibleActions = static function (float $price) use ($promoRows, $calc): array {
            $actions = [];
            foreach ($promoRows as $promoRow) {
                $state = ozon_price_action_state_for_price($promoRow, $calc, $price);
                if ($state !== null) {
                    $actions[] = $state;
                }
            }
            return $actions;
        };

        $selectedActions = $buildCompatibleActions($selectedPrice);
        $selectedPrimary = null;
        foreach ($selectedActions as $candidateAction) {
            if ((int)($candidateAction['action_id'] ?? 0) === (int)($primary['action_id'] ?? 0)) {
                $selectedPrimary = $candidateAction;
                break;
            }
        }
        if ($selectedPrimary === null) {
            $selectedPrimary = $primary;
            $selectedActions = [$primary];
        }

        $currentActionCount = count($selectedActions);
        $currentPrimaryRank = ozon_price_index_level_rank((string)($selectedPrimary['recommended_action_index_level_key'] ?? 'without_index'));
        $currentPrimaryBoost = (float)($selectedPrimary['recommended_action_boost'] ?? 0);
        $candidatePrices = [];
        foreach ($actionPlans as $plan) {
            $planPrice = ozon_price_round_rub((float)($plan['recommended_action_price'] ?? 0));
            if ($planPrice > 0 && $planPrice < $selectedPrice - 0.01) {
                $candidatePrices[number_format($planPrice, 2, '.', '')] = $planPrice;
            }
        }
        $budgetCandidatePrice = ozon_price_round_rub((float)($calc['breakdown']['budget_goods_candidate_price'] ?? 0));
        if ($budgetCandidatePrice > 0 && $budgetCandidatePrice < $selectedPrice - 0.01) {
            $candidatePrices[number_format($budgetCandidatePrice, 2, '.', '')] = $budgetCandidatePrice;
        }
        $budgetBoundaryCandidatePrice = ozon_price_round_rub((float)($calc['breakdown']['budget_goods_boundary_candidate_price'] ?? 0));
        if ($budgetBoundaryCandidatePrice > 0 && $budgetBoundaryCandidatePrice < $selectedPrice - 0.01) {
            $candidatePrices[number_format($budgetBoundaryCandidatePrice, 2, '.', '')] = $budgetBoundaryCandidatePrice;
        }
        rsort($candidatePrices, SORT_NUMERIC);

        $expandedScenario = null;
        foreach ($candidatePrices as $candidatePrice) {
            $candidateActions = $buildCompatibleActions((float)$candidatePrice);
            $candidatePrimary = null;
            foreach ($candidateActions as $candidateAction) {
                if ((int)($candidateAction['action_id'] ?? 0) === (int)($primary['action_id'] ?? 0)) {
                    $candidatePrimary = $candidateAction;
                    break;
                }
            }
            if ($candidatePrimary === null) {
                usort($candidateActions, $actionSortFn);
                $candidatePrimary = $candidateActions[0] ?? null;
            }
            if ($candidatePrimary === null) {
                continue;
            }
            $candidatePrimaryRank = ozon_price_index_level_rank((string)($candidatePrimary['recommended_action_index_level_key'] ?? 'without_index'));
            $candidatePrimaryBoost = (float)($candidatePrimary['recommended_action_boost'] ?? 0);
            if ($comparisonPriceAvailable && $candidatePrimaryRank < $currentPrimaryRank) {
                continue;
            }
            if ($candidatePrimaryBoost + 0.01 < $currentPrimaryBoost) {
                continue;
            }
            $candidateProfitRub = null;
            if (!empty($calc['ozon_item']) && (float)($calc['purchase_cost'] ?? 0) > 0 && $candidatePrice > 0) {
                $candidateSnapshot = ozon_price_profit_snapshot($settings, (array)$calc['ozon_item'], (float)$calc['purchase_cost'], (float)$candidatePrice);
                $candidateProfitRub = is_array($candidateSnapshot) ? (float)($candidateSnapshot['profit_rub'] ?? 0) : null;
            }
            $improvesScenario = count($candidateActions) > $currentActionCount
                || (
                    count($candidateActions) === $currentActionCount
                    && $candidatePrimaryBoost > $currentPrimaryBoost + 0.01
                )
                || (
                    count($candidateActions) === $currentActionCount
                    && abs($candidatePrimaryBoost - $currentPrimaryBoost) < 0.01
                    && $candidateProfitRub !== null
                    && $selectedProfitRub !== null
                    && $candidateProfitRub > $selectedProfitRub + 0.01
                );
            if (!$improvesScenario) {
                continue;
            }
            if (
                $expandedScenario === null
                || count($candidateActions) > count((array)$expandedScenario['actions'])
                || (
                    count($candidateActions) === count((array)$expandedScenario['actions'])
                    && $candidatePrimaryBoost > (float)($expandedScenario['primary']['recommended_action_boost'] ?? 0) + 0.01
                )
                || (
                    count($candidateActions) === count((array)$expandedScenario['actions'])
                    && abs($candidatePrimaryBoost - (float)($expandedScenario['primary']['recommended_action_boost'] ?? 0)) < 0.01
                    && $candidatePrice > (float)$expandedScenario['price']
                )
            ) {
                $expandedScenario = [
                    'price' => (float)$candidatePrice,
                    'actions' => $candidateActions,
                    'primary' => $candidatePrimary,
                    'profit_rub' => $candidateProfitRub,
                ];
            }
        }

        if ($expandedScenario !== null) {
            $selectedPrice = (float)$expandedScenario['price'];
            $selectedActions = (array)$expandedScenario['actions'];
            $selectedPrimary = (array)$expandedScenario['primary'];
            $selectedProfitRub = $expandedScenario['profit_rub'] ?? $selectedProfitRub;
        }

        foreach ($selectedActions as &$actionPlan) {
            $actionPlan['preferred'] = (int)($actionPlan['action_id'] ?? 0) === (int)($selectedPrimary['action_id'] ?? 0);
        }
        unset($actionPlan);
        usort($selectedActions, static function (array $a, array $b): int {
            $preferredCmp = (int)!empty($b['preferred']) <=> (int)!empty($a['preferred']);
            if ($preferredCmp !== 0) {
                return $preferredCmp;
            }
            return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });

        if (!$selectedActions) {
            $selectedPrimary['preferred'] = true;
            $selectedActions[] = $selectedPrimary;
        }
        return [
            'mode' => 'action',
            'final_price' => ozon_price_round_rub($baseRegular),
            'final_min_price' => ozon_price_round_rub($baseMin),
            'base_min_price' => ozon_price_round_rub($baseMinBeforeIndex),
            'reason' => 'Для товара доступны акции, поэтому продвигаем его через акцию. Сначала выбираем лучшую цену для продвижения, а затем добавляем товар во все акции, куда он проходит уже с этой же ценой.',
            'best_action' => $selectedPrimary,
            'actions' => $selectedActions,
            'all_actions' => $actionPlans,
            'index_strategy' => null,
        ];
    }

    $indexStrategy = ozon_price_build_index_only_strategy($calc);
    return [
        'mode' => 'index',
        'final_price' => ozon_price_round_rub((float)($indexStrategy['final_price'] ?? $baseRegular)),
        'final_min_price' => ozon_price_round_rub($baseMin),
        'base_min_price' => ozon_price_round_rub($baseMinBeforeIndex),
        'reason' => $actionsEnabled
            ? 'Подходящих акций нет, поэтому используем индекс цены как единственный инструмент продвижения и выбираем лучшую обычную цену.'
            : 'Продвижение через акции отключено, поэтому используем только индекс цены и выбираем лучшую обычную цену.',
        'best_action' => null,
        'actions' => [],
        'index_strategy' => $indexStrategy,
    ];
}

function ozon_price_payload_old_price(float $price, ?float $preferredOldPrice = null, ?float $currentOldPrice = null): float
{
    $price = ozon_price_round_rub($price);
    foreach ([$preferredOldPrice, $currentOldPrice] as $candidate) {
        if ($candidate === null) {
            continue;
        }
        $candidate = ozon_price_round_rub((float)$candidate);
        if ($candidate > $price + 0.01) {
            return $candidate;
        }
    }

    return ozon_price_round_rub($price + 1.0);
}

function ozon_price_import_prices(array $cfg, array $prices): array
{
    $normalized = [];
    foreach ($prices as $priceRow) {
        if (!is_array($priceRow)) {
            continue;
        }
        $offerId = trim((string)($priceRow['offer_id'] ?? ''));
        $productId = (int)($priceRow['product_id'] ?? 0);
        $price = ozon_price_round_rub((float)($priceRow['price'] ?? 0));
        $minPrice = ozon_price_round_rub((float)($priceRow['min_price'] ?? 0));
        if ($offerId === '' && $productId <= 0) {
            continue;
        }
        if ($price <= 0 || $minPrice <= 0) {
            continue;
        }
        $payload = [
            'price' => number_format($price, 0, '.', ''),
            'min_price' => number_format($minPrice, 0, '.', ''),
            'currency_code' => trim((string)($priceRow['currency_code'] ?? '')) !== ''
                ? trim((string)$priceRow['currency_code'])
                : 'RUB',
        ];
        if (array_key_exists('old_price', $priceRow) || array_key_exists('current_old_price', $priceRow)) {
            $preferredOldPrice = array_key_exists('old_price', $priceRow) && $priceRow['old_price'] !== null && $priceRow['old_price'] !== ''
                ? (float)$priceRow['old_price']
                : null;
            $currentOldPrice = array_key_exists('current_old_price', $priceRow) && $priceRow['current_old_price'] !== null && $priceRow['current_old_price'] !== ''
                ? (float)$priceRow['current_old_price']
                : null;
            $payload['old_price'] = number_format(
                ozon_price_payload_old_price($price, $preferredOldPrice, $currentOldPrice),
                0,
                '.',
                ''
            );
        }
        if ($offerId !== '') {
            if (ozon_price_api_offer_id_error($offerId) !== '') {
                if ($productId <= 0) {
                    continue;
                }
            } else {
                $payload['offer_id'] = $offerId;
            }
        }
        if ($productId > 0) {
            $payload['product_id'] = $productId;
        }
        $normalized[] = $payload;
    }
    if (!$normalized) {
        return ['result' => []];
    }
    $oz = ozon_cfg_or_fail($cfg);
    return ozon_post_json($oz, '/v1/product/import/prices', [
        'prices' => $normalized,
    ]);
}

function ozon_price_calc_is_archived(array $calc): bool
{
    return !empty($calc['ozon_archived']);
}

function ozon_price_build_desired_state(
    array $calc,
    array $promotionStrategy,
    array $promoRows,
    ?int $connectionId = null,
    array $cfg = [],
    bool $ignoreFboForceRule = false
): array
{
    $offerId = trim((string)($calc['offer_id'] ?? ''));
    $productId = (int)($calc['product_id'] ?? 0);
    $forceContext = [
        'category_id' => (string)($calc['category_id'] ?? ''),
        'category_name' => (string)($calc['category_name'] ?? ''),
        'category_path' => (string)($calc['category_path'] ?? ''),
        'brand' => (string)($calc['brand'] ?? ''),
        'vendor' => (string)($calc['vendor'] ?? ''),
    ];
    if (ozon_price_calc_is_archived($calc)) {
        return [
            'offer_id' => $offerId,
            'product_id' => $productId,
            'mode' => 'archived',
            'regular_price' => 0.0,
            'min_price' => 0.0,
            'old_price' => null,
            'strike_price' => null,
            'marketing_price' => null,
            'base_regular_price' => 0.0,
            'base_min_price' => 0.0,
            'base_marketing_price' => null,
            'current_regular_price' => isset($calc['ozon_price']) ? round((float)$calc['ozon_price'], 2) : null,
            'current_min_price' => isset($calc['ozon_min_price']) ? round((float)$calc['ozon_min_price'], 2) : null,
            'current_old_price' => isset($calc['ozon_old_price']) ? round((float)$calc['ozon_old_price'], 2) : null,
            'force_rule' => ($ignoreFboForceRule ? null : ozon_price_fbo_force_rule_for_offer($offerId, $connectionId, $cfg))
                ?? ozon_price_force_rule_for_offer($offerId, $connectionId, $cfg, $forceContext),
            'price_adjustment' => null,
            'validation' => [
                'regular_price' => 0.0,
                'min_price' => 0.0,
                'notes' => [],
                'is_valid' => false,
                'reason' => 'Товар находится в архиве Ozon.',
            ],
            'calc_warnings' => array_values(array_filter((array)($calc['warnings'] ?? []))),
            'price_changed' => false,
            'min_price_changed' => false,
            'desired_actions' => [],
            'current_actions' => [],
            'actions_upsert' => [],
            'actions_remove' => [],
            'summary' => [
                'actions_add_count' => 0,
                'actions_update_count' => 0,
                'actions_remove_count' => 0,
            ],
            'reason' => 'Товар в архиве Ozon, цены и акции не обновляются.',
        ];
    }

    $regularPrice = ozon_price_round_rub((float)($promotionStrategy['final_price'] ?? ($calc['recommended_price'] ?? 0)));
    $minPrice = ozon_price_round_rub((float)($promotionStrategy['final_min_price'] ?? ($calc['recommended_min_price'] ?? 0)));

    $desiredActions = [];
    foreach ((array)($promotionStrategy['actions'] ?? []) as $actionPlan) {
        $actionId = (int)($actionPlan['action_id'] ?? 0);
        $actionPrice = ozon_price_round_rub((float)($actionPlan['recommended_action_price'] ?? 0));
        if ($actionId <= 0 || $actionPrice <= 0) {
            continue;
        }
        $planProductId = (int)($actionPlan['raw']['product_id'] ?? 0);
        $desiredActions[$actionId] = [
            'action_id' => $actionId,
            'title' => (string)($actionPlan['title'] ?? 'Акция Ozon'),
            'product_id' => $planProductId > 0 ? $planProductId : $productId,
            'action_price' => $actionPrice,
            'source' => (string)($actionPlan['recommended_action_source'] ?? ''),
            'boost' => (float)($actionPlan['recommended_action_boost'] ?? 0),
            'index_level' => (string)($actionPlan['recommended_action_index_level'] ?? ''),
        ];
    }

    $currentParticipating = [];
    foreach ($promoRows as $promoRow) {
        if ((string)($promoRow['source_type'] ?? '') !== 'participating') {
            continue;
        }
        $actionId = (int)($promoRow['action_id'] ?? 0);
        if ($actionId <= 0) {
            continue;
        }
        $currentParticipating[$actionId] = [
            'action_id' => $actionId,
            'title' => (string)($promoRow['title'] ?? 'Акция Ozon'),
            'product_id' => (int)($promoRow['product_id'] ?? 0),
            'action_price' => ozon_price_round_rub((float)($promoRow['action_price'] ?? 0)),
        ];
    }

    $actionAllowedFloor = 0.0;
    if (!empty($promotionStrategy['best_action']['floor_price'])) {
        $actionAllowedFloor = ozon_price_round_rub((float)$promotionStrategy['best_action']['floor_price']);
    } elseif (!empty($promotionStrategy['base_min_price'])) {
        $actionAllowedFloor = ozon_price_round_rub((float)$promotionStrategy['base_min_price'] * 0.95);
    } elseif (!empty($calc['breakdown']['recommended_min_price_before_index_step'])) {
        $actionAllowedFloor = ozon_price_round_rub((float)$calc['breakdown']['recommended_min_price_before_index_step'] * 0.95);
    } elseif (!empty($calc['recommended_min_price'])) {
        $actionAllowedFloor = ozon_price_round_rub((float)$calc['recommended_min_price'] * 0.95);
    }

    $actionsRemove = [];
    foreach ($currentParticipating as $actionId => $action) {
        if (!isset($desiredActions[$actionId])) {
            $currentActionPrice = ozon_price_round_rub((float)($action['action_price'] ?? 0));
            if ($actionAllowedFloor > 0 && $currentActionPrice > 0 && $currentActionPrice < $actionAllowedFloor - 0.01) {
                $action['change_type'] = 'remove';
                $action['remove_reason'] = 'Текущая цена товара в акции ниже допустимой нижней границы.';
                $action['current_action_price'] = $currentActionPrice;
                $action['allowed_floor_price'] = $actionAllowedFloor;
                $actionsRemove[] = $action;
            }
        }
    }

    $marketingPrice = 0.0;
    if (($promotionStrategy['mode'] ?? '') === 'action' && !empty($promotionStrategy['best_action'])) {
        $marketingPrice = ozon_price_round_rub((float)($promotionStrategy['best_action']['recommended_action_price'] ?? 0));
    } elseif (($promotionStrategy['mode'] ?? '') === 'index') {
        $marketingPrice = $regularPrice;
    }

    $baseRegularPrice = $regularPrice;
    $baseMinPrice = $minPrice;
    $baseMarketingPrice = $marketingPrice > 0 ? $marketingPrice : null;
    $priceAdjustment = null;
    $forceRule = ($ignoreFboForceRule ? null : ozon_price_fbo_force_rule_for_offer($offerId, $connectionId, $cfg))
        ?? ozon_price_force_rule_for_offer($offerId, $connectionId, $cfg, $forceContext);
    if (is_array($forceRule)) {
        $regularPrice = ozon_price_apply_force_rule($regularPrice, $forceRule);
        $minPrice = ozon_price_apply_force_rule($minPrice, $forceRule);
        if ($marketingPrice > 0) {
            $marketingPrice = ozon_price_apply_force_rule($marketingPrice, $forceRule);
        }
        foreach ($desiredActions as &$actionRow) {
            $actionPriceBeforeForce = ozon_price_round_rub((float)($actionRow['action_price'] ?? 0));
            $actionRow['action_price_before_force'] = $actionPriceBeforeForce;
            $actionRow['action_price'] = ozon_price_apply_force_rule($actionPriceBeforeForce, $forceRule);
            $actionRow['forced_rule'] = (string)($forceRule['label'] ?? '');
            $actionRow['source'] = trim((string)($actionRow['source'] ?? '')) !== ''
                ? ((string)$actionRow['source'] . ' · ' . (string)($forceRule['label'] ?? 'FBO Tool'))
                : (string)($forceRule['label'] ?? 'FBO Tool');
        }
        unset($actionRow);
        $priceAdjustment = [
            'applied' => true,
            'type' => !empty($forceRule['fbo_rule']) ? 'fbo' : 'force',
            'label' => (string)($forceRule['label'] ?? ''),
            'source_line' => (string)($forceRule['source_line'] ?? ''),
            'regular_price_before' => $baseRegularPrice,
            'regular_price_after' => $regularPrice,
            'min_price_before' => $baseMinPrice,
            'min_price_after' => $minPrice,
            'marketing_price_before' => $baseMarketingPrice,
            'marketing_price_after' => $marketingPrice > 0 ? $marketingPrice : null,
        ];

        if (!empty($forceRule['fbo_rule'])) {
            $forcedActionPrice = ozon_price_round_rub((float)($forceRule['value'] ?? 0));
            if ($forcedActionPrice <= 0) {
                $forcedActionPrice = $marketingPrice > 0 ? $marketingPrice : $regularPrice;
            }
            foreach ($promoRows as $promoRow) {
                if (!is_array($promoRow)) {
                    continue;
                }
                $actionId = (int)($promoRow['action_id'] ?? 0);
                if ($actionId <= 0 || isset($desiredActions[$actionId])) {
                    continue;
                }
                $forcedAction = ozon_price_forced_action_state_for_price($promoRow, $calc, $forcedActionPrice, $forceRule);
                if ($forcedAction === null) {
                    continue;
                }
                $planProductId = (int)($forcedAction['raw']['product_id'] ?? 0);
                $desiredActions[$actionId] = [
                    'action_id' => $actionId,
                    'title' => (string)($forcedAction['title'] ?? 'Акция Ozon'),
                    'product_id' => $planProductId > 0 ? $planProductId : $productId,
                    'action_price' => ozon_price_round_rub((float)($forcedAction['recommended_action_price'] ?? $forcedActionPrice)),
                    'source' => (string)($forcedAction['recommended_action_source'] ?? 'FBO Tool'),
                    'boost' => (float)($forcedAction['recommended_action_boost'] ?? 0),
                    'index_level' => (string)($forcedAction['recommended_action_index_level'] ?? ''),
                ];
            }
        }
    }

    $actionsUpsert = [];
    foreach ($desiredActions as $actionId => $action) {
        $current = $currentParticipating[$actionId] ?? null;
        $needsUpsert = $current === null
            || abs((float)($current['action_price'] ?? 0) - (float)$action['action_price']) > 0.01;
        if ($needsUpsert) {
            $action['change_type'] = $current === null ? 'add' : 'update';
            $actionsUpsert[] = $action;
        }
    }

    $currentPrice = isset($calc['ozon_price']) ? ozon_price_round_rub((float)$calc['ozon_price']) : null;
    $currentMinPrice = isset($calc['ozon_min_price']) ? ozon_price_round_rub((float)$calc['ozon_min_price']) : null;
    $currentOldPrice = isset($calc['ozon_old_price']) ? ozon_price_round_rub((float)$calc['ozon_old_price']) : null;
    $strikePrice = isset($calc['strike_price']) ? ozon_price_round_rub((float)$calc['strike_price']) : null;
    $validation = ozon_price_finalize_desired_prices($regularPrice, $minPrice, $currentPrice, $currentMinPrice);
    $regularPrice = (float)$validation['regular_price'];
    $minPrice = (float)$validation['min_price'];
    $oldPrice = ozon_price_payload_old_price($regularPrice, $strikePrice, $currentOldPrice);
    if (is_array($priceAdjustment)) {
        $priceAdjustment['regular_price_after'] = $regularPrice;
        $priceAdjustment['min_price_after'] = $minPrice;
    }

    return [
        'offer_id' => $offerId,
        'product_id' => $productId,
        'mode' => (string)($promotionStrategy['mode'] ?? 'base'),
        'regular_price' => $regularPrice,
        'min_price' => $minPrice,
        'old_price' => $oldPrice,
        'strike_price' => $strikePrice !== null ? ozon_price_round_rub((float)$strikePrice) : null,
        'marketing_price' => $marketingPrice > 0 ? $marketingPrice : null,
        'base_regular_price' => $baseRegularPrice,
        'base_min_price' => $baseMinPrice,
        'base_marketing_price' => $baseMarketingPrice,
        'current_regular_price' => $currentPrice,
        'current_min_price' => $currentMinPrice,
        'current_old_price' => $currentOldPrice,
        'force_rule' => $forceRule,
        'price_adjustment' => $priceAdjustment,
        'validation' => $validation,
        'calc_warnings' => array_values(array_filter((array)($calc['warnings'] ?? []))),
        'price_changed' => $currentPrice === null || abs($regularPrice - $currentPrice) > 0.01,
        'min_price_changed' => $currentMinPrice === null || abs($minPrice - $currentMinPrice) > 0.01,
        'desired_actions' => array_values($desiredActions),
        'current_actions' => array_values($currentParticipating),
        'actions_upsert' => $actionsUpsert,
        'actions_remove' => $actionsRemove,
        'summary' => [
            'actions_add_count' => count(array_filter($actionsUpsert, static fn(array $row): bool => ($row['change_type'] ?? '') === 'add')),
            'actions_update_count' => count(array_filter($actionsUpsert, static fn(array $row): bool => ($row['change_type'] ?? '') === 'update')),
            'actions_remove_count' => count($actionsRemove),
        ],
        'reason' => (string)($promotionStrategy['reason'] ?? ''),
    ];
}

function ozon_price_apply_desired_state(array $cfg, array $feed, array $desiredState, ?string $actor = null): array
{
    $offerId = trim((string)($desiredState['offer_id'] ?? ''));
    if ($offerId === '') {
        throw new RuntimeException('Не удалось определить offer_id для выгрузки в Ozon.');
    }

    $result = [
        'offer_id' => $offerId,
        'price_import' => null,
        'price_import_skipped' => false,
        'price_import_skip_reason' => '',
        'actions_upsert' => [],
        'actions_remove' => [],
        'errors' => [],
        'status' => 'ok',
    ];

    $priceChanged = !empty($desiredState['price_changed']) || !empty($desiredState['min_price_changed']);
    $hasActionChanges = !empty($desiredState['actions_upsert']) || !empty($desiredState['actions_remove']);

    if ($priceChanged) {
        try {
            $result['price_import'] = ozon_price_import_prices($cfg, [[
                'offer_id' => $offerId,
                'product_id' => (int)($desiredState['product_id'] ?? 0),
                'price' => (float)($desiredState['regular_price'] ?? 0),
                'min_price' => (float)($desiredState['min_price'] ?? 0),
                'old_price' => (float)($desiredState['old_price'] ?? 0),
                'current_old_price' => $desiredState['current_old_price'] ?? null,
            ]]);
        } catch (Throwable $e) {
            $result['errors'][] = 'Не удалось обновить обычную цену / min price: ' . $e->getMessage();
        }
    } else {
        $result['price_import_skipped'] = true;
        $result['price_import_skip_reason'] = 'Текущие price и min_price уже совпадают с расчётными.';
    }

    foreach ((array)($desiredState['actions_upsert'] ?? []) as $actionRow) {
        try {
            $result['actions_upsert'][] = [
                'action_id' => (int)($actionRow['action_id'] ?? 0),
                'title' => (string)($actionRow['title'] ?? ''),
                'change_type' => (string)($actionRow['change_type'] ?? 'add'),
                'action_price' => round((float)($actionRow['action_price'] ?? 0), 2),
                'product_id' => (int)($actionRow['product_id'] ?? 0),
                'response' => ozon_actions_activate_products($cfg, (int)($actionRow['action_id'] ?? 0), [[
                    'product_id' => (int)($actionRow['product_id'] ?? 0),
                    'action_price' => (float)($actionRow['action_price'] ?? 0),
                ]]),
            ];
        } catch (Throwable $e) {
            $result['errors'][] = 'Не удалось добавить/обновить акцию "' . (string)($actionRow['title'] ?? 'Ozon') . '": ' . $e->getMessage();
        }
    }

    foreach ((array)($desiredState['actions_remove'] ?? []) as $actionRow) {
        try {
            $result['actions_remove'][] = [
                'action_id' => (int)($actionRow['action_id'] ?? 0),
                'title' => (string)($actionRow['title'] ?? ''),
                'product_id' => (int)($actionRow['product_id'] ?? 0),
                'response' => ozon_actions_deactivate_products($cfg, (int)($actionRow['action_id'] ?? 0), [
                    (int)($actionRow['product_id'] ?? 0),
                ]),
            ];
        } catch (Throwable $e) {
            $result['errors'][] = 'Не удалось удалить товар из акции "' . (string)($actionRow['title'] ?? 'Ozon') . '": ' . $e->getMessage();
        }
    }

    if ($result['errors']) {
        $result['status'] = 'partial_error';
    } elseif (!$priceChanged && !$hasActionChanges) {
        $result['status'] = 'skipped';
    }

    ozon_price_push_log_write(
        (int)($feed['id'] ?? 0),
        $actor,
        $offerId,
        (int)($desiredState['product_id'] ?? 0),
        $result['status'],
        $desiredState,
        $result,
        (int)($feed['connection_id'] ?? 0)
    );

    return $result;
}

function ozon_price_finalize_desired_prices(float $regularPrice, float $minPrice, ?float $currentPrice, ?float $currentMinPrice): array
{
    $notes = [];

    if ($regularPrice <= 0 && $currentPrice !== null && $currentPrice > 0) {
        $regularPrice = ozon_price_round_rub($currentPrice);
        $notes[] = 'Обычная цена взята из текущего price Ozon.';
    }
    if ($minPrice <= 0 && $currentMinPrice !== null && $currentMinPrice > 0) {
        $minPrice = ozon_price_round_rub($currentMinPrice);
        $notes[] = 'Минимальная цена взята из текущего min_price Ozon.';
    }
    if ($regularPrice <= 0 && $minPrice > 0) {
        $regularPrice = ozon_price_round_rub($minPrice);
        $notes[] = 'Обычная цена восстановлена из минимальной цены.';
    }
    if ($minPrice <= 0 && $regularPrice > 0) {
        $minPrice = ozon_price_round_rub($regularPrice);
        $notes[] = 'Минимальная цена восстановлена из обычной цены.';
    }
    if ($regularPrice > 0 && $minPrice > $regularPrice) {
        $minPrice = ozon_price_round_rub($regularPrice);
        $notes[] = 'Минимальная цена ограничена сверху обычной ценой.';
    }
    if ($regularPrice > 0 && $minPrice > 0) {
        $ozonMinPriceFloor = ozon_price_round_rub(ceil($regularPrice * 0.5));
        if ($minPrice < $ozonMinPriceFloor) {
            $minPrice = $ozonMinPriceFloor;
            $notes[] = 'Минимальная цена поднята до 50% обычной цены по ограничению Ozon.';
        }
    }

    $isValid = $regularPrice > 0 && $minPrice > 0;
    $reason = '';
    if (!$isValid) {
        if ($regularPrice <= 0 && $minPrice <= 0) {
            $reason = 'Не удалось получить ни обычную цену, ни min price ни из расчёта, ни из текущих данных Ozon.';
        } elseif ($regularPrice <= 0) {
            $reason = 'Не удалось получить обычную цену ни из расчёта, ни из текущих данных Ozon.';
        } else {
            $reason = 'Не удалось получить min price ни из расчёта, ни из текущих данных Ozon.';
        }
    }

    return [
        'regular_price' => ozon_price_round_rub($regularPrice),
        'min_price' => ozon_price_round_rub($minPrice),
        'notes' => $notes,
        'is_valid' => $isValid,
        'reason' => $reason,
    ];
}

function ozon_price_next_index_transition_target(float $currentMinPrice, float $referencePrice, array $priceIndexes): ?array
{
    $metrics = ozon_price_index_metric_rows($priceIndexes, $referencePrice);
    if (!$metrics) {
        return null;
    }

    $discountFactor = ozon_price_index_discount_factor_from_metrics($metrics, $referencePrice) ?? 1.0;
    $referenceEffectivePrices = array_values(array_filter(array_map(
        static fn(array $metric): float => (float)($metric['api_effective_price'] ?? 0),
        $metrics
    ), static fn(float $value): bool => $value > 0));
    sort($referenceEffectivePrices, SORT_NUMERIC);
    $referenceEffectivePrice = null;
    if ($referenceEffectivePrices) {
        $count = count($referenceEffectivePrices);
        $middle = intdiv($count, 2);
        $referenceEffectivePrice = $count % 2 === 1
            ? (float)$referenceEffectivePrices[$middle]
            : (((float)$referenceEffectivePrices[$middle - 1] + (float)$referenceEffectivePrices[$middle]) / 2.0);
    }
    $comparisonPrices = array_values(array_filter(array_map(
        static fn(array $metric): float => (float)($metric['comparison_price'] ?? 0),
        $metrics
    ), static fn(float $value): bool => $value > 0));
    sort($comparisonPrices, SORT_NUMERIC);
    $aggregateComparisonPrice = null;
    if ($comparisonPrices) {
        $count = count($comparisonPrices);
        $middle = intdiv($count, 2);
        $aggregateComparisonPrice = $count % 2 === 1
            ? (float)$comparisonPrices[$middle]
            : (((float)$comparisonPrices[$middle - 1] + (float)$comparisonPrices[$middle]) / 2.0);
    }

    $currentEval = ozon_price_eval_index_for_metrics($currentMinPrice, $metrics, $discountFactor);
    $currentIndexValue = (float)($currentEval['value'] ?? 0);
    $currentLevel = (string)($currentEval['level'] ?? 'without_index');
    $targetIndex = null;
    $nextLevel = null;

    $baseResult = [
        'benchmark' => [
            'source' => 'aggregate',
            'value' => $aggregateComparisonPrice !== null ? round($aggregateComparisonPrice, 2) : null,
            'index_value' => round($currentIndexValue, 4),
            'method' => 'aggregate_median',
        ],
        'metrics' => $metrics,
        'current_index_value' => round($currentIndexValue, 4),
        'current_level' => $currentLevel,
        'current_level_label' => ozon_price_index_level_label($currentLevel),
        'current_effective_price' => round((float)($currentEval['effective_price'] ?? 0), 2),
        'reference_effective_price' => $referenceEffectivePrice !== null ? round($referenceEffectivePrice, 2) : null,
        'seller_reference_price' => round($referencePrice, 2),
        'discount_factor' => round($discountFactor, 6),
        'discount_percent' => round(max(0.0, (1.0 - $discountFactor) * 100.0), 2),
        'source_indexes_current' => (array)($currentEval['sources'] ?? []),
    ];

    if ($currentLevel === 'bad') {
        $nextLevel = 'moderate';
    } elseif ($currentLevel === 'moderate') {
        $nextLevel = 'good';
    } elseif ($currentLevel === 'good') {
        $nextLevel = 'super';
    } else {
        return $baseResult + [
            'next_level' => null,
            'next_level_label' => null,
            'threshold_price' => null,
            'threshold_effective_price' => null,
            'target_price' => null,
            'target_effective_price' => null,
            'can_apply' => false,
            'drop_percent' => 0.0,
            'reason' => $currentLevel === 'super'
                ? 'Текущая минимальная цена уже попадает в супер-выгодный индекс.'
                : 'Для текущей минимальной цены Ozon не удаётся определить следующий уровень индекса.',
        ];
    }

    $threshold = ozon_price_find_seller_price_for_aggregate_level($currentMinPrice, $metrics, $discountFactor, $nextLevel);
    if ($threshold === null || $threshold <= 0) {
        return null;
    }
    $effectiveThreshold = ozon_price_index_effective_price_for_seller_price($threshold, $discountFactor);
    $effectiveTargetPrice = max(0.0, $effectiveThreshold - 10.0);
    $targetPrice = ozon_price_index_seller_price_for_effective_price($effectiveTargetPrice, $discountFactor);
    if ($targetPrice === null) {
        return null;
    }
    $targetEval = ozon_price_eval_index_for_metrics($targetPrice, $metrics, $discountFactor);
    $dropPercent = $currentMinPrice > 0
        ? (($currentMinPrice - $targetPrice) / $currentMinPrice) * 100.0
        : 0.0;
    $canApply = $targetPrice > 0 && $targetPrice < $currentMinPrice && $dropPercent <= 5.0;
    $reason = '';
    if (!$canApply) {
        if ($targetPrice >= $currentMinPrice) {
            $reason = 'Для перехода на следующий уровень индекса снижать min price не нужно.';
        } elseif ($dropPercent > 5.0) {
            $reason = 'Переход на следующий уровень индекса требует снизить min price больше чем на 5%.';
        }
    }

    return $baseResult + [
        'next_level' => $nextLevel,
        'next_level_label' => ozon_price_index_level_label($nextLevel),
        'threshold_price' => round($threshold, 2),
        'threshold_effective_price' => round($effectiveThreshold, 2),
        'target_price' => round($targetPrice, 2),
        'target_effective_price' => round($effectiveTargetPrice, 2),
        'target_index_value' => isset($targetEval['value']) ? round((float)$targetEval['value'], 4) : null,
        'target_level' => (string)($targetEval['level'] ?? ''),
        'target_level_label' => (string)($targetEval['label'] ?? ''),
        'source_indexes_target' => (array)($targetEval['sources'] ?? []),
        'can_apply' => $canApply,
        'drop_percent' => round(max(0.0, $dropPercent), 2),
        'reason' => $reason,
    ];
}

function ozon_price_resolve_target_profit_min_rub(array $settings, bool $forMinPrice = false): float
{
    $key = $forMinPrice ? 'min_target_profit_min_rub' : 'target_profit_min_rub';
    return max(0.0, (float)($settings[$key] ?? 0));
}

function ozon_price_to_float($value): float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return 0.0;
    }

    $raw = preg_replace('/\x{00A0}|\x{202F}/u', ' ', $raw);
    $raw = preg_replace('/[^\d,.\- ]+/u', '', (string)$raw);
    $raw = trim((string)$raw);
    if ($raw === '') {
        return 0.0;
    }

    $raw = preg_replace('/\s+/u', '', $raw);
    $lastComma = strrpos($raw, ',');
    $lastDot = strrpos($raw, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }
    } elseif ($lastComma !== false) {
        $parts = explode(',', $raw);
        if (count($parts) > 2) {
            $tail = array_pop($parts);
            $raw = implode('', $parts) . '.' . $tail;
        } else {
            $raw = str_replace(',', '.', $raw);
        }
    } elseif ($lastDot !== false) {
        $parts = explode('.', $raw);
        if (count($parts) > 2) {
            $tail = array_pop($parts);
            $raw = implode('', $parts) . '.' . $tail;
        }
    }

    if (!is_numeric($raw)) {
        return 0.0;
    }

    return (float)$raw;
}

function ozon_price_round(float $value, string $mode): float
{
    $mode = strtolower(trim($mode));
    if ($value <= 0) {
        return 0.0;
    }

    switch ($mode) {
        case 'none':
            // Legacy profiles may still store "none", but marketplace prices must be whole rubles.
            return ceil($value);
        case '5rub':
            return ceil($value / 5) * 5;
        case '10rub':
            return ceil($value / 10) * 10;
        case 'end9':
            return floor($value) + 0.0 <= 9 ? 9.0 : (floor(($value - 9) / 10) + 1) * 10 - 1;
        case 'end90':
            return ceil($value / 100) * 100 - 10;
        case 'end99':
            return ceil($value / 100) * 100 - 1;
        case 'rub':
        default:
            return ceil($value);
    }
}

function ozon_price_round_rub(float $value): float
{
    return ozon_price_round($value, 'rub');
}

function ozon_price_feed_fetch_remote_xml(string $url, int $maxBytes = 200 * 1024 * 1024): array
{
    $parts = ozon_price_validate_public_download_url($url);
    $url = ozon_price_url_from_parts($parts);

    $tmp = tempnam(sys_get_temp_dir(), 'ftprice_');
    if (!$tmp) {
        throw new RuntimeException('Не удалось создать временный файл для XML.');
    }

    $fp = fopen($tmp, 'wb');
    if (!$fp) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось открыть временный XML-файл.');
    }

    $currentUrl = $url;
    for ($redirect = 0; $redirect < 5; $redirect++) {
        ozon_price_validate_public_download_url($currentUrl);
        ftruncate($fp, 0);
        rewind($fp);

        $location = '';
        $bytes = 0;
        $tooLarge = false;

        $ch = curl_init($currentUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_USERAGENT => 'FeedTools Ozon Price Tool/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location, $maxBytes): int {
                $len = strlen($header);
                $pos = strpos($header, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($header, 0, $pos)));
                    $value = trim(substr($header, $pos + 1));
                    if ($name === 'location') {
                        $location = $value;
                    }
                    if ($name === 'content-length' && ctype_digit($value) && (int)$value > $maxBytes) {
                        return 0;
                    }
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($fp, &$bytes, &$tooLarge, $maxBytes): int {
                $len = strlen($chunk);
                $bytes += $len;
                if ($bytes > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $written = fwrite($fp, $chunk);
                return ($written === false) ? 0 : $written;
            },
        ]);

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($tooLarge || ($ok === false && stripos($err, 'Failed writing') !== false)) {
            fclose($fp);
            @unlink($tmp);
            throw new RuntimeException('XML-фид слишком большой.');
        }
        if ($ok === false) {
            fclose($fp);
            @unlink($tmp);
            throw new RuntimeException('Не удалось скачать XML: ' . ($err ?: ('HTTP ' . $code)));
        }
        if ($code >= 300 && $code < 400 && $location !== '') {
            $currentUrl = ozon_price_resolve_redirect_url($currentUrl, $location);
            continue;
        }
        if ($code >= 400) {
            fclose($fp);
            @unlink($tmp);
            throw new RuntimeException('Не удалось скачать XML: HTTP ' . $code);
        }

        fflush($fp);
        fclose($fp);
        if ($bytes <= 0) {
            @unlink($tmp);
            throw new RuntimeException('По ссылке пришёл пустой XML.');
        }
        return ['path' => $tmp, 'bytes' => $bytes, 'final_url' => $currentUrl];
    }

    fclose($fp);
    @unlink($tmp);
    throw new RuntimeException('Слишком много redirect при загрузке XML.');
}

function ozon_price_validate_public_download_url(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException('Ссылка на XML пустая.');
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('Некорректная ссылка на XML.');
    }

    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Поддерживаются только http/https ссылки.');
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        throw new RuntimeException('Ссылки с логином/паролем не поддерживаются.');
    }

    $host = (string)$parts['host'];
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $resolved4 = gethostbynamel($host);
        if (is_array($resolved4)) {
            $ips = array_merge($ips, $resolved4);
        }
        $resolved6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($resolved6)) {
            foreach ($resolved6 as $row) {
                if (!empty($row['ipv6'])) {
                    $ips[] = (string)$row['ipv6'];
                }
            }
        }
    }

    $ips = array_values(array_unique(array_filter(array_map('trim', $ips))));
    if (!$ips) {
        throw new RuntimeException('Не удалось определить IP адрес хоста XML-фида.');
    }

    foreach ($ips as $ip) {
        if (ozon_price_is_private_ip($ip)) {
            throw new RuntimeException('Запрещённый адрес XML-хоста.');
        }
    }

    return $parts;
}

function ozon_price_is_private_ip(string $ip): bool
{
    if ($ip === '') {
        return true;
    }

    $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($public !== false) {
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        foreach ([
            ['0.0.0.0', '0.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['224.0.0.0', '239.255.255.255'],
            ['240.0.0.0', '255.255.255.255'],
        ] as [$a, $b]) {
            if ($long >= ip2long($a) && $long <= ip2long($b)) {
                return true;
            }
        }
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $ip = strtolower($ip);
        return $ip === '::1' || str_starts_with($ip, 'fe80:') || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd');
    }

    return true;
}

function ozon_price_url_from_parts(array $parts): string
{
    $url = (string)$parts['scheme'] . '://' . (string)$parts['host'];
    if (!empty($parts['port'])) {
        $url .= ':' . (int)$parts['port'];
    }
    $url .= (string)($parts['path'] ?? '/');
    if (array_key_exists('query', $parts)) {
        $url .= '?' . (string)$parts['query'];
    }
    return $url;
}

function ozon_price_resolve_redirect_url(string $baseUrl, string $location): string
{
    $location = trim($location);
    if ($location === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $location)) {
        return $location;
    }
    if (str_starts_with($location, '//')) {
        $base = parse_url($baseUrl);
        return strtolower((string)($base['scheme'] ?? 'https')) . ':' . $location;
    }

    $base = parse_url($baseUrl);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return $location;
    }

    $root = strtolower((string)$base['scheme']) . '://' . (string)$base['host'];
    if (!empty($base['port'])) {
        $root .= ':' . (int)$base['port'];
    }
    if (str_starts_with($location, '/')) {
        return $root . $location;
    }

    $path = (string)($base['path'] ?? '/');
    $dir = preg_replace('~/[^/]*$~', '/', $path) ?: '/';
    return $root . $dir . $location;
}

function ozon_price_feed_build_category_path(string $categoryId, array $categoryMap): string
{
    $categoryId = trim($categoryId);
    if ($categoryId === '' || empty($categoryMap[$categoryId])) {
        return '';
    }

    $parts = [];
    $seen = [];
    $current = $categoryId;
    for ($i = 0; $i < 30; $i++) {
        if ($current === '' || isset($seen[$current]) || empty($categoryMap[$current])) {
            break;
        }
        $seen[$current] = true;
        $name = trim((string)($categoryMap[$current]['name'] ?? ''));
        if ($name !== '') {
            array_unshift($parts, $name);
        }
        $current = trim((string)($categoryMap[$current]['parentId'] ?? ''));
    }

    return $parts ? implode(' -> ', $parts) : '';
}

function ozon_price_parse_feed(string $xmlPath, string $costTag): array
{
    $costTag = trim($costTag);
    if ($costTag === '') {
        throw new RuntimeException('Не указан тег закупочной цены.');
    }

    $reader = new XMLReader();
    if (!$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Не удалось открыть XML-фид.');
    }

    $offers = [];
    $categoryMap = [];

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        if ($reader->name === 'category') {
            $categoryId = trim((string)$reader->getAttribute('id'));
            if ($categoryId !== '') {
                $categoryMap[$categoryId] = [
                    'id' => $categoryId,
                    'name' => trim((string)$reader->readString()),
                    'parentId' => trim((string)$reader->getAttribute('parentId')),
                ];
            }
            continue;
        }
        if ($reader->name !== 'offer') {
            continue;
        }
        $offers[] = ozon_price_parse_offer_node($reader, $costTag, $categoryMap);
    }

    $reader->close();
    return $offers;
}

function ozon_price_feed_count_cache_path(): string
{
    return dirname(__DIR__) . '/storage/cache/ozon_price_tool_feed_counts.json';
}

function ozon_price_feed_offer_count_cached(array $feed, int $ttlSeconds = 3600, bool $allowRemoteRefresh = true): ?int
{
    $feedId = (int)($feed['id'] ?? 0);
    if ($feedId <= 0) {
        return null;
    }

    $cachePath = ozon_price_feed_count_cache_path();
    $cache = [];
    if (is_file($cachePath)) {
        $raw = @file_get_contents($cachePath);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }

    $cacheKey = (string)$feedId;
    $signature = sha1(json_encode([
        'feed_url' => (string)($feed['feed_url'] ?? ''),
        'cost_tag' => (string)($feed['cost_tag'] ?? ''),
        'supplier_code' => (string)($feed['supplier_code'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $entry = is_array($cache[$cacheKey] ?? null) ? $cache[$cacheKey] : null;
    $now = time();

    if (
        $entry
        && (string)($entry['signature'] ?? '') === $signature
        && (int)($entry['updated_at'] ?? 0) > ($now - $ttlSeconds)
        && array_key_exists('count', $entry)
    ) {
        return (int)$entry['count'];
    }

    if (!$allowRemoteRefresh) {
        if (
            $entry
            && (string)($entry['signature'] ?? '') === $signature
            && array_key_exists('count', $entry)
        ) {
            return (int)$entry['count'];
        }
        return null;
    }

    try {
        $download = ozon_price_feed_fetch_remote_xml((string)($feed['feed_url'] ?? ''));
        try {
            $offers = ozon_price_parse_feed((string)$download['path'], (string)($feed['cost_tag'] ?? ''));
        } finally {
            @unlink((string)$download['path']);
        }

        $supplierCode = (string)($feed['supplier_code'] ?? '');
        $counted = [];
        foreach ($offers as $offer) {
            $offerId = ozon_price_apply_supplier_code(trim((string)($offer['offer_id'] ?? '')), $supplierCode);
            if ($offerId === '') {
                continue;
            }
            $counted[$offerId] = true;
        }
        $count = count($counted);

        $cache[$cacheKey] = [
            'signature' => $signature,
            'count' => $count,
            'updated_at' => $now,
        ];
        @file_put_contents($cachePath, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $count;
    } catch (Throwable $e) {
        if ($entry && array_key_exists('count', $entry)) {
            return (int)$entry['count'];
        }
        return null;
    }
}

function ozon_price_feed_find_offer(array $feed, string $offerIdRaw): ?array
{
    $offerId = ozon_price_apply_supplier_code(trim($offerIdRaw), (string)($feed['supplier_code'] ?? ''));
    if ($offerId === '') {
        return null;
    }

    $download = ozon_price_feed_fetch_remote_xml((string)($feed['feed_url'] ?? ''));
    try {
        $offers = ozon_price_parse_feed((string)$download['path'], (string)($feed['cost_tag'] ?? ''));
    } finally {
        @unlink((string)$download['path']);
    }

    $supplierCode = (string)($feed['supplier_code'] ?? '');
    $feedMap = ozon_price_feed_offers_by_id($offers, $supplierCode, (int)($feed['supplier_id'] ?? 0));
    return ozon_price_feed_offer_for_requested_id($feedMap, $offerId);
}

function ozon_price_feed_offers_by_id(array $offers, string $supplierCode = '', int $supplierId = 0): array
{
    $map = [];
    foreach ($offers as $offer) {
        if (!is_array($offer)) {
            continue;
        }
        $offerId = ozon_price_apply_supplier_code(trim((string)($offer['offer_id'] ?? '')), $supplierCode);
        if ($offerId === '') {
            continue;
        }
        $offer['offer_id'] = $offerId;
        $map[$offerId] = $offer;
    }
    if ($supplierId > 0 && $map && function_exists('supplier_products_marketplace_controls_by_offer_ids')) {
        $controls = supplier_products_marketplace_controls_by_offer_ids($supplierId, array_keys($map));
        foreach ($controls as $offerId => $control) {
            if (!isset($map[$offerId]) || !is_array($control)) {
                continue;
            }
            $map[$offerId]['supplier_marketplace_enabled'] = (int)($control['marketplace_enabled'] ?? 1);
            $map[$offerId]['supplier_stock_modifier'] = (int)($control['stock_modifier'] ?? 0);
            $map[$offerId]['supplier_price_modifier'] = (string)($control['price_modifier'] ?? '');
        }
    }
    return $map;
}

function ozon_price_bundle_derive_offer(array $baseOffer, string $bundleOfferId): ?array
{
    $bundle = bundle_offer_parse($bundleOfferId);
    if (empty($bundle['is_bundle']) || empty($bundle['format_valid'])) {
        return null;
    }

    $baseOfferId = trim((string)($bundle['base_offer_id'] ?? ''));
    $bundleQty = max(1, (int)($bundle['bundle_qty'] ?? 1));
    if ($baseOfferId === '') {
        return null;
    }

    $offer = $baseOffer;
    $offer['offer_id'] = $bundleOfferId;
    $offer['vendorCode'] = $bundleOfferId;
    $offer['vendor_code'] = $bundleOfferId;
    $offer['bundle_base_offer_id'] = $baseOfferId;
    $offer['bundle_qty'] = $bundleQty;
    $offer['bundle_source_offer_id'] = trim((string)($baseOffer['offer_id'] ?? $baseOfferId));

    $purchaseCost = isset($baseOffer['purchase_cost']) ? (float)$baseOffer['purchase_cost'] : 0.0;
    if ($purchaseCost > 0) {
        $offer['purchase_cost'] = round($purchaseCost * $bundleQty, 2);
        $offer['cost_raw'] = (string)$offer['purchase_cost'];
    }

    foreach (['weight'] as $field) {
        if (isset($baseOffer[$field]) && is_numeric($baseOffer[$field])) {
            $offer[$field] = (float)$baseOffer[$field] * $bundleQty;
        }
    }

    return $offer;
}

function ozon_price_feed_offer_for_requested_id(array $feedOffersById, string $requestedOfferId): ?array
{
    $requestedOfferId = trim($requestedOfferId);
    if ($requestedOfferId === '') {
        return null;
    }
    if (isset($feedOffersById[$requestedOfferId]) && is_array($feedOffersById[$requestedOfferId])) {
        return $feedOffersById[$requestedOfferId];
    }

    $bundle = bundle_offer_parse($requestedOfferId);
    if (empty($bundle['is_bundle']) || empty($bundle['format_valid'])) {
        return null;
    }

    $baseOfferId = trim((string)($bundle['base_offer_id'] ?? ''));
    $baseOffer = $baseOfferId !== '' && isset($feedOffersById[$baseOfferId]) && is_array($feedOffersById[$baseOfferId])
        ? $feedOffersById[$baseOfferId]
        : null;
    if (!is_array($baseOffer)) {
        return null;
    }

    return ozon_price_bundle_derive_offer($baseOffer, $requestedOfferId);
}

function ozon_price_feed_offers_for_requested_ids(array $feedOffersById, array $requestedOfferIds): array
{
    $offers = [];
    $missing = [];
    foreach ($requestedOfferIds as $requestedOfferId) {
        $requestedOfferId = trim((string)$requestedOfferId);
        if ($requestedOfferId === '') {
            continue;
        }
        $offer = ozon_price_feed_offer_for_requested_id($feedOffersById, $requestedOfferId);
        if (is_array($offer)) {
            $offers[$requestedOfferId] = $offer;
        } else {
            $missing[$requestedOfferId] = $requestedOfferId;
        }
    }

    return [
        'offers' => array_values($offers),
        'missing_ids' => array_values($missing),
    ];
}

function ozon_price_apply_supplier_code(string $offerId, string $supplierCode): string
{
    $offerId = trim($offerId);
    $supplierCode = trim($supplierCode);
    if ($offerId === '' || $supplierCode === '') {
        return $offerId;
    }
    if (strpos($offerId, '__') !== false) {
        return $offerId;
    }
    return $offerId . '__' . $supplierCode;
}

function ozon_price_parse_offer_node(XMLReader $reader, string $costTag, array $categoryMap = []): array
{
    $offerDepth = $reader->depth;
    $offerId = trim((string)$reader->getAttribute('id'));
    $offer = [
        'offer_id' => $offerId,
        'name' => '',
        'vendor' => '',
        'brand' => '',
        'vendorCode' => '',
        'category_id' => '',
        'category_name' => '',
        'category_path' => '',
        'length' => null,
        'width' => null,
        'height' => null,
        'weight' => null,
        'cost_raw' => '',
        'purchase_cost' => null,
        'source_price_raw' => '',
        'source_price' => null,
        'source_price_tag' => '',
        'price_blocked' => false,
        'warnings' => [],
    ];

    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $offerDepth) {
            break;
        }
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }

        $name = $reader->name;
        if ($name === 'name') {
            $offer['name'] = trim((string)$reader->readString());
            continue;
        }
        if ($name === 'vendor') {
            $offer['vendor'] = trim((string)$reader->readString());
            continue;
        }
        if ($name === 'brand') {
            $offer['brand'] = trim((string)$reader->readString());
            continue;
        }
        if ($name === 'vendorCode') {
            $offer['vendorCode'] = trim((string)$reader->readString());
            continue;
        }
        if ($name === 'categoryId' || $name === 'category') {
            $offer['category_id'] = trim((string)$reader->readString());
            continue;
        }
        if ($name === 'weight') {
            $weight = ozon_price_parse_decimal((string)$reader->readString());
            if ($weight > 0) {
                $offer['weight'] = $weight;
            }
            continue;
        }
        if ($name === 'dimensions') {
            $rawDimensions = trim((string)$reader->readString());
            $parts = preg_split('~\s*[xх/]\s*~iu', $rawDimensions) ?: [];
            if (count($parts) >= 3) {
                $length = ozon_price_parse_decimal((string)$parts[0]);
                $width = ozon_price_parse_decimal((string)$parts[1]);
                $height = ozon_price_parse_decimal((string)$parts[2]);
                if ($length > 0 && $width > 0 && $height > 0) {
                    $offer['length'] = $length;
                    $offer['width'] = $width;
                    $offer['height'] = $height;
                }
            }
            continue;
        }
        if ($name === 'price_original' || $name === 'price') {
            $raw = trim((string)$reader->readString());
            if ($raw !== '' && ($name === 'price_original' || $offer['source_price_tag'] !== 'price_original')) {
                $value = ozon_price_to_float($raw);
                $offer['source_price_raw'] = $raw;
                $offer['source_price'] = is_finite($value) ? round($value, 2) : 0.0;
                $offer['source_price_tag'] = $name;
            }
            if ($name === $costTag) {
                $offer['cost_raw'] = $raw;
                $parsed = ozon_price_parse_cost_value($raw);
                if ($parsed === null) {
                    $offer['warnings'][] = 'Не удалось нормализовать закупочную цену из тега ' . $costTag . '.';
                } else {
                    $offer['purchase_cost'] = $parsed;
                }
            }
            continue;
        }
        if ($name === $costTag) {
            $raw = trim((string)$reader->readString());
            $offer['cost_raw'] = $raw;
            $parsed = ozon_price_parse_cost_value($raw);
            if ($parsed === null) {
                $offer['warnings'][] = 'Не удалось нормализовать закупочную цену из тега ' . $costTag . '.';
            } else {
                $offer['purchase_cost'] = $parsed;
            }
            continue;
        }
    }

    if ($offer['offer_id'] === '') {
        $offer['warnings'][] = 'У offer не найден id.';
    }
    if ($offer['purchase_cost'] === null && $offer['cost_raw'] === '') {
        $offer['warnings'][] = 'В XML нет значения закупочной цены в теге ' . $costTag . '.';
    }
    if ($offer['source_price_tag'] === '' && $offer['cost_raw'] !== '') {
        $value = ozon_price_to_float((string)$offer['cost_raw']);
        $offer['source_price_raw'] = (string)$offer['cost_raw'];
        $offer['source_price'] = is_finite($value) ? round($value, 2) : 0.0;
        $offer['source_price_tag'] = $costTag;
    }
    if ($offer['source_price_tag'] === '') {
        $offer['price_blocked'] = true;
        $offer['warnings'][] = 'В фиде нет цены товара: цены не обновляются, остаток должен быть 0.';
    } elseif ($offer['source_price'] === null || (float)$offer['source_price'] <= 0) {
        $offer['price_blocked'] = true;
        $offer['warnings'][] = 'Цена товара в фиде равна 0: цены не обновляются, остаток должен быть 0.';
    }
    if ($offer['brand'] === '' && $offer['vendor'] !== '') {
        $offer['brand'] = $offer['vendor'];
    }
    $categoryId = trim((string)$offer['category_id']);
    if ($categoryId !== '') {
        $offer['category_path'] = ozon_price_feed_build_category_path($categoryId, $categoryMap);
        if (!empty($categoryMap[$categoryId]['name'])) {
            $offer['category_name'] = trim((string)$categoryMap[$categoryId]['name']);
        }
    }

    return $offer;
}

function ozon_price_offer_blocked_by_feed_price(array $offer): bool
{
    return !empty($offer['price_blocked']);
}

function ozon_price_apply_modifier(float $price, array $settings, bool $forMinPrice = false): float
{
    $modeKey = $forMinPrice ? 'price_modifier_min_mode' : 'price_modifier_mode';
    $valueKey = $forMinPrice ? 'price_modifier_min_value' : 'price_modifier_value';
    $mode = strtolower(trim((string)($settings[$modeKey] ?? 'none')));
    $value = (float)($settings[$valueKey] ?? 0);
    if ($price <= 0 || $mode === 'none' || abs($value) < 0.0001) {
        return $price;
    }
    if ($mode === 'percent') {
        return max(0.0, $price + ($price * ($value / 100.0)));
    }
    if ($mode === 'fixed') {
        return max(0.0, $price + $value);
    }
    return $price;
}

function ozon_price_api_offer_id_length(string $offerId): int
{
    return function_exists('mb_strlen') ? mb_strlen($offerId, 'UTF-8') : strlen($offerId);
}

function ozon_price_api_offer_id_error(string $offerId): string
{
    $offerId = trim($offerId);
    if ($offerId === '') {
        return 'Пропущен offer_id в XML.';
    }
    $length = ozon_price_api_offer_id_length($offerId);
    if ($length > 100) {
        return 'offer_id длиннее лимита Ozon: ' . $length . ' символов при максимуме 100. Товар пропущен в запросе цен Ozon.';
    }
    return '';
}

function ozon_price_parse_cost_value(string $raw): ?float
{
    $value = ozon_price_to_float($raw);
    if ($value <= 0) {
        return null;
    }
    return round($value, 2);
}

function ozon_price_fetch_price_items(array $cfg, array $offerIds, int $chunkSize = 100): array
{
    $offerIds = array_values(array_filter(array_unique(array_map(static fn($v) => trim((string)$v), $offerIds))));
    if (!$offerIds) {
        return [];
    }

    $items = [];
    $apiOfferIds = [];
    foreach ($offerIds as $offerId) {
        $error = ozon_price_api_offer_id_error($offerId);
        if ($error !== '') {
            $items[$offerId] = [
                'offer_id' => $offerId,
                '__invalid_offer_id' => true,
                '__invalid_offer_id_reason' => $error,
            ];
            continue;
        }
        $apiOfferIds[] = $offerId;
    }
    if (!$apiOfferIds) {
        return $items;
    }

    $oz = ozon_cfg_or_fail($cfg);
    foreach (array_chunk($apiOfferIds, max(1, $chunkSize)) as $chunk) {
        $resp = ozon_post_json($oz, '/v5/product/info/prices', [
            'filter' => ['offer_id' => array_values($chunk)],
            'limit' => count($chunk),
        ]);
        foreach ((array)($resp['items'] ?? []) as $item) {
            $offerId = trim((string)($item['offer_id'] ?? ''));
            if ($offerId !== '') {
                $items[$offerId] = $item;
            }
        }

        $infoResp = ozon_post_json($oz, '/v3/product/info/list', [
            'offer_id' => array_values($chunk),
        ]);
        foreach ((array)($infoResp['items'] ?? []) as $infoItem) {
            $offerId = trim((string)($infoItem['offer_id'] ?? ''));
            if ($offerId === '') {
                continue;
            }
            if (!isset($items[$offerId])) {
                $items[$offerId] = [
                    'offer_id' => $offerId,
                ];
            }
            $items[$offerId]['archived'] = !empty($infoItem['is_archived']);
            if (!empty($infoItem['id']) && empty($items[$offerId]['product_id'])) {
                $items[$offerId]['product_id'] = (int)$infoItem['id'];
            }
        }

        try {
            $attributesResp = ozon_post_json($oz, '/v4/product/info/attributes', [
                'filter' => [
                    'offer_id' => array_values($chunk),
                    'visibility' => 'ALL',
                ],
                'limit' => count($chunk),
            ]);
            foreach ((array)($attributesResp['result'] ?? []) as $attributeItem) {
                $offerId = trim((string)($attributeItem['offer_id'] ?? ''));
                if ($offerId === '') {
                    continue;
                }
                if (!isset($items[$offerId])) {
                    $items[$offerId] = [
                        'offer_id' => $offerId,
                    ];
                }
                foreach ([
                    'depth',
                    'width',
                    'height',
                    'dimension_unit',
                    'weight',
                    'weight_unit',
                    'type_id',
                    'description_category_id',
                ] as $field) {
                    if (array_key_exists($field, $attributeItem)) {
                        $items[$offerId][$field] = $attributeItem[$field];
                    }
                }
            }
        } catch (Throwable $e) {
            // Prices can still be calculated from current API tariffs if Ozon temporarily
            // withholds dimensions. Crossing into the <=300 band is skipped without them.
        }
    }

    $missingOfferIds = array_values(array_diff($apiOfferIds, array_keys($items)));
    if ($missingOfferIds) {
        $offerSet = ozon_get_offer_id_set_cached($cfg, 300);
        foreach ($missingOfferIds as $offerId) {
            $items[$offerId] = [
                'offer_id' => $offerId,
                '__missing_price_data' => true,
                '__missing_on_ozon' => empty($offerSet[$offerId]),
            ];
        }
    }

    $standardCommissions = [];
    $standardLogistics = [];
    foreach ($items as $item) {
        $typeId = (int)($item['type_id'] ?? 0);
        if (ozon_price_reference_sale_price($item) <= 300.0) {
            continue;
        }
        $commissions = (array)($item['commissions'] ?? []);
        if ($typeId > 0) {
            foreach (['fbs', 'fbo'] as $scheme) {
                $rate = (float)($commissions['sales_percent_' . $scheme] ?? 0);
                if ($rate <= 0) {
                    continue;
                }
                $standardCommissions[$typeId][$scheme] = max(
                    $rate,
                    (float)($standardCommissions[$typeId][$scheme] ?? 0)
                );
            }
        }

        $volume = ozon_price_budget_goods_volume_liters($item);
        if (!is_array($volume)) {
            continue;
        }
        $volumeKey = number_format((float)$volume['value'], 4, '.', '');
        foreach (['fbs', 'fbo'] as $scheme) {
            foreach ([
                'deliv_to_customer_amount',
                'direct_flow_trans_min_amount',
                'direct_flow_trans_max_amount',
                'return_flow_amount',
            ] as $field) {
                $amount = (float)($commissions[$scheme . '_' . $field] ?? 0);
                if ($amount > 0) {
                    $standardLogistics[$volumeKey][$scheme][$field] = max(
                        $amount,
                        (float)($standardLogistics[$volumeKey][$scheme][$field] ?? 0)
                    );
                }
            }
            if ($scheme === 'fbs') {
                foreach (['first_mile_min_amount', 'first_mile_max_amount'] as $field) {
                    $amount = (float)($commissions['fbs_' . $field] ?? 0);
                    if ($amount > 0) {
                        $standardLogistics[$volumeKey]['fbs'][$field] = max(
                            $amount,
                            (float)($standardLogistics[$volumeKey]['fbs'][$field] ?? 0)
                        );
                    }
                }
            }
        }
    }
    if ($standardCommissions || $standardLogistics) {
        foreach ($items as &$item) {
            $typeId = (int)($item['type_id'] ?? 0);
            if ($typeId > 0 && !empty($standardCommissions[$typeId])) {
                foreach (['fbs', 'fbo'] as $scheme) {
                    $rate = (float)($standardCommissions[$typeId][$scheme] ?? 0);
                    if ($rate > 0) {
                        $item['standard_sales_percent_' . $scheme] = $rate;
                    }
                }
            }

            $volume = ozon_price_budget_goods_volume_liters($item);
            if (is_array($volume)) {
                $volumeKey = number_format((float)$volume['value'], 4, '.', '');
                foreach ((array)($standardLogistics[$volumeKey] ?? []) as $scheme => $fields) {
                    foreach ((array)$fields as $field => $amount) {
                        $item['standard_' . $scheme . '_' . $field] = (float)$amount;
                    }
                }
            }
        }
        unset($item);
    }

    return $items;
}

function ozon_price_calculate_preview(array $settings, array $feedOffers, array $ozonItems): array
{
    $rows = [];
    $stats = [
        'offers_total' => count($feedOffers),
        'calc_ok' => 0,
        'warnings' => 0,
        'errors' => 0,
    ];

    foreach ($feedOffers as $offer) {
        $row = ozon_price_calculate_offer($settings, $offer, $ozonItems[(string)($offer['offer_id'] ?? '')] ?? null);
        $rows[] = $row;
        if ($row['status'] === 'ok') {
            $stats['calc_ok']++;
        } elseif ($row['status'] === 'warn') {
            $stats['warnings']++;
        } else {
            $stats['errors']++;
        }
    }

    return ['rows' => $rows, 'stats' => $stats];
}

function ozon_price_calculate_offer(array $settings, array $offer, ?array $ozonItem): array
{
    $warnings = array_values(array_filter((array)($offer['warnings'] ?? [])));
    $offerId = trim((string)($offer['offer_id'] ?? ''));
    $purchase = isset($offer['purchase_cost']) ? (float)$offer['purchase_cost'] : null;
    $scheme = strtolower(trim((string)($settings['fulfillment_scheme'] ?? 'fbs')));
    $scheme = in_array($scheme, ['fbs', 'fbo'], true) ? $scheme : 'fbs';

    $row = [
        'offer_id' => $offerId,
        'name' => (string)($offer['name'] ?? ''),
        'vendor' => (string)($offer['vendor'] ?? ''),
        'brand' => (string)(($offer['brand'] ?? '') ?: ($offer['vendor'] ?? '')),
        'category_id' => (string)($offer['category_id'] ?? ''),
        'category_name' => (string)($offer['category_name'] ?? ''),
        'category_path' => (string)($offer['category_path'] ?? ''),
        'product_id' => null,
        'purchase_cost' => $purchase,
        'purchase_cost_raw' => (string)($offer['cost_raw'] ?? ''),
        'ozon_price' => null,
        'ozon_min_price' => null,
        'ozon_old_price' => null,
        'marketing_seller_price' => null,
        'recommended_price' => null,
        'recommended_min_price' => null,
        'profit_rub' => null,
        'profit_percent' => null,
        'commission_percent' => null,
        'acquiring_rub' => null,
        'acquiring_rate_percent' => null,
        'fixed_costs_rub' => null,
        'color_index' => '',
        'volume_weight' => null,
        'warnings' => $warnings,
        'status' => 'ok',
        'breakdown' => [],
    ];

    if ($offerId === '') {
        $row['warnings'][] = 'Пропущен offer_id в XML.';
        $row['status'] = 'error';
        return $row;
    }

    if (ozon_price_offer_blocked_by_feed_price($offer)) {
        $row['warnings'][] = 'Цена товара в фиде отсутствует или равна 0: Price Tool не обновляет цену.';
        $row['status'] = 'warn';
        return $row;
    }

    if ($purchase === null || $purchase <= 0) {
        $row['warnings'][] = 'Нет корректной закупочной цены.';
        $row['status'] = 'warn';
        return $row;
    }

    if (!is_array($ozonItem)) {
        $row['warnings'][] = 'Ozon API не вернул данные по цене и тарифам.';
        $row['status'] = 'warn';
        return $row;
    }

    if (!empty($ozonItem['__invalid_offer_id'])) {
        $reason = trim((string)($ozonItem['__invalid_offer_id_reason'] ?? 'offer_id не подходит для Ozon API.'));
        $row['warnings'][] = $reason !== '' ? $reason : 'offer_id не подходит для Ozon API.';
        $row['status'] = 'warn';
        return $row;
    }

    if (!empty($ozonItem['__missing_on_ozon'])) {
        $row['warnings'][] = 'Товара нет на Ozon в этом аккаунте.';
        $row['status'] = 'warn';
        return $row;
    }

    if (!empty($ozonItem['__missing_price_data'])) {
        $row['warnings'][] = 'Ozon API не вернул данные по цене и тарифам.';
        $row['status'] = 'warn';
        return $row;
    }

    if (!empty($ozonItem['archived'])) {
        $row['ozon_archived'] = true;
        $row['warnings'][] = 'Товар находится в архиве Ozon.';
        $row['status'] = 'warn';
        return $row;
    }

    $price = (float)($ozonItem['price']['price'] ?? 0);
    $minPrice = (float)($ozonItem['price']['min_price'] ?? 0);
    $oldPrice = (float)($ozonItem['price']['old_price'] ?? 0);
    $marketingPrice = (float)($ozonItem['price']['marketing_seller_price'] ?? 0);
    $priceIndexes = (array)($ozonItem['price_indexes'] ?? []);
    $currentSalePrice = $marketingPrice > 0 ? $marketingPrice : $price;
    $currentCommissionProfile = ozon_price_sales_commission_profile($ozonItem, $scheme, $currentSalePrice);
    $commissionPercent = (float)($currentCommissionProfile['percent'] ?? 0);

    if ($commissionPercent <= 0) {
        $row['warnings'][] = 'Ozon API не вернул процент комиссии.';
    }

    $appliedTargetProfitPercent = ozon_price_resolve_target_profit_percent($settings, $purchase, false);
    $appliedMinTargetProfitPercent = ozon_price_resolve_target_profit_percent($settings, $purchase, true);
    $targetProfitMinRub = ozon_price_resolve_target_profit_min_rub($settings, false);
    $minTargetProfitMinRub = ozon_price_resolve_target_profit_min_rub($settings, true);
    $normalTargetRub = max($purchase * ($appliedTargetProfitPercent / 100.0), $targetProfitMinRub);
    $minTargetRub = max($purchase * ($appliedMinTargetProfitPercent / 100.0), $minTargetProfitMinRub);

    $normalCalc = ozon_price_solve_target_price($settings, $ozonItem, $purchase, $normalTargetRub);
    $minCalc = ozon_price_solve_target_price($settings, $ozonItem, $purchase, $minTargetRub);

    if ($normalCalc === null || $minCalc === null) {
        $row['warnings'][] = 'Не удалось рассчитать цену: расходы слишком велики относительно выручки.';
        $row['status'] = 'warn';
        return $row;
    }

    $roundedPrice = ozon_price_round($normalCalc['price'], (string)$settings['rounding_mode']);
    $roundedMinPrice = ozon_price_round($minCalc['price'], (string)$settings['rounding_mode']);
    $modifiedPrice = ozon_price_apply_modifier((float)$roundedPrice, $settings, false);
    $modifiedMinPrice = ozon_price_apply_modifier((float)$roundedMinPrice, $settings, true);
    $supplierPriceModifier = trim((string)($offer['supplier_price_modifier'] ?? ''));
    $modifiedMinPriceBeforeIndexStep = $modifiedMinPrice;
    $budgetGoodsCandidate = $modifiedPrice > 300.0
        ? ozon_price_budget_goods_candidate($settings, $ozonItem, $purchase, $normalTargetRub)
        : null;
    $budgetGoodsBoundaryCandidate = $modifiedPrice > 300.0
        ? ozon_price_budget_goods_boundary_candidate($settings, $ozonItem, $purchase)
        : null;
    $budgetGoodsMinCandidate = $modifiedMinPriceBeforeIndexStep > 300.0
        ? ozon_price_budget_goods_candidate($settings, $ozonItem, $purchase, $minTargetRub)
        : null;
    $recommendedSnapshot = ozon_price_profit_snapshot($settings, $ozonItem, $purchase, (float)$modifiedPrice);
    if (
        is_array($budgetGoodsCandidate)
        && ((float)($budgetGoodsCandidate['price'] ?? 0)) > 0
        && (float)($budgetGoodsCandidate['price'] ?? 0) < $modifiedPrice - 0.01
    ) {
        $modifiedPrice = ozon_price_round_rub((float)$budgetGoodsCandidate['price']);
        $recommendedSnapshot = ozon_price_profit_snapshot($settings, $ozonItem, $purchase, (float)$modifiedPrice);
        $row['warnings'][] = 'Для обычной цены выбран льготный тариф Ozon до 300 ₽: целевая доходность выполняется уже на более низкой цене.';
    } elseif (
        is_array($budgetGoodsBoundaryCandidate)
        && (float)($budgetGoodsBoundaryCandidate['profit_rub'] ?? 0) > (float)($recommendedSnapshot['profit_rub'] ?? 0) + 0.01
    ) {
        $modifiedPrice = ozon_price_round_rub((float)$budgetGoodsBoundaryCandidate['price']);
        $recommendedSnapshot = ozon_price_profit_snapshot($settings, $ozonItem, $purchase, (float)$modifiedPrice);
        $row['warnings'][] = 'Для обычной цены выбран льготный тариф Ozon до 300 ₽: в дешёвом сегменте товар даёт больше прибыли.';
    }
    $recommendedMinBeforeIndexSnapshot = ozon_price_profit_snapshot($settings, $ozonItem, $purchase, (float)$modifiedMinPriceBeforeIndexStep);
    if (
        is_array($budgetGoodsMinCandidate)
        && ((float)($budgetGoodsMinCandidate['price'] ?? 0)) > 0
        && (float)($budgetGoodsMinCandidate['price'] ?? 0) < $modifiedMinPriceBeforeIndexStep - 0.01
    ) {
        $modifiedMinPrice = ozon_price_round_rub((float)$budgetGoodsMinCandidate['price']);
        $modifiedMinPriceBeforeIndexStep = $modifiedMinPrice;
        $recommendedMinBeforeIndexSnapshot = ozon_price_profit_snapshot($settings, $ozonItem, $purchase, (float)$modifiedMinPriceBeforeIndexStep);
        $row['warnings'][] = 'Для min price выбран льготный тариф Ozon до 300 ₽: целевая доходность выполняется уже на более низкой цене.';
    }
    $indexReferencePrice = $minPrice > 0 ? $minPrice : ($marketingPrice > 0 ? $marketingPrice : $price);
    $indexMetrics = $indexReferencePrice > 0 ? ozon_price_index_metric_rows($priceIndexes, $indexReferencePrice) : [];
    $indexMetric = ozon_price_effective_index_metric($priceIndexes);
    $indexBenchmark = $indexMetrics[0] ?? null;
    $comparisonPrice = is_array($indexBenchmark) ? (float)($indexBenchmark['comparison_price'] ?? 0) : null;
    $referenceEffectivePrices = array_values(array_filter(array_map(
        static fn(array $metric): float => (float)($metric['api_effective_price'] ?? 0),
        $indexMetrics
    ), static fn(float $value): bool => $value > 0));
    sort($referenceEffectivePrices, SORT_NUMERIC);
    $currentIndexEffectivePrice = null;
    if ($referenceEffectivePrices) {
        $count = count($referenceEffectivePrices);
        $middle = intdiv($count, 2);
        $currentIndexEffectivePrice = $count % 2 === 1
            ? (float)$referenceEffectivePrices[$middle]
            : (((float)$referenceEffectivePrices[$middle - 1] + (float)$referenceEffectivePrices[$middle]) / 2.0);
    }
    $indexDiscountFactor = ozon_price_index_discount_factor_from_metrics($indexMetrics, $indexReferencePrice) ?? 1.0;
    $colorLevel = ozon_price_index_level_from_api_color((string)($priceIndexes['color_index'] ?? ''));
    $indexTransition = $modifiedMinPrice > 0 && $indexReferencePrice > 0
        ? ozon_price_next_index_transition_target($modifiedMinPrice, $indexReferencePrice, $priceIndexes)
        : null;

    if (!empty($settings['min_price_index_step_enabled']) && is_array($indexTransition) && !empty($indexTransition['can_apply']) && isset($indexTransition['target_price'])) {
        $modifiedMinPrice = ozon_price_round_rub((float)$indexTransition['target_price']);
    }

    if ($supplierPriceModifier !== '' && function_exists('supplier_products_apply_price_modifier')) {
        $modifiedPrice = supplier_products_apply_price_modifier((float)$modifiedPrice, $supplierPriceModifier) ?? $modifiedPrice;
        $modifiedMinPriceBeforeIndexStep = supplier_products_apply_price_modifier((float)$modifiedMinPriceBeforeIndexStep, $supplierPriceModifier) ?? $modifiedMinPriceBeforeIndexStep;
        $modifiedMinPrice = supplier_products_apply_price_modifier((float)$modifiedMinPrice, $supplierPriceModifier) ?? $modifiedMinPrice;
        $recommendedSnapshot = ozon_price_profit_snapshot($settings, $ozonItem, $purchase, (float)$modifiedPrice);
    }

    $modifiedPrice = ozon_price_round_rub($modifiedPrice);
    $modifiedMinPriceBeforeIndexStep = ozon_price_round_rub($modifiedMinPriceBeforeIndexStep);
    $modifiedMinPrice = ozon_price_round_rub($modifiedMinPrice);

    $recommendedPriceIndexEval = ozon_price_eval_index_for_metrics($modifiedPrice, $indexMetrics, $indexDiscountFactor);
    $currentMinIndexEval = ozon_price_eval_index_for_metrics($minPrice, $indexMetrics, $indexDiscountFactor);
    $recommendedMinBeforeIndexEval = ozon_price_eval_index_for_metrics($modifiedMinPriceBeforeIndexStep, $indexMetrics, $indexDiscountFactor);
    $recommendedMinIndexEval = ozon_price_eval_index_for_metrics($modifiedMinPrice, $indexMetrics, $indexDiscountFactor);
    $recommendedPriceIndexValue = (float)($recommendedPriceIndexEval['value'] ?? 0);
    $currentMinIndexValue = (float)($currentMinIndexEval['value'] ?? 0);
    $recommendedMinBeforeIndexValue = (float)($recommendedMinBeforeIndexEval['value'] ?? 0);
    $recommendedMinIndexValue = (float)($recommendedMinIndexEval['value'] ?? 0);

    $currentPriceLevel = $colorLevel !== 'without_index'
        ? ozon_price_index_level_label($colorLevel)
        : ($indexMetrics
            ? ozon_price_index_level_label(ozon_price_index_aggregate_level_from_levels(array_map(static fn(array $metric): string => (string)($metric['api_level'] ?? ''), $indexMetrics)))
            : '');
    $recommendedPriceLevel = $recommendedPriceIndexValue > 0
        ? (string)($recommendedPriceIndexEval['label'] ?? '')
        : '';
    $currentMinPriceLevel = $currentMinIndexValue > 0
        ? (string)($currentMinIndexEval['label'] ?? '')
        : '';
    $recommendedMinBeforeIndexLevel = $recommendedMinBeforeIndexValue > 0
        ? (string)($recommendedMinBeforeIndexEval['label'] ?? '')
        : '';
    $recommendedMinPriceLevel = $recommendedMinIndexValue > 0
        ? (string)($recommendedMinIndexEval['label'] ?? '')
        : '';

    $strikePrice = null;
    if ((float)$settings['strike_discount_percent'] > 0) {
        $discount = (float)$settings['strike_discount_percent'] / 100.0;
        if ($discount > 0 && $discount < 1) {
            $strikePrice = ozon_price_round($modifiedPrice / (1 - $discount), (string)$settings['rounding_mode']);
        }
    }

    $recommendedSnapshot = is_array($recommendedSnapshot)
        ? $recommendedSnapshot
        : ozon_price_profit_snapshot($settings, $ozonItem, $purchase, (float)$modifiedPrice);

    $row['ozon_price'] = $price > 0 ? round($price, 2) : null;
    $row['ozon_min_price'] = $minPrice > 0 ? round($minPrice, 2) : null;
    $row['ozon_old_price'] = $oldPrice > 0 ? round($oldPrice, 2) : null;
    $row['marketing_seller_price'] = $marketingPrice > 0 ? round($marketingPrice, 2) : null;
    $row['recommended_price'] = ozon_price_round_rub($modifiedPrice);
    $row['recommended_min_price'] = ozon_price_round_rub($modifiedMinPrice);
    $row['strike_price'] = $strikePrice !== null ? round($strikePrice, 2) : null;
    $row['profit_rub'] = isset($recommendedSnapshot['profit_rub']) ? round((float)$recommendedSnapshot['profit_rub'], 2) : round($normalTargetRub, 2);
    $row['profit_percent'] = isset($recommendedSnapshot['profit_on_cost_percent']) ? round((float)$recommendedSnapshot['profit_on_cost_percent'], 2) : round((float)$settings['target_profit_percent'], 2);
    $row['commission_percent'] = isset($recommendedSnapshot['breakdown']['commission_percent'])
        ? round((float)$recommendedSnapshot['breakdown']['commission_percent'], 2)
        : round($commissionPercent, 2);
    $row['acquiring_rub'] = isset($recommendedSnapshot['breakdown']['acquiring_rub']) ? round((float)$recommendedSnapshot['breakdown']['acquiring_rub'], 2) : null;
    $row['acquiring_rate_percent'] = isset($recommendedSnapshot['breakdown']['acquiring_rate_percent']) ? round((float)$recommendedSnapshot['breakdown']['acquiring_rate_percent'], 2) : null;
    $row['fixed_costs_rub'] = isset($recommendedSnapshot['breakdown']['base_costs_before_sale_percentages']) ? round((float)$recommendedSnapshot['breakdown']['base_costs_before_sale_percentages'], 2) : null;
    $row['product_id'] = (int)($ozonItem['product_id'] ?? 0) ?: null;
    $row['color_index'] = trim((string)($priceIndexes['color_index'] ?? ''));
    $row['volume_weight'] = isset($ozonItem['volume_weight']) ? round((float)$ozonItem['volume_weight'], 3) : null;
    $row['ozon_item'] = $ozonItem;
    $row['breakdown'] = $recommendedSnapshot['breakdown'] ?? [];
    $row['breakdown']['target_profit_rub'] = round($normalTargetRub, 2);
    $row['breakdown']['target_min_profit_rub'] = round($minTargetRub, 2);
    $row['breakdown']['target_profit_percent_applied'] = round($appliedTargetProfitPercent, 2);
    $row['breakdown']['target_min_profit_percent_applied'] = round($appliedMinTargetProfitPercent, 2);
    $row['breakdown']['target_profit_min_rub'] = round($targetProfitMinRub, 2);
    $row['breakdown']['target_min_profit_min_rub'] = round($minTargetProfitMinRub, 2);
    $row['breakdown']['price_modifier_mode'] = (string)($settings['price_modifier_mode'] ?? 'none');
    $row['breakdown']['price_modifier_value'] = round((float)($settings['price_modifier_value'] ?? 0), 2);
    $row['breakdown']['price_modifier_min_mode'] = (string)($settings['price_modifier_min_mode'] ?? 'none');
    $row['breakdown']['price_modifier_min_value'] = round((float)($settings['price_modifier_min_value'] ?? 0), 2);
    $row['breakdown']['supplier_price_modifier'] = $supplierPriceModifier;
    $row['breakdown']['recommended_price_before_modifier'] = ozon_price_round_rub((float)$roundedPrice);
    $row['breakdown']['recommended_min_price_before_modifier'] = ozon_price_round_rub((float)$roundedMinPrice);
    $row['breakdown']['recommended_min_price_before_index_step'] = ozon_price_round_rub((float)$modifiedMinPriceBeforeIndexStep);
    $row['breakdown']['budget_goods_candidate_price'] = is_array($budgetGoodsCandidate) ? round((float)($budgetGoodsCandidate['price'] ?? 0), 2) : null;
    $row['breakdown']['budget_goods_candidate_profit_rub'] = is_array($budgetGoodsCandidate) ? round((float)($budgetGoodsCandidate['profit_rub'] ?? 0), 2) : null;
    $row['breakdown']['budget_goods_boundary_candidate_price'] = is_array($budgetGoodsBoundaryCandidate) ? ozon_price_round_rub((float)($budgetGoodsBoundaryCandidate['price'] ?? 0)) : null;
    $row['breakdown']['budget_goods_boundary_candidate_profit_rub'] = is_array($budgetGoodsBoundaryCandidate) ? round((float)($budgetGoodsBoundaryCandidate['profit_rub'] ?? 0), 2) : null;
    $row['breakdown']['budget_goods_candidate_band'] = is_array($budgetGoodsCandidate) ? (string)($budgetGoodsCandidate['band'] ?? '') : '';
    $row['breakdown']['budget_goods_candidate_volume_liters'] = is_array($budgetGoodsCandidate) ? round((float)($budgetGoodsCandidate['volume_liters'] ?? 0), 4) : null;
    $row['breakdown']['budget_goods_candidate_volume_source'] = is_array($budgetGoodsCandidate) ? (string)($budgetGoodsCandidate['volume_source'] ?? '') : '';
    $row['breakdown']['budget_goods_min_candidate_price'] = is_array($budgetGoodsMinCandidate) ? round((float)($budgetGoodsMinCandidate['price'] ?? 0), 2) : null;
    $row['breakdown']['budget_goods_min_candidate_profit_rub'] = is_array($budgetGoodsMinCandidate) ? round((float)($budgetGoodsMinCandidate['profit_rub'] ?? 0), 2) : null;
    $row['breakdown']['budget_goods_candidate_selected'] = is_array($budgetGoodsCandidate) && abs((float)$modifiedPrice - (float)($budgetGoodsCandidate['price'] ?? 0)) < 0.01;
    $row['breakdown']['budget_goods_boundary_candidate_selected'] = is_array($budgetGoodsBoundaryCandidate) && abs((float)$modifiedPrice - (float)($budgetGoodsBoundaryCandidate['price'] ?? 0)) < 0.01;
    $row['breakdown']['budget_goods_min_candidate_selected'] = is_array($budgetGoodsMinCandidate) && abs((float)$modifiedMinPriceBeforeIndexStep - (float)($budgetGoodsMinCandidate['price'] ?? 0)) < 0.01;
    $row['breakdown']['color_index'] = $row['color_index'];
    $row['breakdown']['volume_weight'] = $row['volume_weight'];
    $row['breakdown']['price_index_metrics'] = $indexTransition['metrics'] ?? $indexMetrics;
    $row['breakdown']['price_index_benchmark'] = $indexTransition['benchmark']['value'] ?? ($indexBenchmark['comparison_price'] ?? $comparisonPrice);
    $row['breakdown']['price_index_benchmark_source'] = $indexTransition['benchmark']['source'] ?? ($indexBenchmark['source'] ?? (is_array($indexMetric) ? $indexMetric['source'] : ''));
    $row['breakdown']['price_index_benchmark_method'] = $indexTransition['benchmark']['method'] ?? ($indexBenchmark['benchmark_method'] ?? '');
    $row['breakdown']['price_index_current_value'] = $indexMetrics
        ? round((float)(ozon_price_eval_index_for_metrics($indexReferencePrice, $indexMetrics, $indexDiscountFactor)['value'] ?? 0), 4)
        : (is_array($indexMetric) ? round((float)$indexMetric['value'], 4) : null);
    $row['breakdown']['price_index_reference_price'] = $indexReferencePrice > 0 ? round($indexReferencePrice, 2) : null;
    $row['breakdown']['price_index_reference_effective_price'] = $indexTransition['reference_effective_price'] ?? ($currentIndexEffectivePrice !== null ? round($currentIndexEffectivePrice, 2) : null);
    $row['breakdown']['price_index_discount_factor'] = $indexTransition['discount_factor'] ?? round($indexDiscountFactor, 6);
    $row['breakdown']['price_index_discount_percent'] = $indexTransition['discount_percent'] ?? round(max(0.0, (1.0 - $indexDiscountFactor) * 100.0), 2);
    $row['breakdown']['price_index_current_level'] = $indexTransition['current_level_label'] ?? $currentMinPriceLevel;
    $row['breakdown']['price_index_next_level'] = $indexTransition['next_level_label'] ?? '';
    $row['breakdown']['price_index_threshold_price'] = $indexTransition['threshold_price'] ?? null;
    $row['breakdown']['price_index_threshold_effective_price'] = $indexTransition['threshold_effective_price'] ?? null;
    $row['breakdown']['price_index_transition_target_price'] = $indexTransition['target_price'] ?? null;
    $row['breakdown']['price_index_transition_target_effective_price'] = $indexTransition['target_effective_price'] ?? null;
    $row['breakdown']['price_index_transition_drop_percent'] = $indexTransition['drop_percent'] ?? null;
    $row['breakdown']['price_index_transition_current_value'] = $indexTransition['current_index_value'] ?? null;
    $row['breakdown']['price_index_transition_applied'] = !empty($indexTransition['can_apply']);
    $row['breakdown']['price_index_transition_enabled'] = !empty($settings['min_price_index_step_enabled']);
    $row['breakdown']['price_index_current_price_level'] = $currentPriceLevel;
    $row['breakdown']['price_index_recommended_price_level'] = $recommendedPriceLevel;
    $row['breakdown']['price_index_current_min_price_level'] = $currentMinPriceLevel;
    $row['breakdown']['price_index_recommended_min_price_before_index_level'] = $recommendedMinBeforeIndexLevel;
    $row['breakdown']['price_index_recommended_min_price_level'] = $recommendedMinPriceLevel;
    $row['breakdown']['price_index_source_indexes_current'] = $indexTransition['source_indexes_current'] ?? (array)($currentMinIndexEval['sources'] ?? []);
    $row['breakdown']['price_index_source_indexes_target'] = $indexTransition['source_indexes_target'] ?? [];
    $row['breakdown']['price_index_recommended_price_value'] = $recommendedPriceIndexValue > 0 ? round($recommendedPriceIndexValue, 4) : null;
    $row['breakdown']['price_index_current_min_price_value'] = $currentMinIndexValue > 0 ? round($currentMinIndexValue, 4) : null;
    $row['breakdown']['price_index_recommended_min_price_before_index_value'] = $recommendedMinBeforeIndexValue > 0 ? round($recommendedMinBeforeIndexValue, 4) : null;
    $row['breakdown']['price_index_recommended_min_price_value'] = $recommendedMinIndexValue > 0 ? round($recommendedMinIndexValue, 4) : null;
    $row['breakdown']['price_index_current_min_price_effective'] = $currentMinIndexEval['effective_price'] ?? ($minPrice > 0 ? ozon_price_index_effective_price_for_seller_price($minPrice, $indexDiscountFactor) : null);
    $row['breakdown']['price_index_recommended_min_price_before_index_effective'] = $recommendedMinBeforeIndexEval['effective_price'] ?? ($modifiedMinPriceBeforeIndexStep > 0 ? ozon_price_index_effective_price_for_seller_price($modifiedMinPriceBeforeIndexStep, $indexDiscountFactor) : null);
    $row['breakdown']['price_index_recommended_min_price_effective'] = $recommendedMinIndexEval['effective_price'] ?? ($modifiedMinPrice > 0 ? ozon_price_index_effective_price_for_seller_price($modifiedMinPrice, $indexDiscountFactor) : null);

    if ($price <= 0) {
        $row['warnings'][] = 'Ozon API не вернул текущую цену price.';
    }
    if ($minPrice <= 0) {
        $row['warnings'][] = 'Ozon API не вернул текущую минимальную цену min_price.';
    }
    if ($marketingPrice <= 0) {
        $row['warnings'][] = 'Ozon API не вернул marketing_seller_price, эквайринг оценён по price.';
    }
    $hasPriceIndexMetrics = !empty($row['breakdown']['price_index_metrics']);
    if (!$hasPriceIndexMetrics) {
        $row['warnings'][] = 'Товар без индекса цены на Ozon.';
    }
    if (!empty($settings['min_price_index_step_enabled'])) {
        if (!$hasPriceIndexMetrics) {
            $row['warnings'][] = 'Товар без индекса цены на Ozon.';
        } elseif (!is_array($indexTransition) || empty($indexTransition['metrics'])) {
            $row['warnings'][] = 'Не удалось рассчитать цену для перехода на следующий уровень индекса.';
        } elseif (
            empty($indexTransition['can_apply'])
            && !empty($indexTransition['reason'])
            && !in_array((string)($indexTransition['current_level'] ?? ''), ['super', 'without_index'], true)
        ) {
            $row['warnings'][] = $indexTransition['reason'];
        }
    }
    if ($row['warnings']) {
        $row['status'] = 'warn';
    }

    return $row;
}

function ozon_price_reference_sale_price(array $ozonItem, float $fallback = 0.0): float
{
    $marketingPrice = (float)($ozonItem['price']['marketing_seller_price'] ?? 0);
    if ($marketingPrice > 0) {
        return $marketingPrice;
    }
    $price = (float)($ozonItem['price']['price'] ?? 0);
    if ($price > 0) {
        return $price;
    }
    return max(0.0, $fallback);
}

function ozon_price_average_amount(float $min, float $max): float
{
    $min = max(0.0, $min);
    $max = max(0.0, $max);
    if ($min > 0 && $max > 0) {
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        return ($min + $max) / 2.0;
    }
    return max($min, $max);
}

function ozon_price_weighted_direct_flow_amount(float $min, float $max): float
{
    $min = max(0.0, $min);
    $max = max(0.0, $max);
    if ($max < $min) {
        [$min, $max] = [$max, $min];
    }
    if ($min <= 0 && $max <= 0) {
        return 0.0;
    }
    if ($max <= 0 || abs($max - $min) < 0.0001) {
        return max($min, $max);
    }
    $settings = ozon_price_apply_global_settings([]);
    $moscowShare = max(0.0, min(100.0, (float)($settings['logistics_moscow_share_percent'] ?? 25.0))) / 100.0;
    $spbShare = max(0.0, min(100.0, (float)($settings['logistics_spb_share_percent'] ?? 25.0))) / 100.0;
    $regionsShare = max(0.0, min(100.0, (float)($settings['logistics_regions_share_percent'] ?? 50.0))) / 100.0;
    $totalShare = $moscowShare + $spbShare + $regionsShare;
    if ($totalShare <= 0) {
        $moscowShare = 0.25;
        $spbShare = 0.25;
        $regionsShare = 0.50;
        $totalShare = 1.0;
    }
    $moscowShare /= $totalShare;
    $spbShare /= $totalShare;
    $regionsShare /= $totalShare;
    $spbMultiplier = max(100.0, (float)($settings['logistics_spb_multiplier_percent'] ?? 130.0)) / 100.0;
    $spb = $min * $spbMultiplier;
    $regionFloor = $min * $spbMultiplier;
    $regionCeil = max($regionFloor, $max);
    $regions = ($regionFloor + $regionCeil) / 2.0;
    return ($min * $moscowShare) + ($spb * $spbShare) + ($regions * $regionsShare);
}

function ozon_price_distribution_factor(float $issuePercent, float $keptPercent): float
{
    if ($issuePercent <= 0 || $keptPercent <= 0) {
        return 0.0;
    }
    return $issuePercent / $keptPercent;
}

function ozon_price_shipment_adjustment_breakdown(array $settings, float $salePrice): array
{
    if ($salePrice <= 0) {
        return ['total_rub' => 0.0, 'effective_percent' => 0.0, 'rows' => []];
    }

    $rules = [
        ['key' => 'ship_0_12_percent', 'label' => '0–12 часов', 'type' => 'discount', 'rate' => 3.0, 'min_rub' => 0.0],
        ['key' => 'ship_12_24_percent', 'label' => '12–24 часа', 'type' => 'discount', 'rate' => 2.0, 'min_rub' => 0.0],
        ['key' => 'ship_24_36_percent', 'label' => '24–36 часов', 'type' => 'penalty', 'rate' => 1.0, 'min_rub' => 50.0],
        ['key' => 'ship_36_48_percent', 'label' => '36–48 часов', 'type' => 'penalty', 'rate' => 2.0, 'min_rub' => 100.0],
        ['key' => 'ship_48_plus_percent', 'label' => '48+ часов', 'type' => 'penalty', 'rate' => 3.0, 'min_rub' => 100.0],
    ];

    $rows = [];
    $totalRub = 0.0;

    foreach ($rules as $rule) {
        $sharePercent = max(0.0, (float)($settings[$rule['key']] ?? 0));
        if ($sharePercent <= 0) {
            continue;
        }
        $shareRatio = $sharePercent / 100.0;
        $rateRub = $salePrice * ($rule['rate'] / 100.0);
        $bucketRub = $rule['type'] === 'discount'
            ? $rateRub
            : max($rateRub, (float)$rule['min_rub']);
        $signedRub = $bucketRub * $shareRatio * ($rule['type'] === 'discount' ? -1.0 : 1.0);
        $totalRub += $signedRub;
        $rows[] = [
            'label' => $rule['label'],
            'type' => $rule['type'],
            'share_percent' => round($sharePercent, 2),
            'rate_percent' => round((float)$rule['rate'], 2),
            'min_rub' => round((float)$rule['min_rub'], 2),
            'amount_rub' => round($signedRub, 2),
        ];
    }

    return [
        'total_rub' => round($totalRub, 2),
        'effective_percent' => round(($totalRub / $salePrice) * 100.0, 4),
        'rows' => $rows,
    ];
}

function ozon_price_budget_goods_volume_liters(array $ozonItem): ?array
{
    foreach (['volume_liters', 'volume', 'volume_amount'] as $field) {
        if (isset($ozonItem[$field]) && (float)$ozonItem[$field] > 0) {
            return [
                'value' => round((float)$ozonItem[$field], 4),
                'source' => $field,
            ];
        }
    }

    $depth = (float)($ozonItem['depth'] ?? 0);
    $width = (float)($ozonItem['width'] ?? 0);
    $height = (float)($ozonItem['height'] ?? 0);
    if ($depth > 0 && $width > 0 && $height > 0) {
        $unit = strtolower(trim((string)($ozonItem['dimension_unit'] ?? 'mm')));
        $divisor = match ($unit) {
            'cm' => 1000.0,
            'm' => 0.001,
            default => 1000000.0,
        };
        $liters = ($depth * $width * $height) / $divisor;
        return [
            'value' => round($liters, 4),
            'source' => 'ozon_dimensions',
        ];
    }

    return null;
}

function ozon_price_budget_goods_logistics_amount(float $volumeLiters): ?float
{
    // Exact current FBS base amounts observed in Ozon API. The whole-ruble
    // figures in the public tariff table are rounded display values.
    if ($volumeLiters <= 0) {
        return null;
    }
    if ($volumeLiters <= 0.2) {
        return 17.28;
    }
    if ($volumeLiters <= 0.4) {
        return 19.32;
    }
    if ($volumeLiters <= 0.6) {
        return 21.35;
    }
    if ($volumeLiters <= 0.8) {
        return 22.37;
    }
    if ($volumeLiters <= 1.0) {
        return 23.38;
    }
    if ($volumeLiters <= 1.25) {
        return 25.42;
    }
    if ($volumeLiters <= 1.5) {
        return 26.44;
    }
    if ($volumeLiters <= 2.0) {
        return 29.48;
    }
    if ($volumeLiters <= 3.0) {
        return 31.52;
    }
    if ($volumeLiters <= 4.0) {
        return 35.58;
    }
    if ($volumeLiters <= 5.0) {
        return 38.63;
    }
    if ($volumeLiters <= 6.0) {
        return 42.70;
    }
    if ($volumeLiters <= 7.0) {
        return 57.95;
    }
    if ($volumeLiters <= 8.0) {
        return 62.02;
    }
    if ($volumeLiters <= 9.0) {
        return 65.07;
    }
    return null;
}

function ozon_price_budget_goods_current_api_logistics_amount(array $ozonItem, string $scheme): ?float
{
    $scheme = strtolower(trim($scheme));
    $scheme = in_array($scheme, ['fbs', 'fbo'], true) ? $scheme : 'fbs';
    $currentPrice = (float)($ozonItem['price']['marketing_seller_price'] ?? 0);
    if ($currentPrice <= 0) {
        $currentPrice = (float)($ozonItem['price']['price'] ?? 0);
    }
    if ($currentPrice <= 0 || $currentPrice > 300.0) {
        return null;
    }

    $commissions = (array)($ozonItem['commissions'] ?? []);
    $directMin = (float)($commissions[$scheme . '_direct_flow_trans_min_amount'] ?? 0);
    $directMax = (float)($commissions[$scheme . '_direct_flow_trans_max_amount'] ?? 0);
    if ($directMin <= 0 && $directMax <= 0) {
        return null;
    }
    if ($directMin <= 0) {
        $directMin = $directMax;
    }
    if ($directMax <= 0) {
        $directMax = $directMin;
    }
    if ($directMax < $directMin) {
        [$directMin, $directMax] = [$directMax, $directMin];
    }

    return round(ozon_price_weighted_direct_flow_amount($directMin, $directMax), 2);
}

function ozon_price_standard_goods_fallback_direct_flow_range(array $ozonItem): array
{
    $volume = ozon_price_budget_goods_volume_liters($ozonItem);
    $liters = is_array($volume) ? max(0.01, (float)$volume['value']) : 1.0;
    $extraLiters = max(0, (int)ceil($liters) - 1);
    return [
        'min' => 56.0 + ($extraLiters * 10.0),
        'max' => 98.0 + ($extraLiters * 10.0),
    ];
}

function ozon_price_budget_goods_tariff_profile(array $ozonItem, float $salePrice, string $scheme = 'fbs'): ?array
{
    if ($salePrice <= 0 || $salePrice > 300.0) {
        return null;
    }

    $volume = ozon_price_budget_goods_volume_liters($ozonItem);
    $logisticsAmount = ozon_price_budget_goods_current_api_logistics_amount($ozonItem, $scheme);
    $logisticsSource = $logisticsAmount !== null ? 'ozon_api_current_tariff' : 'ozon_dimensions_tariff';
    if ($logisticsAmount === null && is_array($volume)) {
        $logisticsAmount = ozon_price_budget_goods_logistics_amount((float)$volume['value']);
    }
    if ($logisticsAmount === null) {
        return null;
    }

    return [
        'band' => $salePrice <= 100.0 ? 'to_100' : 'to_300',
        'commission_percent' => $salePrice <= 100.0 ? 14.0 : 20.0,
        'cluster_logistics_amount' => round($logisticsAmount, 2),
        'logistics_source' => $logisticsSource,
        'volume_liters' => is_array($volume) ? round((float)$volume['value'], 4) : null,
        'volume_source' => is_array($volume) ? (string)$volume['source'] : 'unavailable',
    ];
}

function ozon_price_sales_commission_profile(array $ozonItem, string $scheme, float $salePrice): array
{
    $scheme = strtolower(trim($scheme));
    $scheme = in_array($scheme, ['fbs', 'fbo'], true) ? $scheme : 'fbs';
    if ($salePrice > 0 && $salePrice <= 300.0) {
        return [
            'percent' => $salePrice <= 100.0 ? 14.0 : 20.0,
            'source' => 'budget_goods_band',
        ];
    }

    $commissions = (array)($ozonItem['commissions'] ?? []);
    $apiPercent = (float)($commissions['sales_percent_' . $scheme] ?? 0);
    $currentPrice = ozon_price_reference_sale_price($ozonItem);
    if ($salePrice > 300.0 && $currentPrice > 0 && $currentPrice <= 300.0) {
        $standardPercent = (float)($ozonItem['standard_sales_percent_' . $scheme] ?? 0);
        if ($standardPercent > 0) {
            return [
                'percent' => $standardPercent,
                'source' => 'same_type_standard_price',
            ];
        }
        return [
            'percent' => $scheme === 'fbo' ? 44.0 : 50.0,
            'source' => 'standard_price_conservative_fallback',
        ];
    }

    return [
        'percent' => max(0.0, $apiPercent),
        'source' => 'ozon_api_current_tariff',
    ];
}

function ozon_price_budget_goods_candidate(array $settings, array $ozonItem, float $purchaseCost, float $targetProfitRub): ?array
{
    $solved = ozon_price_solve_target_price_capped($settings, $ozonItem, $purchaseCost, $targetProfitRub, 300.0);
    if (!is_array($solved)) {
        return null;
    }

    $candidatePrice = ozon_price_round_rub((float)($solved['price'] ?? 0));
    if ($candidatePrice <= 0 || $candidatePrice > 300.0) {
        return null;
    }

    $scheme = strtolower(trim((string)($settings['fulfillment_scheme'] ?? 'fbs')));
    $profile = ozon_price_budget_goods_tariff_profile($ozonItem, $candidatePrice, $scheme);
    if (!is_array($profile)) {
        return null;
    }

    $snapshot = ozon_price_profit_snapshot($settings, $ozonItem, $purchaseCost, $candidatePrice);
    if (!is_array($snapshot)) {
        return null;
    }

    $profitRub = (float)($snapshot['profit_rub'] ?? 0);
    if ($profitRub + 0.01 < $targetProfitRub) {
        return null;
    }

    return [
        'price' => $candidatePrice,
        'profit_rub' => round($profitRub, 2),
        'profit_on_cost_percent' => isset($snapshot['profit_on_cost_percent']) ? round((float)$snapshot['profit_on_cost_percent'], 2) : null,
        'commission_percent' => round((float)$profile['commission_percent'], 2),
        'cluster_logistics_amount' => round((float)$profile['cluster_logistics_amount'], 2),
        'band' => (string)$profile['band'],
        'volume_liters' => isset($profile['volume_liters']) ? round((float)$profile['volume_liters'], 4) : null,
        'volume_source' => (string)$profile['volume_source'],
    ];
}

function ozon_price_budget_goods_boundary_candidate(array $settings, array $ozonItem, float $purchaseCost, float $maxPrice = 299.0): ?array
{
    $candidatePrice = ozon_price_round_rub(min(299.0, $maxPrice));
    if ($candidatePrice <= 0 || $candidatePrice > 300.0) {
        return null;
    }

    $scheme = strtolower(trim((string)($settings['fulfillment_scheme'] ?? 'fbs')));
    $profile = ozon_price_budget_goods_tariff_profile($ozonItem, $candidatePrice, $scheme);
    if (!is_array($profile)) {
        return null;
    }

    $snapshot = ozon_price_profit_snapshot($settings, $ozonItem, $purchaseCost, $candidatePrice);
    if (!is_array($snapshot)) {
        return null;
    }

    return [
        'price' => $candidatePrice,
        'profit_rub' => round((float)($snapshot['profit_rub'] ?? 0), 2),
        'profit_on_cost_percent' => isset($snapshot['profit_on_cost_percent']) ? round((float)$snapshot['profit_on_cost_percent'], 2) : null,
        'commission_percent' => round((float)$profile['commission_percent'], 2),
        'cluster_logistics_amount' => round((float)$profile['cluster_logistics_amount'], 2),
        'band' => (string)$profile['band'],
        'volume_liters' => isset($profile['volume_liters']) ? round((float)$profile['volume_liters'], 4) : null,
        'volume_source' => (string)$profile['volume_source'],
    ];
}

function ozon_price_project_scheme_costs(array $ozonItem, string $scheme, float $salePrice): array
{
    $scheme = strtolower(trim($scheme));
    $scheme = in_array($scheme, ['fbs', 'fbo'], true) ? $scheme : 'fbs';
    $comm = (array)($ozonItem['commissions'] ?? []);
    $referencePrice = ozon_price_reference_sale_price($ozonItem, $salePrice);
    $budgetGoodsProfile = ozon_price_budget_goods_tariff_profile($ozonItem, $salePrice, $scheme);
    $standardPriceTransition = $salePrice > 300.0 && $referencePrice > 0 && $referencePrice <= 300.0;

    $deliveryBase = (float)($comm[$scheme . '_deliv_to_customer_amount'] ?? 0);
    $directMin = (float)($comm[$scheme . '_direct_flow_trans_min_amount'] ?? 0);
    $directMax = (float)($comm[$scheme . '_direct_flow_trans_max_amount'] ?? 0);
    if ($standardPriceTransition) {
        $deliveryBase = (float)($ozonItem['standard_' . $scheme . '_deliv_to_customer_amount'] ?? 0);
        if ($deliveryBase <= 0) {
            $deliveryBase = 25.0;
        }
        $directMin = (float)($ozonItem['standard_' . $scheme . '_direct_flow_trans_min_amount'] ?? 0);
        $directMax = (float)($ozonItem['standard_' . $scheme . '_direct_flow_trans_max_amount'] ?? 0);
        if ($directMin <= 0 || $directMax <= 0) {
            $fallbackDirect = ozon_price_standard_goods_fallback_direct_flow_range($ozonItem);
            $directMin = (float)$fallbackDirect['min'];
            $directMax = (float)$fallbackDirect['max'];
        }
    }
    if ($directMin <= 0 && $directMax > 0) {
        $directMin = $directMax;
    }
    if ($directMax <= 0 && $directMin > 0) {
        $directMax = $directMin;
    }
    if ($directMax < $directMin) {
        [$directMin, $directMax] = [$directMax, $directMin];
    }

    $firstMileMin = $scheme === 'fbs' ? (float)($comm['fbs_first_mile_min_amount'] ?? 0) : 0.0;
    $firstMileMax = $scheme === 'fbs' ? (float)($comm['fbs_first_mile_max_amount'] ?? 0) : 0.0;
    if ($standardPriceTransition && $scheme === 'fbs') {
        $firstMileMin = (float)($ozonItem['standard_fbs_first_mile_min_amount'] ?? 0);
        $firstMileMax = (float)($ozonItem['standard_fbs_first_mile_max_amount'] ?? 0);
        if ($firstMileMin <= 0 && $firstMileMax <= 0) {
            $firstMileMax = 10.0;
        }
    }
    $returnFlow = (float)($comm[$scheme . '_return_flow_amount'] ?? 0);
    if ($standardPriceTransition) {
        $returnFlow = (float)($ozonItem['standard_' . $scheme . '_return_flow_amount'] ?? 0);
        if ($returnFlow <= 0) {
            $returnFlow = $directMax;
        }
    }
    if ($firstMileMin <= 0 && $firstMileMax > 0) {
        $firstMileMin = $firstMileMax;
    }
    if ($firstMileMax <= 0 && $firstMileMin > 0) {
        $firstMileMax = $firstMileMin;
    }
    if ($firstMileMax < $firstMileMin) {
        [$firstMileMin, $firstMileMax] = [$firstMileMax, $firstMileMin];
    }

    $deliveryToCustomer = $deliveryBase;
    $deliveryRatePercent = 0.0;
    $deliveryMode = 'fixed_api_amount';
    $deliveryNote = 'Берём фиксированную сумму доставки до клиента из Ozon API. Для текущих тарифов она уже приходит готовым значением и не пересчитывается от цены товара.';

    $directFlow = ozon_price_weighted_direct_flow_amount($directMin, $directMax);
    $directRatePercent = 0.0;
    $directMode = 'weighted_geo_mix';
    $directNote = 'Берём средневзвешенное по географии отгрузок из Москвы: 25% Москва = min, 25% Санкт-Петербург = 130% от min, 50% регионы = середина диапазона от 130% min до max.';
    if ($standardPriceTransition) {
        $deliveryMode = 'standard_price_reference';
        $deliveryNote = 'При переходе выше 300 ₽ используем стандартную последнюю милю, а не льготный тариф текущей дешёвой цены.';
        $directMode = 'standard_price_reference';
        $directNote = 'При переходе выше 300 ₽ используем стандартную прямую логистику товара того же объёма; при отсутствии эталона — консервативный диапазон.';
    }

    if (is_array($budgetGoodsProfile)) {
        if ($deliveryToCustomer <= 0) {
            $deliveryToCustomer = 25.0;
            $deliveryMode = 'budget_goods_fallback_amount';
            $deliveryNote = 'Ozon не вернул тариф для товара без активной продажи. Используем подтверждённую резервную ставку последней мили 25 ₽.';
        } else {
            $deliveryNote = 'Последняя миля остаётся отдельным расходом даже для товаров дешевле 300 ₽. Берём её фиксированную сумму из Ozon API.';
        }
        if ($scheme === 'fbs' && $firstMileMin <= 0 && $firstMileMax <= 0) {
            $firstMileMin = 10.0;
            $firstMileMax = 10.0;
        }
        $directFlow = (float)$budgetGoodsProfile['cluster_logistics_amount'];
        $directMin = $directFlow;
        $directMax = $directFlow;
        $directRatePercent = 0.0;
        $directMode = 'budget_goods_cluster_tariff';
        $directNote = ($budgetGoodsProfile['logistics_source'] ?? '') === 'ozon_api_current_tariff'
            ? 'Для товара дешевле 300 ₽ берём точную текущую льготную ставку прямой логистики из Ozon API.'
            : 'Для перехода в цену до 300 ₽ считаем льготную прямую логистику по реальным габаритам товара и тарифной таблице Ozon.';
    } elseif (!$standardPriceTransition && $scheme === 'fbo' && $referencePrice > 0 && $directMax > $directMin) {
        $currentWeightedSurcharge = max(0.0, $directFlow - $directMin);
        $directRatePercent = ($currentWeightedSurcharge / $referencePrice) * 100.0;
        $directFlow = $directMin + ($salePrice * ($directRatePercent / 100.0));
        $directMode = 'min_plus_scaled_weighted_surcharge';
        $directNote = 'Для FBO фиксируем нижний порог min по габаритам, а средневзвешенную надбавку сверх min масштабируем вместе с ценой по миксу: 25% Москва = min, 25% Санкт-Петербург = 130% min, 50% регионы = середина диапазона от 130% min до max.';
    }

    return [
        'scheme' => $scheme,
        'reference_price' => round($referencePrice, 2),
        'delivery_to_customer' => round($deliveryToCustomer, 2),
        'delivery_base_amount' => round($deliveryBase, 2),
        'delivery_rate_percent' => round($deliveryRatePercent, 4),
        'delivery_mode' => $deliveryMode,
        'delivery_note' => $deliveryNote,
        'direct_flow' => round($directFlow, 2),
        'direct_flow_min' => round($directMin, 2),
        'direct_flow_max' => round($directMax, 2),
        'direct_flow_rate_percent' => round($directRatePercent, 4),
        'direct_flow_mode' => $directMode,
        'direct_flow_note' => $directNote,
        'first_mile' => round(ozon_price_average_amount($firstMileMin, $firstMileMax), 2),
        'first_mile_min' => round($firstMileMin, 2),
        'first_mile_max' => round($firstMileMax, 2),
        'return_flow' => round($returnFlow, 2),
        'price_sensitive_rate_percent' => round($directRatePercent, 4),
        'budget_goods_profile' => $budgetGoodsProfile,
    ];
}

function ozon_price_cost_model(array $settings, array $ozonItem, float $purchaseCost, float $salePrice): array
{
    $scheme = strtolower(trim((string)($settings['fulfillment_scheme'] ?? 'fbs')));
    $scheme = in_array($scheme, ['fbs', 'fbo'], true) ? $scheme : 'fbs';
    $comm = (array)($ozonItem['commissions'] ?? []);

    $currentSchemeCosts = ozon_price_project_scheme_costs($ozonItem, $scheme, $salePrice);
    $fboResaleSchemeCosts = ozon_price_project_scheme_costs($ozonItem, 'fbo', $salePrice);
    $budgetGoodsProfile = ozon_price_budget_goods_tariff_profile($ozonItem, $salePrice, $scheme);

    $commissionProfile = ozon_price_sales_commission_profile($ozonItem, $scheme, $salePrice);
    $fboCommissionProfile = ozon_price_sales_commission_profile($ozonItem, 'fbo', $salePrice);
    $commissionPercent = (float)($commissionProfile['percent'] ?? 0);
    $fboCommissionPercent = (float)($fboCommissionProfile['percent'] ?? 0);
    $acquiringBase = ozon_price_reference_sale_price($ozonItem, $salePrice);
    $acquiringRub = (float)($ozonItem['acquiring'] ?? 0);
    $acquiringRate = ($acquiringBase > 0 && $acquiringRub > 0) ? ($acquiringRub / $acquiringBase) : 0.0;

    $packagingRub = (float)($settings['fulfillment_markup_rub'] ?? 0);
    $packagingPercent = (float)($settings['fulfillment_markup_percent'] ?? 0);
    $nonbuyoutProcessingRub = (float)($settings['nonbuyout_processing_rub'] ?? 0);
    $returnProcessingRub = (float)($settings['return_processing_rub'] ?? 0);
    $settingsFixed = $packagingRub;
    $nonbuyoutPercent = max(0.0, (float)($settings['nonbuyout_percent'] ?? 0));
    $resellableReturnPercent = max(0.0, (float)($settings['return_resellable_percent'] ?? 0));
    $nonresellableReturnPercent = max(0.0, (float)($settings['return_nonresellable_percent'] ?? 0));
    $promotionPercent = (float)($settings['promotion_percent'] ?? 0);
    $creditPercent = (float)($settings['credit_percent'] ?? 0);
    $extraPercent = (float)($settings['extra_expenses_percent'] ?? 0);
    $insurancePercent = (float)($settings['insurance_percent'] ?? 0);
    $taxMode = strtolower(trim((string)($settings['tax_mode'] ?? 'none')));
    $taxRatePercent = ozon_price_tax_rate($settings) * 100.0;
    $vatRatePercent = max(0.0, (float)($settings['vat_percent'] ?? 0));
    $profitTaxRatePercent = max(0.0, (float)($settings['profit_tax_percent'] ?? 0));
    $delayedPercent = (float)($settings['delayed_shipment_percent'] ?? 0);

    $issueTotalPercent = $nonbuyoutPercent + $resellableReturnPercent + $nonresellableReturnPercent;
    $keptPercent = max(0.01, 100.0 - $issueTotalPercent);
    $nonbuyoutFactor = ozon_price_distribution_factor($nonbuyoutPercent, $keptPercent);
    $resellableReturnFactor = ozon_price_distribution_factor($resellableReturnPercent, $keptPercent);
    $nonresellableReturnFactor = ozon_price_distribution_factor($nonresellableReturnPercent, $keptPercent);

    $currentSchemeSaleCost = ($salePrice * ($commissionPercent / 100.0))
        + (float)$currentSchemeCosts['delivery_to_customer']
        + (float)$currentSchemeCosts['direct_flow']
        + (float)$currentSchemeCosts['first_mile'];
    $fboResaleCost = ($salePrice * ($fboCommissionPercent / 100.0))
        + (float)$fboResaleSchemeCosts['delivery_to_customer']
        + (float)$fboResaleSchemeCosts['direct_flow']
        + (float)$fboResaleSchemeCosts['first_mile'];
    $resaleSchemeDelta = $fboResaleCost - $currentSchemeSaleCost;

    $nonbuyoutVariableRatePercent = 0.0;
    $returnVariableRatePercent = 0.0;
    $nonresellableVariableRatePercent = ($acquiringRate * 100.0) + $packagingPercent + $promotionPercent + $creditPercent + $extraPercent + $insurancePercent + $delayedPercent;

    if ($scheme === 'fbs') {
        $nonbuyoutFixedOneOrderCost = $packagingRub
            + (float)$currentSchemeCosts['direct_flow']
            + (float)$currentSchemeCosts['first_mile']
            + (float)$currentSchemeCosts['return_flow']
            + $nonbuyoutProcessingRub;
        $nonbuyoutVariableCost = ($salePrice * ($nonbuyoutVariableRatePercent / 100.0)) * $nonbuyoutFactor;
        $nonbuyoutResaleDelta = 0.0;
        $nonbuyoutOneOrderCost = $nonbuyoutFixedOneOrderCost + ($salePrice * ($nonbuyoutVariableRatePercent / 100.0));
        $nonbuyoutFixedCost = $nonbuyoutFixedOneOrderCost * $nonbuyoutFactor;
        $nonbuyoutCost = $nonbuyoutFixedCost + $nonbuyoutVariableCost;

        $resellableReturnFixedOneOrderCost = $packagingRub
            + (float)$currentSchemeCosts['delivery_to_customer']
            + (float)$currentSchemeCosts['direct_flow']
            + (float)$currentSchemeCosts['first_mile']
            + (float)$currentSchemeCosts['return_flow']
            + $returnProcessingRub;
        $resellableReturnFixedCost = $resellableReturnFixedOneOrderCost * $resellableReturnFactor;
        $resellableReturnVariableCost = 0.0;
        $resellableReturnResaleDelta = 0.0;
        $resellableReturnCost = $resellableReturnFixedCost + $resellableReturnVariableCost;
        $nonresellableReturnFixedCost = $resellableReturnFixedOneOrderCost * $nonresellableReturnFactor;
        $nonresellableReturnVariableCost = ($salePrice * ($nonresellableVariableRatePercent / 100.0)) * $nonresellableReturnFactor;
    } else {
        $nonbuyoutFixedOneOrderCost = $settingsFixed
            + (float)$currentSchemeCosts['delivery_to_customer']
            + (float)$currentSchemeCosts['direct_flow']
            + (float)$currentSchemeCosts['first_mile']
            + (float)$currentSchemeCosts['return_flow'];
        $nonbuyoutVariableCost = ($salePrice * ($nonbuyoutVariableRatePercent / 100.0)) * $nonbuyoutFactor;
        $nonbuyoutResaleDelta = $resaleSchemeDelta * $nonbuyoutFactor;
        $nonbuyoutOneOrderCost = $nonbuyoutFixedOneOrderCost + $resaleSchemeDelta + ($salePrice * ($nonbuyoutVariableRatePercent / 100.0));
        $nonbuyoutFixedCost = $nonbuyoutFixedOneOrderCost * $nonbuyoutFactor;
        $nonbuyoutCost = $nonbuyoutFixedCost + $nonbuyoutVariableCost + $nonbuyoutResaleDelta;

        $resellableReturnFixedCost = (float)$currentSchemeCosts['return_flow'] * $resellableReturnFactor;
        $resellableReturnVariableCost = 0.0;
        $resellableReturnResaleDelta = $resaleSchemeDelta * $resellableReturnFactor;
        $resellableReturnCost = $resellableReturnFixedCost + $resellableReturnVariableCost + $resellableReturnResaleDelta;
        $nonresellableReturnFixedCost = (float)$currentSchemeCosts['return_flow'] * $nonresellableReturnFactor;
        $nonresellableReturnVariableCost = ($salePrice * ($nonresellableVariableRatePercent / 100.0)) * $nonresellableReturnFactor;
    }
    $resellableReturnLogistics = $resellableReturnFixedCost;
    $nonresellableReturnLogisticsCost = $nonresellableReturnFixedCost + $nonresellableReturnVariableCost;
    $nonresellableReturnPurchaseLoss = $purchaseCost * $nonresellableReturnFactor;
    $issueCost = $nonbuyoutCost + $resellableReturnCost + $nonresellableReturnLogisticsCost + $nonresellableReturnPurchaseLoss;

    $shipmentAdjustment = ozon_price_shipment_adjustment_breakdown($settings, $salePrice);
    $commissionBaseRub = $salePrice * ($commissionPercent / 100.0);
    $commissionRub = $commissionBaseRub + (float)$shipmentAdjustment['total_rub'];
    $acquiringAtPrice = $salePrice * $acquiringRate;
    $promotionRub = $salePrice * ($promotionPercent / 100.0);
    $creditRub = $salePrice * ($creditPercent / 100.0);
    $extraRub = $salePrice * ($extraPercent / 100.0);
    $insuranceRub = $salePrice * ($insurancePercent / 100.0);
    $delayedRub = $salePrice * ($delayedPercent / 100.0);
    $packagingPercentRub = $salePrice * ($packagingPercent / 100.0);

    $baseCostsBeforeTaxes = $purchaseCost
        + $settingsFixed
        + (float)$currentSchemeCosts['delivery_to_customer']
        + (float)$currentSchemeCosts['direct_flow']
        + (float)$currentSchemeCosts['first_mile']
        + $issueCost
        + $commissionRub
        + $acquiringAtPrice
        + $packagingPercentRub
        + $promotionRub
        + $creditRub
        + $extraRub
        + $insuranceRub
        + $delayedRub;

    $vatRub = $salePrice * ($vatRatePercent / 100.0);
    $revenueTaxRub = $taxMode === 'usn_income' ? $salePrice * ($taxRatePercent / 100.0) : 0.0;
    $profitBeforeProfitTaxes = $salePrice - ($baseCostsBeforeTaxes + $vatRub + $revenueTaxRub);
    $incomeExpenseTaxRub = $taxMode === 'usn_income_expense'
        ? max(0.0, $profitBeforeProfitTaxes) * ($taxRatePercent / 100.0)
        : 0.0;
    $profitBeforeProfitTax = $profitBeforeProfitTaxes - $incomeExpenseTaxRub;
    $profitTaxRub = max(0.0, $profitBeforeProfitTax) * ($profitTaxRatePercent / 100.0);
    $taxRub = $revenueTaxRub + $incomeExpenseTaxRub + $vatRub + $profitTaxRub;

    $costs = $baseCostsBeforeTaxes + $taxRub;

    $profitRub = $salePrice - $costs;
    $profitOnCostPercent = $purchaseCost > 0 ? ($profitRub / $purchaseCost) * 100.0 : null;

    $baseCostsBeforeSalePercentages = $baseCostsBeforeTaxes
        - $commissionRub
        - $acquiringAtPrice
        - $packagingPercentRub
        - $promotionRub
        - $creditRub
        - $extraRub
        - $insuranceRub
        - $delayedRub;

    $variableRatePercent = $commissionPercent
        + ((float)$currentSchemeCosts['price_sensitive_rate_percent'])
        + ($acquiringRate * 100.0)
        + $packagingPercent
        + $promotionPercent
        + $creditPercent
        + $extraPercent
        + $insurancePercent
        + ($taxMode === 'usn_income' ? $taxRatePercent : 0.0)
        + $vatRatePercent;

    return [
        'sale_price' => round($salePrice, 2),
        'profit_rub' => round($profitRub, 2),
        'profit_on_cost_percent' => $profitOnCostPercent !== null ? round($profitOnCostPercent, 2) : null,
        'total_costs_rub' => round($costs, 2),
        'breakdown' => [
            'purchase_cost' => round($purchaseCost, 2),
            'settings_fixed' => round($settingsFixed, 2),
            'packaging_rub' => round($packagingRub, 2),
            'packaging_percent' => round($packagingPercent, 2),
            'packaging_percent_rub' => round($packagingPercentRub, 2),
            'nonbuyout_processing_rub' => round($nonbuyoutProcessingRub, 2),
            'return_processing_rub' => round($returnProcessingRub, 2),
            'delivery_to_customer' => round((float)$currentSchemeCosts['delivery_to_customer'], 2),
            'delivery_to_customer_rate_percent' => round((float)$currentSchemeCosts['delivery_rate_percent'], 4),
            'delivery_to_customer_note' => (string)$currentSchemeCosts['delivery_note'],
            'direct_flow' => round((float)$currentSchemeCosts['direct_flow'], 2),
            'direct_flow_min' => round((float)$currentSchemeCosts['direct_flow_min'], 2),
            'direct_flow_max_current' => round((float)$currentSchemeCosts['direct_flow_max'], 2),
            'direct_flow_rate_percent' => round((float)$currentSchemeCosts['direct_flow_rate_percent'], 4),
            'direct_flow_note' => (string)$currentSchemeCosts['direct_flow_note'],
            'first_mile' => round((float)$currentSchemeCosts['first_mile'], 2),
            'first_mile_min' => round((float)$currentSchemeCosts['first_mile_min'], 2),
            'first_mile_max' => round((float)$currentSchemeCosts['first_mile_max'], 2),
            'return_flow_tariff' => round((float)$currentSchemeCosts['return_flow'], 2),
            'issue_total_percent' => round($issueTotalPercent, 2),
            'kept_percent' => round($keptPercent, 2),
            'nonbuyout_percent' => round($nonbuyoutPercent, 2),
            'nonbuyout_factor_percent' => round($nonbuyoutFactor * 100.0, 4),
            'nonbuyout_base_one_order_cost' => round($nonbuyoutFixedOneOrderCost, 2),
            'nonbuyout_one_order_cost' => round($nonbuyoutOneOrderCost, 2),
            'nonbuyout_fixed_cost' => round($nonbuyoutFixedCost, 2),
            'nonbuyout_variable_rate_percent' => round($nonbuyoutVariableRatePercent, 4),
            'nonbuyout_variable_cost' => round($nonbuyoutVariableCost, 2),
            'nonbuyout_resale_delta_cost' => round($nonbuyoutResaleDelta, 2),
            'nonbuyout_cost' => round($nonbuyoutCost, 2),
            'current_scheme_sale_cost' => round($currentSchemeSaleCost, 2),
            'fbo_resale_cost' => round($fboResaleCost, 2),
            'resale_scheme_delta' => round($resaleSchemeDelta, 2),
            'return_resellable_factor_percent' => round($resellableReturnFactor * 100.0, 4),
            'return_resellable_logistics_cost' => round($resellableReturnLogistics, 2),
            'return_resellable_variable_rate_percent' => round($returnVariableRatePercent, 4),
            'return_resellable_variable_cost' => round($resellableReturnVariableCost, 2),
            'return_resellable_resale_delta_cost' => round($resellableReturnResaleDelta, 2),
            'return_resellable_cost' => round($resellableReturnCost, 2),
            'return_nonresellable_factor_percent' => round($nonresellableReturnFactor * 100.0, 4),
            'return_nonresellable_logistics_cost' => round($nonresellableReturnFixedCost, 2),
            'return_nonresellable_variable_rate_percent' => round($nonresellableVariableRatePercent, 4),
            'return_nonresellable_variable_cost' => round($nonresellableReturnVariableCost, 2),
            'return_nonresellable_purchase_loss' => round($nonresellableReturnPurchaseLoss, 2),
            'issue_cost' => round($issueCost, 2),
            'commission_percent' => round($commissionPercent, 2),
            'commission_source' => (string)($commissionProfile['source'] ?? ''),
            'budget_goods_tariff_applied' => is_array($budgetGoodsProfile),
            'budget_goods_band' => is_array($budgetGoodsProfile) ? (string)($budgetGoodsProfile['band'] ?? '') : '',
            'budget_goods_commission_percent' => is_array($budgetGoodsProfile) ? round((float)($budgetGoodsProfile['commission_percent'] ?? 0), 2) : null,
            'budget_goods_cluster_logistics_rub' => is_array($budgetGoodsProfile) ? round((float)($budgetGoodsProfile['cluster_logistics_amount'] ?? 0), 2) : null,
            'budget_goods_volume_liters' => is_array($budgetGoodsProfile) ? round((float)($budgetGoodsProfile['volume_liters'] ?? 0), 4) : null,
            'budget_goods_volume_source' => is_array($budgetGoodsProfile) ? (string)($budgetGoodsProfile['volume_source'] ?? '') : '',
            'commission_base_rub' => round($commissionBaseRub, 2),
            'commission_time_adjustment_rub' => round((float)$shipmentAdjustment['total_rub'], 2),
            'commission_time_adjustment_percent' => round((float)$shipmentAdjustment['effective_percent'], 4),
            'shipment_adjustment_rows' => $shipmentAdjustment['rows'],
            'commission_rub' => round($commissionRub, 2),
            'acquiring_rub' => round($acquiringAtPrice, 2),
            'acquiring_rate_percent' => round($acquiringRate * 100.0, 4),
            'promotion_rub' => round($promotionRub, 2),
            'credit_rub' => round($creditRub, 2),
            'extra_rub' => round($extraRub, 2),
            'insurance_rub' => round($insuranceRub, 2),
            'tax_rub' => round($taxRub, 2),
            'revenue_tax_rub' => round($revenueTaxRub, 2),
            'income_expense_tax_rub' => round($incomeExpenseTaxRub, 2),
            'vat_rub' => round($vatRub, 2),
            'profit_tax_rub' => round($profitTaxRub, 2),
            'tax_mode' => $taxMode,
            'tax_rate_percent' => $salePrice > 0 ? round(($taxRub / $salePrice) * 100.0, 2) : 0.0,
            'revenue_tax_rate_percent' => round($taxRatePercent, 2),
            'vat_rate_percent' => round($vatRatePercent, 2),
            'profit_tax_rate_percent' => round($profitTaxRatePercent, 2),
            'delayed_rub' => round($delayedRub, 2),
            'delayed_shipment_percent' => round($delayedPercent, 2),
            'variable_rate_percent' => round($variableRatePercent, 4),
            'price_sensitive_logistics_rate_percent' => round((float)$currentSchemeCosts['price_sensitive_rate_percent'], 4),
            'base_costs_before_sale_percentages' => round($baseCostsBeforeSalePercentages, 2),
        ],
    ];
}

function ozon_price_profit_snapshot(array $settings, ?array $ozonItem, float $purchaseCost, float $salePrice): ?array
{
    if (!is_array($ozonItem) || $purchaseCost <= 0 || $salePrice <= 0) {
        return null;
    }

    $model = ozon_price_cost_model($settings, $ozonItem, $purchaseCost, $salePrice);
    $model['trace'] = ozon_price_build_trace($settings, $ozonItem, $purchaseCost, $salePrice);
    return $model;
}

function ozon_price_build_trace(array $settings, array $ozonItem, float $purchaseCost, float $salePrice): array
{
    $model = ozon_price_cost_model($settings, $ozonItem, $purchaseCost, $salePrice);
    $breakdown = (array)($model['breakdown'] ?? []);

    $scheme = strtolower(trim((string)($settings['fulfillment_scheme'] ?? 'fbs')));
    $scheme = in_array($scheme, ['fbs', 'fbo'], true) ? $scheme : 'fbs';

    $nonbuyoutPercent = max(0.0, (float)($settings['nonbuyout_percent'] ?? 0));
    $resellableReturnPercent = max(0.0, (float)($settings['return_resellable_percent'] ?? 0));
    $nonresellableReturnPercent = max(0.0, (float)($settings['return_nonresellable_percent'] ?? 0));
    $promotionPercent = (float)($settings['promotion_percent'] ?? 0);
    $creditPercent = (float)($settings['credit_percent'] ?? 0);
    $extraPercent = (float)($settings['extra_expenses_percent'] ?? 0);
    $insurancePercent = (float)($settings['insurance_percent'] ?? 0);
    $taxRatePercent = ozon_price_tax_rate($settings) * 100.0;
    $rows = [];
    $running = 0.0;
    $push = static function (string $stage, string $label, ?float $ratePercent, float $amountRub, string $note = '') use (&$rows, &$running): void {
        $running += $amountRub;
        $rows[] = [
            'stage' => $stage,
            'label' => $label,
            'rate_percent' => $ratePercent !== null ? round($ratePercent, 4) : null,
            'amount_rub' => round($amountRub, 2),
            'running_total_rub' => round($running, 2),
            'note' => $note,
        ];
    };

    $push('base', 'Закупочная цена', null, $purchaseCost, 'Стартовая база расчёта');
    $push('fixed', 'Расходы на упаковку на складе', null, (float)($settings['fulfillment_markup_rub'] ?? 0), 'Фиксированная сумма из настроек');
    if ((float)($breakdown['packaging_percent_rub'] ?? 0) > 0) {
        $push('extra', 'Расходы на упаковку на складе, %', (float)($breakdown['packaging_percent'] ?? 0), (float)($breakdown['packaging_percent_rub'] ?? 0), 'Процент от финальной цены.');
    }

    $push('platform', 'Комиссия Ozon', (float)($breakdown['commission_percent'] ?? 0), (float)($breakdown['commission_base_rub'] ?? 0), 'Базовая комиссия Ozon как процент от цены продажи.');
    if (abs((float)($breakdown['commission_time_adjustment_rub'] ?? 0)) > 0.0001) {
        $adjustmentPercent = (float)($breakdown['commission_time_adjustment_percent'] ?? 0);
        $adjustmentType = $adjustmentPercent < 0 ? 'Ожидаемая скидка' : 'Ожидаемый штраф';
        $push(
            'platform',
            'Поправка за скорость отгрузки',
            $adjustmentPercent,
            (float)($breakdown['commission_time_adjustment_rub'] ?? 0),
            $adjustmentType . ' к комиссии по распределению заказов между окнами 0–12 / 12–24 / 24–36 / 36–48 / 48+ часов.'
        );
    }
    if ((float)($breakdown['acquiring_rub'] ?? 0) > 0) {
        $push('platform', 'Эквайринг', (float)($breakdown['acquiring_rate_percent'] ?? 0), (float)($breakdown['acquiring_rub'] ?? 0), 'Ставку восстанавливаем из текущего ответа Ozon API.');
    }

    $deliveryRate = (float)($breakdown['delivery_to_customer_rate_percent'] ?? 0);
    $deliveryNote = (string)($breakdown['delivery_to_customer_note'] ?? 'Тариф Ozon');
    $push('ozon', 'Логистика: доставка до клиента', $deliveryRate > 0 ? $deliveryRate : null, (float)($breakdown['delivery_to_customer'] ?? 0), $deliveryNote);

    $directRate = (float)($breakdown['direct_flow_rate_percent'] ?? 0);
    $directNote = (string)($breakdown['direct_flow_note'] ?? 'Среднее значение из диапазона Ozon.');
    $push('ozon', 'Логистика: магистраль', $directRate > 0 ? $directRate : null, (float)($breakdown['direct_flow'] ?? 0), $directNote);

    $firstMile = (float)($breakdown['first_mile'] ?? 0);
    if ($firstMile > 0) {
        $push('ozon', 'Логистика: первая миля', null, $firstMile, $scheme === 'fbs'
            ? 'Для FBS берём среднее между min и max, чтобы усреднить неизвестное направление сдачи.'
            : 'Тариф Ozon');
    }

    if ((float)($breakdown['nonbuyout_cost'] ?? 0) > 0) {
        $baseCost = (float)($breakdown['nonbuyout_fixed_cost'] ?? 0);
        if ($baseCost > 0) {
            $baseNote = $scheme === 'fbs'
                ? 'Для FBS: упаковка, прямая логистика, первая миля, обратная логистика и обработка невыкупа, распределённые на успешные продажи.'
                : 'Упаковка, прямая логистика и обратный поток, распределённые на успешные продажи.';
            $push('risk', 'Невыкупы: фиксированные расходы', (float)($breakdown['nonbuyout_factor_percent'] ?? null), $baseCost, $baseNote);
        }
        if ((float)($breakdown['nonbuyout_variable_cost'] ?? 0) > 0) {
            $push(
                'risk',
                'Невыкупы: ценозависимая часть',
                (float)($breakdown['nonbuyout_variable_rate_percent'] ?? null),
                (float)$breakdown['nonbuyout_variable_cost'],
                'Процентные расходы, которые растут вместе с ценой: процентная упаковка, продвижение, кредит, доп. расходы, страховой резерв и штрафы за срок отгрузки.'
            );
        }
        if (abs((float)($breakdown['nonbuyout_resale_delta_cost'] ?? 0)) > 0.0001) {
            $push('risk', 'Невыкупы: дельта FBO против текущей схемы', (float)($breakdown['nonbuyout_factor_percent'] ?? null), (float)$breakdown['nonbuyout_resale_delta_cost'], 'После невыкупа предполагаем повторную продажу по FBO и учитываем разницу её расходов против текущей схемы.');
        }
    }
    if ((float)($breakdown['return_resellable_logistics_cost'] ?? 0) > 0) {
        $returnNote = $scheme === 'fbs'
            ? 'Для FBS: упаковка, последняя миля, прямая логистика, первая миля, обратная логистика и обработка возврата, распределённые на успешные продажи.'
            : 'Тариф возврата Ozon × коэффициент распределения возвратов на успешные продажи.';
        $push('risk', 'Возвраты: логистика и обработка', (float)($breakdown['return_resellable_factor_percent'] ?? null), (float)$breakdown['return_resellable_logistics_cost'], $returnNote);
    }
    if ((float)($breakdown['return_resellable_variable_cost'] ?? 0) > 0) {
        $push(
            'risk',
            'Возвраты: ценозависимая часть',
            (float)($breakdown['return_resellable_variable_rate_percent'] ?? null),
            (float)$breakdown['return_resellable_variable_cost'],
            'Процентные расходы возврата, которые меняются вместе с ценой: эквайринг, процентная упаковка, продвижение, кредит, доп. расходы, страховой резерв и штрафы за срок отгрузки.'
        );
    }
    if (abs((float)($breakdown['return_resellable_resale_delta_cost'] ?? 0)) > 0.0001) {
        $push('risk', 'Возвраты: дельта FBO против текущей схемы', (float)($breakdown['return_resellable_factor_percent'] ?? null), (float)$breakdown['return_resellable_resale_delta_cost'], 'Разница повторной продажи по FBO с учётом пересчёта ценозависимой логистики.');
    }
    if ((float)($breakdown['return_nonresellable_logistics_cost'] ?? 0) > 0) {
        $lossNote = $scheme === 'fbs'
            ? 'Для FBS: упаковка, последняя миля, прямая логистика, первая миля, обратная логистика и обработка возврата, распределённые на успешные продажи.'
            : 'Тариф возврата Ozon × коэффициент распределения безвозвратных потерь на успешные продажи.';
        $push('risk', 'Безвозвратные потери: логистика и обработка', (float)($breakdown['return_nonresellable_factor_percent'] ?? null), (float)$breakdown['return_nonresellable_logistics_cost'], $lossNote);
    }
    if ((float)($breakdown['return_nonresellable_variable_cost'] ?? 0) > 0) {
        $push(
            'risk',
            'Безвозвратные потери: ценозависимая часть',
            (float)($breakdown['return_nonresellable_variable_rate_percent'] ?? null),
            (float)$breakdown['return_nonresellable_variable_cost'],
            'Процентные расходы безвозвратной потери, которые меняются вместе с ценой: эквайринг, процентная упаковка, продвижение, кредит, доп. расходы, страховой резерв и штрафы за срок отгрузки.'
        );
    }
    if ((float)($breakdown['return_nonresellable_purchase_loss'] ?? 0) > 0) {
        $push('risk', 'Безвозвратные потери: потери в себестоимости', (float)($breakdown['return_nonresellable_factor_percent'] ?? null), (float)$breakdown['return_nonresellable_purchase_loss'], 'Закупка × коэффициент распределения безвозвратных потерь на успешные продажи.');
    }

    if ($promotionPercent > 0) {
        $push('extra', 'Продвижение', $promotionPercent, (float)($breakdown['promotion_rub'] ?? 0), 'Процент от финальной цены.');
    }
    if ($creditPercent > 0) {
        $push('extra', 'Кредит / рассрочка', $creditPercent, (float)($breakdown['credit_rub'] ?? 0), 'Процент от финальной цены.');
    }
    if ($extraPercent > 0) {
        $push('extra', 'Доп. расходы', $extraPercent, (float)($breakdown['extra_rub'] ?? 0), 'Процент от финальной цены.');
    }
    if ($insurancePercent > 0) {
        $push('extra', 'Страховые / резерв', $insurancePercent, (float)($breakdown['insurance_rub'] ?? 0), 'Процент от финальной цены.');
    }
    if ((float)($breakdown['revenue_tax_rub'] ?? 0) > 0) {
        $label = ($breakdown['tax_mode'] ?? 'none') === 'usn_income' ? 'УСН доходы' : 'Налог с выручки';
        $push('tax', $label, (float)($breakdown['revenue_tax_rate_percent'] ?? 0), (float)($breakdown['revenue_tax_rub'] ?? 0), 'Налог считается как процент от выручки.');
    }
    if ((float)($breakdown['income_expense_tax_rub'] ?? 0) > 0) {
        $push('tax', 'УСН доходы-расходы', null, (float)($breakdown['income_expense_tax_rub'] ?? 0), 'Налог считается от положительной прибыли после основных расходов.');
    }
    if ((float)($breakdown['vat_rub'] ?? 0) > 0) {
        $push('tax', 'НДС', (float)($breakdown['vat_rate_percent'] ?? 0), (float)($breakdown['vat_rub'] ?? 0), 'Добавляем НДС как процент от цены продажи.');
    }
    if ((float)($breakdown['profit_tax_rub'] ?? 0) > 0) {
        $push('tax', 'Налог на прибыль', (float)($breakdown['profit_tax_rate_percent'] ?? 0), (float)($breakdown['profit_tax_rub'] ?? 0), 'Считаем как процент от положительной прибыли после остальных налогов.');
    }
    return [
        'rows' => $rows,
        'total_costs_rub' => round($running, 2),
        'sale_price_rub' => round($salePrice, 2),
        'profit_rub' => round((float)($model['profit_rub'] ?? 0), 2),
        'profit_on_cost_percent' => isset($model['profit_on_cost_percent']) ? round((float)$model['profit_on_cost_percent'], 2) : null,
    ];
}

function ozon_price_tax_rate(array $settings): float
{
    $mode = strtolower(trim((string)($settings['tax_mode'] ?? 'none')));
    $rate = max(0.0, (float)$settings['tax_percent']);
    if ($mode === 'usn_income' || $mode === 'usn_income_expense') {
        return $rate / 100.0;
    }
    return 0.0;
}

function ozon_price_solve_target_price(array $settings, array $ozonItem, float $purchaseCost, float $targetProfitRub): ?array
{
    $startPrice = ozon_price_reference_sale_price($ozonItem, $purchaseCost + $targetProfitRub);
    $price = max(1.0, $startPrice > 0 ? $startPrice : ($purchaseCost + $targetProfitRub));

    for ($i = 0; $i < 30; $i++) {
        $snapshot = ozon_price_cost_model($settings, $ozonItem, $purchaseCost, $price);
        $next = (float)$snapshot['total_costs_rub'] + $targetProfitRub;
        if (!is_finite($next) || $next <= 0) {
            return null;
        }
        if (abs($next - $price) < 0.01) {
            $price = $next;
            break;
        }
        $price = $next;
        if ($price > 100000000) {
            return null;
        }
    }

    if ($price <= 0 || !is_finite($price)) {
        return null;
    }

    $snapshot = ozon_price_cost_model($settings, $ozonItem, $purchaseCost, $price);
    return [
        'price' => $price,
        'delayed_charge' => (float)($snapshot['breakdown']['delayed_rub'] ?? 0),
    ];
}

function ozon_price_solve_target_price_capped(array $settings, array $ozonItem, float $purchaseCost, float $targetProfitRub, float $maxPrice): ?array
{
    if ($maxPrice <= 0) {
        return null;
    }

    $startPrice = ozon_price_reference_sale_price($ozonItem, min($maxPrice, $purchaseCost + $targetProfitRub));
    $price = max(1.0, min($maxPrice, $startPrice > 0 ? $startPrice : ($purchaseCost + $targetProfitRub)));

    for ($i = 0; $i < 30; $i++) {
        $snapshot = ozon_price_cost_model($settings, $ozonItem, $purchaseCost, $price);
        $next = (float)$snapshot['total_costs_rub'] + $targetProfitRub;
        if (!is_finite($next) || $next <= 0) {
            return null;
        }
        if ($next > $maxPrice + 0.01) {
            return null;
        }
        if (abs($next - $price) < 0.01) {
            $price = $next;
            break;
        }
        $price = max(1.0, min($maxPrice, $next));
    }

    if ($price <= 0 || !is_finite($price) || $price > $maxPrice + 0.01) {
        return null;
    }

    $snapshot = ozon_price_cost_model($settings, $ozonItem, $purchaseCost, $price);
    $profitRub = (float)($snapshot['profit_rub'] ?? 0);
    if ($profitRub + 0.01 < $targetProfitRub) {
        return null;
    }

    return [
        'price' => $price,
        'delayed_charge' => (float)($snapshot['breakdown']['delayed_rub'] ?? 0),
    ];
}
