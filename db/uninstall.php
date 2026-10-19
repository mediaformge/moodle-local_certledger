<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Pre-uninstall steps.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Saves the ledger as a CSV file in moodledata before its table is dropped (it is the only link between the
 * printed numbers and the people; the codes stay on the certificates) and removes the technical block role,
 * which Moodle's uninstall does not touch.
 *
 * @return bool
 */
function xmldb_local_certledger_uninstall() {
    local_certledger_export_register();
    local_certledger_remove_block_role();
    return true;
}

/**
 * Removes the role that blocks re-issues, with its assignments.
 */
function local_certledger_remove_block_role(): void {
    global $DB;

    if ($roleid = $DB->get_field('role', 'id', ['shortname' => 'certledger_blocked'])) {
        role_unassign_all(['roleid' => $roleid]);
        delete_role($roleid);
    }
}

/**
 * Writes every ledger record to moodledata/local_certledger/ledger-<date>.csv.
 *
 * @return string|null the file written, null if the ledger is empty
 */
function local_certledger_export_register(): ?string {
    global $CFG, $DB;

    if (!$DB->get_manager()->table_exists('local_certledger') || !$DB->count_records('local_certledger')) {
        return null;
    }
    $dir = $CFG->dataroot . '/local_certledger';
    check_dir_exists($dir);
    $file = $dir . '/ledger-' . date('Ymd-His') . '.csv';
    $handle = fopen($file, 'w');
    $records = $DB->get_recordset('local_certledger', null, 'scope, seq');
    $header = false;
    foreach ($records as $record) {
        if (!$header) {
            fputcsv($handle, array_keys((array)$record), ',', '"', '');
            $header = true;
        }
        fputcsv($handle, array_values((array)$record), ',', '"', '');
    }
    $records->close();
    fclose($handle);
    return $file;
}
