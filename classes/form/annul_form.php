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
 * Annulment form.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_certledger\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Asks for the reason of the annulment. The session key is checked by moodleform.
 */
class annul_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement(
            'textarea',
            'reason',
            get_string('annulreason', 'local_certledger'),
            ['rows' => 4, 'cols' => 60, 'maxlength' => 1000]
        );
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', null, 'required', null, 'client');
        $mform->addRule('reason', get_string('maximumchars', '', 1000), 'maxlength', 1000, 'client');

        if (!empty($this->_customdata['canblock'])) {
            $mform->addElement(
                'advcheckbox',
                'block',
                get_string('blockreissue', 'local_certledger'),
                get_string('blockreissue_desc', 'local_certledger')
            );
            $mform->setDefault('block', 1);
        }

        // Explicit labels: in Italian the standard "cancel" string reads "Annulla", which would be ambiguous here.
        $buttons = [
            $mform->createElement(
                'submit',
                'annulbutton',
                get_string('confirmannul', 'local_certledger'),
                ['class' => 'btn btn-danger']
            ),
            $mform->createElement('cancel', 'cancel', get_string('backtoregister', 'local_certledger')),
        ];
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Rejects a reason made only of spaces.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim($data['reason'] ?? '') === '') {
            $errors['reason'] = get_string('required');
        }
        return $errors;
    }
}
