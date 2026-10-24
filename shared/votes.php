<?php
// Single source of truth for the admin voting rule (used by admin/api/reservations.php
// and the student page, so both always show the same numbers).

/** Approvals needed to approve a reservation: 3 (capped at the number of admin accounts so it can always be reached). */
function approvals_needed(PDO $pdo): int {
    $total = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $want  = (int)(getenv('APPROVALS_REQUIRED') ?: 3);
    return max(1, min($want, max($total, 1)));
}

/** Declines needed to decline: simple majority of all admin accounts (3 admins -> 2). */
function declines_needed(PDO $pdo): int {
    $total = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    return intdiv(max($total, 1), 2) + 1;
}

/** Every declining admin's reason, joined for the student (stored in reservations.decline_reason). */
function decline_reason_text(PDO $pdo, int $reservationId): string {
    $rs = $pdo->prepare("SELECT a.office, a.display_name, x.reason
                           FROM reservation_votes x JOIN admins a ON a.id = x.admin_id
                          WHERE x.reservation_id = ? AND x.vote = 'decline' ORDER BY x.voted_at");
    $rs->execute([$reservationId]);
    $parts = [];
    foreach ($rs->fetchAll() as $row) {
        $office = $row['office'] && $row['office'] !== 'Admin' ? $row['office'] : $row['display_name'];
        $parts[] = $office . ': ' . ($row['reason'] ?: 'No reason given');
    }
    return implode("\n", $parts);
}
