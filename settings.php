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
 * Admin settings and register page.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_certledger', new lang_string('settings', 'local_certledger'));

    $settings->add(new admin_setting_heading(
        'local_certledger/numberingheading',
        new lang_string('numbering', 'local_certledger'),
        new lang_string('numbering_desc', 'local_certledger')
    ));
    $settings->add(new admin_setting_configtext(
        'local_certledger/prefix',
        new lang_string('prefix', 'local_certledger'),
        new lang_string('prefix_desc', 'local_certledger'),
        'CERT',
        '/^(?=[0-9]*[A-Za-z])[A-Za-z0-9]{1,10}$/',
        10
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_certledger/yearlyreset',
        new lang_string('yearlyreset', 'local_certledger'),
        new lang_string('yearlyreset_desc', 'local_certledger'),
        1
    ));
    $settings->add(new admin_setting_configselect(
        'local_certledger/digits',
        new lang_string('digits', 'local_certledger'),
        new lang_string('digits_desc', 'local_certledger'),
        6,
        array_combine(range(4, 8), range(4, 8))
    ));
    $settings->add(new admin_setting_configselect(
        'local_certledger/suffixlength',
        new lang_string('suffixlength', 'local_certledger'),
        new lang_string('suffixlength_desc', 'local_certledger'),
        5,
        [
            0 => new lang_string('suffixnone', 'local_certledger'),
            4 => 4,
            5 => 5,
            6 => 6,
        ]
    ));

    $settings->add(new admin_setting_heading(
        'local_certledger/privacyheading',
        new lang_string('privacyheading', 'local_certledger'),
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_certledger/privacyanonymise',
        new lang_string('privacyanonymise', 'local_certledger'),
        new lang_string('privacyanonymise_desc', 'local_certledger'),
        1
    ));

    $ADMIN->add('localplugins', $settings);
}

// Local plugin settings are loaded for every user: managers with the capability see the ledger under Reports.
$ADMIN->add('reports', new admin_externalpage(
    'local_certledger_register',
    new lang_string('register', 'local_certledger'),
    new moodle_url('/local/certledger/index.php'),
    'local/certledger:view'
));
