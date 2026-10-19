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
 * Behat data generator.
 *
 * @package    local_certledger
 * @category   test
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Lets the features issue certificates: the following "local_certledger > issues" exist.
 */
class behat_local_certledger_generator extends behat_generator_base {
    /**
     * Returns the entities that can be created.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'issues' => [
                'singular' => 'issue',
                'datagenerator' => 'issue',
                'required' => ['certificate', 'user'],
                'switchids' => ['certificate' => 'customcertid', 'user' => 'userid'],
            ],
        ];
    }

    /**
     * Returns the id of a Custom certificate activity from its name or idnumber.
     *
     * @param string $name
     * @return int
     */
    protected function get_certificate_id(string $name): int {
        return (int)$this->get_cm_by_activity_name('customcert', $name)->instance;
    }
}
