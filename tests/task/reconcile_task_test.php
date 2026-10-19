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

namespace local_certledger\task;

use local_certledger\registry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the scheduled reconciliation.
 *
 * @package    local_certledger
 * @category   test
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_certledger\task\reconcile_task
 */
#[CoversClass(reconcile_task::class)]
final class reconcile_task_test extends \advanced_testcase {
    public function test_execute_numbers_missed_issues_and_records_the_run(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $customcert = $this->getDataGenerator()->create_module('customcert', ['course' => $course->id]);
        $issue = $this->getDataGenerator()->get_plugin_generator('local_certledger')->create_raw_issue([
            'customcertid' => $customcert->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
        ]);
        set_config('lasterrors', json_encode(['an old problem']), 'local_certledger');

        $this->expectOutputRegex('/numbered: 1,/');
        (new reconcile_task())->execute();

        $this->assertTrue($DB->record_exists(registry::TABLE, ['issueid' => $issue->id]));
        $this->assertGreaterThan(0, (int)get_config('local_certledger', 'lastrun'));
        $this->assertSame('', get_config('local_certledger', 'lasterrors'));
    }

    public function test_execute_keeps_the_errors_for_the_ledger_page(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $customcert = $this->getDataGenerator()->create_module('customcert', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_certledger');
        $generator->create_raw_issue(['customcertid' => $customcert->id, 'userid' => $this->getDataGenerator()->create_user()->id,
            'code' => 'CERT-2026-000001-AAAAA']);
        $generator->create_raw_issue(['customcertid' => $customcert->id, 'userid' => $this->getDataGenerator()->create_user()->id,
            'code' => 'CERT-2026-000001-BBBBB']);

        $this->expectOutputRegex('/already in the ledger/');
        (new reconcile_task())->execute();

        $errors = json_decode(get_config('local_certledger', 'lasterrors'), true);
        $this->assertCount(1, $errors);
    }
}
