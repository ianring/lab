<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/sms.php';
require __DIR__ . '/lib/auth.php';

auth_session_start();

$config = auth_config();
$notice = '';
$diagnostics = [];

/* Already signed in? Nothing to do here. */
if (current_user() && q('code') === null) {
    redirect('index.php');
}

/* ---------------- coming back from Google ---------------- */

if (q('error') !== null) {
    $notice = 'Google cancelled the sign-in: ' . (string)q('error') . '.';
} elseif (q('code') !== null) {
    $attempt = auth_claim_attempt((string)q('state', ''));
    if (!$attempt) {
        $notice = 'That sign-in did not match the one this browser started. Try again.';
        // Guessing at this from the outside is miserable, so say what happened.
        $diagnostics = auth_session_diagnostics();
    } else {
        [$ok, $tokens] = auth_exchange_code((string)q('code'));

        if (!$ok) {
            $notice = (string)$tokens;
        } else {
            $claims = auth_id_token_claims((string)$tokens['id_token']);

            if (!$claims || empty($claims['sub'])) {
                $notice = 'Google’s identity token could not be read.';
            } elseif (($claims['nonce'] ?? '') !== ($attempt['nonce'] ?? '')) {
                $notice = 'That sign-in could not be verified. Try again.';
            } elseif (empty($claims['email_verified'])) {
                $notice = 'That Google account has no verified email address.';
            } else {
                // Signing in is enough. There is no allowlist.
                $user = user_upsert($claims);
                session_regenerate_id(true);
                $_SESSION['user_sub'] = $user['sub'];

                $next = (string)($_SESSION['after_login'] ?? 'index.php');
                unset($_SESSION['after_login']);
                // Only ever bounce to somewhere inside this app.
                if ($next === '' || strpos($next, '//') !== false || strpos($next, ':') !== false) {
                    $next = 'index.php';
                }
                // Ask about text reminders once, the first time somebody signs
                // in. The answer is stored either way, so it is asked only once.
                redirect(sms_available() && empty($user['sms_asked']) ? 'profile.php?ask=1#reminders' : $next);
            }
        }
    }
}

/* ---------------- the sign-in page ---------------- */

$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));
auth_remember_attempt($state, $nonce);

$pageTitle = 'Sign in';
$active    = '';
$hideNav   = true;
require __DIR__ . '/partials/header.php';
?>

<?php if ($notice !== ''): ?>
    <p class="alert alert--warn"><?= e($notice) ?></p>
<?php endif; ?>

<?php if (!empty($diagnostics)): ?>
    <details class="whole-schedule signin-why">
        <summary>
            <span class="when-closed">Why did that happen?</span>
            <span class="when-open">Hide the details</span>
        </summary>
        <dl class="manifest">
            <?php foreach ($diagnostics as $label => $finding): ?>
                <div><dt><?= e($label) ?></dt><dd><?= e($finding) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <p class="hint">A sign-in is only accepted if the browser comes back with the same session it
            left with. Every line above is about that session.</p>
    </details>
<?php endif; ?>

<section class="card signin">
    <h2><?= e(APP_NAME) ?></h2>
    <p class="hint">Firing schedules, checklists and the log for the Waterloo Potter&rsquo;s Workshop electric kilns.</p>

    <?php $dbProblem = store_diagnose(); ?>
    <?php if ($dbProblem !== ''): ?>
        <p class="alert alert--warn"><?= e($dbProblem) ?></p>
        <p class="hint">Signing in needs the database, because that is where profiles live.
            <a href="install.php">Open the installer</a>.</p>
    <?php elseif ($config['configured']): ?>
        <a class="btn btn--primary signin-google" href="<?= e(auth_authorize_url($state, $nonce)) ?>">
            Sign in with Google
        </a>
        <p class="hint">Any Google account will do.</p>
    <?php else: ?>
        <p class="alert alert--warn"><?= e($config['error']) ?></p>
        <h3 class="phase">Setting this up</h3>
        <ol class="setup-steps">
            <li>Create an OAuth client at
                <code>console.cloud.google.com/apis/credentials</code>, type <em>Web application</em>.</li>
            <li>Put this exact address into <em>Authorised redirect URIs</em>:
                <code class="redirect-uri"><?= e(auth_redirect_uri()) ?></code></li>
            <li>Copy <code>config/auth.example.json</code> to <code>config/auth.json</code> on the server
                and paste in the client ID and secret.</li>
        </ol>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
