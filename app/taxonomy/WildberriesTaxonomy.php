<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ops.php';
require_once __DIR__ . '/../wildberries/WildberriesClient.php';

final class WildberriesTaxonomy
{
  public const SOURCE = 'wildberries';
  private const COMMIT_EVERY = 1000;

  private static function progress(int $opId, int $done, int $total, string $stage, string $msg): void
  {
    if ($opId <= 0) return;
    ops_update_progress($opId, $done, max(1, $total), $stage, $msg);
  }

  public static function defaultMeta(array $raw = []): array
  {
    return [
      'description' => '',
      'typical_goods' => '',
      'features' => '',
      'wb_required_attributes' => [],
      'wb_characteristics_meta' => [],
      'keywords' => [],
      'raw' => $raw,
    ];
  }

  private static function pathHash(string $fullPath): string
  {
    return hash('sha256', $fullPath);
  }

  private static function upsertNode(PDO $pdo, array $row): void
  {
    $existingId = self::findExistingNodeId($pdo, (string)$row['external_id']);
    if ($existingId > 0) {
      self::updateExistingNode($pdo, $existingId, $row);
      return;
    }

    static $stmt = null;
    if (!$stmt) {
      $stmt = $pdo->prepare("
        INSERT INTO feedtools_taxonomy_categories
          (source, full_path_hash, external_id, parent_external_id, level, name, full_path, is_leaf, meta_json)
        VALUES
          (:source, :full_path_hash, :external_id, :parent_external_id, :level, :name, :full_path, :is_leaf, :meta_json)
        ON DUPLICATE KEY UPDATE
          parent_external_id = VALUES(parent_external_id),
          level = VALUES(level),
          name = VALUES(name),
          full_path = VALUES(full_path),
          is_leaf = GREATEST(feedtools_taxonomy_categories.is_leaf, VALUES(is_leaf)),
          meta_json = COALESCE(feedtools_taxonomy_categories.meta_json, VALUES(meta_json)),
          updated_at = NOW()
      ");
    }

    $stmt->execute([
      ':source' => self::SOURCE,
      ':full_path_hash' => self::pathHash((string)$row['full_path']),
      ':external_id' => (string)$row['external_id'],
      ':parent_external_id' => $row['parent_external_id'],
      ':level' => (int)$row['level'],
      ':name' => (string)$row['name'],
      ':full_path' => (string)$row['full_path'],
      ':is_leaf' => (int)$row['is_leaf'],
      ':meta_json' => json_encode($row['meta_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
  }

  private static function findExistingNodeId(PDO $pdo, string $externalId): int
  {
    $externalId = trim($externalId);
    if ($externalId === '') {
      return 0;
    }
    $st = $pdo->prepare("
      SELECT id
      FROM feedtools_taxonomy_categories
      WHERE source = ?
        AND external_id = ?
      LIMIT 1
    ");
    $st->execute([self::SOURCE, $externalId]);
    return (int)($st->fetchColumn() ?: 0);
  }

  private static function updateExistingNode(PDO $pdo, int $id, array $row): void
  {
    $hash = self::pathHash((string)$row['full_path']);
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
      'parent_external_id = :parent_external_id',
      'level = :level',
      'name = :name',
      'full_path = :full_path',
      'is_leaf = GREATEST(is_leaf, :is_leaf)',
      'updated_at = NOW()',
    ];
    if (!$hashConflict) {
      array_unshift($sets, 'full_path_hash = :full_path_hash');
    }

    $st = $pdo->prepare("
      UPDATE feedtools_taxonomy_categories
      SET " . implode(",\n          ", $sets) . "
      WHERE id = :id
      LIMIT 1
    ");
    $params = [
      ':parent_external_id' => $row['parent_external_id'],
      ':level' => (int)$row['level'],
      ':name' => (string)$row['name'],
      ':full_path' => (string)$row['full_path'],
      ':is_leaf' => (int)$row['is_leaf'],
      ':id' => $id,
    ];
    if (!$hashConflict) {
      $params[':full_path_hash'] = $hash;
    }
    $st->execute($params);
  }

  private static function buildParentNode(array $parent): array
  {
    $parentId = (int)($parent['id'] ?? 0);
    $name = trim((string)($parent['name'] ?? ''));
    if ($parentId <= 0 || $name === '') {
      throw new InvalidArgumentException('Invalid WB parent category payload');
    }

    return [
      'external_id' => 'wb:parent:' . $parentId,
      'parent_external_id' => null,
      'level' => 1,
      'name' => $name,
      'full_path' => $name,
      'is_leaf' => 0,
      'meta_json' => self::defaultMeta([
        'wb_parent_id' => $parentId,
        'is_visible' => (bool)($parent['isVisible'] ?? true),
        'node_type' => 'parent',
      ]),
    ];
  }

  private static function buildSubjectNode(array $subject): array
  {
    $subjectId = (int)($subject['subjectID'] ?? 0);
    $parentId = (int)($subject['parentID'] ?? 0);
    $subjectName = trim((string)($subject['subjectName'] ?? ''));
    $parentName = trim((string)($subject['parentName'] ?? ''));
    if ($subjectId <= 0 || $parentId <= 0 || $subjectName === '' || $parentName === '') {
      throw new InvalidArgumentException('Invalid WB subject payload');
    }

    return [
      'external_id' => 'wb:subject:' . $subjectId,
      'parent_external_id' => 'wb:parent:' . $parentId,
      'level' => 2,
      'name' => $subjectName,
      'full_path' => $parentName . ' > ' . $subjectName,
      'is_leaf' => 1,
      'meta_json' => self::defaultMeta([
        'wb_parent_id' => $parentId,
        'wb_category' => $subjectId,
        'wb_subject_id' => $subjectId,
        'wb_parent_name' => $parentName,
        'node_type' => 'subject',
      ]),
    ];
  }

  public static function fetchAllParents(WildberriesClient $client): array
  {
    $resp = $client->getParentCategories();
    $items = $resp['data'] ?? [];
    return is_array($items) ? $items : [];
  }

  public static function fetchAllSubjects(WildberriesClient $client, int $limit = 1000, int $maxPages = 0): array
  {
    $all = [];
    $offset = 0;
    $seenKeys = [];
    $page = 0;

    while (true) {
      $page++;
      if ($maxPages > 0 && $page > $maxPages) {
        break;
      }
      $resp = $client->getSubjects([
        'limit' => $limit,
        'offset' => $offset,
      ]);

      $items = $resp['data'] ?? [];
      if (!is_array($items) || !$items) {
        break;
      }

      $added = 0;
      foreach ($items as $item) {
        if (is_array($item)) {
          $subjectId = (int)($item['subjectID'] ?? 0);
          $parentId = (int)($item['parentID'] ?? 0);
          $key = $subjectId . ':' . $parentId;
          if (isset($seenKeys[$key])) {
            continue;
          }
          $seenKeys[$key] = true;
          $all[] = $item;
          $added++;
        }
      }

      if ($added === 0 || count($items) < $limit) {
        break;
      }

      $offset += $limit;
    }

    return $all;
  }

  public static function exportSubjectsFile(array $parents, array $subjects, ?string $path = null): ?string
  {
    $path ??= __DIR__ . '/../../storage/taxonomies/wildberries/categories_wb.txt';
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('Cannot create WB taxonomy export dir: ' . $dir);
    }

    $lines = [];
    foreach ($subjects as $subject) {
      if (!is_array($subject)) continue;
      $subjectId = (int)($subject['subjectID'] ?? 0);
      $subjectName = trim((string)($subject['subjectName'] ?? ''));
      $parentName = trim((string)($subject['parentName'] ?? ''));
      if ($subjectId <= 0 || $subjectName === '' || $parentName === '') continue;
      $lines[] = $parentName . ' > ' . $subjectName . ' (' . $subjectId . ')';
    }

    natcasesort($lines);
    $payload = implode("\n", $lines);
    if ($payload !== '') $payload .= "\n";

    if (file_put_contents($path, $payload) === false) {
      throw new RuntimeException('Cannot write WB taxonomy export file: ' . $path);
    }

    return $path;
  }

  public static function importFromApiWithProgress(
    array $wbConfig,
    int $opId,
    callable $log,
    bool $replace = false
  ): array {
    $client = new WildberriesClient($wbConfig);
    $pdo = db();

    self::progress($opId, 0, 100, 'fetch', 'Fetching Wildberries parent categories...');
    $parents = self::fetchAllParents($client);

    self::progress($opId, 5, 100, 'fetch', 'Fetching Wildberries subjects...');
    $subjects = self::fetchAllSubjects($client, 1000, 0);

    $total = count($parents) + count($subjects);
    if ($total === 0) {
      throw new RuntimeException('Wildberries taxonomy import: no categories returned');
    }

    $exportPath = self::exportSubjectsFile($parents, $subjects);

    if ($replace) {
      $log("Replace mode: deleting old Wildberries taxonomy...\n");
      $pdo->prepare("DELETE FROM feedtools_taxonomy_categories WHERE source = ?")->execute([self::SOURCE]);
    }

    $done = 0;
    $upserts = 0;
    $parentCount = 0;
    $subjectCount = 0;
    $pdo->beginTransaction();

    foreach ($parents as $parent) {
      self::upsertNode($pdo, self::buildParentNode($parent));
      $done++;
      $upserts++;
      $parentCount++;

      if (($done % self::COMMIT_EVERY) === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        self::progress($opId, $done, $total, 'import', "Imported {$done}/{$total} WB taxonomy nodes");
      }
    }

    foreach ($subjects as $subject) {
      self::upsertNode($pdo, self::buildSubjectNode($subject));
      $done++;
      $upserts++;
      $subjectCount++;

      if (($done % self::COMMIT_EVERY) === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        self::progress($opId, $done, $total, 'import', "Imported {$done}/{$total} WB taxonomy nodes");
      }
    }

    $pdo->commit();
    self::progress($opId, $total, $total, 'done', 'Done');

    $summary = [
      'source' => self::SOURCE,
      'parents' => $parentCount,
      'subjects' => $subjectCount,
      'upserts' => $upserts,
    ];

    $log("Wildberries taxonomy import finished.\n");
    $log("Parents: {$parentCount}\n");
    $log("Subjects: {$subjectCount}\n");
    $log("Upserts: {$upserts}\n");
    if ($exportPath) {
      $log("Export file: {$exportPath}\n");
    }

    return $summary;
  }
}
