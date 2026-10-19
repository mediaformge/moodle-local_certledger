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
 * Scheduled reconciliation of the register.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_certledger\task;

use local_certledger\registry;

/**
 * Keeps the register in line with the issued certificates, see {@see registry::reconcile()}.
 */
class reconcile_task extends \core\task\scheduled_task {
    /**
     * Returns the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskreconcile', 'local_certledger');
    }

    /**
     * Runs the reconciliation.
     */
    public function execute() {
        $result = registry::reconcile();
        mtrace(registry::format_result($result));
        foreach ($result->errors as $error) {
            mtrace('  ' . $error);
        }
        // Shown in the register page until a run without errors.
        set_config('lastrun', time(), 'local_certledger');
        set_config('lasterrors', $result->errors ? json_encode($result->errors) : '', 'local_certledger');
    }
}
