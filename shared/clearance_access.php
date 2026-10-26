<?php
/**
 * shared/clearance_access.php
 * Only the OSAS (super admin) account may generate / download the Activity Clearance.
 * Administration and Academic Affairs no longer can.
 */
require_once __DIR__ . '/approval_flow.php';
function admin_can_download_clearance(?array $admin): bool {
    return admin_is_osas($admin);
}
