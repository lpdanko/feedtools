<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';

final class OzonTaxonomy
{
  public const SOURCE = 'ozon';

  public static function defaultMeta(array $raw = []): array {
    return [
      'description' => '',
      'typical_goods' => '',
      'features' => '',
      'ozon_required_attributes' => [],
      'keywords' => [],
      'raw' => $raw,
    ];
  }

  private static function stripBom(string $s): string {
    return preg_replace('/^\xEF\xBB\xBF/', '', $s);
  }

  /**
   * Формат строки:
   *   "A > B > C (71107562_970976086)"
   * Возвращает null если строка пустая/не соответствует формату.
   */
  public static function parseLine(string $line): ?array
  {
    $line = trim(self::stripBom($line));
    if ($line === '') return null;

    // нормализуем пробелы вокруг >
    $line = preg_replace('~\s*>\s*~u', ' > ', $line);

    if (!preg_match('~\(([^()]+)\)\s*$~u', $line, $m)) {
      return null;
    }
    $pair = trim($m[1]);
    $pathOnly = trim(preg_replace('~\(([^()]+)\)\s*$~u', '', $line));

    $parts = array_values(array_filter(array_map('trim', explode(' > ', $pathOnly)), fn($x) => $x !== ''));
    if (!$parts) return null;

    $ozonParentId = null;
    $ozonLeafId = null;
    if (preg_match('~^(\d+)_([0-9]+)$~', $pair, $mm)) {
      $ozonParentId = (int)$mm[1];
      $ozonLeafId   = (int)$mm[2];
    }

    return [
      'parts' => $parts,
      'full_path' => implode(' > ', $parts),
      'ozon_parent_id' => $ozonParentId,
      'ozon_leaf_id' => $ozonLeafId,
      'raw_pair' => $pair,
      'raw_path' => $pathOnly,
    ];
  }

  private static function pathHash(string $fullPath): string {
    return hash('sha256', $fullPath);
  }

  private static function externalIdFromHash(string $hash): string {
    return self::SOURCE . ':' . $hash;
  }

  private static function externalIdFromPath(string $fullPath): string {
    return self::externalIdFromHash(self::pathHash($fullPath));
  }

  private static function externalIdFromOzonCode(int $descriptionCategoryId, int $typeId): string
  {
    return self::SOURCE . ':' . $descriptionCategoryId . '_' . $typeId;
  }

  private static function decodeMeta($metaJson): array
  {
    if (!is_string($metaJson) || trim($metaJson) === '') {
      return [];
    }
    $meta = json_decode($metaJson, true);
    return is_array($meta) ? $meta : [];
  }

  private static function hasFilledValue($value): bool
  {
    if (is_array($value)) {
      return count(array_filter($value, static fn($item): bool => self::hasFilledValue($item))) > 0;
    }
    if (is_bool($value)) {
      return $value;
    }
    if (is_int($value) || is_float($value)) {
      return $value !== 0;
    }
    return trim((string)$value) !== '';
  }

  private static function metaScore($metaJson): int
  {
    $meta = self::decodeMeta($metaJson);
    if (!$meta) {
      return 0;
    }

    $score = 1;
    $attrsMeta = $meta['ozon_required_attributes_meta'] ?? [];
    if (is_array($attrsMeta) && $attrsMeta) {
      $score += 1000 + count($attrsMeta) * 10;
    }
    $attrs = $meta['ozon_required_attributes'] ?? [];
    if (is_array($attrs) && $attrs) {
      $score += 500 + count($attrs);
    }
    if ((int)($meta['ozon_description_category_id'] ?? 0) > 0) {
      $score += 30;
    }
    if ((int)($meta['ozon_type_id'] ?? 0) > 0) {
      $score += 30;
    }
    foreach (['description', 'typical_goods', 'features'] as $key) {
      $value = trim((string)($meta[$key] ?? ''));
      if ($value !== '') {
        $score += 50 + min(200, mb_strlen($value, 'UTF-8'));
      }
    }
    $keywords = $meta['keywords'] ?? [];
    if (is_array($keywords) && $keywords) {
      $score += 30 + count($keywords);
    }

    return $score;
  }

