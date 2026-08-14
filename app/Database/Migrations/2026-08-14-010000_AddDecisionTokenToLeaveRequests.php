<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * One-click approve / reject straight from the administrator's email.
 *
 * The link has to work for somebody who is not signed in — that is the whole
 * point — so the URL itself is the credential. Stored the way `api_keys` stores
 * a personal access token: **only the sha256 hash**, never the plaintext, so a
 * leaked database backup cannot be replayed into an approval.
 *
 *   decision_token_hash        sha256 of the emailed token, NULL once spent
 *   decision_token_expires_at  a link that outlives its usefulness is only a risk
 *
 * On `leave_requests` rather than in a table of its own: exactly one live link
 * per request, and the request's own `status` already retires it — a decided
 * request cannot be decided again, whatever anybody clicks.
 *
 * Guarded, so it is safe on a live database and re-runnable.
 */
class AddDecisionTokenToLeaveRequests extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('leave_requests') || $db->fieldExists('decision_token_hash', 'leave_requests')) {
            return;
        }

        $this->forge->addColumn('leave_requests', [
            'decision_token_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'review_note',
                'comment'    => 'sha256 of the emailed one-click decision token; NULL once used or expired',
            ],
            'decision_token_expires_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'decision_token_hash',
            ],
        ]);
    }

    public function down(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('leave_requests')) {
            return;
        }

        foreach (['decision_token_hash', 'decision_token_expires_at'] as $column) {
            if ($db->fieldExists($column, 'leave_requests')) {
                $this->forge->dropColumn('leave_requests', $column);
            }
        }
    }
}
