<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/localization.php';

function ft_bootstrap_public(array $options = []): array
{
  $cfg = require __DIR__ . '/config.php';

  if (empty($options['skip_auth'])) {
    ft_require_basic_auth((array)($cfg['auth'] ?? []));
  }

  ft_i18n_bootstrap();

  return $cfg;
}

function ft_require_basic_auth(array $auth): void
{
  if (PHP_SAPI === 'cli' || empty($auth['enabled'])) {
    return;
  }

  $realm = trim((string)($auth['realm'] ?? 'FeedTools'));
  $user = trim((string)($auth['user'] ?? ''));
  $pass = (string)($auth['pass'] ?? '');
  $passHash = trim((string)($auth['pass_hash'] ?? ''));

  if ($user === '' || ($pass === '' && $passHash === '')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Basic auth is enabled but credentials are not configured.\n";
    exit;
  }

  [$givenUser, $givenPass] = ft_basic_auth_credentials();

  $userOk = ($givenUser !== null) && hash_equals($user, $givenUser);
  $passOk = false;

  if ($givenPass !== null) {
    if ($passHash !== '') {
      $passOk = password_verify($givenPass, $passHash);
    } else {
      $passOk = hash_equals($pass, $givenPass);
    }
  }

  if ($userOk && $passOk) {
    return;
  }

  header('WWW-Authenticate: Basic realm="' . addslashes($realm) . '"');
  http_response_code(401);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Authentication required.\n";
  exit;
}

function ft_normalize_actor(?string $value): ?string
{
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }
  $value = preg_replace('~[^\p{L}\p{N}@._ -]+~u', '', $value);
  $value = trim((string)preg_replace('~\s+~u', ' ', (string)$value));
  if ($value === '') {
    return null;
  }
  return mb_substr($value, 0, 80, 'UTF-8');
}

function ft_actor_cookie_name(): string
{
  return 'feedtools_operator';
}

function ft_authenticated_user(): ?string
{
  foreach (['PHP_AUTH_USER', 'REMOTE_USER'] as $key) {
    $value = ft_normalize_actor(isset($_SERVER[$key]) ? (string)$_SERVER[$key] : null);
    if ($value !== null) return $value;
  }

  [$user] = ft_basic_auth_credentials();
  return ft_normalize_actor(is_string($user) ? $user : null);
}

function ft_operator_user(): ?string
{
  return ft_normalize_actor(isset($_COOKIE[ft_actor_cookie_name()]) ? (string)$_COOKIE[ft_actor_cookie_name()] : null);
}

function ft_set_operator_user(?string $actor): void
{
  $actor = ft_normalize_actor($actor);
  $params = [
    'expires' => $actor !== null ? time() + 60 * 60 * 24 * 180 : time() - 3600,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
  ];
  setcookie(ft_actor_cookie_name(), $actor ?? '', $params);
  if ($actor !== null) {
    $_COOKIE[ft_actor_cookie_name()] = $actor;
  } else {
    unset($_COOKIE[ft_actor_cookie_name()]);
  }
}

function ft_basic_auth_credentials(): array
{
  $user = $_SERVER['PHP_AUTH_USER'] ?? null;
  $pass = $_SERVER['PHP_AUTH_PW'] ?? null;

  if ($user !== null || $pass !== null) {
    return [$user, $pass];
  }

  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
  if (!is_string($header) || stripos($header, 'basic ') !== 0) {
    return [null, null];
  }

  $decoded = base64_decode(substr($header, 6), true);
  if (!is_string($decoded) || strpos($decoded, ':') === false) {
    return [null, null];
  }

  [$user, $pass] = explode(':', $decoded, 2);
  return [$user, $pass];
}

function ft_current_user(): ?string
{
  return ft_authenticated_user() ?? ft_operator_user();
}

function ft_is_admin_user(): bool
{
  return ft_authenticated_user() === 'admin';
}

function ft_require_admin_user(): void
{
  if (ft_is_admin_user()) {
    return;
  }

  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Admin access only.\n";
  exit;
}

function ft_app_env(array $cfg): string
{
  return strtolower(trim((string)($cfg['app']['env'] ?? 'production')));
}

function ft_is_staging_env(array $cfg): bool
{
  return in_array(ft_app_env($cfg), ['staging', 'stage', 'dev', 'development', 'test', 'testing'], true);
}

function ft_env_badge_label(array $cfg): string
{
  return ft_is_staging_env($cfg) ? 'TEST' : '';
}
