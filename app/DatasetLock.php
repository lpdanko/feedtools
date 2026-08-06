<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function feedtools_dataset_locks_table_ensure(): void
{
  static $ready = false;
  if ($ready) return;

  db()->exec("
    CREATE TABLE IF NOT EXISTS feedtools_dataset_locks (
      dataset_id BIGINT UNSIGNED NOT NULL,
      op_id BIGINT UNSIGNED NULL,
      owner_token CHAR(32) NOT NULL,
      holder VARCHAR(191) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (dataset_id),
      KEY idx_op_id (op_id),
      KEY idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $ready = true;
}

function feedtools_dataset_lock_should_guard_inplace(array $params): bool
{
  $inplace = trim((string)($params['inplace'] ?? '0'));
  return $inplace !== '' && $inplace !== '0';
}

function feedtools_dataset_lock_is_stale(array $lockRow): bool
{
  $datasetId = isset($lockRow['dataset_id']) ? (int)$lockRow['dataset_id'] : 0;
  $opId = isset($lockRow['op_id']) && $lockRow['op_id'] !== null ? (int)$lockRow['op_id'] : 0;
  if ($opId > 0) {
    $st = db()->prepare("
      SELECT
        status,
        TIMESTAMPDIFF(
          SECOND,
          COALESCE(heartbeat_at, started_at, created_at),
          NOW()
        ) AS age_sec
      FROM feedtools_operations
      WHERE id=? LIMIT 1
    ");
    $st->execute([$opId]);
    $op = $st->fetch();
    if (!$op) return true;

    $status = trim((string)($op['status'] ?? ''));
    if (in_array($status, ['done', 'error'], true)) return true;

    return ((int)($op['age_sec'] ?? 0) > 2 * 60 * 60);
  }

  if ($datasetId <= 0) return true;

  $st = db()->prepare("
    SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_sec
    FROM feedtools_dataset_locks
    WHERE dataset_id=? LIMIT 1
  ");
  $st->execute([$datasetId]);
  $ageSec = (int)($st->fetchColumn() ?: 0);
  return $ageSec > 30 * 60;
}

function feedtools_dataset_lock_conflict_message(int $datasetId, array $lockRow): string
{
  $opId = isset($lockRow['op_id']) && $lockRow['op_id'] !== null ? (int)$lockRow['op_id'] : 0;
  $holder = trim((string)($lockRow['holder'] ?? ''));

  if ($opId > 0) {
    return "Датасет #{$datasetId} сейчас изменяется операцией #{$opId}. Дождись её завершения и запусти задачу повторно.";
  }

  if ($holder !== '') {
    return "Датасет #{$datasetId} сейчас изменяется процессом {$holder}. Дождись завершения и попробуй ещё раз.";
  }

  return "Датасет #{$datasetId} сейчас заблокирован другой операцией. Дождись завершения и попробуй ещё раз.";
}

function feedtools_dataset_lock_acquire(int $datasetId, ?int $opId = null, ?string $holder = null): array
{
  feedtools_dataset_locks_table_ensure();

  $ownerToken = bin2hex(random_bytes(16));
  $holder = trim((string)$holder);
  $attempts = 0;

  while ($attempts < 2) {
    $attempts++;

    try {
      $st = db()->prepare("
        INSERT INTO feedtools_dataset_locks (dataset_id, op_id, owner_token, holder)
        VALUES (?, ?, ?, ?)
      ");
      $st->execute([
        $datasetId,
        $opId !== null ? $opId : null,
        $ownerToken,
        $holder !== '' ? $holder : null,
      ]);

      return [
        'dataset_id' => $datasetId,
        'op_id' => $opId,
        'owner_token' => $ownerToken,
        'holder' => $holder,
      ];
    } catch (PDOException $e) {
      $lockSt = db()->prepare("SELECT * FROM feedtools_dataset_locks WHERE dataset_id=? LIMIT 1");
      $lockSt->execute([$datasetId]);
      $lockRow = $lockSt->fetch();

      if (!$lockRow) {
        continue;
      }

      if (feedtools_dataset_lock_is_stale($lockRow)) {
        $del = db()->prepare("DELETE FROM feedtools_dataset_locks WHERE dataset_id=?");
        $del->execute([$datasetId]);
        continue;
      }

      throw new RuntimeException(feedtools_dataset_lock_conflict_message($datasetId, $lockRow), 0, $e);
    }
  }

  throw new RuntimeException("Не удалось установить блокировку на датасет #{$datasetId}.");
}

function feedtools_dataset_lock_release(int $datasetId, string $ownerToken): void
{
  feedtools_dataset_locks_table_ensure();
  $st = db()->prepare("DELETE FROM feedtools_dataset_locks WHERE dataset_id=? AND owner_token=?");
  $st->execute([$datasetId, $ownerToken]);
}

function feedtools_dataset_lock_release_by_op(int $opId): void
{
  feedtools_dataset_locks_table_ensure();
  $st = db()->prepare("DELETE FROM feedtools_dataset_locks WHERE op_id=?");
  $st->execute([$opId]);
}
