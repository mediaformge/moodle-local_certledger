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

namespace local_certledger\table;

use local_certledger\registry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the filters of the ledger table, which must work on every supported database.
 *
 * @package    local_certledger
 * @category   test
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_certledger\table\register_table
 */
#[CoversClass(register_table::class)]
final class register_table_test extends \advanced_testcase {
    /** @var \stdClass[] ledger records by learner last name */
    private $records = [];

    /** @var \stdClass */
    private $course;

    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_certledger');
        $this->course = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_module('customcert', ['course' => $this->course->id]);
        $second = $this->getDataGenerator()->create_module('customcert', ['course' => $course2->id]);
        foreach ([['Ada', 'Lovelace', $first], ['Alan', 'Turing', $first], ['Grace', 'Hopper', $second]] as [$fn, $ln, $cert]) {
            $user = $this->getDataGenerator()->create_user(['firstname' => $fn, 'lastname' => $ln,
                'email' => strtolower($fn) . '@example.com']);
            $issue = $generator->create_issue(['customcertid' => $cert->id, 'userid' => $user->id]);
            $this->records[$ln] = $DB->get_record(registry::TABLE, ['issueid' => $issue->id], '*', MUST_EXIST);
        }
        registry::annul($this->records['Turing']->id, 'Annulled', get_admin()->id);
    }

    /**
     * Returns the last names of the learners matching the filters.
     *
     * @param array $filters
     * @return string[]
     */
    private function matching(array $filters): array {
        global $DB;

        [$where, $params] = register_table::build_where($filters);
        $names = $DB->get_fieldset_sql('SELECT r.lastname FROM {' . registry::TABLE . '} r WHERE ' . $where, $params);
        sort($names);
        return $names;
    }

    public function test_no_filters(): void {
        $this->assertSame(['Hopper', 'Lovelace', 'Turing'], $this->matching([]));
        $this->assertSame(['Hopper', 'Lovelace', 'Turing'], $this->matching(['status' => -1, 'search' => '  ']));
    }

    public function test_search(): void {
        $this->assertSame(['Lovelace'], $this->matching(['search' => 'ada lovelace']));
        $this->assertSame(['Lovelace'], $this->matching(['search' => 'LOVELACE ADA']));
        $this->assertSame(['Hopper'], $this->matching(['search' => 'grace@example']));
        $this->assertSame(['Turing'], $this->matching(['search' => $this->records['Turing']->certcode]));
        $this->assertSame(['Turing'], $this->matching(['search' => $this->records['Turing']->origcode]));
        $this->assertSame([], $this->matching(['search' => '100%_']));
    }

    public function test_status_course_and_year(): void {
        $year = (int)registry::year_of(time());

        $this->assertSame(['Turing'], $this->matching(['status' => registry::STATUS_ANNULLED]));
        $this->assertSame(['Hopper', 'Lovelace'], $this->matching(['status' => registry::STATUS_VALID]));
        $this->assertSame(['Lovelace', 'Turing'], $this->matching(['courseid' => $this->course->id]));
        $this->assertSame(['Hopper', 'Lovelace', 'Turing'], $this->matching(['year' => $year]));
        $this->assertSame([], $this->matching(['year' => $year - 1]));
    }
}
