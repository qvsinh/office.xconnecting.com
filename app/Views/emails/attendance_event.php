<?php
/**
 * Check-in / check-out notification to the office administrator.
 *
 * Inline styles and a table layout on purpose — email clients strip <style> and
 * have no useful flexbox, so the shared Tailwind stylesheet is no help here.
 * Every value is escaped: an employee's name reaches this template from the
 * database, and a mail client will happily render injected markup.
 *
 * @var string $employee
 * @var string $email
 * @var string $workDate
 * @var string $checkinAt
 * @var string $checkoutAt
 * @var string $at         the time this event happened
 * @var string $timezone
 * @var bool   $isCheckin
 *
 * No footer: it used to name `mail_notify_admin_recipient` and `mail_notify_attendance`,
 * which is internal configuration leaking into somebody's inbox. Where to change
 * the recipient belongs in DEPLOYMENT.md and on the settings screen, not in every
 * notification.
 */
$accent = $isCheckin ? '#346538' : '#1F6C9F';
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title><?= esc($isCheckin ? 'Check-in' : 'Check-out') ?></title></head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111111;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;border-radius:8px;">
        <tr>
            <td style="padding:20px 24px;border-bottom:1px solid #eaeaea;">
                <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?= esc($accent, 'attr') ?>1a;color:<?= esc($accent, 'attr') ?>;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">
                    <?= esc($isCheckin ? 'Checked in' : 'Checked out') ?>
                </span>
                <h1 style="margin:12px 0 0;font-size:18px;font-weight:600;"><?= esc($employee) ?></h1>
                <?php if ($email !== '') : ?>
                    <p style="margin:2px 0 0;font-size:13px;color:#5c5955;"><?= esc($email) ?></p>
                <?php endif ?>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;">
                    <tr>
                        <td style="padding:6px 0;color:#5c5955;">Date</td>
                        <td style="padding:6px 0;text-align:right;font-weight:500;"><?= esc($workDate) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:#5c5955;">Checked in</td>
                        <td style="padding:6px 0;text-align:right;font-weight:500;"><?= esc($checkinAt) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:#5c5955;">Checked out</td>
                        <td style="padding:6px 0;text-align:right;font-weight:500;"><?= esc($checkoutAt) ?></td>
                    </tr>
                </table>
                <p style="margin:16px 0 0;font-size:12px;color:#5c5955;">
                    Times shown in <?= esc($timezone) ?>.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
