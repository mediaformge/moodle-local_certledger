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
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * Tests for the numbering and the ledger.
 *
 * @package    local_certledger
 * @category   test
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_certledger\registry
 */
#[CoversClass(registry::class)]
final class registry_test extends \advanced_testcase {
    /** @var \local_certledger_generator */
    private $generator;

    /** @var stdClass */
    private $course;

    /** @var stdClass the Custom certificate activity */
    private $customcert;

    /** @var string the current year in the site timezone */
    private $year;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The numbering observer is external: inside the test transaction (PostgreSQL) it would wait for a commit.
        $this->preventResetByRollback();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_certledger');
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Workplace safety']);
        $this->customcert = $this->getDataGenerator()->create_module(
            'customcert',
            ['course' => $this->course->id, 'name' => 'Safety certificate']
        );
        $this->year = registry::year_of(time());
    }

    /**
     * Creates a user enrolled as student in the course.
     *
     * @param array $record user fields
     * @return stdClass
     */
    private function create_learner(array $record = []): stdClass {
        $user = $this->getDataGenerator()->create_user($record);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        return $user;
    }

    /**
     * Issues the certificate of the course activity to a user, through mod_customcert.
     *
     * @param stdClass $user
     * @return stdClass the customcert_issues record
     */
    private function issue(stdClass $user): stdClass {
        return $this->generator->create_issue(['customcertid' => $this->customcert->id, 'userid' => $user->id]);
    }

    /**
     * Returns the ledger record of an issue.
     *
     * @param int $issueid
     * @return stdClass
     */
    private function get_record(int $issueid): stdClass {
        global $DB;

        return $DB->get_record(registry::TABLE, ['issueid' => $issueid], '*', MUST_EXIST);
    }

    public function test_issue_is_numbered_when_created(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $user = $this->create_learner(['firstname' => 'Ada', 'lastname' => 'Lovelace', 'email' => 'ada@example.com']);
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Analysts']);
        cohort_add_member($cohort->id, $user->id);

        $issue = $this->issue($user);
        $record = $this->get_record($issue->id);

        $this->assertSame("CERT-{$this->year}-000001", $record->certnumber);
        $this->assertMatchesRegularExpression("/^CERT-{$this->year}-000001-[A-HJKMNP-Z2-9]{5}$/", $record->certcode);
        $this->assertSame($record->certcode, $issue->code);
        $this->assertNotEmpty($record->origcode);
        $this->assertNotSame($record->origcode, $record->certcode);
        $this->assertSame("CERT-{$this->year}", $record->scope);
        $this->assertEquals(1, $record->seq);
        $this->assertEquals(registry::STATUS_VALID, $record->status);
        $this->assertEquals($user->id, $record->userid);
        $this->assertSame('Ada', $record->firstname);
        $this->assertSame('Lovelace', $record->lastname);
        $this->assertSame('ada@example.com', $record->email);
        $this->assertSame('Analysts', $record->cohorts);
        $this->assertSame('Workplace safety', $record->coursename);
        $this->assertSame('Safety certificate', $record->certname);
        $this->assertEquals($this->customcert->cmid, $record->cmid);
        $this->assertEquals($this->course->id, $record->courseid);
        $this->assertEquals($issue->timecreated, $record->timeissued);
        $this->assertSame($record->certcode, $DB->get_field('customcert_issues', 'code', ['id' => $issue->id]));
    }

    public function test_numbers_are_sequential(): void {
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $this->get_record($this->issue($this->create_learner())->id)->certnumber;
        }
        $this->assertSame(["CERT-{$this->year}-000001", "CERT-{$this->year}-000002", "CERT-{$this->year}-000003"], $numbers);
    }

    public function test_number_format_follows_settings(): void {
        set_config('prefix', 'acme', 'local_certledger');
        set_config('yearlyreset', 0, 'local_certledger');
        set_config('digits', 4, 'local_certledger');
        set_config('suffixlength', 0, 'local_certledger');

        $record = $this->get_record($this->issue($this->create_learner())->id);

        $this->assertSame('ACME-0001', $record->certnumber);
        $this->assertSame('ACME-0001', $record->certcode);
        $this->assertSame('ACME', $record->scope);
    }

    public function test_get_settings_falls_back_on_invalid_values(): void {
        set_config('prefix', '123', 'local_certledger');
        set_config('digits', 12, 'local_certledger');
        set_config('suffixlength', 9, 'local_certledger');

        $settings = registry::get_settings();

        $this->assertSame('CERT', $settings->prefix);
        $this->assertSame(6, $settings->digits);
        $this->assertSame(5, $settings->suffixlength);
        $this->assertTrue($settings->yearlyreset);
        $this->assertTrue($settings->privacyanonymise);
    }

    public function test_get_example(): void {
        $example = registry::get_example();

        $this->assertSame("CERT-{$this->year}-000001", $example->number);
        $this->assertSame("CERT-{$this->year}-000001-XXXXX", $example->code);
    }

    /**
     * Codes that are and are not written by the plugin.
     *
     * @return array code, expected scope, sequence and number (null when the code is not ours)
     */
    public static function parse_code_provider(): array {
        return [
            'yearly with suffix' => ['CERT-2026-000001-K7QXM', 'CERT-2026', 1, 'CERT-2026-000001'],
            'no year, no suffix' => ['CERT-000012', 'CERT', 12, 'CERT-000012'],
            'digits in the prefix' => ['A1-2025-0042-AB', 'A1-2025', 42, 'A1-2025-0042'],
            'customcert digits with hyphens' => ['0123-4567-8901', null, null, null],
            'customcert random code' => ['aB3dE5gH7k', null, null, null],
            'too few digits' => ['CERT-123', null, null, null],
            'lowercase' => ['cert-2026-000001', null, null, null],
            'empty' => ['', null, null, null],
        ];
    }

    /**
     * Recognises the codes written by the plugin, and only those.
     *
     * @dataProvider parse_code_provider
     * @param string $code
     * @param string|null $scope
     * @param int|null $seq
     * @param string|null $number
     */
    #[DataProvider('parse_code_provider')]
    public function test_parse_code(string $code, ?string $scope, ?int $seq, ?string $number): void {
        $parsed = registry::parse_code($code);
        if ($scope === null) {
            $this->assertNull($parsed);
            return;
        }
        $this->assertSame($scope, $parsed->scope);
        $this->assertSame($seq, $parsed->seq);
        $this->assertSame($number, $parsed->number);
    }

    public function test_register_issue_is_idempotent(): void {
        global $DB;

        $issue = $this->issue($this->create_learner());
        $first = $this->get_record($issue->id);
        $again = registry::register_issue($issue->id);

        $this->assertEquals($first->id, $again->id);
        $this->assertEquals(1, $DB->count_records(registry::TABLE));
    }

    public function test_register_issue_ignores_orphans(): void {
        $issue = $this->generator->create_raw_issue(['customcertid' => -1, 'userid' => $this->create_learner()->id]);

        $this->assertNull(registry::register_issue($issue->id));
        $this->assertNull(registry::register_issue(-1));
    }

    public function test_register_issue_triggers_event(): void {
        $issue = $this->generator->create_raw_issue(['customcertid' => $this->customcert->id,
            'userid' => $this->create_learner()->id]);

        $sink = $this->redirectEvents();
        $record = registry::register_issue($issue->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(event\number_assigned::class, $events[0]);
        $this->assertEquals($record->id, $events[0]->objectid);
        $this->assertSame($record->certnumber, $events[0]->other['number']);
    }

    public function test_existing_number_is_adopted_without_changing_the_code(): void {
        $code = "CERT-{$this->year}-000041-ABCDE";
        $issue = $this->generator->create_raw_issue(['customcertid' => $this->customcert->id,
            'userid' => $this->create_learner()->id, 'code' => $code]);

        $record = registry::register_issue($issue->id);

        $this->assertSame("CERT-{$this->year}-000041", $record->certnumber);
        $this->assertSame($code, $record->certcode);
        $this->assertNull($record->origcode);
        $this->assertEquals(41, $record->seq);
        $this->assertSame("CERT-{$this->year}-000042", $this->get_record($this->issue($this->create_learner())->id)->certnumber);
    }

    public function test_numbering_skips_numbers_printed_on_unregistered_issues(): void {
        $this->generator->create_raw_issue(['customcertid' => $this->customcert->id,
            'userid' => $this->create_learner()->id, 'code' => "CERT-{$this->year}-000007-WXYZ2"]);

        $record = $this->get_record($this->issue($this->create_learner())->id);

        $this->assertSame("CERT-{$this->year}-000008", $record->certnumber);
    }

    public function test_copy_of_a_registered_certificate_is_not_numbered(): void {
        $issue = $this->issue($this->create_learner());
        $course2 = $this->getDataGenerator()->create_course();
        $restored = $this->getDataGenerator()->create_module('customcert', ['course' => $course2->id]);
        $copy = $this->generator->create_raw_issue(['customcertid' => $restored->id, 'userid' => $issue->userid,
            'code' => $issue->code]);

        $this->assertNull(registry::register_issue($copy->id));
        $this->assertSame(1, registry::count_copies());
        $this->assertSame(0, registry::count_unregistered(0));
        $this->assertArrayNotHasKey($copy->id, registry::get_unregistered_issues(0));
    }

    public function test_each_year_has_its_own_series(): void {
        $timezone = \core_date::get_server_timezone_object();
        $lastyear = (int)$this->year - 1;
        $old = $this->generator->create_raw_issue(['customcertid' => $this->customcert->id,
            'userid' => $this->create_learner()->id,
            'timecreated' => (new \DateTime("{$lastyear}-06-15 12:00:00", $timezone))->getTimestamp()]);

        $oldrecord = registry::register_issue($old->id);
        $newrecord = $this->get_record($this->issue($this->create_learner())->id);

        $this->assertSame("CERT-{$lastyear}-000001", $oldrecord->certnumber);
        $this->assertSame("CERT-{$this->year}-000001", $newrecord->certnumber);
    }

    public function test_year_of_uses_the_site_timezone(): void {
        $this->setTimezone('Europe/Rome');
        // 31 December 2025, 23:30 UTC is already 2026 in Rome.
        $this->assertSame('2026', registry::year_of(gmmktime(23, 30, 0, 12, 31, 2025)));
    }

    public function test_annul_deletes_the_certificate_and_its_copies(): void {
        global $DB;

        $issue = $this->issue($this->create_learner());
        $record = $this->get_record($issue->id);
        $course2 = $this->getDataGenerator()->create_course();
        $restored = $this->getDataGenerator()->create_module('customcert', ['course' => $course2->id]);
        $this->generator->create_raw_issue(['customcertid' => $restored->id, 'userid' => $issue->userid,
            'code' => $issue->code]);
        $admin = get_admin();

        $sink = $this->redirectEvents();
        $annulled = registry::annul($record->id, 'Issued by mistake', $admin->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertEquals(registry::STATUS_ANNULLED, $annulled->status);
        $this->assertSame('Issued by mistake', $annulled->statusreason);
        $this->assertEquals($admin->id, $annulled->statusby);
        $this->assertFalse(registry::is_blocked($annulled));
        $this->assertFalse($DB->record_exists('customcert_issues', ['code' => $issue->code]));
        $classes = array_map('get_class', $events);
        $this->assertSame(2, count(array_keys($classes, \mod_customcert\event\issue_deleted::class)));
        $this->assertContains(event\certificate_annulled::class, $classes);
    }

    public function test_only_valid_certificates_can_be_annulled(): void {
        $record = $this->get_record($this->issue($this->create_learner())->id);
        registry::annul($record->id, 'First', get_admin()->id);

        $this->expectException(\moodle_exception::class);
        registry::annul($record->id, 'Second', get_admin()->id);
    }

    public function test_block_stops_a_new_issue_until_lifted(): void {
        global $DB;

        $user = $this->create_learner();
        $record = $this->get_record($this->issue($user)->id);
        $context = \context_module::instance($this->customcert->cmid);
        $this->assertTrue(has_capability('mod/customcert:receiveissue', $context, $user));

        $annulled = registry::annul($record->id, 'Failed the final test', get_admin()->id, true);

        $this->assertTrue(registry::is_blocked($annulled));
        $this->assertNotEmpty($DB->get_field(registry::TABLE, 'timeblocked', ['id' => $record->id]));
        $this->assertTrue($DB->record_exists(
            'role_assignments',
            ['component' => 'local_certledger', 'itemid' => $record->id, 'userid' => $user->id, 'contextid' => $context->id]
        ));
        $this->assertFalse(has_capability('mod/customcert:receiveissue', $context, $user));

        registry::unblock($annulled);

        $this->assertFalse(registry::is_blocked($annulled));
        $this->assertNull($DB->get_field(registry::TABLE, 'timeblocked', ['id' => $record->id]));
        $this->assertTrue(has_capability('mod/customcert:receiveissue', $context, $user));
    }

    public function test_block_role_is_created_once(): void {
        $this->assertNull(registry::get_block_roleid());

        $roleid = registry::get_block_roleid(true);

        $this->assertSame($roleid, registry::get_block_roleid(true));
        $this->assertSame([CONTEXT_MODULE], array_values(array_map('intval', get_role_contextlevels($roleid))));
    }

    public function test_mark_removed_only_changes_valid_certificates(): void {
        $first = $this->get_record($this->issue($this->create_learner())->id);
        $second = $this->get_record($this->issue($this->create_learner())->id);
        registry::annul($second->id, 'Annulled', get_admin()->id);

        $this->assertTrue(registry::mark_removed($first->issueid, 5, 1000));
        $this->assertFalse(registry::mark_removed($first->issueid));
        $this->assertFalse(registry::mark_removed($second->issueid));

        $removed = $this->get_record($first->issueid);
        $this->assertEquals(registry::STATUS_REMOVED, $removed->status);
        $this->assertEquals(5, $removed->statusby);
        $this->assertEquals(1000, $removed->timestatus);
        $this->assertEquals(registry::STATUS_ANNULLED, $this->get_record($second->issueid)->status);
    }

    public function test_reconcile_numbers_missed_issues(): void {
        $issue = $this->generator->create_raw_issue(['customcertid' => $this->customcert->id,
            'userid' => $this->create_learner()->id]);
        $this->assertSame(1, registry::count_unregistered(0));

        $result = registry::reconcile();

        $this->assertSame(1, $result->registered);
        $this->assertSame(0, $result->failed);
        $record = $this->get_record($issue->id);
        $this->assertSame($issue->code, $record->origcode);
        $this->assertSame(0, registry::count_unregistered(0));
    }

    public function test_reconcile_leaves_issues_before_enabledsince_to_the_cli(): void {
        set_config('enabledsince', time() - HOURSECS, 'local_certledger');
        $issue = $this->generator->create_raw_issue(['customcertid' => $this->customcert->id,
            'userid' => $this->create_learner()->id, 'timecreated' => time() - DAYSECS]);

        $this->assertSame(0, registry::reconcile()->registered);
        $this->assertSame(1, registry::reconcile(0)->registered);
        $this->assertNotEmpty($this->get_record($issue->id));
    }

    public function test_reconcile_adopts_old_issues_with_our_number(): void {
        $issue = $this->generator->create_raw_issue(['customcertid' => $this->customcert->id,
            'userid' => $this->create_learner()->id, 'code' => 'CERT-2020-000003-QWERT', 'timecreated' => 1000]);

        $result = registry::reconcile();

        $this->assertSame(1, $result->adopted);
        $this->assertSame('CERT-2020-000003', $this->get_record($issue->id)->certnumber);
    }

    public function test_reconcile_reports_a_number_already_in_use(): void {
        $record = $this->get_record($this->issue($this->create_learner())->id);
        $this->generator->create_raw_issue(['customcertid' => $this->customcert->id,
            'userid' => $this->create_learner()->id, 'code' => $record->certnumber . '-ZZZZZ']);

        $result = registry::reconcile();

        $this->assertSame(0, $result->adopted);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('already in the ledger', $result->errors[0]);
    }

    public function test_reconcile_marks_deleted_issues_as_removed(): void {
        global $DB;

        $issue = $this->issue($this->create_learner());
        $DB->delete_records('customcert_issues', ['id' => $issue->id]);

        $result = registry::reconcile();

        $this->assertSame(1, $result->removed);
        $this->assertEquals(registry::STATUS_REMOVED, $this->get_record($issue->id)->status);
    }

    public function test_reconcile_restores_certificates_still_issued(): void {
        $issue = $this->issue($this->create_learner());
        registry::mark_removed($issue->id);

        $result = registry::reconcile();

        $this->assertSame(1, $result->restored);
        $this->assertEquals(registry::STATUS_VALID, $this->get_record($issue->id)->status);
    }

    public function test_reconcile_rewrites_a_changed_code(): void {
        global $DB;

        $issue = $this->issue($this->create_learner());
        $DB->set_field('customcert_issues', 'code', 'TAMPERED', ['id' => $issue->id]);

        $result = registry::reconcile();

        $this->assertSame(1, $result->codesfixed);
        $this->assertSame($issue->code, $DB->get_field('customcert_issues', 'code', ['id' => $issue->id]));
    }

    public function test_reconcile_relinks_a_restored_issue(): void {
        global $DB;

        $issue = $this->issue($this->create_learner());
        $record = $this->get_record($issue->id);
        $DB->delete_records('customcert_issues', ['id' => $issue->id]);
        $restored = $this->generator->create_raw_issue(['customcertid' => $this->customcert->id, 'userid' => $issue->userid,
            'code' => $issue->code, 'timecreated' => $issue->timecreated]);

        $result = registry::reconcile();

        $this->assertSame(1, $result->relinked);
        $this->assertSame(0, $result->registered);
        $relinked = $DB->get_record(registry::TABLE, ['id' => $record->id]);
        $this->assertEquals($restored->id, $relinked->issueid);
        $this->assertEquals(registry::STATUS_VALID, $relinked->status);
    }

    public function test_reconcile_deletes_copies_of_annulled_certificates(): void {
        global $DB;

        $issue = $this->issue($this->create_learner());
        registry::annul($this->get_record($issue->id)->id, 'Annulled', get_admin()->id);
        $copy = $this->generator->create_raw_issue(['customcertid' => $this->customcert->id, 'userid' => $issue->userid,
            'code' => $issue->code]);

        $result = registry::reconcile();

        $this->assertSame(1, $result->annulledcopies);
        $this->assertFalse($DB->record_exists('customcert_issues', ['id' => $copy->id]));
    }

    public function test_reconcile_restores_a_dropped_block(): void {
        global $DB;

        $record = $this->get_record($this->issue($this->create_learner())->id);
        registry::annul($record->id, 'Annulled', get_admin()->id, true);
        // Moodle drops the assignment, e.g. when the learner is unenrolled.
        role_unassign_all(['component' => 'local_certledger', 'itemid' => $record->id]);

        $result = registry::reconcile();

        $this->assertSame(1, $result->reblocked);
        $this->assertTrue($DB->record_exists('role_assignments', ['component' => 'local_certledger', 'itemid' => $record->id]));
        $this->assertSame(0, registry::reconcile()->reblocked);
    }

    public function test_summary_years_and_courses(): void {
        $first = $this->get_record($this->issue($this->create_learner())->id);
        $this->issue($this->create_learner());
        registry::annul($first->id, 'Annulled', get_admin()->id);

        $summary = registry::get_summary();

        $this->assertSame(2, $summary->total);
        $this->assertSame(1, $summary->valid);
        $this->assertSame(1, $summary->annulled);
        $this->assertSame(0, $summary->removed);
        $this->assertSame([(int)$this->year => $this->year], registry::get_years());
        $this->assertSame([$this->course->id => 'Workplace safety'], registry::get_courses());
    }

    public function test_anonymise_user_keeps_the_numbering(): void {
        $learner = $this->create_learner(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $own = $this->get_record($this->issue($learner)->id);
        $other = $this->get_record($this->issue($this->create_learner())->id);
        registry::annul($other->id, 'Annulled', $learner->id);

        registry::anonymise_user($learner->id);

        $own = $this->get_record($own->issueid);
        $this->assertEquals(0, $own->userid);
        $this->assertSame('', $own->firstname);
        $this->assertSame(get_string('anonymised', 'local_certledger'), $own->lastname);
        $this->assertSame('', $own->email);
        $this->assertSame("CERT-{$this->year}-000001", $own->certnumber);
        $this->assertEquals(0, $this->get_record($other->issueid)->statusby);
        $this->assertNotEquals(0, $this->get_record($other->issueid)->userid);
    }
}
