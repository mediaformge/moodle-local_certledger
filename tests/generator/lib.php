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
 * Test data generator.
 *
 * @package    local_certledger
 * @category   test
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates Custom certificate issues for the tests.
 */
class local_certledger_generator extends component_generator_base {
    /**
     * Issues a certificate through mod_customcert, so that the ledger numbers it through its observer.
     *
     * @param array|stdClass $record customcertid, userid
     * @return stdClass the customcert_issues record
     */
    public function create_issue($record): stdClass {
        global $DB;

        $record = (array)$record;
        if (empty($record['customcertid']) || empty($record['userid'])) {
            throw new coding_exception('An issue needs customcertid and userid.');
        }
        $issueid = \mod_customcert\certificate::issue_certificate($record['customcertid'], $record['userid']);
        return $DB->get_record('customcert_issues', ['id' => $issueid], '*', MUST_EXIST);
    }

    /**
     * Inserts an issue without triggering any event, as a course restore or a missed observer leaves it.
     *
     * @param array|stdClass $record customcertid, userid; optional code and timecreated
     * @return stdClass the customcert_issues record
     */
    public function create_raw_issue($record): stdClass {
        global $DB;

        $record = (array)$record;
        $issue = (object)[
            'customcertid' => $record['customcertid'],
            'userid' => $record['userid'],
            'code' => $record['code'] ?? \mod_customcert\certificate::generate_code(),
            'emailed' => 0,
            'timecreated' => $record['timecreated'] ?? time(),
        ];
        $issue->id = $DB->insert_record('customcert_issues', $issue);
        return $issue;
    }
}
