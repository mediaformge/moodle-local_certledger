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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the observers of mod_customcert events.
 *
 * @package    local_certledger
 * @category   test
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_certledger\observer
 */
#[CoversClass(observer::class)]
final class observer_test extends \advanced_testcase {
    /** @var \stdClass the Custom certificate activity */
    private $customcert;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The tests control the transactions themselves.
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $this->customcert = $this->getDataGenerator()->create_module('customcert', ['course' => $course->id]);
    }

    public function test_issue_created_numbers_the_certificate_before_it_is_printed(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $issueid = \mod_customcert\certificate::issue_certificate($this->customcert->id, $user->id);

        $record = $DB->get_record(registry::TABLE, ['issueid' => $issueid], '*', MUST_EXIST);
        $this->assertSame($record->certcode, $DB->get_field('customcert_issues', 'code', ['id' => $issueid]));
    }

    public function test_issue_created_in_a_transaction_is_numbered_after_the_commit(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $transaction = $DB->start_delegated_transaction();
        $issueid = \mod_customcert\certificate::issue_certificate($this->customcert->id, $user->id);
        $this->assertFalse($DB->record_exists(registry::TABLE, ['issueid' => $issueid]));
        $transaction->allow_commit();

        $this->assertTrue($DB->record_exists(registry::TABLE, ['issueid' => $issueid]));
    }

    public function test_issue_rolled_back_is_not_numbered(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $transaction = $DB->start_delegated_transaction();
        \mod_customcert\certificate::issue_certificate($this->customcert->id, $user->id);
        try {
            $transaction->rollback(new \moodle_exception('error'));
        } catch (\moodle_exception $e) {
            // Rolling back rethrows the exception.
            $this->assertSame('error', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records(registry::TABLE));
        $this->assertSame(0, $DB->count_records('customcert_issues'));
    }

    public function test_issue_deleted_marks_the_certificate_removed(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $issueid = \mod_customcert\certificate::issue_certificate($this->customcert->id, $user->id);
        $this->setUser($teacher);

        // As the report of mod_customcert does.
        $DB->delete_records('customcert_issues', ['id' => $issueid]);
        \mod_customcert\event\issue_deleted::create([
            'objectid' => $issueid,
            'context' => \context_module::instance($this->customcert->cmid),
            'relateduserid' => $user->id,
        ])->trigger();

        $record = $DB->get_record(registry::TABLE, ['issueid' => $issueid], '*', MUST_EXIST);
        $this->assertEquals(registry::STATUS_REMOVED, $record->status);
        $this->assertEquals($teacher->id, $record->statusby);
    }

    public function test_issue_created_never_breaks_the_issue(): void {
        global $DB;

        // An issue whose activity no longer exists cannot be numbered: the observer must not throw.
        $user = $this->getDataGenerator()->create_user();
        $issueid = $DB->insert_record('customcert_issues', (object)['customcertid' => -1, 'userid' => $user->id,
            'code' => 'ABCDEFGHIJ', 'emailed' => 0, 'timecreated' => time()]);
        $event = \mod_customcert\event\issue_created::create([
            'objectid' => $issueid,
            'context' => \context_module::instance($this->customcert->cmid),
            'relateduserid' => $user->id,
        ]);

        observer::issue_created($event);

        $this->assertFalse($DB->record_exists(registry::TABLE, ['issueid' => $issueid]));
    }
}
