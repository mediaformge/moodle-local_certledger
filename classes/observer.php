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
 * Observers of the mod_customcert events.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_certledger;

/**
 * Numbers the certificates as soon as they are issued and tracks their deletion.
 *
 * The observers never throw: issuing or deleting a certificate must not fail because of the register.
 * Whatever they cannot do is picked up by the scheduled reconciliation.
 */
class observer {
    /**
     * A certificate has been issued: number it before mod_customcert generates the PDF.
     *
     * @param \core\event\base $event \mod_customcert\event\issue_created
     */
    public static function issue_created(\core\event\base $event): void {
        global $DB;

        try {
            if ($DB->is_transaction_started()) {
                // Numbering inside someone else's transaction could roll back their work: leave it to the scheduled task.
                // Known limit: a PDF downloaded before the task runs carries the random code of mod_customcert; the
                // reconciliation then replaces that code, and the register keeps it in origcode for reference.
                self::warn('numbering of issue ' . $event->objectid . ' deferred to the scheduled task: inside a transaction');
                return;
            }
            registry::register_issue((int)$event->objectid);
        } catch (\Throwable $e) {
            self::warn('numbering of issue ' . $event->objectid . ' deferred to the scheduled task: ' . $e->getMessage());
        }
    }

    /**
     * A certificate has been deleted from the platform.
     *
     * @param \core\event\base $event \mod_customcert\event\issue_deleted
     */
    public static function issue_deleted(\core\event\base $event): void {
        try {
            registry::mark_removed((int)$event->objectid, (int)$event->userid, (int)$event->timecreated);
        } catch (\Throwable $e) {
            self::warn('could not mark issue ' . $event->objectid . ' as removed: ' . $e->getMessage());
        }
    }

    /**
     * Reports a problem to developers. Administrators see its effect on the ledger page, which warns about the
     * certificates waiting for a number and about the errors of the last reconciliation.
     *
     * @param string $message
     */
    private static function warn(string $message): void {
        debugging('local_certledger: ' . $message, DEBUG_NORMAL);
    }
}
