<?php
// Single source of truth for the signatories printed on the "Permission to
// Conduct Activity / Program" form: the two "Approved by" names/positions and
// the "Noted by" name/position. Admins edit them (Settings page); the student
// form and the admin's form view both read them from here.

/** Values used until an admin saves their own (also if the table isn't imported yet). */
function signatories_defaults(): array {
    return [
        'approver1_name'  => 'DR. RODRIGO M. DANTAY JR.',
        'approver1_title' => 'Vice President, Office of the Student Affairs and Services',
        'approver2_name'  => 'ENGR. WENALD H. LOPEZ',
        'approver2_title' => 'Vice President for Academic Affairs',
        'noted_name'      => 'DR. ELOISA P. MACKAY',
        'noted_title'     => 'Vice President for Administration',
    ];
}

/** Current signatories. Falls back to the defaults if the table/row is missing. */
function get_signatories(PDO $pdo): array {
    $out = signatories_defaults();
    try {
        $row = $pdo->query('SELECT * FROM form_signatories WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out; // migration_form_signatories.sql not imported yet
    }
    if ($row) {
        foreach ($out as $k => $_) {
            if (array_key_exists($k, $row)) $out[$k] = (string) $row[$k];
        }
    }
    return $out;
}

/** Saves the signatories (all six fields). Creates the row if needed. */
function save_signatories(PDO $pdo, array $v): void {
    $keys = array_keys(signatories_defaults());
    $vals = [];
    foreach ($keys as $k) $vals[$k] = mb_substr(trim((string) ($v[$k] ?? '')), 0, 150);
    $pdo->prepare(
        'INSERT INTO form_signatories (id, approver1_name, approver1_title, approver2_name, approver2_title, noted_name, noted_title)
         VALUES (1, :approver1_name, :approver1_title, :approver2_name, :approver2_title, :noted_name, :noted_title)
         ON DUPLICATE KEY UPDATE approver1_name = VALUES(approver1_name), approver1_title = VALUES(approver1_title),
           approver2_name = VALUES(approver2_name), approver2_title = VALUES(approver2_title),
           noted_name = VALUES(noted_name), noted_title = VALUES(noted_title)'
    )->execute($vals);
}
