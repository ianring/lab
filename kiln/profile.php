<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/sms.php';
require __DIR__ . '/lib/auth.php';

require_login();
$user = current_user();

$notice = '';
$sent   = '';

// Send one message right now, to prove the whole path works end to end.
if (sms_available() && $_SERVER['REQUEST_METHOD'] === 'POST' && p('action') === 'test_sms') {
    $phone = trim((string)p('phone', (string)$user['phone']));
    if (sms_normalise_number($phone) === '') {
        $notice = 'Put a phone number in first — there is nowhere to send it.';
    } else {
        // Save the number as typed, so a test is never sent to one number while
        // the reminders would go to another.
        if ($phone !== (string)$user['phone']) {
            user_set_reminders((string)$user['sub'], $phone, (bool)$user['sms_opt_in']);
            $user = current_user();
        }
        [$ok, $detail] = sms_send($phone, 'Hello world! SMS messages are working. — ' . APP_NAME);
        if ($ok && sms_provider() === 'log') {
            $sent = 'Written to data/sms.log — the server is in log mode, so nothing was actually sent. '
                . 'Set SMS_PROVIDER to twilio in config/sms.php to send for real.';
        } elseif ($ok) {
            $sent = 'Sent to ' . sms_normalise_number($phone) . '. It should arrive in a few seconds. ('
                . $detail . ')';
        } else {
            $notice = 'It did not send: ' . $detail;
        }
    }
}

if (sms_available() && $_SERVER['REQUEST_METHOD'] === 'POST' && p('action') === 'reminders') {
    $optIn = p('sms_opt_in') === '1';
    $phone = (string)p('phone', '');

    if ($optIn && sms_normalise_number($phone) === '') {
        $notice = 'That does not look like a phone number. A 10-digit number is fine — 519 555 1234.';
    } else {
        user_set_reminders((string)$user['sub'], $phone, $optIn);
        redirect('profile.php?saved=1');
    }
}

$asking = sms_available() && q('ask') !== null && !$user['sms_asked'];

$pageTitle = 'Profile';
$active    = 'profile';
require __DIR__ . '/partials/header.php';
?>

<?php if (q('saved')): ?>
    <p class="alert alert--ok">Saved.</p>
<?php endif; ?>
<?php if ($notice !== ''): ?>
    <p class="alert alert--warn"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($sent !== ''): ?>
    <p class="alert alert--ok"><?= e($sent) ?></p>
<?php endif; ?>

<?php if ($asking): ?>
    <p class="alert alert--ok">One question before you start &mdash; see below.</p>
<?php endif; ?>

<section class="card">
    <div class="profile-head">
        <?php if (!empty($user['picture'])): ?>
            <img class="avatar avatar--large" src="<?= e($user['picture']) ?>" alt="" referrerpolicy="no-referrer">
        <?php endif; ?>
        <div>
            <h2><?= e($user['name']) ?></h2>
            <p class="hint"><?= e($user['email']) ?></p>
        </div>
    </div>

    <dl class="facts">
        <div><dt>Signed in since</dt><dd><?= e(fmt_day(parse_dt($user['created_at'] ?? ''))) ?></dd></div>
        <div><dt>Last sign-in</dt><dd><?= e(fmt_day(parse_dt($user['last_login'] ?? ''))) ?></dd></div>
    </dl>

    <p class="hint">Firings you start are recorded against this account.
        <a href="users.php">Everyone who has signed in</a>.</p>
</section>

<?php if (sms_available()): ?>
<section class="card" id="reminders">
    <h2>Text reminders</h2>
    <p class="hint">A firing means three trips to the workshop. If you want, the app can text you
        <strong>24 hours before</strong> each one is due &mdash; loading and starting, being back for the
        last two hours, and unloading.</p>

    <form method="post" action="profile.php">
        <input type="hidden" name="action" value="reminders">

        <div class="field">
            <label for="phone">Mobile number</label>
            <input type="tel" name="phone" id="phone" inputmode="tel" autocomplete="tel"
                   placeholder="519 555 1234" value="<?= e($user['phone'] ?? '') ?>">
            <p class="hint">Canadian and US numbers can be typed any way you like.</p>
        </div>

        <label class="check check--inline">
            <input type="checkbox" name="sms_opt_in" value="1" <?= !empty($user['sms_opt_in']) ? 'checked' : '' ?>>
            <span class="box"></span>
            <span class="check-text">Yes, text me reminders before each visit</span>
        </label>

        <p class="hint">Leave it unticked and nothing is sent. You can change your mind here any time.</p>
        <button type="submit" class="btn btn--primary">Save</button>
    </form>

    <form method="post" action="profile.php" class="test-sms">
        <input type="hidden" name="action" value="test_sms">
        <input type="hidden" name="phone" id="test-sms-phone" value="<?= e($user['phone'] ?? '') ?>">
        <button type="submit" class="btn">Send a test message</button>
        <span class="hint">One message, straight away, to check the whole path works.
            You do not have to be opted in.</span>
    </form>

    <?php if (!empty($user['sms_opt_in'])): ?>
        <p class="hint">
            <?php if (!sms_config_loaded()): ?>
                Reminders are on for you, but the server has no <code>config/sms.php</code> yet, so nothing
                can be sent.
            <?php elseif (sms_provider() === 'log'): ?>
                Reminders are on, and the server is in <strong>log</strong> mode &mdash; messages are written
                to <code>data/sms.log</code> rather than sent. Good for checking the wording first.
            <?php elseif (!sms_ready()): ?>
                Reminders are on, but <code>config/sms.php</code> is missing its Twilio credentials.
            <?php else: ?>
                Reminders are on and the server can send them.
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2>Session</h2>
    <p class="hint">Signing out only ends it on this device.</p>
    <p><a class="btn btn--ghost" href="logout.php">Sign out</a></p>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
