<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

final class OpenAIRequestLog
{
    public static function setContext(array $context): void
    {
        $GLOBALS['__feedtools_openai_request_context'] = $context;
    }

    public static function clearContext(): void
    {
        unset($GLOBALS['__feedtools_openai_request_context']);
    }

    public static function context(): array
    {
        $ctx = $GLOBALS['__feedtools_openai_request_context'] ?? [];
        return is_array($ctx) ? $ctx : [];
    }

    public static function ensureTable(): void
    {
        static $ready = false;
        if ($ready) return;

        db()->exec("
            CREATE TABLE IF NOT EXISTS feedtools_openai_requests (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              op_id BIGINT UNSIGNED NULL,
              dataset_id BIGINT UNSIGNED NULL,
              op_type VARCHAR(100) NULL,
              actor_user VARCHAR(191) NULL,
              root_op_id BIGINT UNSIGNED NULL,
              root_op_type VARCHAR(100) NULL,
              model VARCHAR(100) NOT NULL,
              endpoint VARCHAR(64) NOT NULL,
              status VARCHAR(20) NOT NULL,
              duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
              local_cache_hit TINYINT(1) NOT NULL DEFAULT 0,
              cache_key CHAR(64) NULL,
              prompt_cache_key VARCHAR(128) NULL,
              prompt_cache_retention VARCHAR(32) NULL,
              input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
              cached_input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
              output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
              total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
              request_hash CHAR(64) NULL,
              response_hash CHAR(64) NULL,
              request_json LONGTEXT NULL,
              response_text MEDIUMTEXT NULL,
              response_json LONGTEXT NULL,
              error_text TEXT NULL,
              PRIMARY KEY (id),
              KEY idx_op_created (op_id, created_at),
              KEY idx_dataset_created (dataset_id, created_at),
              KEY idx_actor_created (actor_user, created_at),
              KEY idx_root_created (root_op_id, created_at),
              KEY idx_created (created_at),
              KEY idx_cache_key (cache_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $wanted = [
            'actor_user' => "ALTER TABLE feedtools_openai_requests ADD COLUMN actor_user VARCHAR(191) NULL AFTER op_type",
            'root_op_id' => "ALTER TABLE feedtools_openai_requests ADD COLUMN root_op_id BIGINT UNSIGNED NULL AFTER actor_user",
            'root_op_type' => "ALTER TABLE feedtools_openai_requests ADD COLUMN root_op_type VARCHAR(100) NULL AFTER root_op_id",
        ];
        foreach ($wanted as $col => $sql) {
            $st = db()->prepare("
              SELECT COUNT(*)
              FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'feedtools_openai_requests'
                AND COLUMN_NAME = ?
            ");
            $st->execute([$col]);
            if ((int)$st->fetchColumn() === 0) {
                db()->exec($sql);
            }
        }

        $indexes = [
            'idx_actor_created' => "ALTER TABLE feedtools_openai_requests ADD KEY idx_actor_created (actor_user, created_at)",
            'idx_root_created' => "ALTER TABLE feedtools_openai_requests ADD KEY idx_root_created (root_op_id, created_at)",
        ];
        foreach ($indexes as $name => $sql) {
            $st = db()->prepare("
              SELECT COUNT(*)
              FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'feedtools_openai_requests'
                AND INDEX_NAME = ?
            ");
            $st->execute([$name]);
            if ((int)$st->fetchColumn() === 0) {
                db()->exec($sql);
            }
        }

        $ready = true;
    }

    public static function write(array $row, int $maxRequestChars = 200000, int $maxResponseChars = 200000): void
    {
        try {
            self::ensureTable();

            $requestJson = self::jsonForStorage($row['request_payload'] ?? null, $maxRequestChars);
            $responseJson = self::jsonForStorage($row['response_payload'] ?? null, $maxResponseChars);
            $responseText = self::limitText((string)($row['response_text'] ?? ''), min($maxResponseChars, 100000));
            $errorText = self::limitText((string)($row['error_text'] ?? ''), 5000);

            $st = db()->prepare("
                INSERT INTO feedtools_openai_requests (
                  op_id, dataset_id, op_type, actor_user, root_op_id, root_op_type,
                  model, endpoint, status, duration_ms,
                  local_cache_hit, cache_key, prompt_cache_key, prompt_cache_retention,
                  input_tokens, cached_input_tokens, output_tokens, total_tokens,
                  request_hash, response_hash, request_json, response_text, response_json, error_text
                ) VALUES (
                  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                  ?, ?, ?, ?,
                  ?, ?, ?, ?,
                  ?, ?, ?, ?, ?, ?
                )
            ");

            $ctx = self::context();
            $st->execute([
                self::nullableInt($ctx['op_id'] ?? null),
                self::nullableInt($ctx['dataset_id'] ?? null),
                self::nullableString($ctx['op_type'] ?? null, 100),
                self::nullableString($ctx['actor_user'] ?? null, 191),
                self::nullableInt($ctx['root_op_id'] ?? null),
                self::nullableString($ctx['root_op_type'] ?? null, 100),
                (string)($row['model'] ?? ''),
                (string)($row['endpoint'] ?? ''),
                (string)($row['status'] ?? 'ok'),
                max(0, (int)($row['duration_ms'] ?? 0)),
                !empty($row['local_cache_hit']) ? 1 : 0,
                self::nullableString($row['cache_key'] ?? null, 64),
                self::nullableString($row['prompt_cache_key'] ?? null, 128),
                self::nullableString($row['prompt_cache_retention'] ?? null, 32),
                max(0, (int)($row['input_tokens'] ?? 0)),
                max(0, (int)($row['cached_input_tokens'] ?? 0)),
                max(0, (int)($row['output_tokens'] ?? 0)),
                max(0, (int)($row['total_tokens'] ?? 0)),
                $requestJson !== null ? hash('sha256', $requestJson) : null,
                $responseJson !== null ? hash('sha256', $responseJson) : null,
                $requestJson,
                $responseText !== '' ? $responseText : null,
                $responseJson,
                $errorText !== '' ? $errorText : null,
            ]);
            self::cleanupConfiguredOnce();
        } catch (Throwable $e) {
            // Журнал GPT не должен ломать рабочие операции.
        }
    }

    public static function cleanupOld(int $days = 1, int $limit = 5000): int
    {
        self::ensureTable();
        $days = max(1, min(3650, $days));
        $limit = max(1, min(50000, $limit));
        $st = db()->prepare("
            DELETE FROM feedtools_openai_requests
            WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)
            LIMIT {$limit}
        ");
        $st->execute();
        return (int)$st->rowCount();
    }

    private static function cleanupConfiguredOnce(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $days = 1;
            $cfgPath = __DIR__ . '/../config.php';
            if (is_file($cfgPath)) {
                $cfg = require $cfgPath;
                if (is_array($cfg)) {
                    $days = (int)($cfg['retention']['llm_request_days'] ?? $days);
                }
            } elseif (function_exists('ft_env_int')) {
                $days = ft_env_int('APP_RETENTION_LLM_REQUEST_DAYS', $days);
            }
            self::cleanupOld($days);
        } catch (Throwable $e) {
            // Очистка журнала не должна мешать LLM-операциям.
        }
    }

    public static function countForOp(int $opId): int
    {
        return self::count(['op_id' => $opId]);
    }

    public static function count(array $filters = []): int
    {
        try {
            self::ensureTable();
            [$whereSql, $args] = self::buildWhere($filters);
            $sql = "SELECT COUNT(*) FROM feedtools_openai_requests";
            if ($whereSql !== '') $sql .= ' WHERE ' . $whereSql;
            $st = db()->prepare($sql);
            self::bindArgs($st, $args);
            $st->execute();
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function latest(array $filters = [], int $limit = 100): array
    {
        self::ensureTable();

        [$whereSql, $args] = self::buildWhere($filters);

        $sql = "
            SELECT id, created_at, op_id, dataset_id, op_type, actor_user, root_op_id, root_op_type, model, endpoint, status,
                   duration_ms, local_cache_hit, cache_key, prompt_cache_key,
                   input_tokens, cached_input_tokens, output_tokens, total_tokens,
                   error_text
            FROM feedtools_openai_requests
        ";
        if ($whereSql !== '') $sql .= ' WHERE ' . $whereSql;
        $sql .= ' ORDER BY id DESC LIMIT ?';

        $st = db()->prepare($sql);
        self::bindArgs($st, $args);
        $st->bindValue(count($args) + 1, max(1, min(500, $limit)), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public static function get(int $id): ?array
    {
        self::ensureTable();
        $st = db()->prepare("SELECT * FROM feedtools_openai_requests WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function summarize(array $filters = []): array
    {
        self::ensureTable();

        [$whereSql, $args] = self::buildWhere($filters);

        $sql = "
            SELECT
              COUNT(*) AS requests,
              SUM(CASE WHEN local_cache_hit=0 THEN 1 ELSE 0 END) AS api_requests,
              SUM(CASE WHEN local_cache_hit=1 THEN 1 ELSE 0 END) AS local_hits,
              SUM(CASE WHEN local_cache_hit=0 AND cached_input_tokens>0 THEN 1 ELSE 0 END) AS prompt_hits,
              SUM(input_tokens) AS input_tokens,
              SUM(cached_input_tokens) AS cached_input_tokens,
              SUM(output_tokens) AS output_tokens,
              SUM(CASE WHEN local_cache_hit=0 THEN input_tokens ELSE 0 END) AS api_input_tokens,
              SUM(CASE WHEN local_cache_hit=0 THEN cached_input_tokens ELSE 0 END) AS api_cached_input_tokens,
              SUM(CASE WHEN local_cache_hit=0 THEN output_tokens ELSE 0 END) AS api_output_tokens
            FROM feedtools_openai_requests
        ";
        if ($whereSql !== '') $sql .= ' WHERE ' . $whereSql;

        $st = db()->prepare($sql);
        self::bindArgs($st, $args);
        $st->execute();
        $row = $st->fetch() ?: [];

        return [
            'requests' => (int)($row['requests'] ?? 0),
            'api_requests' => (int)($row['api_requests'] ?? 0),
            'local_hits' => (int)($row['local_hits'] ?? 0),
            'prompt_hits' => (int)($row['prompt_hits'] ?? 0),
            'input_tokens' => (int)($row['input_tokens'] ?? 0),
            'cached_input_tokens' => (int)($row['cached_input_tokens'] ?? 0),
            'output_tokens' => (int)($row['output_tokens'] ?? 0),
            'api_input_tokens' => (int)($row['api_input_tokens'] ?? 0),
            'api_cached_input_tokens' => (int)($row['api_cached_input_tokens'] ?? 0),
            'api_output_tokens' => (int)($row['api_output_tokens'] ?? 0),
        ];
    }

    public static function summarizeByModel(array $filters = []): array
    {
        self::ensureTable();

        [$whereSql, $args] = self::buildWhere($filters);

        $sql = "
            SELECT
              model,
              COUNT(*) AS requests,
              SUM(CASE WHEN local_cache_hit=0 THEN 1 ELSE 0 END) AS api_requests,
              SUM(CASE WHEN local_cache_hit=1 THEN 1 ELSE 0 END) AS local_hits,
              SUM(CASE WHEN local_cache_hit=0 THEN input_tokens ELSE 0 END) AS api_input_tokens,
              SUM(CASE WHEN local_cache_hit=0 THEN cached_input_tokens ELSE 0 END) AS api_cached_input_tokens,
              SUM(CASE WHEN local_cache_hit=0 THEN output_tokens ELSE 0 END) AS api_output_tokens
            FROM feedtools_openai_requests
        ";
        if ($whereSql !== '') $sql .= ' WHERE ' . $whereSql;
        $sql .= ' GROUP BY model ORDER BY model';

        $st = db()->prepare($sql);
        self::bindArgs($st, $args);
        $st->execute();

        $out = [];
        while ($row = $st->fetch()) {
            $out[] = [
                'model' => (string)($row['model'] ?? ''),
                'requests' => (int)($row['requests'] ?? 0),
                'api_requests' => (int)($row['api_requests'] ?? 0),
                'local_hits' => (int)($row['local_hits'] ?? 0),
                'api_input_tokens' => (int)($row['api_input_tokens'] ?? 0),
                'api_cached_input_tokens' => (int)($row['api_cached_input_tokens'] ?? 0),
                'api_output_tokens' => (int)($row['api_output_tokens'] ?? 0),
            ];
        }
        return $out;
    }

    private static function buildWhere(array $filters): array
    {
        $where = [];
        $args = [];

        $opIds = [];
        if (!empty($filters['op_ids']) && is_array($filters['op_ids'])) {
            foreach ($filters['op_ids'] as $opId) {
                $opId = (int)$opId;
                if ($opId > 0) $opIds[$opId] = true;
            }
        }

        if ($opIds) {
            $ids = array_map('intval', array_keys($opIds));
            $where[] = 'op_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $opId) {
                $args[] = $opId;
            }
        } elseif (!empty($filters['op_id'])) {
            $where[] = 'op_id = ?';
            $args[] = (int)$filters['op_id'];
        }

        if (!empty($filters['dataset_id'])) {
            $where[] = 'dataset_id = ?';
            $args[] = (int)$filters['dataset_id'];
        }

        if (!empty($filters['root_op_id'])) {
            $where[] = 'root_op_id = ?';
            $args[] = (int)$filters['root_op_id'];
        }

        $actorUser = trim((string)($filters['actor_user'] ?? ''));
        if ($actorUser !== '') {
            $where[] = 'actor_user = ?';
            $args[] = $actorUser;
        }

        return [implode(' AND ', $where), $args];
    }

    private static function bindArgs(PDOStatement $st, array $args): void
    {
        foreach (array_values($args) as $idx => $arg) {
            if (is_int($arg)) {
                $st->bindValue($idx + 1, $arg, PDO::PARAM_INT);
            } else {
                $st->bindValue($idx + 1, (string)$arg, PDO::PARAM_STR);
            }
        }
    }

    private static function jsonForStorage(mixed $value, int $limit): ?string
    {
        if ($value === null) return null;

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) return null;
        return self::limitText($json, $limit);
    }

    private static function limitText(string $text, int $limit): string
    {
        if ($limit > 0 && mb_strlen($text, 'UTF-8') > $limit) {
            return mb_substr($text, 0, $limit, 'UTF-8') . "\n...[truncated]";
        }
        return $text;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        return (int)$value;
    }

    private static function nullableString(mixed $value, int $limit): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        if (mb_strlen($value, 'UTF-8') > $limit) {
            $value = mb_substr($value, 0, $limit, 'UTF-8');
        }
        return $value;
    }
}
