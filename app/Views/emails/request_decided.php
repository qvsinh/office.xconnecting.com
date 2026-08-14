<?php

use App\Models\LeaveRequestModel;

/**
 * The employee's copy of an approval decision.
 *
 * A separate template from `request_admin.php` on purpose, for the same reason
 * the attendance pair is split: this one is addressed *to* the person ("Your
 * annual leave was approved"), the other reports *about* them. One template with
 * conditionals would read oddly to whichever side was not the primary reader.
 *
 * The manager's note is shown whenever there is one — on a rejection it is
 * usually the only thing the reader actually wants.
 *
 * @var string      $name
 * @var string      $what   "Annual leave", "Late arrival (09:30)"
 * @var string      $dates
 * @var string      $reason
 * @var string      $status "approved" | "rejected"
 * @var string|null $note   the reviewer's note, if they left one
 */
$approved = $status === LeaveRequestModel::STATUS_APPROVED;
$accent   = $approved ? '#346538' : '#9F2F2D';
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title><?= esc($approved ? 'Request approved' : 'Request rejected') ?></title></head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111111;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;border-radius:8px;">
        <tr>
            <td style="padding:20px 24px;border-bottom:1px solid #eaeaea;">
                <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?= esc($accent, 'attr') ?>1a;color:<?= esc($accent, 'attr') ?>;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">
                    <?= esc($approved ? 'Approved' : 'Rejected') ?>
                </span>
                <h1 style="margin:12px 0 0;font-size:18px;font-weight:600;">
                    Your <?= esc(strtolower($what)) ?> for <?= esc($dates) ?> was <?= esc($status) ?>
                </h1>
                <p style="margin:4px 0 0;font-size:13px;color:#5c5955;"><?= esc($name) ?></p>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;">
                    <tr>
                        <td style="padding:6px 0;color:#5c5955;">Type</td>
                        <td style="padding:6px 0;text-align:right;font-weight:500;"><?= esc($what) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:#5c5955;">Dates</td>
                        <td style="padding:6px 0;text-align:right;font-weight:500;"><?= esc($dates) ?></td>
                    </tr>
                </table>

                <?php if (($note ?? null) !== null && trim((string) $note) !== '') : ?>
                    <p style="margin:16px 0 0;font-size:12px;color:#5c5955;">Note from your manager</p>
                    <p style="margin:2px 0 0;font-size:14px;white-space:pre-line;"><?= esc((string) $note) ?></p>
                <?php endif ?>

                <?php if (! $approved) : ?>
                    <p style="margin:16px 0 0;font-size:13px;color:#5c5955;">
                        You can submit a new request if the dates change — speak to your manager first.
                    </p>
                <?php endif ?>

                <p style="margin:20px 0 0;font-size:14px;">
                    <a href="<?= esc(site_url('requests'), 'attr') ?>" style="color:#1F6C9F;">See your requests</a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
