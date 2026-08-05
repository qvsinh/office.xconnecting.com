<?php

namespace App\Libraries;

use App\Models\EmailModel;
use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Config\Mail;
use Throwable;

/**
 * Sends mail through Amazon SES, and records every attempt in `emails`.
 *
 * Modelled on `cars.akamai.vn`'s `AwsLib`, keeping the parts that matter and
 * dropping its tenant plumbing (this app has one tenant).
 *
 * ## Credentials come from `mail.aws.*`, never from `AWS_ACCESS_KEY_ID`
 *
 * This is the one rule to not "simplify" later, and the reference project learned
 * it the hard way. The SDK's default credential provider chain reads the generic
 * `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_REGION` environment
 * variables, and when they are present they **shadow the EC2 instance role for
 * every client the SDK builds** — so putting SES keys there breaks anything else
 * that expected the instance role (S3 uploads start returning Access Denied).
 * Dedicated `mail.aws.*` keys, passed explicitly below, keep SES self-contained.
 *
 * ## Sending never breaks the thing that triggered it
 *
 * `send()` returns a status and swallows every failure into the log. A check-in
 * must not fail because SES was unreachable — the same rule `AccessLogger`
 * follows.
 */
class Mailer
{
    /**
     * Row not attempted (sending switched off, or not configured).
     */
    public const STATUS_PENDING = 0;

    /**
     * SES accepted the message and returned a MessageId.
     */
    public const STATUS_SENT = 1;

    /**
     * Attempted and refused, or never attempted because of bad config.
     */
    public const STATUS_FAILED = 2;

    private Mail $config;
    private ?SesClient $client = null;

    public function __construct(?Mail $config = null)
    {
        $this->config = $config ?? config('Mail');
    }

    /**
     * Send one HTML message. Returns one of the STATUS_* constants.
     *
     * `$name` is a short label for the email log ("attendance.checkin"), so the
     * admin list can be filtered by what produced the message.
     */
    public function send(string $to, string $subject, string $htmlBody, string $name = ''): int
    {
        $to = trim($to);

        if ($to === '' || ! $this->isDeliverable($to)) {
            $this->log($to, $subject, $htmlBody, self::STATUS_FAILED, $name, 'No valid recipient.');

            return self::STATUS_FAILED;
        }

        // Switched off: log it as pending rather than failed. Nothing is wrong —
        // somebody chose not to send, and calling that a failure makes the admin
        // list useless for spotting real problems.
        if (! $this->config->enabled()) {
            $this->log($to, $subject, $htmlBody, self::STATUS_PENDING, $name, 'mail.sending_enable is off.');

            return self::STATUS_PENDING;
        }

        if (! $this->config->configured()) {
            $this->log($to, $subject, $htmlBody, self::STATUS_FAILED, $name, 'SES is not configured (needs mail.aws.access_key_id, .secret_access_key and .region).');

            return self::STATUS_FAILED;
        }

        try {
            $result = $this->client()->sendEmail([
                'Destination' => ['ToAddresses' => [$to]],
                'Source'      => $this->source(),
                'Message'     => [
                    'Subject' => ['Charset' => 'UTF-8', 'Data' => $subject],
                    'Body'    => ['Html' => ['Charset' => 'UTF-8', 'Data' => $htmlBody]],
                ],
            ]);

            $messageId = (string) ($result['MessageId'] ?? '');

            if ($messageId === '') {
                // A 200 with no MessageId is not a delivery; treat it as failed
                // rather than quietly claiming success.
                $this->log($to, $subject, $htmlBody, self::STATUS_FAILED, $name, 'SES returned no MessageId.');

                return self::STATUS_FAILED;
            }

            $this->log($to, $subject, $htmlBody, self::STATUS_SENT, $name, '');

            return self::STATUS_SENT;
        } catch (AwsException $e) {
            $this->log($to, $subject, $htmlBody, self::STATUS_FAILED, $name, $e->getAwsErrorMessage() ?? $e->getMessage());

            return self::STATUS_FAILED;
        } catch (Throwable $e) {
            // A missing SDK, a DNS failure, a bad region string — all of it lands
            // here rather than reaching the caller.
            $this->log($to, $subject, $htmlBody, self::STATUS_FAILED, $name, $e->getMessage());

            return self::STATUS_FAILED;
        }
    }

    /**
     * Built lazily: constructing a `SesClient` validates the region, so a mailer
     * that is never used (sending off) must not blow up on construction.
     */
    private function client(): SesClient
    {
        return $this->client ??= new SesClient([
            'version'     => 'latest',
            'region'      => trim($this->config->aws['region']),
            'credentials' => [
                'key'    => trim($this->config->aws['access_key_id']),
                'secret' => trim($this->config->aws['secret_access_key']),
            ],
        ]);
    }

    /**
     * RFC 5322 `From`. A display name containing any of `",;<>@[]\` has to be
     * quoted, and quotes inside it escaped, or SES rejects the whole message.
     */
    private function source(): string
    {
        $email = trim($this->config->sender['email']);
        $name  = trim($this->config->sender['name']);

        if ($name === '') {
            return $email;
        }

        if (preg_match('/[",;<>@\[\]\\\\]/', $name) === 1) {
            $name = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
        }

        return $name . ' <' . $email . '>';
    }

    /**
     * A recipient SES could plausibly accept. Cheap guard so a typo in a setting
     * shows up in the log instead of as an API error.
     */
    private function isDeliverable(string $to): bool
    {
        return filter_var($to, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Record the attempt. Never throws: losing a log line must not turn into a
     * failed check-in.
     */
    private function log(string $to, string $subject, string $htmlBody, int $status, string $name, string $error): void
    {
        try {
            model(EmailModel::class)->insert([
                'id'            => bin2hex(random_bytes(16)),
                'from_email'    => trim($this->config->sender['email']) ?: 'no-reply@localhost',
                'to_email'      => $to,
                'subject'       => $subject,
                'content'       => $htmlBody,
                'status'        => $status,
                'name'          => $name,
                'error_message' => $error,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Could not record the sent email ({name} -> {to}): {msg}', [
                'name' => $name,
                'to'   => $to,
                'msg'  => $e->getMessage(),
            ]);
        }
    }
}
