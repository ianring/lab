<?php
declare(strict_types=1);

/**
 * Google sign-in, and the user profiles it produces.
 *
 * Standard OAuth 2.0 authorization-code flow, done by hand — no dependencies:
 *
 *   login.php  →  Google  →  login.php?code=…  →  token endpoint  →  profile
 *
 * Signing in is the whole of it: any Google account that completes the flow is
 * a user of this app, and users are the only kind of person it knows about.
 * Firings are recorded against a users.id.
 */

/* ------------------------------------------------------------------ */
/* Configuration                                                       */
/* ------------------------------------------------------------------ */

function auth_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $config = [
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => '',
        'configured'    => false,
        'error'         => '',
    ];

    if (!is_file(AUTH_FILE)) {
        $config['error'] = 'No config/auth.json yet. Copy config/auth.example.json to config/auth.json '
            . 'on the server and put your Google client ID and secret in it.';
        $cache = $config;
        return $cache;
    }

    $decoded = json_decode((string)file_get_contents(AUTH_FILE), true);
    if (!is_array($decoded)) {
        $config['error'] = 'config/auth.json could not be parsed (' . json_last_error_msg() . ').';
        $cache = $config;
        return $cache;
    }

    $config['client_id']     = trim((string)($decoded['client_id'] ?? ''));
    $config['client_secret'] = trim((string)($decoded['client_secret'] ?? ''));
    $config['redirect_uri']  = trim((string)($decoded['redirect_uri'] ?? ''));

    if ($config['client_id'] === '' || $config['client_secret'] === '') {
        $config['error'] = 'config/auth.json is missing the client ID or client secret.';
    } else {
        $config['configured'] = true;
    }

    $cache = $config;
    return $cache;
}

/** Where Google should send people back to. Shown on the login page to paste into the console. */
function auth_redirect_uri(): string
{
    $configured = auth_config()['redirect_uri'];
    if ($configured !== '') {
        return $configured;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir  = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/login.php'))), '/');

    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/login.php';
}

/* ------------------------------------------------------------------ */
/* Session                                                             */
/* ------------------------------------------------------------------ */

/**
 * Somewhere to keep sessions when the server's own directory cannot be written
 * to. Distributions disagree about where that directory is and who owns it, so
 * moving to a new host can leave PHP silently unable to persist anything —
 * which looks exactly like a broken sign-in and nothing like a permissions
 * problem. Falling back to a directory the app owns keeps it working.
 *
 * Returns '' when the configured path is fine, or cannot be replaced.
 */
function auth_session_fallback_path(): string
{
    if ((string)ini_get('session.save_handler') !== 'files') {
        return '';
    }

    $configured = (string)(session_save_path() ?: sys_get_temp_dir());
    if (strpos($configured, ';') !== false) {   // an "N;/path" depth prefix
        $configured = substr($configured, strrpos($configured, ';') + 1);
    }
    if (is_dir($configured) && is_writable($configured)) {
        return '';
    }

    /*
     * The system temp directory first: it is outside the web root, so session
     * files can never be fetched over http. A directory inside the app is the
     * last resort, for a host that has locked the temp directory down.
     */
    $candidates = [
        rtrim(sys_get_temp_dir(), '/') . '/kiln-sessions',
        __DIR__ . '/../data/sessions',
    ];
    foreach ($candidates as $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        if (is_dir($path) && is_writable($path)) {
            return $path;
        }
    }
    return '';
}

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $fallback = auth_session_fallback_path();
    if ($fallback !== '') {
        session_save_path($fallback);
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path'     => '/',
            // Explicitly host-only. Any key left out of this array keeps
            // whatever the server's php.ini says, and a session.cookie_domain
            // inherited from another host makes the browser throw the cookie
            // away without a word.
            'domain'   => '',
            'httponly' => true,
            'secure'   => $https,
            'samesite' => 'Lax',
        ]);
    } else {
        // The array form, and SameSite with it, arrived in PHP 7.3.
        session_set_cookie_params(60 * 60 * 24 * 30, '/', '', $https, true);
    }
    session_name('kilnsession');
    session_start();
}

