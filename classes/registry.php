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
 * Certificate numbering and register.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_certledger;

use core\lock\lock_config;
use core_text;
use moodle_exception;
use stdClass;

/**
 * Assigns progressive numbers to mod_customcert issues and keeps the register in sync with them.
 *
 * The number is written into customcert_issues.code: mod_customcert then prints it through its standard
 * "Code" and "QR code" elements and verifies it on its own verification page. mod_customcert is never modified,
 * only its public events and its issues table are used.
 */
class registry {
    /** @var string Register table. */
    public const TABLE = 'local_certledger';

    /** @var int The certificate is valid and available on the platform. */
    public const STATUS_VALID = 0;

    /** @var int The certificate was annulled from the register, with a reason. */
    public const STATUS_ANNULLED = 1;

    /** @var int The certificate was deleted from the platform by other means (report, activity or course deletion, reset). */
    public const STATUS_REMOVED = 2;

    /** @var string Characters of the check suffix, without 0/O and 1/I/L to avoid reading mistakes. */
    public const SUFFIX_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @var int Seconds to wait for the numbering lock. */
    public const LOCK_TIMEOUT = 30;

    /** @var int Attempts before giving up when a concurrent write collides. */
    public const MAX_ATTEMPTS = 3;

    /** @var string Shortname of the role that stops mod_customcert from issuing again an annulled certificate. */
    public const BLOCK_ROLE = 'certledger_blocked';

    /** @var int Errors kept by a reconciliation run. */
    public const MAX_ERRORS = 20;

    /**
     * Returns the numbering settings, falling back to safe defaults for missing or invalid values.
     *
     * @return stdClass prefix, yearlyreset, digits, suffixlength, enabledsince, privacyanonymise
     */
    public static function get_settings(): stdClass {
        $config = get_config('local_certledger');
        $prefix = strtoupper(trim((string)($config->prefix ?? 'CERT')));
        $digits = (int)($config->digits ?? 6);
        $suffixlength = (int)($config->suffixlength ?? 5);

        return (object)[
            'prefix' => preg_match('/^(?=[0-9]*[A-Z])[A-Z0-9]{1,10}$/', $prefix) ? $prefix : 'CERT',
            'yearlyreset' => !isset($config->yearlyreset) || !empty($config->yearlyreset),
            'digits' => ($digits >= 4 && $digits <= 8) ? $digits : 6,
            'suffixlength' => ($suffixlength >= 0 && $suffixlength <= 8) ? $suffixlength : 5,
            'enabledsince' => (int)($config->enabledsince ?? 0),
            'privacyanonymise' => !isset($config->privacyanonymise) || !empty($config->privacyanonymise),
        ];
    }

    /**
     * Numbers a certificate issue and records it in the register.
     *
     * Idempotent: an issue already in the register is returned unchanged. An issue that already carries one of our
     * numbers (register lost or restored from an older backup) is recorded with that number, its code is never changed.
     *
     * @param int $issueid customcert_issues.id
     * @return stdClass|null the register record; null if the issue does not exist or is a copy of a registered certificate
     */
    public static function register_issue(int $issueid): ?stdClass {
        global $DB;

        if ($record = $DB->get_record(self::TABLE, ['issueid' => $issueid])) {
            return $record;
        }
        if (!$issue = $DB->get_record('customcert_issues', ['id' => $issueid])) {
            return null;
        }
        if (!$snapshot = self::snapshot($issue)) {
            // Orphan issue: its activity no longer exists, nothing can print it.
            return null;
        }
        $settings = self::get_settings();

        $lock = self::lock_factory()->get_lock('numbering', self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new moodle_exception('locktimeout', 'local_certledger');
        }
        try {
            // Another process may have numbered this issue while we were waiting for the lock.
            if ($record = $DB->get_record(self::TABLE, ['issueid' => $issueid])) {
                return $record;
            }
            if ($parsed = self::parse_code($issue->code)) {
                if ($DB->record_exists(self::TABLE, ['certnumber' => $parsed->number])) {
                    return null;
                }
                $record = self::adopt($issue, $snapshot, $parsed);
            } else {
                $record = self::allocate($issue, $snapshot, $settings);
            }
        } finally {
            $lock->release();
        }

        event\number_assigned::create([
            'context' => \context_system::instance(),
            'objectid' => $record->id,
            'relateduserid' => $record->userid,
            'other' => ['issueid' => (int)$record->issueid, 'number' => $record->certnumber],
        ])->trigger();

        return $record;
    }

