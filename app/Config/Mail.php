<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Outbound mail settings, all under one `mail.` group in `.env`.
 *
 * Amazon SES only. The SparkPost settings that came with the starter are gone —
 * no library was installed for it and no code referenced it, so they were three
 * values that could never do anything.
 *
 * CI4 maps `mail.<property>` onto the properties below, and `mail.<property>.<key>`
 * into the array ones — the same mechanism `database.default.hostname` uses. So:
 *
 *   mail.sending_enable           -> $sending_enable
 *   mail.sender.email             -> $sender['email']
 *   mail.aws.access_key_id        -> $aws['access_key_id']
 *
 * Property names stay snake_case to match the env keys exactly — CI4 matches them
 * character for character, and `Config\Auth` already follows this convention.
 *
 * ## Nothing sends mail yet
 *
 * These values are configuration without a consumer: no code in `app/` reads them,
 * no AWS SDK is installed, and `Config\Email::$protocol` is still PHP's `mail`
 * default with nothing calling it. Attendance reminders are delivered
 * in-app only (`NotificationModel`). See DEPLOYMENT.md, "Email send pipeline".
 *
 * This class exists so the settings are named, typed and grouped for when the
 * dispatch is built — not because mail works today.
 */
class Mail extends BaseConfig
{
    /**
     * Master switch, read through `enabled()`.
     *
     * A string rather than a bool because CI4 only coerces the literals `true`
     * and `false` from `.env` — a human-written `no` (which is what this project's
     * `.env` has held all along) would hit a TypeError on a `bool` property.
     */
    public string $sending_enable = 'false';

    /**
     * @var array{name: string, email: string}
     */
    public array $sender = [
        'name'  => '',
        'email' => '',
    ];

    /**
     * Where replies and problem reports should go.
     *
     * @var array{email: string}
     */
    public array $supporter = [
        'email' => '',
    ];

    /**
     * Amazon SES.
     *
     * `region` has no default on purpose: SES is regional, and a wrong region
     * fails in a confusing way (credentials look valid, nothing arrives). Better
     * to leave it empty so it is obviously unset than to guess one.
     *
     * @var array{access_key_id: string, secret_access_key: string, region: string}
     */
    public array $aws = [
        'access_key_id'     => '',
        'secret_access_key' => '',
        'region'            => '',
    ];

    /**
     * Is sending switched on?
     *
     * Tolerant of the spellings people actually write in a `.env` — `no`, `off`,
     * `0`, `false` all mean off.
     */
    public function enabled(): bool
    {
        return filter_var($this->sending_enable, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Are the credentials for the selected provider actually present?
     *
     * Lets a caller (or a system-info page) distinguish "switched off" from
     * "switched on but misconfigured", which are very different problems.
     */
    public function configured(): bool
    {
        return trim($this->aws['access_key_id']) !== ''
            && trim($this->aws['secret_access_key']) !== ''
            && trim($this->aws['region']) !== '';
    }
}
