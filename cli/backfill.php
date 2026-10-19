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
 * Numbers the certificates issued before the plugin was installed, or missed for any reason.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_certledger\registry;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'since' => '', 'all' => false, 'run' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    echo "Numbers the certificates that are not in the register yet, in order of issue.

Without --run nothing is changed: the script only lists what would be numbered.
By default only the certificates issued since numbering was enabled are considered.

Options:
  --since=YYYY-MM-DD  Consider the certificates issued from this date
  --all               Consider every certificate, including those issued before the plugin was installed
  --run               Number them
  -h, --help          Show this help

Example:
  php public/local/certledger/cli/backfill.php --all
  php public/local/certledger/cli/backfill.php --all --run
(from the Moodle directory; without public/ on Moodle 5.0)
";
    exit(0);
}

$settings = registry::get_settings();
if ($options['all']) {
    $since = 0;
} else if ($options['since'] !== '') {
    $date = DateTime::createFromFormat('!Y-m-d', $options['since'], core_date::get_server_timezone_object());
    if (!$date || $date->format('Y-m-d') !== $options['since']) {
        cli_error('Invalid --since date, use YYYY-MM-DD.');
    }
    $since = $date->getTimestamp();
} else {
    $since = $settings->enabledsince;
}

$issues = registry::get_unregistered_issues($since);
cli_writeln(count($issues) . ' certificates not in the register' . ($since ? ' issued since ' . userdate($since) : '') . '.');
foreach ($issues as $issue) {
    $parsed = registry::parse_code($issue->code);
    cli_writeln('  issue ' . $issue->id . ', user ' . $issue->userid . ', certificate ' . $issue->customcertid
        . ', issued ' . userdate($issue->timecreated) . ', code ' . $issue->code
        . ($parsed ? ' -> keep number ' . $parsed->number : ' -> assign the next number'));
}

if (!$options['run']) {
    cli_writeln('Nothing changed. Add --run to number them.');
    exit(0);
}

$result = registry::reconcile($since, 0);
cli_writeln(registry::format_result($result));
foreach ($result->errors as $error) {
    cli_writeln('  ' . $error);
}
exit($result->failed ? 1 : 0);
