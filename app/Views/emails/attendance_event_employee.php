<?php
/**
 * The employee's own copy of a check-in / check-out.
 *
 * A separate template from `attendance_event.php` on purpose. The admin's copy
 * reports *about* somebody ("Watched Person checked in"); this one is a receipt
 * addressed *to* them — second person, and it says plainly that nothing is
 * expected in reply. Reusing one template for both audiences would have meant
 * conditionals in the markup and a message that reads oddly to whichever side was
 * not the primary reader.
 *
 * Inline styles and a table layout, like the admin copy: mail clients strip
 * `<style>` and have no useful flexbox, so the Tailwind sheet is no help.
 *
 * No configuration names anywhere in the body — those belong on the settings
 * screen, not in somebody's inbox.
 *
 * @var string $name       the employee's display name
 * @var string $workDate
 * @var string $checkinAt
 * @var string $checkoutAt
 * @var string $at         the time of this event
 * @var string $timezone
 * @var bool   $isCheckin
 */
$accent = $isCheckin ? '#346538' : '#1F6C9F';
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title><?= esc($isCheckin ? 'Check-in recorded' : 'Check-out recorded') ?></title></head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111111;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;border-radius:8px;">
        <tr>
            <td style="padding:20px 24px;border-bottom:1px solid #eaeaea;">
                <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?= esc($accent, 'attr') ?>1a;color:<?= esc($accent, 'attr') ?>;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">
                    <?= esc($isCheckin ? 'Check-in recorded' : 'Check-out recorded') ?>
                </span>
                <h1 style="margin:12px 0 0;font-size:18px;font-weight:600;">
                    <?= esc($isCheckin ? 'You checked in at ' . $at : 'You checked out at ' . $at) ?>
                </h1>
                <p style="margin:4px 0 0;font-size:13px;color:#5c5955;">
                    <?= esc($name) ?> &middot; <?= esc($workDate) ?>
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;">
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
                    Times shown in <?= esc($timezone) ?>. This is an automatic receipt — no reply is needed.
                    If a time looks wrong, ask your manager to correct it.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
