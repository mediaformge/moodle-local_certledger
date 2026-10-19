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
 * Event observers.
 *
 * issue_created is observed as an external observer: outside a transaction it runs immediately, so the number is
 * assigned before mod_customcert generates the PDF in the same request; inside a transaction Moodle calls it right
 * after the commit (and not at all after a rollback). issue_deleted stays internal, so it follows the deletion's
 * own rollback.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_customcert\event\issue_created',
        'callback' => '\local_certledger\observer::issue_created',
        'internal' => false,
    ],
    [
        'eventname' => '\mod_customcert\event\issue_deleted',
        'callback' => '\local_certledger\observer::issue_deleted',
    ],
];
