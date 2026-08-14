<?php

use App\Libraries\RequestNotifier;

/**
 * The administrator's copy of a leave / late-arrival request.
 *
 * Written *about* somebody — same voice as `attendance_event.php`, and the same
 * inline styles and table layout, because mail clients strip `<style>` and have
 * no useful flexbox.
 *
 * Covers both admin-facing moments: a request arriving and a request being
 * withdrawn. One template rather than two because the audience, the voice and
 * every fact are identical — only the headline verb changes.
 *
 * No configuration names in the body: those belong on the settings screen.
 *
 * @var string $employee
 * @var string $email
 * @var string $what      "Annual leave", "Late arrival (09:30)"
 * @var string $dates
 * @var string $reason
 * @var string $submitted
 * @var string $event       RequestNotifier::EVENT_SUBMITTED | EVENT_WITHDRAWN
 * @var string $decisionUrl base of the one-click link; '' when there is none
 */
$isNew  = $event === RequestNotifier::EVENT_SUBMITTED;
$accent = $isNew ? '#1F6C9F' : '#787774';
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title><?= esc($isNew ? 'Request awaiting approval' : 'Request withdrawn') ?></title></head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111111;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;border-radius:8px;">
        <tr>
            <td style="padding:20px 24px;border-bottom:1px solid #eaeaea;">
                <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?= esc($accent, 'attr') ?>1a;color:<?= esc($accent, 'attr') ?>;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">
                    <?= esc($isNew ? 'Awaiting approval' : 'Withdrawn') ?>
                </span>
                <h1 style="margin:12px 0 0;font-size:18px;font-weight:600;">
                    <?= esc($employee) ?> <?= esc($isNew ? 'requested' : 'withdrew') ?> <?= esc(strtolower($what)) ?>
                </h1>
                <p style="margin:4px 0 0;font-size:13px;color:#5c5955;"><?= esc($email) ?></p>
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
                    <?php if ($submitted !== '') : ?>
                        <tr>
                            <td style="padding:6px 0;color:#5c5955;">Submitted</td>
                            <td style="padding:6px 0;text-align:right;font-weight:500;"><?= esc($submitted) ?></td>
                        </tr>
                    <?php endif ?>
                </table>

                <?php if ($reason !== '') : ?>
                    <p style="margin:16px 0 0;font-size:12px;color:#5c5955;">Reason</p>
                    <p style="margin:2px 0 0;font-size:14px;white-space:pre-line;"><?= esc($reason) ?></p>
                <?php endif ?>

                <?php if ($isNew && $decisionUrl !== '') : ?>
                    <!-- One click from the inbox. Both links land on a
                         confirmation page — neither decides anything on its own,
                         because mail clients and scanners follow links by
                         themselves and an approval nobody made is worse than an
                         extra click. -->
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 0;">
                        <tr>
                            <td style="padding-right:10px;">
                                <a href="<?= esc($decisionUrl . '/approve', 'attr') ?>"
                                   style="display:inline-block;padding:11px 22px;border-radius:8px;background:#346538;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">Approve</a>
                            </td>
                            <td>
                                <a href="<?= esc($decisionUrl . '/reject', 'attr') ?>"
                                   style="display:inline-block;padding:11px 22px;border-radius:8px;background:#ffffff;border:1px solid #E0DEDA;color:#9F2F2D;font-size:14px;font-weight:600;text-decoration:none;">Reject</a>
                            </td>
                        </tr>
                    </table>
                    <p style="margin:10px 0 0;font-size:12px;color:#5c5955;">
                        You will be asked to confirm on the next screen. The links stop working once the request is decided.
                    </p>
                    <p style="margin:16px 0 0;font-size:13px;">
                        <a href="<?= esc(site_url('admin/requests'), 'attr') ?>" style="color:#1F6C9F;">Open the approval queue instead</a>
                    </p>
                <?php elseif ($isNew) : ?>
                    <p style="margin:20px 0 0;font-size:14px;">
                        <a href="<?= esc(site_url('admin/requests'), 'attr') ?>" style="color:#1F6C9F;">Open the approval queue</a>
                    </p>
                <?php else : ?>
                    <p style="margin:20px 0 0;font-size:13px;color:#5c5955;">
                        Nothing to do — the request was withdrawn before a decision.
                    </p>
                <?php endif ?>
            </td>
        </tr>
    </table>
</body>
</html>
