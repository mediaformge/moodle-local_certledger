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

namespace local_certledger\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_certledger\registry;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

/**
 * Tests for the privacy provider.
 *
 * @package    local_certledger
 * @category   test
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_certledger\privacy\provider
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var stdClass learner with a certificate */
    private $learner;

    /** @var stdClass another learner with a certificate */
    private $other;

    /** @var stdClass manager who annulled the certificate of the other learner */
    private $manager;

    /** @var stdClass user without data in the ledger */
    private $nobody;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_certledger');
        $course = $this->getDataGenerator()->create_course();
        $customcert = $this->getDataGenerator()->create_module('customcert', ['course' => $course->id]);
        $this->learner = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $this->other = $this->getDataGenerator()->create_user(['firstname' => 'Alan', 'lastname' => 'Turing']);
        $this->manager = $this->getDataGenerator()->create_user();
        $this->nobody = $this->getDataGenerator()->create_user();

        $generator->create_issue(['customcertid' => $customcert->id, 'userid' => $this->learner->id]);
        $generator->create_issue(['customcertid' => $customcert->id, 'userid' => $this->other->id]);
        registry::annul($this->get_record($this->other->id)->id, 'Issued by mistake', $this->manager->id);
    }

    /**
     * Returns the ledger record of a learner.
     *
     * @param int $userid
     * @return stdClass
     */
    private function get_record(int $userid): stdClass {
        global $DB;

        return $DB->get_record(registry::TABLE, ['userid' => $userid], '*', MUST_EXIST);
    }

    public function test_get_metadata(): void {
        $items = provider::get_metadata(new collection('local_certledger'))->get_collection();

        $this->assertCount(1, $items);
        $this->assertSame(registry::TABLE, $items[0]->get_name());
        $this->assertArrayHasKey('email', $items[0]->get_privacy_fields());
    }

    public function test_get_contexts_for_userid(): void {
        $system = \context_system::instance();

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($this->learner->id)->get_contextids());
        $this->assertEquals([$system->id], provider::get_contexts_for_userid($this->manager->id)->get_contextids());
        $this->assertEquals([], provider::get_contexts_for_userid($this->nobody->id)->get_contextids());
    }

    public function test_get_users_in_context(): void {
        $userlist = new userlist(\context_system::instance(), 'local_certledger');
        provider::get_users_in_context($userlist);
        $expected = [$this->learner->id, $this->other->id, $this->manager->id];
        sort($expected);
        $actual = $userlist->get_userids();
        sort($actual);

        $this->assertEquals($expected, $actual);

        $userlist = new userlist(\context_user::instance($this->learner->id), 'local_certledger');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }

    public function test_export_user_data(): void {
        $system = \context_system::instance();

        $this->export_context_data_for_user($this->learner->id, $system, 'local_certledger');
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'local_certledger')]);
        $this->assertCount(1, $data->certificates);
        $this->assertSame('Lovelace', $data->certificates[0]->lastname);
        $this->assertSame($this->get_record($this->learner->id)->certnumber, $data->certificates[0]->number);
        $this->assertSame([], $data->actions);

        writer::reset();
        $this->export_context_data_for_user($this->manager->id, $system, 'local_certledger');
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'local_certledger')]);
        $this->assertSame([], $data->certificates);
        $this->assertCount(1, $data->actions);
        $this->assertSame('Issued by mistake', $data->actions[0]->statusreason);
    }

    public function test_delete_data_for_user_anonymises_by_default(): void {
        global $DB;

        $number = $this->get_record($this->learner->id)->certnumber;
        $contextlist = new approved_contextlist($this->learner, 'local_certledger', [\context_system::instance()->id]);

        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists(registry::TABLE, ['userid' => $this->learner->id]));
        $record = $DB->get_record(registry::TABLE, ['certnumber' => $number], '*', MUST_EXIST);
        $this->assertEquals(0, $record->userid);
        $this->assertSame('', $record->firstname);
        $this->assertSame('', $record->email);
        $this->assertSame('Turing', $this->get_record($this->other->id)->lastname);
    }

    public function test_delete_data_for_user_keeps_the_ledger_when_disabled(): void {
        set_config('privacyanonymise', 0, 'local_certledger');
        $contextlist = new approved_contextlist($this->learner, 'local_certledger', [\context_system::instance()->id]);

        provider::delete_data_for_user($contextlist);

        $this->assertSame('Lovelace', $this->get_record($this->learner->id)->lastname);
    }

    public function test_delete_data_for_users(): void {
        global $DB;

        $userlist = new approved_userlist(\context_system::instance(), 'local_certledger', [$this->manager->id]);

        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $DB->count_records(registry::TABLE, ['statusby' => $this->manager->id]));
        $this->assertSame('Turing', $this->get_record($this->other->id)->lastname);
        $this->assertSame('Lovelace', $this->get_record($this->learner->id)->lastname);
    }

    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context(\context_user::instance($this->learner->id));
        $this->assertSame(2, $DB->count_records_select(registry::TABLE, 'userid > 0'));

        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertSame(0, $DB->count_records_select(registry::TABLE, 'userid > 0'));
        $this->assertSame(2, $DB->count_records(registry::TABLE));
    }
}
