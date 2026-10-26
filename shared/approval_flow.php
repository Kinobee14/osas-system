<?php
/**
 * shared/approval_flow.php
 *
 * The approval SEQUENCE for a reservation (replaces the old "3 votes" rule):
 *
 *   Step 1  Administration  ┐ BOTH must approve first (either order)
 *   Step 2  Academic Affairs ┘
 *   Step 3  OSAS (super admin) — makes the FINAL decision (approve, or decline with a reason),
 *           only after Administration AND Academic approved
 *
 * - A decline by any of the three declines the reservation (a reason is required).
 * - OSAS cannot see/act on a request until Administration and Academic approved it.
 * - The decisions are still stored in `reservation_votes` (one row per admin), so
 *   messages, e-signatures and the student's notifications keep working.
 */
require_once __DIR__ . '/esignatures.php';

const FLOW_FIRST = ['admin', 'academic'];   // reviewers before OSAS
const FLOW_LABEL = ['admin' => 'Administration', 'academic' => 'Academic Affairs', 'osas' => 'OSAS'];

/** 'admin' | 'academic' | 'osas' | null for an admin account (same recognition as the e-signature slots). */
function flow_role(?array $admin): ?string {
    $slot = esig_slot_for_admin($admin);
    return ['approver1' => 'osas', 'approver2' => 'academic', 'noted' => 'admin'][$slot] ?? null;
}

/** OSAS is the super admin: the only account allowed on the OSAS-only pages and actions. */
function admin_is_osas(?array $admin): bool { return flow_role($admin) === 'osas'; }

/**
 * Where a reservation is in the sequence.
 * Returns ['steps' => [role => ['state' => approved|declined|pending|waiting, 'by','at','reason']],
 *          'stage' => review|osas|approved|declined, 'waiting_for' => text]
 */
function flow_state(PDO $pdo, int $reservationId): array {
    $st = $pdo->prepare("SELECT x.vote, x.reason, x.voted_at, a.username, a.office, a.display_name
                           FROM reservation_votes x JOIN admins a ON a.id = x.admin_id
                          WHERE x.reservation_id = ? ORDER BY x.voted_at, x.id");
    $st->execute([$reservationId]);
    $steps = [];
    foreach (['admin', 'academic', 'osas'] as $r) $steps[$r] = ['state' => 'pending', 'by' => null, 'at' => null, 'reason' => null];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $role = flow_role($v);
        if (!$role) continue;
        $steps[$role] = ['state' => $v['vote'] === 'approve' ? 'approved' : 'declined', 'by' => $v['display_name'], 'at' => $v['voted_at'], 'reason' => $v['reason']];
    }
    $firstDone = $steps['admin']['state'] === 'approved' && $steps['academic']['state'] === 'approved';
    if ($steps['osas']['state'] === 'pending' && !$firstDone) $steps['osas']['state'] = 'waiting';

    $declined = false;
    foreach ($steps as $s) if ($s['state'] === 'declined') $declined = true;
    if ($declined) $stage = 'declined';
    elseif ($steps['osas']['state'] === 'approved') $stage = 'approved';
    elseif ($firstDone) $stage = 'osas';
    else $stage = 'review';

    $need = [];
    foreach (FLOW_FIRST as $r) if ($steps[$r]['state'] === 'pending') $need[] = FLOW_LABEL[$r];
    // The reviewer who wasn't needed is shown as such once the request has moved on to OSAS.
    if (in_array($stage, ['osas', 'approved'], true)) {
        foreach (FLOW_FIRST as $r) if ($steps[$r]['state'] === 'pending') $steps[$r]['state'] = 'skipped';
    }
    $waiting = $stage === 'review' ? 'Waiting for ' . implode(' and ', $need)
             : ($stage === 'osas' ? 'Waiting for OSAS final approval' : '');
    return ['steps' => $steps, 'stage' => $stage, 'waiting_for' => $waiting];
}

/** May THIS role cast a vote right now? */
function flow_can_act(?string $role, array $flow): bool {
    if (!$role || !in_array($flow['stage'], ['review', 'osas'], true)) return false;
    if ($flow['steps'][$role]['state'] !== 'pending') return false;     // already decided / not needed / waiting
    return $role === 'osas' ? $flow['stage'] === 'osas' : $flow['stage'] === 'review';
}

/** Status the reservation should have given the recorded decisions. */
function flow_status(array $flow): string {
    return ['approved' => 'approved', 'declined' => 'declined'][$flow['stage']] ?? 'pending';
}
