<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

\QualitySystem\Support\AppLogger::install();

function isHttpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function securityBootstrap(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    $bootstrapped = true;
    $isProduction = filter_var(getenv('APP_PRODUCTION') ?: '1', FILTER_VALIDATE_BOOL);

    ini_set('display_errors', $isProduction ? '0' : '1');
    ini_set('display_startup_errors', $isProduction ? '0' : '1');
    error_reporting(E_ALL);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }

    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        requireValidCsrf();
    }

    if (str_starts_with($scriptName, 'delete') && str_ends_with($scriptName, '.php')) {
        requirePost();
    }
}

function csrfToken(): string
{
    securityBootstrap();

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8')
        . '">';
}

function deleteForm(string $action, string $confirmation = 'Are you sure you want to delete this record?'): string
{
    $safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    $safeConfirmation = htmlspecialchars($confirmation, ENT_QUOTES, 'UTF-8');

    return '<form method="post" action="' . $safeAction . '" class="inline-delete-form" '
        . 'style="display:inline" onsubmit="return confirm(&quot;' . $safeConfirmation . '&quot;);">'
        . csrfField()
        . '<button type="submit" class="btn btn-delete">Delete</button>'
        . '</form>';
}

function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed.');
    }
}

function requireValidCsrf(): void
{
    $submitted = $_POST['_csrf_token'] ?? '';
    $expected = $_SESSION['_csrf_token'] ?? '';

    if (!is_string($submitted) || !is_string($expected) || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('The request could not be verified. Please return to the previous page and try again.');
    }
}

securityBootstrap();