/** The signed-in user's profile, or null. */
function current_user(): ?array
{
    auth_session_start();
    $sub = (string)($_SESSION['user_sub'] ?? '');
    if ($sub === '') {
        return null;
    }
    return user_by_sub($sub);
}

/**
 * Gate a page. Anything not signed in goes to the login page, with a note of
 * where it was heading so it can be sent back afterwards.
 */
function require_login(): void
{
    if (current_user()) {
        return;
    }
    auth_session_start();
    $_SESSION['after_login'] = (string)($_SERVER['REQUEST_URI'] ?? 'index.php');
    header('Location: login.php');
    exit;
}

function auth_sign_out(): void
{
    auth_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'],
            (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

/* ------------------------------------------------------------------ */
/* The Google round trip                                               */
/* ------------------------------------------------------------------ */

/*
 * Sign-in attempts waiting for Google to send someone back.
 *
 * There can be more than one at a time, and only remembering the newest is a
 * bug: a second tab, pressing back, or a browser prefetching the login page
 * all re-render it, and the attempt the person is actually in the middle of
 * would be overwritten before they ever pressed the button. So they are kept
 * in a short list, each with the nonce it was issued with.
 */
const OAUTH_PENDING_MAX = 6;
const OAUTH_PENDING_TTL = 900;   // 15 minutes is plenty to sign in

/** Outstanding attempts, oldest first, expired ones dropped. */
function auth_pending_attempts(): array
{
    $pending = $_SESSION['oauth_pending'] ?? [];
    if (!is_array($pending)) {
        return [];
    }
    $cutoff = time() - OAUTH_PENDING_TTL;
    return array_filter($pending, static function ($attempt) use ($cutoff) {
        return is_array($attempt) && (int)($attempt['at'] ?? 0) >= $cutoff;
    });
}

function auth_remember_attempt(string $state, string $nonce): void
{
    $pending = auth_pending_attempts();
    $pending[$state] = [
        'nonce' => $nonce,
        'at'    => time(),
        // Kept for the diagnostics: leaving from one hostname and coming back
        // on another is the usual reason a session goes missing.
        'from'  => auth_current_origin(),
    ];
    if (count($pending) > OAUTH_PENDING_MAX) {
        $pending = array_slice($pending, -OAUTH_PENDING_MAX, null, true);
    }
    $_SESSION['oauth_pending'] = $pending;
}

/** Take an attempt off the list by its state, or null if it is not there. */
function auth_claim_attempt(string $state): ?array
{
    $pending = auth_pending_attempts();
    $attempt = ($state !== '' && isset($pending[$state])) ? $pending[$state] : null;
    if ($attempt !== null) {
        unset($pending[$state]);
    }
    // Write back either way: expired attempts have just been cleaned out.
    $_SESSION['oauth_pending'] = $pending;
    return $attempt;
}

/** Scheme and host of the request being served, e.g. "https://kiln.example.com". */
function auth_current_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    return ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'unknown');
}

/**
 * Why a sign-in might have lost its session, in plain facts rather than
 * guesses. Shown on the login page when the state check fails — the answer is
 * nearly always visible in one of these lines.
 *
 * @return array<string, string> label => finding
 */