    /**
     * Returns the lock factory, created once per process.
     *
     * @return \core\lock\lock_factory
     */
    private static function lock_factory(): \core\lock\lock_factory {
        static $factory = null;
        if ($factory === null) {
            $factory = lock_config::get_lock_factory('local_certledger');
        }
        return $factory;
    }

    /**
     * Takes the next number of the series and writes it on the issue. Must be called holding the numbering lock.
     *
     * @param stdClass $issue customcert_issues record
     * @param stdClass $snapshot learner and course data, see {@see snapshot()}
     * @param stdClass $settings numbering settings
     * @return stdClass the new register record
     */
    private static function allocate(stdClass $issue, stdClass $snapshot, stdClass $settings): stdClass {
        global $DB;

        $scope = $settings->prefix;
        if ($settings->yearlyreset) {
            $scope .= '-' . self::year_of((int)$issue->timecreated);
        }

        // Inside someone else's transaction a rollback would mark their work for rollback too: no retry there.
        $outer = $DB->is_transaction_started();
        for ($attempt = 1;; $attempt++) {
            $transaction = $DB->start_delegated_transaction();
            try {
                $seq = self::next_seq($scope);
                $number = $scope . '-' . str_pad((string)$seq, $settings->digits, '0', STR_PAD_LEFT);
                $code = self::unique_code($number, $settings->suffixlength);
                $now = time();

                $record = (object)array_merge((array)$snapshot, [
                    'issueid' => (int)$issue->id,
                    'scope' => $scope,
                    'seq' => $seq,
                    'certnumber' => $number,
                    'certcode' => $code,
                    'origcode' => $issue->code,
                    'timeissued' => (int)$issue->timecreated,
                    'status' => self::STATUS_VALID,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $record->id = $DB->insert_record(self::TABLE, $record);
                $DB->set_field('customcert_issues', 'code', $code, ['id' => $issue->id]);

                $transaction->allow_commit();
                return $record;
            } catch (\Throwable $e) {
                try {
                    // Rolling back rethrows the original exception.
                    $transaction->rollback($e);
                } catch (\Throwable $rethrown) {
                    // Expected, the decision is taken below.
                    unset($rethrown);
                }
                if ($attempt >= self::MAX_ATTEMPTS || $outer || !($e instanceof \dml_write_exception)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Returns the next free number of a series: after the last one in the register and after any number already
     * printed on a certificate the register does not know (register lost, or restored from an older backup).
     *
     * @param string $scope
     * @return int
     */
    private static function next_seq(string $scope): int {
        global $DB;

        $last = (int)$DB->get_field_sql('SELECT MAX(seq) FROM {' . self::TABLE . '} WHERE scope = :scope', ['scope' => $scope]);
        // Prefix LIKE on the indexed code column, only for issues the register does not know: normally none.
        $codes = $DB->get_fieldset_sql(
            'SELECT i.code FROM {customcert_issues} i WHERE ' . $DB->sql_like('i.code', ':prefix', false, false)
            . ' AND NOT EXISTS (SELECT 1 FROM {' . self::TABLE . '} r WHERE r.issueid = i.id)',
            ['prefix' => $DB->sql_like_escape($scope . '-') . '%']
        );
        foreach ($codes as $code) {
            $parsed = self::parse_code($code);
            if ($parsed && $parsed->scope === $scope) {
                $last = max($last, $parsed->seq);
            }
        }
        return $last + 1;
    }

    /**
     * Recognises a code written by this plugin: PREFIX[-YEAR]-SEQ[-SUFFIX], with at least one letter in the prefix
     * (the "digits with hyphens" codes of mod_customcert, e.g. 0123-4567-8901, must not match).
     *
     * @param string|null $code
     * @return stdClass|null scope, seq, number; null if the code is not one of ours
     */
    public static function parse_code(?string $code): ?stdClass {
        if (!preg_match('/^(?=[0-9]*[A-Z])([A-Z0-9]{1,10})(-(\d{4}))?-(\d{4,8})(-[A-Z0-9]{1,8})?$/', (string)$code, $m)) {
            return null;
        }
        $scope = $m[1] . ($m[3] !== '' ? '-' . $m[3] : '');
        return (object)['scope' => $scope, 'seq' => (int)$m[4], 'number' => $scope . '-' . $m[4]];
    }

    /**
     * Records an issue that already carries one of our numbers, without changing its code.
     * Must be called holding the numbering lock.
     *
     * @param stdClass $issue customcert_issues record
     * @param stdClass $snapshot learner and course data, see {@see snapshot()}
     * @param stdClass $parsed result of {@see parse_code()}
     * @return stdClass the new register record
     */
    private static function adopt(stdClass $issue, stdClass $snapshot, stdClass $parsed): stdClass {
        global $DB;

        $now = time();
        $record = (object)array_merge((array)$snapshot, [
            'issueid' => (int)$issue->id,
            'scope' => $parsed->scope,
            'seq' => $parsed->seq,
            'certnumber' => $parsed->number,
            'certcode' => $issue->code,
            'origcode' => null,
            'timeissued' => (int)$issue->timecreated,
            'status' => self::STATUS_VALID,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $record->id = $DB->insert_record(self::TABLE, $record);
        return $record;
    }

    /**
     * Returns the activity, course module and course of an issue.
     *
     * @param stdClass $issue customcert_issues record
     * @return stdClass|null customcertid, cmid, courseid, certname; null if the activity no longer exists
     */
    private static function links(stdClass $issue): ?stdClass {
        global $DB;

        if (!$customcert = $DB->get_record('customcert', ['id' => $issue->customcertid], 'id, course, name')) {
            return null;
        }
        $cm = get_coursemodule_from_instance('customcert', $customcert->id, $customcert->course, false, IGNORE_MISSING);
        return (object)[
            'customcertid' => (int)$customcert->id,
            'cmid' => $cm ? (int)$cm->id : null,
            'courseid' => (int)$customcert->course,
            'certname' => core_text::substr($customcert->name, 0, 255),
        ];
    }

    /**
     * Collects the data recorded in the register, as they are when the certificate is issued.
     *
     * @param stdClass $issue customcert_issues record
     * @return stdClass|null null if the activity no longer exists
     */
    private static function snapshot(stdClass $issue): ?stdClass {
        global $DB;

        if (!$links = self::links($issue)) {
            return null;
        }
        $course = $DB->get_record('course', ['id' => $links->courseid], 'id, fullname');
        $user = $DB->get_record('user', ['id' => $issue->userid], 'id, firstname, lastname, email');
        $cohorts = $DB->get_fieldset_sql(
            "SELECT c.name
               FROM {cohort} c
               JOIN {cohort_members} m ON m.cohortid = c.id
              WHERE m.userid = :userid
           ORDER BY c.name",
            ['userid' => $issue->userid]
        );

        return (object)[
            'customcertid' => $links->customcertid,
            'cmid' => $links->cmid,
            'courseid' => $links->courseid,
            'userid' => (int)$issue->userid,
            'firstname' => $user ? core_text::substr($user->firstname, 0, 100) : '',
            'lastname' => $user ? core_text::substr($user->lastname, 0, 100) : '',
            'email' => $user ? core_text::substr($user->email, 0, 100) : '',
            'coursename' => $course ? core_text::substr($course->fullname, 0, 255) : '',
            'certname' => $links->certname,
            'cohorts' => implode(', ', $cohorts),
        ];
    }

    /**
     * Builds the code printed on the certificate: the number plus a random check suffix.
     *
     * The suffix stops anyone from finding other people's certificates by trying consecutive numbers
     * on the public verification page.
     *
     * @param string $number register number
     * @param int $suffixlength characters of the suffix, 0 for none
     * @return string
     */
    private static function unique_code(string $number, int $suffixlength): string {
        global $DB;

        for ($i = 0; $i < 10; $i++) {
            $code = $number;
            if ($suffixlength > 0) {
                $code .= '-' . self::random_suffix($suffixlength);
            }
            if (!$DB->record_exists('customcert_issues', ['code' => $code])) {
                return $code;
            }
            if ($suffixlength === 0) {
                break;
            }
        }
        throw new moodle_exception('codegenerationfailed', 'local_certledger');
    }

    /**
     * Returns random characters from {@see SUFFIX_ALPHABET}, using a cryptographically secure generator.
     *
     * @param int $length
     * @return string
     */
    private static function random_suffix(int $length): string {
        $max = strlen(self::SUFFIX_ALPHABET) - 1;
        do {
            $suffix = '';
            for ($i = 0; $i < $length; $i++) {
                $suffix .= self::SUFFIX_ALPHABET[random_int(0, $max)];
            }
            // At least one letter, so the suffix can never be read as a number by parse_code().
        } while (!preg_match('/[A-Z]/', $suffix));
        return $suffix;
    }

    /**
     * Returns the year of a timestamp in the site timezone.
     *
     * @param int $timestamp
     * @return string
     */
    public static function year_of(int $timestamp): string {
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(\core_date::get_server_timezone_object());
        return $date->format('Y');
    }

    /**
     * Returns an example of the number and of the printed code with the current settings.
     *
     * @return stdClass number, code
     */
    public static function get_example(): stdClass {
        $settings = self::get_settings();
        $number = $settings->prefix . ($settings->yearlyreset ? '-' . self::year_of(time()) : '') . '-'
            . str_pad('1', $settings->digits, '0', STR_PAD_LEFT);
        $code = $number . ($settings->suffixlength > 0 ? '-' . str_repeat('X', $settings->suffixlength) : '');
        return (object)['number' => $number, 'code' => $code];
    }

    /**
     * Annuls a valid certificate: marks it in the register and deletes every copy of it from the platform,
     * so it can no longer be downloaded or verified. The number is never reused.
     *
     * @param int $id register record id
     * @param string $reason why the certificate is annulled
     * @param int $byuserid who annuls it
     * @param bool $block also stop mod_customcert from issuing the certificate again to the learner, see {@see block()}
     * @return stdClass the updated register record
     */
    public static function annul(int $id, string $reason, int $byuserid, bool $block = false): stdClass {
        global $DB;

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        // Read inside the transaction: two simultaneous annulments must not both go through.
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        if ((int)$record->status !== self::STATUS_VALID) {
            $transaction->allow_commit();
            throw new moodle_exception('notvalid', 'local_certledger');
        }
        // Every issue with this code: the original and any copy made by a course restore.
        $issues = $DB->get_records('customcert_issues', ['code' => $record->certcode]);
        $record->status = self::STATUS_ANNULLED;
        $record->statusreason = $reason;
        $record->statusby = $byuserid;
        $record->timestatus = $now;
        $record->timemodified = $now;
        $DB->update_record(self::TABLE, $record);
        foreach ($issues as $issue) {
            $DB->delete_records('customcert_issues', ['id' => $issue->id]);
        }
        $transaction->allow_commit();

        foreach ($issues as $issue) {
            self::trigger_issue_deleted($issue);
        }
        if ($block) {
            self::block($record);
        }
        event\certificate_annulled::create([
            'context' => \context_system::instance(),
            'objectid' => $record->id,
            'relateduserid' => !empty($record->userid) ? $record->userid : null,
            'other' => ['number' => $record->certnumber],
        ])->trigger();

        return $record;
    }

    /**
     * Triggers the same event mod_customcert triggers when a teacher deletes an issue, so its logs stay complete.
     *
     * @param stdClass $issue the deleted customcert_issues record
     */
    private static function trigger_issue_deleted(stdClass $issue): void {
        $cm = get_coursemodule_from_instance('customcert', $issue->customcertid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        \mod_customcert\event\issue_deleted::create([
            'objectid' => $issue->id,
            'context' => \context_module::instance($cm->id),
            'relateduserid' => $issue->userid,
        ])->trigger();
    }

    /**
     * Returns the role that forbids mod/customcert:receiveissue, creating it on first use.
     *
     * mod_customcert issues a certificate again as soon as the learner opens the activity, or within a minute
     * when automatic issuing or student emails are on: assigning this role in the activity context stops both.
     *
     * @param bool $create
     * @return int|null role id, null if it does not exist and $create is false
     */
    public static function get_block_roleid(bool $create = false): ?int {
        global $DB;

        if ($roleid = $DB->get_field('role', 'id', ['shortname' => self::BLOCK_ROLE])) {
            return (int)$roleid;
        }
        if (!$create) {
            return null;
        }
        $roleid = create_role(
            get_string('blockrolename', 'local_certledger'),
            self::BLOCK_ROLE,
            get_string('blockroledesc', 'local_certledger')
        );
        set_role_contextlevels($roleid, [CONTEXT_MODULE]);
        assign_capability('mod/customcert:receiveissue', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        return (int)$roleid;
    }

    /**
     * Stops mod_customcert from issuing the certificate of a register record again to its learner.
     *
     * @param stdClass $record register record
     * @return bool false if the activity no longer exists or the learner is anonymised
     */
    public static function block(stdClass $record): bool {
        global $DB;

        if (empty($record->cmid) || empty($record->userid)) {
            return false;
        }
        $context = \context_module::instance($record->cmid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        role_assign(self::get_block_roleid(true), $record->userid, $context->id, 'local_certledger', $record->id);
        if (empty($record->timeblocked)) {
            $record->timeblocked = time();
            $DB->set_field(self::TABLE, 'timeblocked', $record->timeblocked, ['id' => $record->id]);
        }
        return true;
    }

    /**
     * Removes the block set by {@see block()}.
     *
     * @param stdClass $record register record
     */
    public static function unblock(stdClass $record): void {
        global $DB;

        if ($roleid = self::get_block_roleid()) {
            role_unassign_all(['roleid' => $roleid, 'component' => 'local_certledger', 'itemid' => $record->id]);
        }
        $record->timeblocked = null;
        $DB->set_field(self::TABLE, 'timeblocked', null, ['id' => $record->id]);
    }

    /**
     * Whether the block set by {@see block()} is active.
     *
     * @param stdClass $record register record
     * @return bool
     */
    public static function is_blocked(stdClass $record): bool {
        return !empty($record->timeblocked);
    }

    /**
     * Marks a valid certificate as removed from the platform. Annulled certificates are left unchanged.
     *
     * @param int $issueid customcert_issues.id
     * @param int $byuserid who deleted it, 0 if unknown
     * @param int|null $time when it was deleted, now if null
     * @return bool true if the register changed
     */
    public static function mark_removed(int $issueid, int $byuserid = 0, ?int $time = null): bool {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['issueid' => $issueid, 'status' => self::STATUS_VALID]);
        if (!$record) {
            return false;
        }
        $record->status = self::STATUS_REMOVED;
        $record->statusby = $byuserid ?: null;
        $record->statusreason = null;
        $record->timestatus = $time ?? time();
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);
        return true;
    }

    /**
     * Returns the issues not in the register, excluding the copies of registered certificates (same code).
     *
     * @param int $since consider the issues created from this time
     * @param int $limit 0 for all
     * @param bool $ownonly only the issues whose code is one of our numbers (see {@see parse_code()})
     * @return stdClass[]
     */
    public static function get_unregistered_issues(int $since, int $limit = 0, bool $ownonly = false): array {
        global $DB;

        $sql = "SELECT i.id, i.userid, i.customcertid, i.code, i.timecreated
                  FROM {customcert_issues} i
                 WHERE NOT EXISTS (SELECT 1 FROM {" . self::TABLE . "} r WHERE r.issueid = i.id)
                   AND NOT EXISTS (SELECT 1 FROM {" . self::TABLE . "} c WHERE c.certcode = i.code)
                   AND i.timecreated >= :since";
        $params = ['since' => $since];
        if ($ownonly) {
            $sql .= ' AND ' . $DB->sql_like('i.code', ':hyphen', false, false);
            $params['hyphen'] = '%-%';
        }
        $sql .= ' ORDER BY i.timecreated, i.id';
        $issues = $DB->get_records_sql($sql, $params, 0, $ownonly ? 0 : $limit);
        if ($ownonly) {
            $issues = array_filter($issues, fn($issue) => self::parse_code($issue->code) !== null);
            if ($limit > 0) {
                $issues = array_slice($issues, 0, $limit, true);
            }
        }
        return $issues;
    }

    /**
     * Counts the issues not in the register that are copies of registered certificates (same code).
     *
     * @return int
     */
    public static function count_copies(): int {
        global $DB;

        return (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {customcert_issues} i
               JOIN {" . self::TABLE . "} c ON c.certcode = i.code
              WHERE NOT EXISTS (SELECT 1 FROM {" . self::TABLE . "} r WHERE r.issueid = i.id)"
        );
    }

    /**
     * Counts the issues created since a time that still wait for a number.
     *
     * @param int $since
     * @return int
     */
    public static function count_unregistered(int $since): int {
        global $DB;

        return (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {customcert_issues} i
              WHERE NOT EXISTS (SELECT 1 FROM {" . self::TABLE . "} r WHERE r.issueid = i.id)
                AND NOT EXISTS (SELECT 1 FROM {" . self::TABLE . "} c WHERE c.certcode = i.code)
                AND i.timecreated >= :since",
            ['since' => $since]
        );
    }

    /**
     * Brings the register in line with the issues table. Run by the scheduled task, safe to run at any time.
     *
     * 1. relinks a valid or removed certificate whose issue disappeared to the one live issue with its code
     *    (activity restored from the recycle bin or from a backup);
     * 2. deletes the copies of annulled certificates (same code), so an annulled certificate never comes back,
     *    and sets again the re-issue blocks whose role assignment Moodle dropped;
     * 3. records the issues that already carry one of our numbers, whatever their date (plugin reinstalled);
     * 4. numbers the issues the observer could not number;
     * 5. marks as removed the certificates deleted without an event (course reset, privacy deletion);
     * 6. restores the certificates marked as removed whose deletion did not complete;
     * 7. rewrites the registered code on the issue if something changed it.
     *
     * @param int|null $since number issues created from this time, default: when numbering was enabled
     * @param int $limit max issues to number in one run, 0 for all
     * @return stdClass counters: relinked, annulledcopies, reblocked, adopted, registered, skipped, failed, removed,
     *                  restored, codesfixed; errors: messages of the failures (at most MAX_ERRORS)
     */
    public static function reconcile(?int $since = null, int $limit = 500): stdClass {
        global $DB;

        $result = (object)['relinked' => 0, 'annulledcopies' => 0, 'reblocked' => 0, 'adopted' => 0, 'registered' => 0,
            'skipped' => 0, 'failed' => 0, 'removed' => 0, 'restored' => 0, 'codesfixed' => 0, 'errors' => []];
        $since = $since ?? self::get_settings()->enabledsince;
        $fail = function (string $what, \Throwable $e) use ($result) {
            $result->failed++;
            if (count($result->errors) < self::MAX_ERRORS) {
                // No debuginfo: for DB errors it carries the SQL parameters, i.e. personal data.
                $result->errors[] = $what . ': ' . get_class($e) . ': ' . $e->getMessage();
            }
        };

        // 1. Certificates whose issue disappeared but whose code is back on a live, unregistered issue.
        $sql = "SELECT r.*
                  FROM {" . self::TABLE . "} r
             LEFT JOIN {customcert_issues} i ON i.id = r.issueid
                 WHERE i.id IS NULL AND r.status IN (:valid, :removed)
                   AND EXISTS (SELECT 1
                                 FROM {customcert_issues} i2
                                WHERE i2.code = r.certcode
                                  AND NOT EXISTS (SELECT 1 FROM {" . self::TABLE . "} r2 WHERE r2.issueid = i2.id))";
        foreach ($DB->get_records_sql($sql, ['valid' => self::STATUS_VALID, 'removed' => self::STATUS_REMOVED]) as $record) {
            try {
                $live = array_filter(
                    $DB->get_records('customcert_issues', ['code' => $record->certcode]),
                    fn($issue) => !$DB->record_exists(self::TABLE, ['issueid' => $issue->id])
                );
                if (count($live) !== 1) {
                    continue;
                }
                $issue = reset($live);
                if (!$links = self::links($issue)) {
                    continue;
                }
                $DB->update_record(self::TABLE, (object)[
                    'id' => $record->id,
                    'issueid' => (int)$issue->id,
                    'customcertid' => $links->customcertid,
                    'cmid' => $links->cmid,
                    'courseid' => $links->courseid,
                    'status' => self::STATUS_VALID,
                    'statusby' => null,
                    'statusreason' => null,
                    'timestatus' => null,
                    'timemodified' => time(),
                ]);
                $result->relinked++;
            } catch (\Throwable $e) {
                $fail('relink ' . $record->certnumber, $e);
            }
        }

        // 2. Copies of annulled certificates.
        $sql = "SELECT i.*
                  FROM {customcert_issues} i
                  JOIN {" . self::TABLE . "} r ON r.certcode = i.code
                 WHERE r.status = :annulled";
        foreach ($DB->get_records_sql($sql, ['annulled' => self::STATUS_ANNULLED]) as $issue) {
            try {
                $DB->delete_records('customcert_issues', ['id' => $issue->id]);
                self::trigger_issue_deleted($issue);
                $result->annulledcopies++;
            } catch (\Throwable $e) {
                $fail('annulled copy issue ' . $issue->id, $e);
            }
        }

        // 2b. Blocks whose role assignment Moodle dropped (e.g. on unenrolment): set it again.
        $sql = "SELECT r.*
                  FROM {" . self::TABLE . "} r
                 WHERE r.status = :annulled AND r.timeblocked IS NOT NULL AND r.cmid IS NOT NULL AND r.userid > 0
                   AND NOT EXISTS (SELECT 1 FROM {role_assignments} ra WHERE ra.component = :component AND ra.itemid = r.id)";
        foreach ($DB->get_records_sql($sql, ['annulled' => self::STATUS_ANNULLED, 'component' => 'local_certledger']) as $record) {
            try {
                if (self::block($record)) {
                    $result->reblocked++;
                }
            } catch (\Throwable $e) {
                $fail('block ' . $record->certnumber, $e);
            }
        }

        // 3. Issues that already carry one of our numbers, whatever their date.
        foreach (self::get_unregistered_issues(0, 0, true) as $issue) {
            try {
                if (self::register_issue((int)$issue->id)) {
                    $result->adopted++;
                } else if (count($result->errors) < self::MAX_ERRORS) {
                    $result->errors[] = 'issue ' . $issue->id . ': its number ' . $issue->code
                        . ' is already in the ledger with another code, or its activity no longer exists';
                }
            } catch (\Throwable $e) {
                $fail('adopt issue ' . $issue->id, $e);
            }
        }

        // 4. Numbering.
        foreach (self::get_unregistered_issues($since, $limit) as $issue) {
            try {
                if (self::register_issue((int)$issue->id)) {
                    $result->registered++;
                }
            } catch (\Throwable $e) {
                $fail('number issue ' . $issue->id, $e);
            }
        }
        $result->skipped = self::count_copies();

        // 5. Deleted without an event.
        $sql = "SELECT r.issueid
                  FROM {" . self::TABLE . "} r
             LEFT JOIN {customcert_issues} i ON i.id = r.issueid
                 WHERE r.status = :status AND i.id IS NULL";
        foreach ($DB->get_fieldset_sql($sql, ['status' => self::STATUS_VALID]) as $issueid) {
            if (self::mark_removed((int)$issueid)) {
                $result->removed++;
            }
        }

        // 6. Removed, but the issue is still there.
        $sql = "SELECT r.id
                  FROM {" . self::TABLE . "} r
                  JOIN {customcert_issues} i ON i.id = r.issueid AND i.customcertid = r.customcertid
                                            AND (r.userid = 0 OR i.userid = r.userid)
                 WHERE r.status = :status";
        foreach ($DB->get_fieldset_sql($sql, ['status' => self::STATUS_REMOVED]) as $id) {
            $DB->update_record(self::TABLE, (object)[
                'id' => $id,
                'status' => self::STATUS_VALID,
                'statusby' => null,
                'statusreason' => null,
                'timestatus' => null,
                'timemodified' => time(),
            ]);
            $result->restored++;
        }

        // 7. Code changed on the issue.
        $sql = "SELECT r.id, r.issueid, r.certcode
                  FROM {" . self::TABLE . "} r
                  JOIN {customcert_issues} i ON i.id = r.issueid AND i.customcertid = r.customcertid
                                            AND (r.userid = 0 OR i.userid = r.userid)
                 WHERE r.status = :status AND (i.code IS NULL OR i.code <> r.certcode)";
        foreach ($DB->get_records_sql($sql, ['status' => self::STATUS_VALID]) as $row) {
            $DB->set_field('customcert_issues', 'code', $row->certcode, ['id' => $row->issueid]);
            $result->codesfixed++;
        }

        return $result;
    }

    /**
     * Formats the counters of a reconciliation for logs.
     *
     * @param stdClass $result see {@see reconcile()}
     * @return string
     */
    public static function format_result(stdClass $result): string {
        return 'relinked: ' . $result->relinked . ', annulled copies deleted: ' . $result->annulledcopies
            . ', blocks restored: ' . $result->reblocked . ', adopted: ' . $result->adopted
            . ', numbered: ' . $result->registered . ', copies left: ' . $result->skipped . ', failed: ' . $result->failed
            . ', marked as removed: ' . $result->removed . ', restored: ' . $result->restored
            . ', codes fixed: ' . $result->codesfixed;
    }

    /**
     * Counts the register records by status.
     *
     * @return stdClass total, valid, annulled, removed
     */
    public static function get_summary(): stdClass {
        global $DB;

        $counts = $DB->get_records_sql_menu('SELECT status, COUNT(1) FROM {' . self::TABLE . '} GROUP BY status');
        $summary = (object)[
            'valid' => (int)($counts[self::STATUS_VALID] ?? 0),
            'annulled' => (int)($counts[self::STATUS_ANNULLED] ?? 0),
            'removed' => (int)($counts[self::STATUS_REMOVED] ?? 0),
        ];
        $summary->total = $summary->valid + $summary->annulled + $summary->removed;
        return $summary;
    }

    /**
     * Returns the years with certificates in the register, newest first.
     *
     * @return array year => year
     */
    public static function get_years(): array {
        global $DB;

        $range = $DB->get_record_sql('SELECT MIN(timeissued) AS mintime, MAX(timeissued) AS maxtime FROM {' . self::TABLE . '}');
        if (empty($range->mintime)) {
            return [];
        }
        $years = [];
        for ($year = (int)self::year_of((int)$range->maxtime); $year >= (int)self::year_of((int)$range->mintime); $year--) {
            $years[$year] = (string)$year;
        }
        return $years;
    }

    /**
     * Returns the courses with certificates in the register.
     *
     * @return array courseid => course name
     */
    public static function get_courses(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT courseid, MAX(coursename) AS coursename FROM {' . self::TABLE . '} GROUP BY courseid ORDER BY MAX(coursename)'
        );
        $context = \context_system::instance();
        $courses = [];
        foreach ($rows as $row) {
            $courses[$row->courseid] = format_string($row->coursename, true, ['context' => $context]);
        }
        return $courses;
    }

    /**
     * Returns the label of a status.
     *
     * @param int $status
     * @return string
     */
    public static function status_label(int $status): string {
        switch ($status) {
            case self::STATUS_ANNULLED:
                return get_string('status_annulled', 'local_certledger');
            case self::STATUS_REMOVED:
                return get_string('status_removed', 'local_certledger');
            default:
                return get_string('status_valid', 'local_certledger');
        }
    }

    /**
     * Removes the personal data of a user from the register, keeping number, course, dates and status
     * so the numbering stays complete.
     *
     * @param int $userid
     */
    public static function anonymise_user(int $userid): void {
        global $DB;

        if ($userid <= 0) {
            return;
        }
        $params = ['anon' => get_string('anonymised', 'local_certledger'), 'now' => time(), 'userid' => $userid];
        $DB->execute(
            "UPDATE {" . self::TABLE . "}
                SET userid = 0, firstname = '', lastname = :anon, email = '', cohorts = '', statusreason = '',
                    timemodified = :now
              WHERE userid = :userid",
            $params
        );
        $DB->execute(
            "UPDATE {" . self::TABLE . "} SET statusby = 0, timemodified = :now WHERE statusby = :userid",
            ['now' => time(), 'userid' => $userid]
        );
    }

    /**
     * Removes the personal data of every user from the register.
     */
    public static function anonymise_all(): void {
        global $DB;

        $DB->execute(
            "UPDATE {" . self::TABLE . "}
                SET userid = 0, firstname = '', lastname = :anon, email = '', cohorts = '', statusreason = '',
                    statusby = 0, timemodified = :now",
            ['anon' => get_string('anonymised', 'local_certledger'), 'now' => time()]
        );
    }
}
