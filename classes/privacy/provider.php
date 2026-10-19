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
 * Privacy API implementation.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_certledger\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_certledger\registry;

/**
 * The register stores a copy of the learner data taken when the certificate is issued.
 *
 * Deletion requests anonymise the learner data and keep number, course, dates and status,
 * so the numbering of the register stays complete.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes the stored personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(registry::TABLE, [
            'userid' => 'privacy:metadata:userid',
            'issueid' => 'privacy:metadata:issueid',
            'customcertid' => 'privacy:metadata:customcertid',
            'cmid' => 'privacy:metadata:cmid',
            'courseid' => 'privacy:metadata:courseid',
            'origcode' => 'privacy:metadata:origcode',
            'firstname' => 'privacy:metadata:firstname',
            'lastname' => 'privacy:metadata:lastname',
            'email' => 'privacy:metadata:email',
            'cohorts' => 'privacy:metadata:cohorts',
            'certnumber' => 'privacy:metadata:certnumber',
            'certcode' => 'privacy:metadata:certcode',
            'coursename' => 'privacy:metadata:coursename',
            'certname' => 'privacy:metadata:certname',
            'timeissued' => 'privacy:metadata:timeissued',
            'status' => 'privacy:metadata:status',
            'statusreason' => 'privacy:metadata:statusreason',
            'statusby' => 'privacy:metadata:statusby',
            'timestatus' => 'privacy:metadata:timestatus',
        ], 'privacy:metadata:table');

        return $collection;
    }

    /**
     * The register lives in the system context.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :contextlevel
                   AND EXISTS (SELECT 1 FROM {" . registry::TABLE . "} r WHERE r.userid = :userid OR r.statusby = :statusby)";
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_SYSTEM, 'userid' => $userid, 'statusby' => $userid]);
        return $contextlist;
    }

    /**
     * Lists the users with data in the register.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . registry::TABLE . '} WHERE userid > 0', []);
        $userlist->add_from_sql('statusby', 'SELECT statusby FROM {' . registry::TABLE . '} WHERE statusby > 0', []);
    }

    /**
     * Exports the certificates of the user and the annulments they made.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            $certificates = [];
            foreach ($DB->get_records(registry::TABLE, ['userid' => $userid], 'timeissued, id') as $record) {
                $certificates[] = (object)[
                    'number' => $record->certnumber,
                    'code' => $record->certcode,
                    'firstname' => $record->firstname,
                    'lastname' => $record->lastname,
                    'email' => $record->email,
                    'cohorts' => $record->cohorts,
                    'course' => format_string($record->coursename, true, ['context' => $context]),
                    'certificate' => format_string($record->certname, true, ['context' => $context]),
                    'timeissued' => transform::datetime($record->timeissued),
                    'status' => registry::status_label((int)$record->status),
                    'statusreason' => $record->statusreason,
                    'timestatus' => $record->timestatus ? transform::datetime($record->timestatus) : null,
                ];
            }
            $actions = [];
            foreach ($DB->get_records(registry::TABLE, ['statusby' => $userid], 'timestatus, id') as $record) {
                $actions[] = (object)[
                    'number' => $record->certnumber,
                    'status' => registry::status_label((int)$record->status),
                    'statusreason' => $record->statusreason,
                    'timestatus' => $record->timestatus ? transform::datetime($record->timestatus) : null,
                ];
            }
            if ($certificates || $actions) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_certledger')],
                    (object)['certificates' => $certificates, 'actions' => $actions]
                );
            }
        }
    }

    /**
     * Whether deletion requests anonymise the ledger. Enabled by default; a site that must keep the ledger
     * as a record of the certificates issued (e.g. by law) can disable it in the settings.
     *
     * @return bool
     */
    protected static function anonymisation_enabled(): bool {
        return registry::get_settings()->privacyanonymise;
    }

    /**
     * Anonymises the whole register, if enabled.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_system && self::anonymisation_enabled()) {
            registry::anonymise_all();
        }
    }

    /**
     * Anonymises the data of one user, if enabled.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (!self::anonymisation_enabled()) {
            return;
        }
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                registry::anonymise_user((int)$contextlist->get_user()->id);
            }
        }
    }

    /**
     * Anonymises the data of several users, if enabled.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system || !self::anonymisation_enabled()) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            registry::anonymise_user((int)$userid);
        }
    }
}
