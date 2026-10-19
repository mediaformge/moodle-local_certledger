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
 * Annuls a certificate of the register, or lifts the block on an annulled one.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_certledger\form\annul_form;
use local_certledger\registry;

$id = required_param('id', PARAM_INT);
$unblock = optional_param('unblock', 0, PARAM_BOOL);

// Checks login and the view capability, then the annul capability.
admin_externalpage_setup(
    'local_certledger_register',
    '',
    ['id' => $id],
    new moodle_url('/local/certledger/annul.php')
);
$context = context_system::instance();
require_capability('local/certledger:annul', $context);

$record = $DB->get_record(registry::TABLE, ['id' => $id], '*', MUST_EXIST);
$returnurl = new moodle_url('/local/certledger/index.php');
$number = s($record->certnumber);

if ($unblock) {
    require_sesskey();
    if ((int)$record->status === registry::STATUS_ANNULLED && registry::is_blocked($record)) {
        registry::unblock($record);
        redirect(
            $returnurl,
            get_string('unblocked', 'local_certledger', $number),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect($returnurl);
}

if ((int)$record->status === registry::STATUS_ANNULLED && registry::is_blocked($record)) {
    // Annulled and blocked: offer to lift the block.
    $PAGE->navbar->add(get_string('unblock', 'local_certledger'));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('annulcertificate', 'local_certledger', $number));
    echo $OUTPUT->notification(get_string('blockedinfo', 'local_certledger'), 'info', false);
    echo $OUTPUT->single_button(
        new moodle_url('/local/certledger/annul.php', ['id' => $id, 'unblock' => 1]),
        get_string('unblock', 'local_certledger'),
        'post'
    );
    echo html_writer::div(html_writer::link($returnurl, get_string('backtoregister', 'local_certledger')), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

if ((int)$record->status !== registry::STATUS_VALID) {
    redirect($returnurl, get_string('notvalid', 'local_certledger'), null, \core\output\notification::NOTIFY_WARNING);
}

// The block needs the activity and a learner: both may be gone.
$canblock = !empty($record->cmid) && !empty($record->userid)
    && context_module::instance($record->cmid, IGNORE_MISSING) !== false;
$form = new annul_form(new moodle_url('/local/certledger/annul.php'), ['canblock' => $canblock]);
$form->set_data(['id' => $record->id]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    registry::annul((int)$record->id, trim($data->reason), (int)$USER->id, $canblock && !empty($data->block));
    redirect(
        $returnurl,
        get_string('annulled', 'local_certledger', $number),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->navbar->add(get_string('annulcertificate', 'local_certledger', $number));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('annulcertificate', 'local_certledger', $number));

$details = [
    get_string('certnumber', 'local_certledger') => $number,
    get_string('certcode', 'local_certledger') => html_writer::tag(
        'code',
        s($record->certcode),
        ['class' => 'local-certledger-code']
    ),
    get_string('learner', 'local_certledger') => s(trim($record->firstname . ' ' . $record->lastname)),
    get_string('email') => s($record->email),
    get_string('cohorts', 'local_certledger') => s($record->cohorts),
    get_string('course') => format_string($record->coursename, true, ['context' => $context]),
    get_string('certname', 'local_certledger') => format_string($record->certname, true, ['context' => $context]),
    get_string('timeissued', 'local_certledger') => userdate(
        $record->timeissued,
        get_string('strftimedatetimeshort', 'langconfig')
    ),
];
$list = '';
foreach ($details as $label => $value) {
    $list .= html_writer::tag('dt', $label, ['class' => 'col-sm-3']) . html_writer::tag('dd', $value, ['class' => 'col-sm-9']);
}
echo html_writer::tag('dl', $list, ['class' => 'row']);
echo $OUTPUT->notification(get_string('annulwarning', 'local_certledger'), 'warning', false);

// Custom certificate issues again within a minute when automatic issuing or student emails are on
// (issueautomatically exists from customcert 5.0.8 on).
$customcert = $DB->get_record('customcert', ['id' => $record->customcertid]);
if ($customcert && (!empty($customcert->emailstudents) || !empty($customcert->issueautomatically))) {
    echo $OUTPUT->notification(get_string('reissuewarning', 'local_certledger'), 'warning', false);
}

$form->display();

echo $OUTPUT->footer();
