<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function ft_load_ozon_taxonomy_options(): array
{
  $options = [];
  $seen = [];

  $stmt = db()->prepare("
    SELECT full_path, ozon_parent_id, ozon_leaf_id
    FROM feedtools_taxonomy_categories
    WHERE source = ? AND is_leaf = 1
    ORDER BY full_path ASC
  ");
  $stmt->execute(['ozon']);

  while ($r = $stmt->fetch()) {
    $pair = '';
    if (!empty($r['ozon_parent_id']) && !empty($r['ozon_leaf_id'])) {
      $pair = (string)$r['ozon_parent_id'] . '_' . (string)$r['ozon_leaf_id'];
    }
    $dedupeKey = $pair !== '' ? ('leaf:' . $pair) : ('path:' . (string)($r['full_path'] ?? ''));
    if ($dedupeKey === 'path:' || isset($seen[$dedupeKey])) {
      continue;
    }
    $seen[$dedupeKey] = true;

    $label = (string)($r['full_path'] ?? '');
    if ($pair !== '') $label .= " ({$pair})";

    $options[] = [
      'value' => ($pair !== '' ? $pair : $label),
      'label' => $label,
    ];
  }

  return $options;
}

function ft_search_ozon_taxonomy_options(string $query = '', int $limit = 50): array
{
  $limit = max(1, min(200, $limit));
  $query = trim($query);

  $sql = "
    SELECT full_path, ozon_parent_id, ozon_leaf_id
    FROM feedtools_taxonomy_categories
    WHERE source = ? AND is_leaf = 1
  ";
  $args = ['ozon'];

  if ($query !== '') {
    $like = '%' . $query . '%';
    $sql .= " AND (
      full_path LIKE ?
      OR CONCAT(COALESCE(ozon_parent_id, ''), '_', COALESCE(ozon_leaf_id, '')) LIKE ?
    )";
    $args[] = $like;
    $args[] = $like;
  }

  $sql .= " ORDER BY full_path ASC LIMIT " . (int)($limit * 8);

  $stmt = db()->prepare($sql);
  $stmt->execute($args);

  $options = [];
  $seen = [];
  while ($r = $stmt->fetch()) {
    $pair = '';
    if (!empty($r['ozon_parent_id']) && !empty($r['ozon_leaf_id'])) {
      $pair = (string)$r['ozon_parent_id'] . '_' . (string)$r['ozon_leaf_id'];
    }
    $dedupeKey = $pair !== '' ? ('leaf:' . $pair) : ('path:' . (string)($r['full_path'] ?? ''));
    if ($dedupeKey === 'path:' || isset($seen[$dedupeKey])) {
      continue;
    }
    $seen[$dedupeKey] = true;

    $label = (string)($r['full_path'] ?? '');
    if ($pair !== '') $label .= " ({$pair})";

    $options[] = [
      'value' => ($pair !== '' ? $pair : $label),
      'label' => $label,
    ];
    if (count($options) >= $limit) break;
  }

  return $options;
}