function auth_session_diagnostics(?array $attempt = null): array
{
    $name      = session_name();
    $hadCookie = isset($_COOKIE[$name]);
    $pending   = auth_pending_attempts();
    $params    = session_get_cookie_params();

    $out = [];

    $out['Session cookie sent back'] = $hadCookie
        ? 'Yes — the browser returned the ' . $name . ' cookie.'
        : 'No. The browser did not send one, so this request started a brand new session. '
          . 'That points at the cookie being rejected: a wrong domain, or Secure set while the '
          . 'page was served over plain http.';

    $out['Sign-in attempts on record'] = $pending || $attempt
        ? count($pending) + ($attempt ? 1 : 0) . ' in this session.'
        : ($hadCookie
            ? 'None — the cookie came back but the session behind it was empty. That points at '
              . 'session storage on the server, not at the browser.'
            : 'None.');

    $out['Started from'] = $attempt && !empty($attempt['from'])
        ? (string)$attempt['from']
        : ($pending ? implode(', ', array_unique(array_column($pending, 'from'))) : 'unknown');
    $out['Came back to'] = auth_current_origin();

    if ($out['Started from'] !== 'unknown' && strpos($out['Started from'], $out['Came back to']) === false) {
        $out['Host match'] = 'These differ. A session cookie is not shared between two hostnames or '
            . 'between http and https — this is the cause.';
    }

    $out['Redirect URI in use'] = auth_redirect_uri();
    $out['Cookie settings'] = 'domain=' . (($params['domain'] ?? '') === '' ? '(host only)' : $params['domain'])
        . ', path=' . ($params['path'] ?? '/')
        . ', secure=' . (!empty($params['secure']) ? 'yes' : 'no')
        . ', samesite=' . (($params['samesite'] ?? '') ?: 'not set');

    $handler = (string)ini_get('session.save_handler');
    if ($handler === 'files') {
        // Whatever is in force now, which may already be the fallback.
        $path = (string)(session_save_path() ?: sys_get_temp_dir());
        if (strpos($path, ';') !== false) {   // an "N;/path" depth prefix
            $path = substr($path, strrpos($path, ';') + 1);
        }

        if (!is_dir($path)) {
            $state = 'does NOT exist. Sessions cannot persist.';
        } elseif (!is_writable($path)) {
            $state = 'NOT writable by the user PHP runs as ('
                . (function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                    ? (string)(posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
                    : 'unknown')
                . '). Sessions cannot persist.';
        } else {
            $state = 'writable.';
        }
        $out['Session storage'] = $path . ' — ' . $state;

        $configured = (string)ini_get('session.save_path');
        if ($configured !== '' && strpos($path, $configured) === false) {
            $out['Session storage'] .= ' (The configured ' . $configured
                . ' could not be used, so the app fell back to this.)';
        }
    } else {
        $out['Session storage'] = 'handler: ' . ($handler ?: 'unknown');
    }

    return $out;
}

function auth_authorize_url(string $state, string $nonce): string
{
    $config = auth_config();
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $config['client_id'],
        'redirect_uri'  => auth_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'nonce'         => $nonce,
        'prompt'        => 'select_account',
    ]);
}

/** Swap the one-time code for tokens. Returns [ok, data|error]. */
function auth_exchange_code(string $code): array
{
    $config = auth_config();
    $body = http_build_query([
        'code'          => $code,
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri'  => auth_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);

    [$ok, $response] = auth_post('https://oauth2.googleapis.com/token', $body);
    if (!$ok) {
        return [false, $response];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return [false, 'Google sent a reply we could not read.'];
    }
    if (isset($data['error'])) {
        return [false, 'Google refused the sign-in: ' . $data['error']
            . (isset($data['error_description']) ? ' — ' . $data['error_description'] : '')];
    }
    if (empty($data['id_token'])) {
        return [false, 'Google did not return an identity token.'];
    }
    return [true, $data];
}

/** POST with cURL if it is available, otherwise streams. Returns [ok, body|error]. */
function auth_post(string $url, string $body): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            return [false, 'Could not reach Google: ' . $error];
        }
        return [true, (string)$response];
    }

    $context = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => $body,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return [false, 'Could not reach Google. The server may not allow outbound HTTPS requests.'];
    }
    return [true, (string)$response];
}

/**
 * Read the claims out of an ID token.
 *
 * The signature is not checked, and does not need to be: this token came
 * straight back from Google's token endpoint over TLS in response to our own
 * client secret, not from the browser. Google documents that as sufficient.
 */
function auth_id_token_claims(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    $payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
    if ($payload === false) {
        return null;
    }
    $claims = json_decode($payload, true);
    return is_array($claims) ? $claims : null;
}

/* ------------------------------------------------------------------ */
/* Profiles                                                            */
/* ------------------------------------------------------------------ */

