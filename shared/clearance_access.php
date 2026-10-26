<?php
/**
 * shared/clearance_access.php
 * Which admin accounts may download the Activity Clearance.
 * Keyed on the (non-editable) username, not the office/display name,
 * because admins can rename those from Settings.
 */
function admin_can_download_clearance(?array $admin): bool {
    $blocked = ['admin', 'academic'];
    return $admin && !in_array(strtolower((string)($admin['username'] ?? '')), $blocked, true);
}
