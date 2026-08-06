<?php
require_once __DIR__ . '/../../app/bootstrap_http.php';
$cfg = ft_bootstrap_public();
require_once __DIR__ . '/../../app/taxonomy/MarketplaceCategoryContext.php';

header('Content-Type: application/json; charset=UTF-8');

$source = trim((string)($_GET['source'] ?? ''));
if ($source === 'wb') $source = 'wildberries';
$includeParents = (string)($_GET['include_parents'] ?? '') === '1';
$maxDepth = max(0, min(20, (int)($_GET['max_depth'] ?? 0)));
$minDepth = max(0, min(20, (int)($_GET['min_depth'] ?? 0)));
$includeLeaves = (string)($_GET['include_leaves'] ?? '1') !== '0';
$treePicker = (string)($_GET['tree_picker'] ?? '') === '1' || (string)($_GET['mode'] ?? '') === 'tree_picker';
$parentPath = trim((string)($_GET['parent_path'] ?? ''));
$allLeaves = (string)($_GET['all_leaves'] ?? '') === '1';

try {
  if ($source === 'ozon') {
    $query = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 50);
	    echo json_encode([
	      'ok' => true,
	      'source' => 'ozon',
	      'options' => $includeParents
	        ? ($treePicker
	          ? ($parentPath !== ''
	            ? ($allLeaves
	              ? ft_taxonomy_picker_descendant_leaf_options('ozon', $parentPath, $limit)
	              : ft_taxonomy_picker_direct_children_options('ozon', $parentPath, $limit))
	            : ($query === ''
	              ? ft_taxonomy_picker_direct_children_options('ozon', '', $limit)
	              : ft_search_marketplace_taxonomy_picker_options('ozon', $query, $limit)))
	          : ft_search_ozon_taxonomy_tree_options($query, $limit, $maxDepth, $includeLeaves))
	        : ft_search_ozon_taxonomy_options($query, $limit),
	    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($source === 'wildberries') {
    $query = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 50);
	    echo json_encode([
	      'ok' => true,
	      'source' => 'wildberries',
	      'options' => $includeParents
	        ? ($treePicker
	          ? ($parentPath !== ''
	            ? ($allLeaves
	              ? ft_taxonomy_picker_descendant_leaf_options('wildberries', $parentPath, $limit)
	              : ft_taxonomy_picker_direct_children_options('wildberries', $parentPath, $limit))
	            : ($query === ''
	              ? ft_taxonomy_picker_direct_children_options('wildberries', '', $limit)
	              : ft_search_marketplace_taxonomy_picker_options('wildberries', $query, $limit)))
	          : ft_search_wb_taxonomy_tree_options($query, $limit, $maxDepth, $includeLeaves, $minDepth))
	        : ft_search_wb_taxonomy_options($query, $limit),
	    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'error' => 'unsupported_source',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