  private static function normalizeMetaKey(string $value): string
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('~\s+~u', ' ', $value);
    return $value ?? '';
  }

  private static function mergeListValues(array $primary, array $secondary): array
  {
    $out = [];
    $seen = [];
    foreach ([$primary, $secondary] as $list) {
      foreach ($list as $item) {
        if (is_array($item)) {
          $keySource = (string)($item['id'] ?? ($item['attribute_id'] ?? ($item['name'] ?? json_encode($item, JSON_UNESCAPED_UNICODE))));
          $key = self::normalizeMetaKey($keySource);
        } else {
          $item = trim((string)$item);
          $key = self::normalizeMetaKey($item);
        }
        if ($key === '' || isset($seen[$key])) {
          continue;
        }
        $seen[$key] = true;
        $out[] = $item;
      }
    }
    return $out;
  }

  private static function mergeAssocMetaValues(array $primary, array $secondary): array
  {
    $out = [];
    foreach ([$primary, $secondary] as $map) {
      foreach ($map as $key => $value) {
        $dedupeKey = '';
        if (is_array($value)) {
          $dedupeKey = (string)($value['id'] ?? ($value['attribute_id'] ?? ($value['name'] ?? '')));
        }
        if ($dedupeKey === '') {
          $dedupeKey = is_string($key) ? $key : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $dedupeKey = self::normalizeMetaKey((string)$dedupeKey);
        if ($dedupeKey === '' || isset($out[$dedupeKey])) {
          continue;
        }
        $out[$dedupeKey] = $value;
      }
    }
    return $out;
  }

  private static function mergeMetaJson($winnerJson, $loserJson): string
  {
    $winner = self::decodeMeta($winnerJson);
    $loser = self::decodeMeta($loserJson);
    if (!$winner && !$loser) {
      return json_encode(self::defaultMeta(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!$winner) {
      $winner = [];
    }

    foreach (['description', 'typical_goods', 'features'] as $key) {
      if (trim((string)($winner[$key] ?? '')) === '' && trim((string)($loser[$key] ?? '')) !== '') {
        $winner[$key] = trim((string)$loser[$key]);
      }
    }

    foreach (['ozon_description_category_id', 'ozon_type_id'] as $key) {
      if ((int)($winner[$key] ?? 0) <= 0 && (int)($loser[$key] ?? 0) > 0) {
        $winner[$key] = (int)$loser[$key];
      }
    }

    foreach (['keywords', 'ozon_required_attributes'] as $key) {
      $winnerList = is_array($winner[$key] ?? null) ? $winner[$key] : [];
      $loserList = is_array($loser[$key] ?? null) ? $loser[$key] : [];
      if ($winnerList || $loserList) {
        $winner[$key] = self::mergeListValues($winnerList, $loserList);
      }
    }

    $winnerAttrs = is_array($winner['ozon_required_attributes_meta'] ?? null) ? $winner['ozon_required_attributes_meta'] : [];
    $loserAttrs = is_array($loser['ozon_required_attributes_meta'] ?? null) ? $loser['ozon_required_attributes_meta'] : [];
    if ($winnerAttrs || $loserAttrs) {
      $winner['ozon_required_attributes_meta'] = self::mergeAssocMetaValues($winnerAttrs, $loserAttrs);
    }

    if (!isset($winner['raw']) && isset($loser['raw'])) {
      $winner['raw'] = $loser['raw'];
    } elseif (is_array($winner['raw'] ?? null) && is_array($loser['raw'] ?? null)) {
      $winner['raw'] = array_replace_recursive($loser['raw'], $winner['raw']);
    }

    foreach ($loser as $key => $value) {
      if (!array_key_exists($key, $winner) && self::hasFilledValue($value)) {
        $winner[$key] = $value;
      }
    }

    return json_encode($winner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  public static function cleanupDuplicateOzonLeaves(PDO $pdo): array
  {
    $groupsStmt = $pdo->query("
      SELECT ozon_parent_id, ozon_leaf_id, COUNT(*) AS c
      FROM feedtools_taxonomy_categories
      WHERE source = 'ozon'
        AND is_leaf = 1
        AND ozon_parent_id > 0
        AND ozon_leaf_id > 0
      GROUP BY ozon_parent_id, ozon_leaf_id
      HAVING c > 1
    ");
    $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$groups) {
      return ['groups' => 0, 'deleted' => 0, 'updated' => 0];
    }

    $select = $pdo->prepare("
      SELECT *
      FROM feedtools_taxonomy_categories
      WHERE source = 'ozon'
        AND is_leaf = 1
        AND ozon_parent_id = ?
        AND ozon_leaf_id = ?
      ORDER BY id ASC
    ");
    $update = $pdo->prepare("
      UPDATE feedtools_taxonomy_categories
      SET external_id = ?,
          meta_json = ?,
          updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $delete = $pdo->prepare("DELETE FROM feedtools_taxonomy_categories WHERE id = ? LIMIT 1");

    $deleted = 0;
    $updated = 0;
    $startedTx = !$pdo->inTransaction();
    if ($startedTx) {
      $pdo->beginTransaction();
    }

    try {
      foreach ($groups as $group) {
        $parentId = (int)$group['ozon_parent_id'];
        $leafId = (int)$group['ozon_leaf_id'];
        $select->execute([$parentId, $leafId]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < 2) {
          continue;
        }

        usort($rows, static function (array $a, array $b): int {
          $scoreCmp = self::metaScore($b['meta_json'] ?? null) <=> self::metaScore($a['meta_json'] ?? null);
          if ($scoreCmp !== 0) {
            return $scoreCmp;
          }
          return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        $winner = array_shift($rows);
        $winnerId = (int)$winner['id'];
        $mergedMeta = (string)($winner['meta_json'] ?? '');
        foreach ($rows as $loser) {
          $mergedMeta = self::mergeMetaJson($mergedMeta, $loser['meta_json'] ?? null);
        }

        $update->execute([
          self::externalIdFromOzonCode($parentId, $leafId),
          $mergedMeta,
          $winnerId,
        ]);
        $updated++;

        foreach ($rows as $loser) {
          $delete->execute([(int)$loser['id']]);
          $deleted++;
        }
      }

      if ($startedTx) {
        $pdo->commit();
      }
    } catch (Throwable $e) {
      if ($startedTx && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }

    return ['groups' => count($groups), 'deleted' => $deleted, 'updated' => $updated];
  }

  public static function ensureOzonLeafUniqueIndex(PDO $pdo): bool
  {
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'feedtools_taxonomy_categories'
        AND INDEX_NAME = ?
    ");
    $stmt->execute(['uq_ozon_leaf_code']);
    if ((int)$stmt->fetchColumn() > 0) {
      return false;
    }

    self::cleanupDuplicateOzonLeaves($pdo);
    $pdo->exec("
      ALTER TABLE feedtools_taxonomy_categories
      ADD UNIQUE KEY uq_ozon_leaf_code (source, is_leaf, ozon_parent_id, ozon_leaf_id)
    ");
    return true;
  }

  private static function updateLeafByOzonCode(PDO $pdo, array $row): bool
  {
    $descriptionCategoryId = (int)($row['ozon_parent_id'] ?? 0);
    $typeId = (int)($row['ozon_leaf_id'] ?? 0);
    if ($descriptionCategoryId <= 0 || $typeId <= 0) {
      return false;
    }

    $find = $pdo->prepare("
      SELECT id
      FROM feedtools_taxonomy_categories
      WHERE source = ?
        AND is_leaf = 1
        AND ozon_parent_id = ?
        AND ozon_leaf_id = ?
      ORDER BY id ASC
      LIMIT 1
    ");
    $find->execute([self::SOURCE, $descriptionCategoryId, $typeId]);
    $id = (int)($find->fetchColumn() ?: 0);
    if ($id <= 0) {
      return false;
    }

    $hash = (string)$row['full_path_hash'];
    $dupe = $pdo->prepare("
      SELECT id
      FROM feedtools_taxonomy_categories
      WHERE source = ?
        AND full_path_hash = ?
        AND id <> ?
      LIMIT 1
    ");
    $dupe->execute([self::SOURCE, $hash, $id]);
    $hashConflict = (int)($dupe->fetchColumn() ?: 0) > 0;

    $sets = [
      'external_id = :external_id',
      'parent_external_id = :parent_external_id',
      'level = :level',
      'name = :name',
      'full_path = :full_path',
      'is_leaf = 1',
      'ozon_parent_id = :ozon_parent_id',
      'ozon_leaf_id = :ozon_leaf_id',
      'updated_at = NOW()',
    ];
    if (!$hashConflict) {
      array_unshift($sets, 'full_path_hash = :full_path_hash');
    }

    $update = $pdo->prepare("
      UPDATE feedtools_taxonomy_categories
      SET " . implode(",\n          ", $sets) . "
      WHERE id = :id
      LIMIT 1
    ");
    $params = [
      ':external_id' => (string)$row['external_id'],
      ':parent_external_id' => $row['parent_external_id'],
      ':level' => (int)$row['level'],
      ':name' => (string)$row['name'],
      ':full_path' => (string)$row['full_path'],
      ':ozon_parent_id' => $descriptionCategoryId,
      ':ozon_leaf_id' => $typeId,
      ':id' => $id,
    ];
    if (!$hashConflict) {
      $params[':full_path_hash'] = $hash;
    }
    $update->execute($params);
    return true;
  }

  /** Быстро считаем строки (для progress_total) */
  public static function countLines(string $filePath): int
  {
    $fh = fopen($filePath, 'rb');
    if (!$fh) throw new RuntimeException("Cannot open file: {$filePath}");
    $n = 0;
    while (!feof($fh)) {
      $line = fgets($fh);
      if ($line === false) break;
      $n++;
    }
    fclose($fh);
    return $n;
  }

  /**
   * Импорт из файла с прогрессом (для worker/операции).
   * replace=true: сначала удаляет старые категории source='ozon'.
   */
  public static function importFromFileWithProgress(
    string $filePath,
    int $opId,
    callable $log,
    bool $replace = false,
    int $commitEvery = 800
  ): array
  {
    if (!is_file($filePath)) throw new RuntimeException("File not found: {$filePath}");

    $totalLines = self::countLines($filePath);
    ops_update_progress($opId, 0, max(1, $totalLines), 'scan', "Lines: {$totalLines}");

    $pdo = db();

    if ($replace) {
      $log("Replace mode: deleting old categories...\n");
      ops_update_progress($opId, 0, max(1, $totalLines), 'cleanup', "Deleting old categories (source=" . self::SOURCE . ")");
      $pdo->prepare("DELETE FROM feedtools_taxonomy_categories WHERE source = ?")->execute([self::SOURCE]);
    }

    $sql = "
      INSERT INTO feedtools_taxonomy_categories
        (source, full_path_hash, external_id, parent_external_id, level, name, full_path, is_leaf, ozon_parent_id, ozon_leaf_id, meta_json)
      VALUES
        (:source, :full_path_hash, :external_id, :parent_external_id, :level, :name, :full_path, :is_leaf, :ozon_parent_id, :ozon_leaf_id, :meta_json)
      ON DUPLICATE KEY UPDATE
        parent_external_id = VALUES(parent_external_id),
        level = VALUES(level),
        name  = VALUES(name),
        full_path = VALUES(full_path),
        is_leaf = GREATEST(feedtools_taxonomy_categories.is_leaf, VALUES(is_leaf)),
        ozon_parent_id = COALESCE(feedtools_taxonomy_categories.ozon_parent_id, VALUES(ozon_parent_id)),
        ozon_leaf_id   = COALESCE(feedtools_taxonomy_categories.ozon_leaf_id, VALUES(ozon_leaf_id)),
        meta_json = COALESCE(feedtools_taxonomy_categories.meta_json, VALUES(meta_json)),
        updated_at = NOW()
    ";
    $stmt = $pdo->prepare($sql);

    $fh = fopen($filePath, 'rb');
    if (!$fh) throw new RuntimeException("Cannot open file: {$filePath}");

    $t0 = microtime(true);

    $lineNo = 0;
    $leafCount = 0;
    $nodeUpserts = 0;
    $invalidLines = 0;
    $batch = 0;

    $pdo->beginTransaction();

    while (($line = fgets($fh)) !== false) {
      $lineNo++;

      $parsed = self::parseLine($line);
      if (!$parsed) {
        $invalidLines++;
        // прогресс по строкам всё равно двигаем
        if (($lineNo % 200) === 0) {
          ops_update_progress($opId, min($lineNo, $totalLines), max(1, $totalLines), 'parse', "Line {$lineNo}/{$totalLines} (invalid={$invalidLines})");
        }
        continue;
      }

      $leafParts = $parsed['parts'];
      $leafDepth = count($leafParts);
      $leafCount++;

      // для каждого префикса создаём узел
      for ($lvl = 1; $lvl <= $leafDepth; $lvl++) {
        $prefixParts = array_slice($leafParts, 0, $lvl);
        $fullPath = implode(' > ', $prefixParts);
        $name = $prefixParts[$lvl - 1];
        $isLeafNode = ($lvl === $leafDepth) ? 1 : 0;

        $hash = self::pathHash($fullPath);
        $externalId = ($isLeafNode && (int)($parsed['ozon_parent_id'] ?? 0) > 0 && (int)($parsed['ozon_leaf_id'] ?? 0) > 0)
          ? self::externalIdFromOzonCode((int)$parsed['ozon_parent_id'], (int)$parsed['ozon_leaf_id'])
          : self::externalIdFromHash($hash);

        $parentExternal = null;
        if ($lvl > 1) {
          $parentPath = implode(' > ', array_slice($prefixParts, 0, -1));
          $parentExternal = self::externalIdFromPath($parentPath);
        }

        $meta = self::defaultMeta([
          'line' => $parsed['raw_path'],
          'pair' => $parsed['raw_pair'],
        ]);

        $rowForSql = [
          ':source' => self::SOURCE,
          ':full_path_hash' => $hash,
          ':external_id' => $externalId,
          ':parent_external_id' => $parentExternal,
          ':level' => $lvl,
          ':name' => $name,
          ':full_path' => $fullPath,
          ':is_leaf' => $isLeafNode,
          ':ozon_parent_id' => $isLeafNode ? $parsed['ozon_parent_id'] : null,
          ':ozon_leaf_id' => $isLeafNode ? $parsed['ozon_leaf_id'] : null,
          ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ];

        if ($isLeafNode && self::updateLeafByOzonCode($pdo, [
          'full_path_hash' => $hash,
          'external_id' => $externalId,
          'parent_external_id' => $parentExternal,
          'level' => $lvl,
          'name' => $name,
          'full_path' => $fullPath,
          'ozon_parent_id' => $parsed['ozon_parent_id'],
          'ozon_leaf_id' => $parsed['ozon_leaf_id'],
        ])) {
          $nodeUpserts++;
          $batch++;
        } else {
          $stmt->execute($rowForSql);
          $nodeUpserts++;
          $batch++;
        }

        if ($batch >= $commitEvery) {
          $pdo->commit();
          $pdo->beginTransaction();
          $batch = 0;

          // обновляем прогресс по строкам (не по узлам)
          ops_update_progress(
            $opId,
            min($lineNo, $totalLines),
            max(1, $totalLines),
            'import',
            "Lines {$lineNo}/{$totalLines}; leaves={$leafCount}; upserts={$nodeUpserts}"
          );
        }
      }

      if (($lineNo % 200) === 0) {
        ops_update_progress(
          $opId,
          min($lineNo, $totalLines),
          max(1, $totalLines),
          'import',
          "Lines {$lineNo}/{$totalLines}; leaves={$leafCount}; upserts={$nodeUpserts}"
        );
      }
    }

    fclose($fh);
    $pdo->commit();

    $elapsed = microtime(true) - $t0;
    $dedupe = self::cleanupDuplicateOzonLeaves($pdo);
    $indexAdded = self::ensureOzonLeafUniqueIndex($pdo);

    ops_update_progress($opId, $totalLines, max(1, $totalLines), 'done', 'Done');

    $log("Import finished.\n");
    $log("Lines: {$totalLines}\n");
    $log("Leaves parsed: {$leafCount}\n");
    $log("Upserts: {$nodeUpserts}\n");
    $log("Invalid lines: {$invalidLines}\n");
    if ((int)($dedupe['deleted'] ?? 0) > 0) {
      $log("Duplicate Ozon leaves removed: " . (int)$dedupe['deleted'] . "\n");
    }
    if ($indexAdded) {
      $log("Unique Ozon leaf index added.\n");
    }
    $log("Elapsed: " . round($elapsed, 2) . " sec\n");

    return [
      'file' => $filePath,
      'lines_total' => $totalLines,
      'leaves_parsed' => $leafCount,
      'nodes_upserts' => $nodeUpserts,
      'invalid_lines' => $invalidLines,
      'duplicates_deleted' => (int)($dedupe['deleted'] ?? 0),
      'unique_index_added' => $indexAdded ? 1 : 0,
      'elapsed_sec' => round($elapsed, 2),
      'replace' => $replace ? 1 : 0,
    ];
  }
}