/** One database row as the profile array the app passes around. */
function user_from_row(array $row): array
{
    return [
        'id'         => (int)$row['id'],
        'sub'        => (string)$row['google_sub'],
        'email'      => (string)$row['email'],
        'name'       => (string)$row['name'],
        'picture'    => (string)$row['picture'],
        'phone'      => (string)($row['phone'] ?? ''),
        'sms_opt_in' => (bool)($row['sms_opt_in'] ?? false),
        'sms_asked'  => (bool)($row['sms_asked'] ?? false),
        'created_at' => db_iso($row['created_at']),
        'last_login' => db_iso($row['last_login']),
    ];
}

function users_all(): array
{
    if (!db_ready()) {
        return [];
    }
    return array_map('user_from_row', db_rows('SELECT * FROM users ORDER BY name'));
}

/**
 * The options for a "whose firings" filter: you first, then everyone, then the
 * rest by name. Your own firings are what you nearly always want, so they are
 * one tap away rather than somewhere down an alphabetical list.
 *
 * @return array<int, array{id:int, label:string}>
 */
function person_filter_options(): array
{
    $me      = current_user_id();
    $options = [];

    $mine = $me > 0 ? user_get($me) : null;
    if ($mine) {
        $options[] = ['id' => $me, 'label' => 'Me (' . $mine['name'] . ')'];
    }
    $options[] = ['id' => 0, 'label' => 'Everyone'];

    foreach (users_all() as $user) {
        if ((int)$user['id'] === $me) {
            continue;
        }
        $options[] = ['id' => (int)$user['id'], 'label' => (string)$user['name']];
    }
    return $options;
}

function user_get(int $id): ?array
{
    if ($id <= 0 || !db_ready()) {
        return null;
    }
    $row = db_row('SELECT * FROM users WHERE id = ?', [$id]);
    return $row ? user_from_row($row) : null;
}

/**
 * A name to show for a user id. Unknown ids still render, so a firing whose
 * user has been removed never goes blank.
 */
function user_name($id, string $fallback = 'Unassigned'): string
{
    $id = (int)$id;
    if ($id <= 0) {
        return $fallback;
    }
    $user = user_get($id);
    return $user ? (string)$user['name'] : 'user ' . $id . ' (removed)';
}

function current_user_id(): int
{
    $user = current_user();
    return $user ? (int)$user['id'] : 0;
}

function user_by_sub(string $sub): ?array
{
    if (!db_ready()) {
        return null;
    }
    $row = db_row('SELECT * FROM users WHERE google_sub = ?', [$sub]);
    return $row ? user_from_row($row) : null;
}

/** Create or refresh the profile for a set of Google claims. */
function user_upsert(array $claims): array
{
    $sub   = (string)($claims['sub'] ?? '');
    $email = strtolower((string)($claims['email'] ?? ''));
    $name  = (string)($claims['name'] ?? $email);
    $now   = db_datetime(now());

    $existing = user_by_sub($sub);
    if ($existing) {
        db_query(
            'UPDATE users SET email = ?, name = ?, picture = ?, last_login = ? WHERE google_sub = ?',
            [$email, $name, (string)($claims['picture'] ?? ''), $now, $sub]
        );
    } else {
        db_query(
            'INSERT INTO users (google_sub, email, name, picture, created_at, last_login)
                  VALUES (?, ?, ?, ?, ?, ?)',
            [$sub, $email, $name, (string)($claims['picture'] ?? ''), $now, $now]
        );
    }

    return user_by_sub($sub) ?? [
        'sub' => $sub, 'email' => $email, 'name' => $name, 'picture' => '',
        'phone' => '', 'sms_opt_in' => false, 'sms_asked' => false,
        'created_at' => '', 'last_login' => '',
    ];
}

/**
 * Record the answer to the reminders question. Saving it either way is the
 * point — a "no" has to stick, or the app asks again every time you sign in.
 */
function user_set_reminders(string $sub, string $phone, bool $optIn): bool
{
    return db_query(
        'UPDATE users SET phone = ?, sms_opt_in = ?, sms_asked = 1 WHERE google_sub = ?',
        [trim($phone), $optIn ? 1 : 0, $sub]
    ) !== null;
}
