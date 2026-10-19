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

namespace local_certledger;

use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/certledger/db/uninstall.php');

/**
 * Tests for the uninstall steps.
 *
 * @package    local_certledger
 * @category   test
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_certledger_export_register
 * @covers     ::local_certledger_remove_block_role
 */
#[CoversFunction('local_certledger_export_register')]
#[CoversFunction('local_certledger_remove_block_role')]
final class uninstall_test extends \advanced_testcase {
    public function test_ledger_is_saved_and_block_role_removed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->assertNull(local_certledger_export_register());

        $course = $this->getDataGenerator()->create_course();
        $customcert = $this->getDataGenerator()->create_module('customcert', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user(['lastname' => 'Lovelace']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $issue = $this->getDataGenerator()->get_plugin_generator('local_certledger')
            ->create_issue(['customcertid' => $customcert->id, 'userid' => $user->id]);
        $record = $DB->get_record(registry::TABLE, ['issueid' => $issue->id], '*', MUST_EXIST);
        registry::annul($record->id, 'Annulled', get_admin()->id, true);

        $file = local_certledger_export_register();

        $this->assertFileExists($file);
        $rows = array_map(fn($line) => str_getcsv($line, ',', '"', ''), file($file, FILE_IGNORE_NEW_LINES));
        $this->assertCount(2, $rows);
        $this->assertContains('certnumber', $rows[0]);
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame($record->certnumber, $row['certnumber']);
        $this->assertSame('Lovelace', $row['lastname']);

        local_certledger_remove_block_role();

        $this->assertFalse($DB->record_exists('role', ['shortname' => registry::BLOCK_ROLE]));
        $this->assertFalse($DB->record_exists('role_assignments', ['component' => 'local_certledger']));
    }
}
