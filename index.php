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
 * Certificate register.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_certledger\registry;
use local_certledger\table\register_table;

$download = optional_param('download', '', PARAM_ALPHA);
if ($download !== '' && !array_key_exists($download, core_plugin_manager::instance()->get_enabled_plugins('dataformat'))) {
    $download = '';
}
$filters = [
    'year' => optional_param('year', 0, PARAM_INT),
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'status' => optional_param('status', -1, PARAM_INT),
    'search' => trim(optional_param('search', '', PARAM_TEXT)),
];
if ($filters['year'] < 1970 || $filters['year'] > 2100) {
    $filters['year'] = 0;
}
$urlparams = array_filter($filters, function ($value, $key) {
    return $key === 'status' ? $value >= 0 : !empty($value);
}, ARRAY_FILTER_USE_BOTH);

// Checks login and the local/certledger:view capability.
admin_externalpage_setup('local_certledger_register', '', $urlparams);

$context = context_system::instance();
$baseurl = new moodle_url('/local/certledger/index.php', $urlparams);
$canannul = has_capability('local/certledger:annul', $context);

$table = new register_table('local-certledger-register');
$table->is_downloading(
    $download,
    'certificate_ledger_' . userdate(time(), '%Y%m%d', 99, false),
    get_string('register', 'local_certledger')
);
$table->define_baseurl($baseurl);
$table->setup_register($filters, $canannul);

if ($table->is_downloading()) {
    \local_certledger\event\register_exported::create([
        'context' => $context,
        'other' => ['format' => $download],
    ])->trigger();
    $table->out(0, false);
    exit;
}

\local_certledger\event\register_viewed::create(['context' => $context])->trigger();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('register', 'local_certledger'));

$example = registry::get_example();
$settings = registry::get_settings();
echo html_writer::tag('p', get_string('registerintro', 'local_certledger', (object)[
    'number' => $example->number,
    'code' => $example->code,
    'since' => $settings->enabledsince ? userdate($settings->enabledsince, get_string('strftimedate', 'langconfig')) : '-',
]), ['class' => 'text-muted']);

$summary = registry::get_summary();
echo html_writer::div(
    html_writer::span(get_string('summarytotal', 'local_certledger', $summary->total), 'badge text-bg-dark me-2') .
    html_writer::span(get_string('summaryvalid', 'local_certledger', $summary->valid), 'badge text-bg-success me-2') .
    html_writer::span(get_string('summaryannulled', 'local_certledger', $summary->annulled), 'badge text-bg-danger me-2') .
    html_writer::span(get_string('summaryremoved', 'local_certledger', $summary->removed), 'badge text-bg-secondary'),
    'mb-3'
);

// Problems the scheduled reconciliation found, and certificates still waiting for a number.
$lasterrors = json_decode((string)get_config('local_certledger', 'lasterrors'), true) ?: [];
if ($lasterrors) {
    $lastrun = (int)get_config('local_certledger', 'lastrun');
    echo $OUTPUT->notification(
        get_string('warninglasterrors', 'local_certledger', $lastrun ? userdate($lastrun) : '-')
        . html_writer::alist(array_map('s', $lasterrors), ['class' => 'small mb-0 mt-2']),
        'warning',
        false
    );
}
if ($waiting = registry::count_unregistered($settings->enabledsince)) {
    echo $OUTPUT->notification(get_string('warningunregistered', 'local_certledger', $waiting), 'warning', false);
}

// Filters, sent with GET so that the filtered view can be bookmarked; nothing is changed by this form.
$field = function (string $label, string $id, string $control): string {
    return html_writer::div(html_writer::label($label, $id, true, ['class' => 'form-label small mb-1 d-block']) . $control);
};
$statusoptions = [
    -1 => get_string('all', 'local_certledger'),
    registry::STATUS_VALID => get_string('status_valid', 'local_certledger'),
    registry::STATUS_ANNULLED => get_string('status_annulled', 'local_certledger'),
    registry::STATUS_REMOVED => get_string('status_removed', 'local_certledger'),
];
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/certledger/index.php'))->out(false),
    'class' => 'local-certledger-filters d-flex flex-wrap gap-3 align-items-end mb-4',
    'role' => 'search',
]);
echo $field(
    get_string('filteryear', 'local_certledger'),
    'local-certledger-year',
    html_writer::select(
        [0 => get_string('all', 'local_certledger')] + registry::get_years(),
        'year',
        $filters['year'],
        false,
        ['id' => 'local-certledger-year', 'class' => 'form-select']
    )
);
echo $field(
    get_string('course'),
    'local-certledger-course',
    html_writer::select(
        [0 => get_string('all', 'local_certledger')] + registry::get_courses(),
        'courseid',
        $filters['courseid'],
        false,
        ['id' => 'local-certledger-course', 'class' => 'form-select']
    )
);
echo $field(
    get_string('status', 'local_certledger'),
    'local-certledger-status',
    html_writer::select(
        $statusoptions,
        'status',
        $filters['status'],
        false,
        ['id' => 'local-certledger-status', 'class' => 'form-select']
    )
);
echo $field(get_string('search'), 'local-certledger-search', html_writer::empty_tag('input', [
    'type' => 'search',
    'name' => 'search',
    'id' => 'local-certledger-search',
    'value' => $filters['search'],
    'class' => 'form-control local-certledger-search',
    'placeholder' => get_string('searchplaceholder', 'local_certledger'),
]));
echo html_writer::div(
    html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
        'value' => get_string('applyfilters', 'local_certledger')]) . ' ' .
    html_writer::link(
        new moodle_url('/local/certledger/index.php'),
        get_string('resetfilters', 'local_certledger'),
        ['class' => 'btn btn-outline-secondary']
    )
);
echo html_writer::end_tag('form');

$table->out(50, false);

echo $OUTPUT->footer();
