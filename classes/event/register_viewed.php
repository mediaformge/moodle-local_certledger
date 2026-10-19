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
 * Event: the register has been viewed.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_certledger\event;

/**
 * The register has been viewed. Logged because the register contains personal data.
 */
class register_viewed extends \core\event\base {
    /**
     * Initialises the event.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Returns the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventregisterviewed', 'local_certledger');
    }

    /**
     * Returns the event description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' viewed the certificate ledger.";
    }

    /**
     * Returns the register URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/certledger/index.php');
    }

    /**
     * The register is not part of course backups.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
