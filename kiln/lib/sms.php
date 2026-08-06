<?php
declare(strict_types=1);

/**
 * Sending a text message.
 *
 * Credentials are constants in config/sms.php, which is gitignored. The
 * provider can be set to 'log' to write messages to a file instead of sending
 * them, which is how you should run this until the wording and the timing look
 * right — a reminder that arrives at 3 am is worse than none.
 */

function sms_config_loaded(): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    if (is_file(SMS_CONFIG_FILE)) {
        require_once SMS_CONFIG_FILE;
    }
    $loaded = defined('SMS_PROVIDER');
    return $loaded;
}

function sms_provider(): string
{
    return sms_config_loaded() ? (string)SMS_PROVIDER : 'off';
}

/**
 * Should the app offer text reminders at all?
 *
 * FEATURE_SMS in lib/config.php is the switch, and it is deliberately not
 * inferred from whether config/sms.php happens to exist — "hidden" has to mean
 * hidden, whatever is lying around on the server. With it off there is no phone
 * field, no opt-in, no test button and no question at sign-in.
 */
function sms_available(): bool
{
    if (!defined('FEATURE_SMS') || !FEATURE_SMS) {
        return false;
    }
    return sms_config_loaded() && sms_provider() !== 'off';
}

/**
 * The username and password for Twilio's basic auth.
 *
 * An API key is a pair of its own — the SK… SID as the username, its secret as
 * the password — while the account SID still identifies the account in the URL.
 * Falling back to the account SID and Auth Token keeps the simpler setup
 * working.
 *
 * @return array [user, password] — either may be '' if nothing is configured.
 */
function sms_credentials(): array
{
    if (defined('SMS_KEY_SID') && SMS_KEY_SID !== '' && defined('SMS_KEY_SECRET') && SMS_KEY_SECRET !== '') {
        return [SMS_KEY_SID, SMS_KEY_SECRET];
    }
    return [
        defined('SMS_SID') ? SMS_SID : '',
        defined('SMS_TOKEN') ? SMS_TOKEN : '',
    ];
}

/** Is sending set up well enough to bother trying? */
function sms_ready(): bool
{
    if (!sms_config_loaded()) {
        return false;
    }
    if (sms_provider() === 'log') {
        return true;
    }
    if (sms_provider() !== 'twilio') {
        return false;
    }
    [$user, $password] = sms_credentials();
    return defined('SMS_SID') && SMS_SID !== '' && $user !== '' && $password !== ''
        && defined('SMS_FROM') && SMS_FROM !== '';
}

/**
 * Tidy a number into E.164, which is what Twilio wants. North American
 * 10-digit numbers get a +1; anything already starting with + is left alone.
 * Returns '' if it cannot be made sense of.
 */
function sms_normalise_number(string $number): string
{
    $number = trim($number);
    if ($number === '') {
        return '';
    }
    $plus   = strpos($number, '+') === 0;
    $digits = preg_replace('/\D+/', '', $number) ?? '';

    if ($plus) {
        return strlen($digits) >= 8 ? '+' . $digits : '';
    }
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '1') {
        return '+' . $digits;
    }
    return strlen($digits) >= 8 ? '+' . $digits : '';
}

/**
 * Send one message.
 *
 * @return array [ok, detail] — detail is the provider's id, or why it failed.
 */
function sms_send(string $to, string $body): array
{
    if (!sms_config_loaded()) {
        return [false, 'No config/sms.php on the server.'];
    }

    $number = sms_normalise_number($to);
    if ($number === '') {
        return [false, 'Not a usable phone number: ' . $to];
    }

    if (sms_provider() === 'off') {
        return [false, 'Sending is switched off in config/sms.php.'];
    }

    if (sms_provider() === 'log') {
        $line = now()->format('c') . "\t" . $number . "\t" . str_replace("\n", ' / ', $body) . "\n";
        $path = dirname(DATA_FILE) . '/sms.log';
        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            return [false, 'Could not write ' . basename($path)];
        }
        return [true, 'logged'];
    }

    if (!sms_ready()) {
        return [false, 'config/sms.php is missing the account SID, the credentials or the from-number.'];
    }

    // The account SID always names the account in the path, even when an API
    // key is what actually authenticates.
    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(SMS_SID) . '/Messages.json';
    $post = http_build_query(['From' => SMS_FROM, 'To' => $number, 'Body' => $body]);
    [$authUser, $authPassword] = sms_credentials();

    if (!function_exists('curl_init')) {
        return [false, 'cURL is not available, so Twilio cannot be reached.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERPWD        => $authUser . ':' . $authPassword,
    ]);
    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [false, 'Could not reach Twilio: ' . $error];
    }
    $data = json_decode((string)$response, true);
    if ($status >= 200 && $status < 300) {
        return [true, (string)($data['sid'] ?? 'sent')];
    }
    return [false, 'Twilio said ' . $status . ': ' . (string)($data['message'] ?? substr((string)$response, 0, 160))];
}