function ft_ozon_taxonomy_children_count(string $fullPath): int
{
  $fullPath = trim($fullPath);
  if ($fullPath === '') return 0;
  $st = db()->prepare("
    SELECT COUNT(DISTINCT CONCAT(ozon_parent_id, '_', ozon_leaf_id))
    FROM feedtools_taxonomy_categories
    WHERE source = 'ozon'
      AND is_leaf = 1
      AND ozon_parent_id > 0
      AND ozon_leaf_id > 0
      AND full_path LIKE ?
  ");
  $st->execute([$fullPath . ' > %']);
  return (int)$st->fetchColumn();
}

function ft_taxonomy_path_depth(string $fullPath): int
{
  $parts = array_values(array_filter(array_map('trim', explode('>', $fullPath)), static fn(string $part): bool => $part !== ''));
  return count($parts);
}

function ft_search_ozon_taxonomy_tree_options(string $query = '', int $limit = 80, int $maxDepth = 0, bool $includeLeaves = true): array
{
  $limit = max(1, min(1000, $limit));
  $query = trim($query);
  $maxDepth = max(0, min(20, $maxDepth));

  $sql = "
    SELECT id, level, name, full_path, is_leaf, ozon_parent_id, ozon_leaf_id
    FROM feedtools_taxonomy_categories
    WHERE source = 'ozon'
  ";
  $args = [];

  if ($query === '') {
    $sql .= " AND is_leaf = 0";
  } elseif (!$includeLeaves) {
    $sql .= " AND is_leaf = 0";
  }

  if ($maxDepth === 1) {
    $sql .= " AND full_path NOT LIKE '% > %'";
  } elseif ($maxDepth === 2) {
    $sql .= " AND full_path NOT LIKE '% > % > %'";
  }

  if ($query !== '') {
    $like = '%' . $query . '%';
    $sql .= " AND (
      name LIKE ?
      OR full_path LIKE ?
      OR CONCAT(COALESCE(ozon_parent_id, ''), '_', COALESCE(ozon_leaf_id, '')) LIKE ?
    )";
    $args[] = $like;
    $args[] = $like;
    $args[] = $like;
  }

  $sql .= " ORDER BY is_leaf ASC, full_path ASC LIMIT " . (int)($limit * 8);
  $stmt = db()->prepare($sql);
  $stmt->execute($args);

  $options = [];
  $seen = [];
  while ($r = $stmt->fetch()) {
    $isLeaf = (int)($r['is_leaf'] ?? 0) === 1;
    if ($isLeaf && !$includeLeaves) continue;
    $fullPath = (string)($r['full_path'] ?? '');
    if ($maxDepth > 0 && ft_taxonomy_path_depth($fullPath) > $maxDepth) continue;
    $nodeId = (int)($r['id'] ?? 0);
    $pair = '';
    if (!empty($r['ozon_parent_id']) && !empty($r['ozon_leaf_id'])) {
      $pair = (string)$r['ozon_parent_id'] . '_' . (string)$r['ozon_leaf_id'];
    }
    if ($isLeaf && $pair === '') continue;

    $dedupeKey = $isLeaf ? ('leaf:' . $pair) : ('parent:' . $fullPath);
    if ($dedupeKey === 'parent:' || isset($seen[$dedupeKey])) continue;
    $seen[$dedupeKey] = true;

    $childrenCount = $isLeaf ? 0 : ft_ozon_taxonomy_children_count($fullPath);
    if (!$isLeaf && $childrenCount <= 0) continue;
    $label = $fullPath !== '' ? $fullPath : (string)($r['name'] ?? '');
    if ($isLeaf && $pair !== '') $label .= " ({$pair})";

    $options[] = [
      'value' => $isLeaf ? ('leaf:' . $pair) : ('node:' . $nodeId),
      'category_value' => $isLeaf ? $pair : '',
      'label' => $label,
      'kind' => $isLeaf ? 'leaf' : 'parent',
      'node_id' => $nodeId,
      'children_count' => $childrenCount,
      'full_path' => $fullPath,
    ];
    if (count($options) >= $limit) break;
  }

  return $options;
}

