<?php
/**
 * Text-message credentials.
 *
 * Copy this to config/sms.php on the server and fill it in. That file is
 * gitignored — the secret must never be committed.
 *
 *     cp config/sms.example.php config/sms.php
 *
 * SMS_PROVIDER:
 *   'log'     write what would be sent to data/sms.log and send nothing —
 *             start here, and stay here until the wording and timing look right
 *   'twilio'  send for real
 *   'off'     no messages at all
 */

const SMS_PROVIDER = 'log';

/**
 * Your account, from the Twilio console home page. Starts with AC.
 * This identifies the account whatever you authenticate with.
 */
const SMS_SID = '';

/**
 * How to authenticate. Two ways — pick one.
 *
 * 1. An API key (recommended). Console → Account → API keys & tokens →
 *    Create API key, type "Standard". You get an SK… SID and a secret shown
 *    once. A key can be revoked on its own without changing the account
 *    password, and Standard keys can send messages but cannot manage the
 *    account itself.
 */
const SMS_KEY_SID    = '';
const SMS_KEY_SECRET = '';

/**
 * 2. The account Auth Token, from the console home page. Simpler, but it is
 *    the key to the whole account and cannot be scoped. Only used when
 *    SMS_KEY_SID above is left empty.
 */
const SMS_TOKEN = '';

/** The number messages come from, in E.164: +15195551234 */
const SMS_FROM = '';

/**
 * Optional: run cron.php over the web instead of the command line. Leave this
 * empty unless you have to — then only cron.php?key=<this> will run.
 */
const CRON_SECRET = '';
