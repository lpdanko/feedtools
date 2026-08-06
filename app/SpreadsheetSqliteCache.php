<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;

final class FeedtoolsSpreadsheetSqliteCache implements CacheInterface
{
  private SQLite3 $db;
  private string $path;
  private bool $closed = false;
  private SQLite3Stmt $getStmt;
  private SQLite3Stmt $hasStmt;
  private SQLite3Stmt $setStmt;
  private SQLite3Stmt $deleteStmt;

  public function __construct(string $path)
  {
    if (!class_exists(SQLite3::class)) {
      throw new RuntimeException('PHP extension sqlite3 is required for large Excel exports');
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('Cannot create spreadsheet cache directory');
    }

    $this->path = $path;
    $this->db = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $this->db->enableExceptions(true);
    $this->db->busyTimeout(30000);
    $this->db->exec('PRAGMA journal_mode = OFF');
    $this->db->exec('PRAGMA synchronous = OFF');
    $this->db->exec('PRAGMA temp_store = MEMORY');
    $this->db->exec('PRAGMA locking_mode = EXCLUSIVE');
    $this->db->exec('CREATE TABLE IF NOT EXISTS cache_items (
      cache_key TEXT PRIMARY KEY,
      cache_value BLOB NOT NULL,
      expires_at INTEGER NULL
    ) WITHOUT ROWID');
    $this->db->exec('BEGIN IMMEDIATE');

    $this->getStmt = $this->db->prepare(
      'SELECT cache_value, expires_at FROM cache_items WHERE cache_key = :cache_key'
    );
    $this->hasStmt = $this->db->prepare(
      'SELECT expires_at FROM cache_items WHERE cache_key = :cache_key'
    );
    $this->setStmt = $this->db->prepare(
      'INSERT INTO cache_items (cache_key, cache_value, expires_at)
       VALUES (:cache_key, :cache_value, :expires_at)
       ON CONFLICT(cache_key) DO UPDATE SET
         cache_value = excluded.cache_value,
         expires_at = excluded.expires_at'
    );
    $this->deleteStmt = $this->db->prepare(
      'DELETE FROM cache_items WHERE cache_key = :cache_key'
    );
  }

  public function get(string $key, mixed $default = null): mixed
  {
    $this->assertOpen();
    $result = $this->executeForResult($this->getStmt, $key);
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $result->finalize();
    if (!is_array($row)) return $default;

    $expiresAt = isset($row['expires_at']) ? (int)$row['expires_at'] : null;
    if ($expiresAt !== null && $expiresAt <= time()) {
      $this->delete($key);
      return $default;
    }

    return unserialize((string)$row['cache_value'], ['allowed_classes' => true]);
  }

  public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
  {
    $this->assertOpen();
    $expiresAt = $this->expiresAt($ttl);
    $this->setStmt->reset();
    $this->setStmt->clear();
    $this->setStmt->bindValue(':cache_key', $key, SQLITE3_TEXT);
    $this->setStmt->bindValue(':cache_value', serialize($value), SQLITE3_BLOB);
    if ($expiresAt === null) {
      $this->setStmt->bindValue(':expires_at', null, SQLITE3_NULL);
    } else {
      $this->setStmt->bindValue(':expires_at', $expiresAt, SQLITE3_INTEGER);
    }
    $result = $this->setStmt->execute();
    if ($result instanceof SQLite3Result) $result->finalize();
    return true;
  }

  public function delete(string $key): bool
  {
    $this->assertOpen();
    $this->deleteStmt->reset();
    $this->deleteStmt->clear();
    $this->deleteStmt->bindValue(':cache_key', $key, SQLITE3_TEXT);
    $result = $this->deleteStmt->execute();
    if ($result instanceof SQLite3Result) $result->finalize();
    return true;
  }

  public function clear(): bool
  {
    $this->assertOpen();
    return $this->db->exec('DELETE FROM cache_items');
  }

  public function getMultiple(iterable $keys, mixed $default = null): iterable
  {
    $values = [];
    foreach ($keys as $key) {
      $key = (string)$key;
      $values[$key] = $this->get($key, $default);
    }
    return $values;
  }

  public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
  {
    foreach ($values as $key => $value) {
      $this->set((string)$key, $value, $ttl);
    }
    return true;
  }

  public function deleteMultiple(iterable $keys): bool
  {
    if ($this->closed) return true;
    foreach ($keys as $key) {
      $this->delete((string)$key);
    }
    return true;
  }

  public function has(string $key): bool
  {
    $this->assertOpen();
    $result = $this->executeForResult($this->hasStmt, $key);
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $result->finalize();
    if (!is_array($row)) return false;

    $expiresAt = isset($row['expires_at']) ? (int)$row['expires_at'] : null;
    if ($expiresAt !== null && $expiresAt <= time()) {
      $this->delete($key);
      return false;
    }
    return true;
  }

  public function close(): void
  {
    if ($this->closed) return;
    $this->closed = true;

    foreach (['getStmt', 'hasStmt', 'setStmt', 'deleteStmt'] as $property) {
      if (isset($this->{$property})) {
        $this->{$property}->close();
      }
    }

    try {
      $this->db->exec('COMMIT');
    } catch (Throwable $e) {
      // The connection can already be unwinding after a fatal export error.
    }
    $this->db->close();
    @unlink($this->path);
    @unlink($this->path . '-journal');
    @unlink($this->path . '-wal');
    @unlink($this->path . '-shm');
  }

  public function __destruct()
  {
    $this->close();
  }

  private function executeForResult(SQLite3Stmt $stmt, string $key): SQLite3Result
  {
    $stmt->reset();
    $stmt->clear();
    $stmt->bindValue(':cache_key', $key, SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result instanceof SQLite3Result) {
      throw new RuntimeException('Spreadsheet cache query failed');
    }
    return $result;
  }

  private function expiresAt(null|int|DateInterval $ttl): ?int
  {
    if ($ttl === null) return null;
    if ($ttl instanceof DateInterval) {
      return (new DateTimeImmutable())->add($ttl)->getTimestamp();
    }
    return time() + max(0, $ttl);
  }

  private function assertOpen(): void
  {
    if ($this->closed) {
      throw new RuntimeException('Spreadsheet cache is already closed');
    }
  }
}