function ft_load_wb_taxonomy_options(): array
{
  $options = [];

  $stmt = db()->prepare("
    SELECT full_path, meta_json
    FROM feedtools_taxonomy_categories
    WHERE source = ? AND is_leaf = 1
    ORDER BY full_path ASC
  ");
  $stmt->execute(['wildberries']);

  while ($r = $stmt->fetch()) {
    $meta = json_decode((string)($r['meta_json'] ?? ''), true) ?: [];
    $subjectId = (string)($meta['raw']['wb_category'] ?? ($meta['raw']['wb_subject_id'] ?? ''));
    if ($subjectId === '') continue;

    $label = (string)($r['full_path'] ?? '');
    if ($label === '') $label = $subjectId;
    $label .= " ({$subjectId})";

    $options[] = [
      'value' => $subjectId,
      'label' => $label,
    ];
  }

  return $options;
}

function ft_search_wb_taxonomy_tree_options(string $query = '', int $limit = 80, int $maxDepth = 0, bool $includeLeaves = true, int $minDepth = 0): array
{
  $limit = max(1, min(1000, $limit));
  $query = trim($query);
  $maxDepth = max(0, min(20, $maxDepth));
  $minDepth = max(0, min(20, $minDepth));

  $sql = "
    SELECT id, external_id, parent_external_id, level, name, full_path, is_leaf, meta_json
    FROM feedtools_taxonomy_categories
    WHERE source = 'wildberries'
  ";
  $args = [];

  if ($query === '') {
    $sql .= " AND is_leaf = 0";
  } elseif (!$includeLeaves) {
    $sql .= " AND is_leaf = 0";
  }

  if ($maxDepth === 1) {
    $sql .= " AND full_path NOT LIKE '% > %'";
  } elseif ($maxDepth === 2) {
    $sql .= " AND full_path NOT LIKE '% > % > %'";
  }

  if ($minDepth === 2) {
    $sql .= " AND full_path LIKE '% > %'";
  } elseif ($minDepth >= 3) {
    $sql .= " AND full_path LIKE '% > % > %'";
  }

  if ($query !== '') {
    $like = '%' . $query . '%';
    $sql .= " AND (
      name LIKE ?
      OR full_path LIKE ?
      OR external_id LIKE ?
      OR meta_json LIKE ?
    )";
    $args[] = $like;
    $args[] = $like;
    $args[] = '%wb:subject:' . $query . '%';
    $args[] = $like;
  }

  $sql .= " ORDER BY is_leaf ASC, full_path ASC LIMIT " . (int)($limit * 8);
  $stmt = db()->prepare($sql);
  $stmt->execute($args);

  $options = [];
  $seen = [];
  while ($r = $stmt->fetch()) {
    $isLeaf = (int)($r['is_leaf'] ?? 0) === 1;
    $externalId = (string)($r['external_id'] ?? '');
    $subjectId = '';
    if (preg_match('~^wb:subject:(\d+)$~', $externalId, $m)) {
      $subjectId = $m[1];
    }

    if ($isLeaf && $subjectId === '') continue;
    $fullPath = trim((string)($r['full_path'] ?? ''));
    if ($isLeaf && !$includeLeaves) continue;
    $depth = ft_taxonomy_path_depth($fullPath);
    if ($maxDepth > 0 && $depth > $maxDepth) continue;
    if ($minDepth > 0 && $depth < $minDepth) continue;
    $dedupeKey = $isLeaf ? ('leaf:' . $subjectId) : ('parent:' . ($fullPath !== '' ? $fullPath : $externalId));
    if (isset($seen[$dedupeKey])) continue;
    $seen[$dedupeKey] = true;

    $childrenCount = 0;
    if (!$isLeaf) {
      if ($fullPath !== '') {
        $st = db()->prepare("
          SELECT COUNT(DISTINCT external_id)
          FROM feedtools_taxonomy_categories
          WHERE source = 'wildberries'
            AND is_leaf = 1
            AND external_id LIKE 'wb:subject:%'
            AND full_path LIKE ?
        ");
        $st->execute([$fullPath . ' > %']);
      } else {
        $st = db()->prepare("
          SELECT COUNT(DISTINCT external_id)
          FROM feedtools_taxonomy_categories
          WHERE source = 'wildberries'
            AND is_leaf = 1
            AND parent_external_id = ?
        ");
        $st->execute([$externalId]);
      }
      $childrenCount = (int)$st->fetchColumn();
      if ($childrenCount <= 0) continue;
    }

    $label = (string)($r['full_path'] ?? '');
    if ($label === '') $label = (string)($r['name'] ?? ($subjectId ?: $externalId));
    if ($isLeaf && $subjectId !== '') $label .= " ({$subjectId})";

    $options[] = [
      'value' => $isLeaf ? ('leaf:' . $subjectId) : ('node:' . (int)$r['id']),
      'category_value' => $isLeaf ? $subjectId : '',
      'label' => $label,
      'kind' => $isLeaf ? 'leaf' : 'parent',
      'node_id' => (int)$r['id'],
      'children_count' => $childrenCount,
      'full_path' => (string)($r['full_path'] ?? ''),
    ];
    if (count($options) >= $limit) break;
  }

  return $options;
}

function ft_search_wb_taxonomy_options(string $query = '', int $limit = 50): array
{
  $limit = max(1, min(200, $limit));
  $query = trim($query);

  $sql = "
    SELECT full_path, meta_json
    FROM feedtools_taxonomy_categories
    WHERE source = ? AND is_leaf = 1
  ";
  $args = ['wildberries'];

  if ($query !== '') {
    $like = '%' . $query . '%';
    $sql .= " AND (
      full_path LIKE ?
      OR external_id LIKE ?
      OR meta_json LIKE ?
    )";
    $args[] = $like;
    $args[] = '%wb:subject:' . $query . '%';
    $args[] = $like;
  }

  $sql .= " ORDER BY full_path ASC LIMIT " . (int)$limit;

  $stmt = db()->prepare($sql);
  $stmt->execute($args);

  $options = [];
  while ($r = $stmt->fetch()) {
    $meta = json_decode((string)($r['meta_json'] ?? ''), true) ?: [];
    $subjectId = (string)($meta['raw']['wb_category'] ?? ($meta['raw']['wb_subject_id'] ?? ''));
    if ($subjectId === '') continue;

    $label = (string)($r['full_path'] ?? '');
    if ($label === '') $label = $subjectId;
    $label .= " ({$subjectId})";

    $options[] = [
      'value' => $subjectId,
      'label' => $label,
    ];
  }

  return $options;
}

function ft_taxonomy_picker_leaf_value(string $source, array $row): string
{
  if ($source === 'ozon') {
    $parentId = (string)($row['ozon_parent_id'] ?? '');
    $leafId = (string)($row['ozon_leaf_id'] ?? '');
    return ($parentId !== '' && $leafId !== '') ? ($parentId . '_' . $leafId) : '';
  }

  $externalId = (string)($row['external_id'] ?? '');
  if (preg_match('~^wb:subject:(\d+)$~', $externalId, $m)) {
    return (string)$m[1];
  }

  $meta = json_decode((string)($row['meta_json'] ?? ''), true);
  if (is_array($meta)) {
    return (string)($meta['raw']['wb_category'] ?? ($meta['raw']['wb_subject_id'] ?? ''));
  }

  return '';
}

function ft_taxonomy_picker_leaf_id_sql(string $source): string
{
  return $source === 'ozon'
    ? "CONCAT(COALESCE(ozon_parent_id, ''), '_', COALESCE(ozon_leaf_id, ''))"
    : "REPLACE(external_id, 'wb:subject:', '')";
}

function ft_taxonomy_picker_descendant_leaf_count(string $source, string $fullPath): int
{
  $source = $source === 'wb' ? 'wildberries' : $source;
  if (!in_array($source, ['ozon', 'wildberries'], true) || trim($fullPath) === '') return 0;

  if ($source === 'ozon') {
    $stmt = db()->prepare("
      SELECT COUNT(DISTINCT CONCAT(ozon_parent_id, '_', ozon_leaf_id))
      FROM feedtools_taxonomy_categories
      WHERE source = 'ozon'
        AND is_leaf = 1
        AND ozon_parent_id > 0
        AND ozon_leaf_id > 0
        AND full_path LIKE ?
    ");
    $stmt->execute([$fullPath . ' > %']);
    return (int)$stmt->fetchColumn();
  }

  $stmt = db()->prepare("
    SELECT COUNT(DISTINCT external_id)
    FROM feedtools_taxonomy_categories
    WHERE source = 'wildberries'
      AND is_leaf = 1
      AND external_id LIKE 'wb:subject:%'
      AND full_path LIKE ?
  ");
  $stmt->execute([$fullPath . ' > %']);
  return (int)$stmt->fetchColumn();
}

function ft_taxonomy_picker_option_from_row(string $source, array $row): ?array
{
  $source = $source === 'wb' ? 'wildberries' : $source;
  $isLeaf = (int)($row['is_leaf'] ?? 0) === 1;
  $fullPath = trim((string)($row['full_path'] ?? ''));
  $name = trim((string)($row['name'] ?? ''));
  $parts = array_values(array_filter(array_map('trim', explode('>', $fullPath)), static fn(string $part): bool => $part !== ''));
  $depth = max(0, count($parts) - 1);

  if ($isLeaf) {
    $value = ft_taxonomy_picker_leaf_value($source, $row);
    if ($value === '' || ($source === 'ozon' && $value === '_')) return null;
    $labelPath = $fullPath !== '' ? $fullPath : ($name !== '' ? $name : $value);
    return [
      'value' => $value,
      'category_value' => $value,
      'label' => $labelPath . ' (' . $value . ')',
      'full_path' => $labelPath,
      'name' => $parts ? (string)end($parts) : ($name !== '' ? $name : $value),
      'kind' => 'leaf',
      'depth' => $depth,
      'code' => $value,
      'children_count' => 0,
    ];
  }

  if ($fullPath === '') return null;
  $childrenCount = ft_taxonomy_picker_descendant_leaf_count($source, $fullPath);
  if ($childrenCount <= 0) return null;
  return [
    'value' => 'node:' . md5($source . ':' . $fullPath),
    'category_value' => '',
    'label' => $name !== '' ? $name : ($parts ? (string)end($parts) : $fullPath),
    'full_path' => $fullPath,
    'name' => $name !== '' ? $name : ($parts ? (string)end($parts) : $fullPath),
    'kind' => 'parent',
    'depth' => $depth,
    'children_count' => $childrenCount,
  ];
}

function ft_taxonomy_picker_direct_children_options(string $source, string $parentPath = '', int $limit = 180): array
{
  $source = $source === 'wb' ? 'wildberries' : $source;
  if (!in_array($source, ['ozon', 'wildberries'], true)) return [];
  $limit = max(1, min(700, $limit));
  $parentPath = trim($parentPath);

  $where = "source = ?";
  $args = [$source];
  if ($parentPath === '') {
    $where .= " AND full_path NOT LIKE '% > %'";
  } else {
    $where .= " AND full_path LIKE ? AND full_path NOT LIKE ?";
    $args[] = $parentPath . ' > %';
    $args[] = $parentPath . ' > % > %';
  }

  $stmt = db()->prepare("
    SELECT id, external_id, parent_external_id, level, name, full_path, is_leaf,
           ozon_parent_id, ozon_leaf_id, meta_json
    FROM feedtools_taxonomy_categories
    WHERE {$where}
    ORDER BY is_leaf ASC, full_path ASC
    LIMIT " . (int)($limit * 4) . "
  ");
  $stmt->execute($args);

  $options = [];
  $seen = [];
  while ($row = $stmt->fetch()) {
    $option = ft_taxonomy_picker_option_from_row($source, $row);
    if (!$option) continue;
    $key = (string)$option['kind'] . ':' . (string)($option['full_path'] ?? $option['value'] ?? '');
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $options[] = $option;
    if (count($options) >= $limit) break;
  }

  return $options;
}

function ft_taxonomy_picker_descendant_leaf_options(string $source, string $parentPath, int $limit = 500): array
{
  $source = $source === 'wb' ? 'wildberries' : $source;
  if (!in_array($source, ['ozon', 'wildberries'], true)) return [];
  $parentPath = trim($parentPath);
  if ($parentPath === '') return [];
  $limit = max(1, min(1000, $limit));

  $rows = ft_taxonomy_picker_query_leaf_rows($source, 'full_path LIKE ?', [$parentPath . ' > %'], $limit);
  $options = [];
  foreach ($rows as $row) {
    $option = ft_taxonomy_picker_option_from_row($source, $row);
    if ($option) $options[] = $option;
  }
  return $options;
}

function ft_taxonomy_picker_query_leaf_rows(string $source, string $whereSql, array $args, int $limit): array
{
  $limit = max(1, min(700, $limit));
  $idExpr = ft_taxonomy_picker_leaf_id_sql($source);
  $sql = "
    SELECT id, external_id, parent_external_id, level, name, full_path, is_leaf,
           ozon_parent_id, ozon_leaf_id, meta_json
    FROM feedtools_taxonomy_categories
    WHERE source = ?
      AND is_leaf = 1
      AND {$whereSql}
    ORDER BY full_path ASC
    LIMIT {$limit}
  ";
  $stmt = db()->prepare($sql);
  $stmt->execute(array_merge([$source], $args));

  $rows = [];
  while ($row = $stmt->fetch()) {
    $value = ft_taxonomy_picker_leaf_value($source, $row);
    if ($value === '' || ($source === 'ozon' && $value === '_')) continue;
    $rows[] = $row;
  }
  return $rows;
}

function ft_taxonomy_picker_add_leaf(array &$rowsByValue, string $source, array $row): void
{
  $value = ft_taxonomy_picker_leaf_value($source, $row);
  if ($value === '' || ($source === 'ozon' && $value === '_')) return;
  if (!isset($rowsByValue[$value])) {
    $rowsByValue[$value] = $row;
  }
}

function ft_taxonomy_picker_parent_paths(string $source, string $query, int $limit): array
{
  $query = trim($query);
  if ($query === '') return [];
  $limit = max(1, min(80, $limit));
  $like = '%' . $query . '%';
  $prefixLike = $query . '%';
  $stmt = db()->prepare("
    SELECT full_path
    FROM feedtools_taxonomy_categories
    WHERE source = ?
      AND is_leaf = 0
      AND (name LIKE ? OR full_path LIKE ?)
    ORDER BY
      CASE
        WHEN LOWER(name) = LOWER(?) THEN 0
        WHEN name LIKE ? THEN 1
        WHEN full_path LIKE ? THEN 2
        ELSE 3
      END ASC,
      level ASC,
      full_path ASC
    LIMIT {$limit}
  ");
  $stmt->execute([$source, $like, $like, $query, $prefixLike, $prefixLike]);
  $paths = [];
  while ($row = $stmt->fetch()) {
    $path = trim((string)($row['full_path'] ?? ''));
    if ($path !== '') $paths[] = $path;
  }
  return array_values(array_unique($paths));
}

function ft_search_marketplace_taxonomy_picker_options(string $source, string $query = '', int $limit = 180): array
{
  $source = $source === 'wb' ? 'wildberries' : $source;
  if (!in_array($source, ['ozon', 'wildberries'], true)) return [];

  $limit = max(20, min(500, $limit));
  $query = trim($query);
  $rowsByValue = [];

  if ($query !== '') {
    foreach (ft_taxonomy_picker_parent_paths($source, $query, 30) as $path) {
      $remaining = max(10, $limit - count($rowsByValue));
      foreach (ft_taxonomy_picker_query_leaf_rows($source, 'full_path LIKE ?', [$path . ' > %'], min($remaining, 140)) as $row) {
        ft_taxonomy_picker_add_leaf($rowsByValue, $source, $row);
        if (count($rowsByValue) >= $limit) break 2;
      }
    }

    if (count($rowsByValue) < $limit) {
      $like = '%' . $query . '%';
      $idExpr = ft_taxonomy_picker_leaf_id_sql($source);
      $where = $source === 'ozon'
        ? "(name LIKE ? OR full_path LIKE ? OR {$idExpr} LIKE ?)"
        : "(name LIKE ? OR full_path LIKE ? OR external_id LIKE ? OR meta_json LIKE ?)";
      $args = $source === 'ozon'
        ? [$like, $like, $like]
        : [$like, $like, '%wb:subject:' . $query . '%', $like];
      foreach (ft_taxonomy_picker_query_leaf_rows($source, $where, $args, $limit - count($rowsByValue)) as $row) {
        ft_taxonomy_picker_add_leaf($rowsByValue, $source, $row);
        if (count($rowsByValue) >= $limit) break;
      }
    }
  } else {
    foreach (ft_taxonomy_picker_query_leaf_rows($source, '1=1', [], min(220, $limit)) as $row) {
      ft_taxonomy_picker_add_leaf($rowsByValue, $source, $row);
      if (count($rowsByValue) >= $limit) break;
    }
  }

  $rows = array_values($rowsByValue);
  if ($query === '') {
    usort($rows, static function(array $a, array $b): int {
      return strnatcasecmp((string)($a['full_path'] ?? ''), (string)($b['full_path'] ?? ''));
    });
  }

  $options = [];
  $seenParents = [];
  foreach ($rows as $row) {
    $value = ft_taxonomy_picker_leaf_value($source, $row);
    if ($value === '' || ($source === 'ozon' && $value === '_')) continue;
    $fullPath = trim((string)($row['full_path'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    $parts = array_values(array_filter(array_map('trim', explode('>', $fullPath)), static fn(string $part): bool => $part !== ''));
    if (!$parts && $name !== '') $parts = [$name];

    $prefix = [];
    $parentCount = max(0, count($parts) - 1);
    for ($i = 0; $i < $parentCount; $i++) {
      $prefix[] = $parts[$i];
      $path = implode(' > ', $prefix);
      if ($path === '' || isset($seenParents[$path])) continue;
      $seenParents[$path] = true;
      $options[] = [
        'value' => 'node:' . md5($source . ':' . $path),
        'category_value' => '',
        'label' => $parts[$i],
        'full_path' => $path,
        'name' => $parts[$i],
        'kind' => 'parent',
        'depth' => $i,
        'children_count' => 0,
      ];
    }

    $labelPath = $fullPath !== '' ? $fullPath : ($name !== '' ? $name : $value);
    $options[] = [
      'value' => $value,
      'category_value' => $value,
      'label' => $labelPath . ' (' . $value . ')',
      'full_path' => $labelPath,
      'name' => $parts ? (string)end($parts) : ($name !== '' ? $name : $value),
      'kind' => 'leaf',
      'depth' => max(0, count($parts) - 1),
      'code' => $value,
      'children_count' => 0,
    ];
  }

  return $options;
}

function ft_taxonomy_label_map(array $options): array
{
  $map = [];
  foreach ($options as $opt) {
    $value = trim((string)($opt['value'] ?? ''));
    $label = trim((string)($opt['label'] ?? ''));
    if ($value === '' || $label === '') continue;
    $map[$value] = $label;
  }
  return $map;
}

function ft_taxonomy_keyword_lines_from_value($value): array
{
  $parts = [];
  if (is_array($value)) {
    array_walk_recursive($value, static function ($item) use (&$parts): void {
      $item = trim((string)$item);
      if ($item !== '') $parts[] = $item;
    });
  } else {
    $parts = preg_split('~\R+~u', trim((string)($value ?? ''))) ?: [];
  }
  return array_values(array_filter(array_map(
    static fn($line): string => trim((string)$line),
    $parts
  ), static fn(string $line): bool => $line !== ''));
}

function ft_taxonomy_key_search_query_lines(array $meta): array
{
  $block = is_array($meta['key_search_queries'] ?? null) ? $meta['key_search_queries'] : [];
  $queries = is_array($block['queries'] ?? null) ? $block['queries'] : [];
  $lines = [];
  foreach ($queries as $row) {
    if (!is_array($row)) continue;
    $query = trim((string)($row['query'] ?? ''));
    if ($query !== '') $lines[] = $query;
  }
  return $lines;
}

function ft_taxonomy_meta_keywords(array $meta): string
{
  $lines = [];
  $seen = [];
  $add = static function (array $candidates) use (&$lines, &$seen): void {
    foreach ($candidates as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      $norm = str_replace('ё', 'е', mb_strtolower($line, 'UTF-8'));
      $norm = preg_replace('~\s+~u', ' ', $norm);
      $norm = trim((string)$norm);
      if ($norm === '' || isset($seen[$norm])) continue;
      $seen[$norm] = true;
      $lines[] = $line;
      if (count($lines) >= 100) return;
    }
  };

  foreach (['keywords', 'keywords_lines', 'keywords_by_lines', 'search_queries_keywords_lines', 'seo_keywords'] as $key) {
    $add(ft_taxonomy_keyword_lines_from_value($meta[$key] ?? ''));
    if (count($lines) >= 100) break;
  }
  if (count($lines) < 100) {
    $add(ft_taxonomy_key_search_query_lines($meta));
  }

  return implode("\n", array_slice($lines, 0, 100));
}

function ft_load_ozon_category_context(string $code): ?array
{
  $code = trim((string)$code);
  if ($code === '' || !preg_match('~^(\d+)_(\d+)$~', $code, $m)) return null;

  $stmt = db()->prepare("
    SELECT *
    FROM feedtools_taxonomy_categories
    WHERE source='ozon' AND is_leaf=1 AND ozon_parent_id=? AND ozon_leaf_id=?
    LIMIT 1
  ");
  $stmt->execute([(int)$m[1], (int)$m[2]]);
  $row = $stmt->fetch();
  if (!$row) return null;

  $meta = [];
  if (!empty($row['meta_json'])) {
    $tmp = json_decode((string)$row['meta_json'], true);
    if (is_array($tmp)) $meta = $tmp;
  }

  return [
    'source' => 'ozon',
    'source_label' => 'Ozon',
    'code' => $code,
    'name' => (string)($row['name'] ?? ''),
    'full_path' => (string)($row['full_path'] ?? ''),
    'description' => (string)($meta['description'] ?? ''),
    'typical_goods' => (string)($meta['typical_goods'] ?? ''),
    'features' => (string)($meta['features'] ?? ''),
    'keywords' => ft_taxonomy_meta_keywords($meta),
  ];
}

function ft_load_wb_category_context(string $code): ?array
{
  $code = trim((string)$code);
  if ($code === '' || !ctype_digit($code)) return null;

  $stmt = db()->prepare("
    SELECT *
    FROM feedtools_taxonomy_categories
    WHERE source='wildberries' AND is_leaf=1 AND external_id=?
    LIMIT 1
  ");
  $stmt->execute(['wb:subject:' . $code]);
  $row = $stmt->fetch();
  if (!$row) return null;

  $meta = [];
  if (!empty($row['meta_json'])) {
    $tmp = json_decode((string)$row['meta_json'], true);
    if (is_array($tmp)) $meta = $tmp;
  }

  return [
    'source' => 'wildberries',
    'source_label' => 'Wildberries',
    'code' => $code,
    'name' => (string)($row['name'] ?? ''),
    'full_path' => (string)($row['full_path'] ?? ''),
    'description' => (string)($meta['description'] ?? ''),
    'typical_goods' => (string)($meta['typical_goods'] ?? ''),
    'features' => (string)($meta['features'] ?? ''),
    'keywords' => ft_taxonomy_meta_keywords($meta),
  ];
}

function ft_merge_marketplace_category_contexts(array $contexts, bool $includeKeywords = false): ?array
{
  $contexts = array_values(array_filter($contexts, static fn($ctx) => is_array($ctx) && trim((string)($ctx['code'] ?? '')) !== ''));
  if (!$contexts) return null;

  if (count($contexts) === 1) {
    $one = $contexts[0];
    return [
      'code' => (string)$one['code'],
      'source' => (string)$one['source'],
      'name' => (string)$one['name'],
      'full_path' => (string)$one['full_path'],
      'description' => (string)$one['description'],
      'typical_goods' => (string)$one['typical_goods'],
      'features' => (string)$one['features'],
      'keywords_lines' => $includeKeywords ? (string)($one['keywords'] ?? '') : '',
      'marketplace_contexts' => $contexts,
    ];
  }

  $codes = [];
  $names = [];
  $paths = [];
  $descriptions = [];
  $typicalGoods = [];
  $features = [];
  $keywords = [];

  foreach ($contexts as $ctx) {
    $label = (string)($ctx['source_label'] ?? $ctx['source'] ?? '');
    $prefix = $label !== '' ? '[' . $label . '] ' : '';

    $source = (string)($ctx['source'] ?? '');
    $code = trim((string)($ctx['code'] ?? ''));
    if ($source !== '' && $code !== '') $codes[] = $source . ':' . $code;

    foreach ([
      'name' => &$names,
      'full_path' => &$paths,
      'description' => &$descriptions,
      'typical_goods' => &$typicalGoods,
      'features' => &$features,
    ] as $key => &$bucket) {
      $value = trim((string)($ctx[$key] ?? ''));
      if ($value !== '') $bucket[] = $prefix . $value;
    }
    unset($bucket);

    $kw = trim((string)($ctx['keywords'] ?? ''));
    if ($includeKeywords && $kw !== '') $keywords[] = $prefix . $kw;
  }

  return [
    'code' => implode(' | ', $codes),
    'source' => 'multi_marketplace',
    'name' => implode(' + ', array_values(array_unique($names))),
    'full_path' => implode("\n", array_values(array_unique($paths))),
    'description' => implode("\n\n", array_values(array_unique($descriptions))),
    'typical_goods' => implode("\n\n", array_values(array_unique($typicalGoods))),
    'features' => implode("\n\n", array_values(array_unique($features))),
    'keywords_lines' => $includeKeywords ? implode("\n", array_values(array_unique($keywords))) : '',
    'marketplace_contexts' => $contexts,
  ];
}

function ft_load_combined_marketplace_category_context(string $ozonCode, string $wbCode, bool $includeKeywords = false): ?array
{
  static $cache = [];

  $key = ($includeKeywords ? 'kw:' : 'no_kw:') . trim($ozonCode) . '|' . trim($wbCode);
  if (array_key_exists($key, $cache)) return $cache[$key];

  $contexts = [];
  $ozon = ft_load_ozon_category_context($ozonCode);
  if ($ozon) $contexts[] = $ozon;
  $wb = ft_load_wb_category_context($wbCode);
  if ($wb) $contexts[] = $wb;

  $cache[$key] = ft_merge_marketplace_category_contexts($contexts, $includeKeywords);
  return $cache[$key];
}
