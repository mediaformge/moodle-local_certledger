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
 * Register table.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_certledger\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

use html_writer;
use local_certledger\registry;
use moodle_url;
use stdClass;

/**
 * Lists the register with filters, sorting, paging and download in every enabled format.
 */
class register_table extends \table_sql {
    /** @var bool Whether the current user can annul certificates. */
    protected $canannul = false;

    /** @var string[] Full names of the users who annulled or removed certificates, by id. */
    protected $usernames = [];

    /** @var bool Whether any filter is active (changes the empty message). */
    protected $hasfilters = false;

    /** @var bool[] Whether the viewer can download the PDFs of a course module, by cmid. */
    protected $candownload = [];

    /**
     * Defines columns and query. Call after {@see is_downloading()}.
     *
     * @param array $filters year, courseid, status, search
     * @param bool $canannul whether to show the annul action
     */
    public function setup_register(array $filters, bool $canannul): void {
        $this->canannul = $canannul;
        $this->hasfilters = !empty($filters['year']) || !empty($filters['courseid'])
            || trim((string)($filters['search'] ?? '')) !== ''
            || (isset($filters['status']) && (int)$filters['status'] >= 0);
        if (method_exists($this, 'set_caption')) {
            $this->set_caption(get_string('register', 'local_certledger'), ['class' => 'visually-hidden']);
        }

        $columns = ['certnumber', 'certcode', 'lastname', 'firstname', 'email', 'coursename', 'certname', 'cohorts',
            'timeissued', 'status'];
        $headers = [
            get_string('certnumber', 'local_certledger'),
            get_string('certcode', 'local_certledger'),
            get_string('lastname'),
            get_string('firstname'),
            get_string('email'),
            get_string('course'),
            get_string('certname', 'local_certledger'),
            get_string('cohorts', 'local_certledger'),
            get_string('timeissued', 'local_certledger'),
            get_string('status', 'local_certledger'),
        ];
        if (!$this->is_downloading()) {
            $columns[] = 'actions';
            $headers[] = get_string('actions');
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->sortable(true, 'timeissued', SORT_DESC);
        $this->no_sorting('certcode');
        $this->no_sorting('cohorts');
        $this->no_sorting('actions');
        $this->collapsible(false);
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);
        $this->set_attribute('class', 'generaltable table-sm local-certledger-table');

        [$where, $params] = self::build_where($filters);
        $this->set_sql('r.*', '{' . registry::TABLE . '} r', $where, $params);
        $this->set_count_sql('SELECT COUNT(1) FROM {' . registry::TABLE . '} r WHERE ' . $where, $params);
    }

    /**
     * Builds the WHERE clause for the filters.
     *
     * @param array $filters year, courseid, status (-1 for all), search
     * @return array [sql, params]
     */
    public static function build_where(array $filters): array {
        global $DB;

        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['year'])) {
            $timezone = \core_date::get_server_timezone_object();
            $from = new \DateTime((int)$filters['year'] . '-01-01 00:00:00', $timezone);
            $to = new \DateTime(((int)$filters['year'] + 1) . '-01-01 00:00:00', $timezone);
            $where[] = 'r.timeissued >= :yearfrom AND r.timeissued < :yearto';
            $params['yearfrom'] = $from->getTimestamp();
            $params['yearto'] = $to->getTimestamp();
        }
        if (!empty($filters['courseid'])) {
            $where[] = 'r.courseid = :courseid';
            $params['courseid'] = (int)$filters['courseid'];
        }
        if (isset($filters['status']) && (int)$filters['status'] >= 0) {
            $where[] = 'r.status = :status';
            $params['status'] = (int)$filters['status'];
        }
        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $conditions = [];
            $fields = ['r.certnumber', 'r.certcode', 'r.origcode', 'r.lastname', 'r.firstname', 'r.email', 'r.cohorts',
                $DB->sql_concat('r.firstname', "' '", 'r.lastname'), $DB->sql_concat('r.lastname', "' '", 'r.firstname')];
            foreach ($fields as $i => $field) {
                $conditions[] = $DB->sql_like($field, ':search' . $i, false, false);
                $params['search' . $i] = '%' . $DB->sql_like_escape($search) . '%';
            }
            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Adds the assignment order as tie-breaker, so certificates issued in the same second keep their number order.
     *
     * @return string
     */
    public function get_sql_sort() {
        $sort = parent::get_sql_sort();
        $direction = preg_match('/\bASC\s*$/i', (string)$sort) ? 'ASC' : 'DESC';
        return ($sort ? $sort . ', ' : '') . 'id ' . $direction;
    }

    /**
     * Escapes text for the page and for downloads that render HTML; other downloads get the plain value.
     *
     * @param string|null $value
     * @return string
     */
    protected function text(?string $value): string {
        $value = (string)$value;
        if ($this->is_downloading() && !$this->export_class_instance()->supports_html()) {
            return $value;
        }
        return s($value);
    }

    /**
     * Number column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_certnumber($row) {
        if ($this->is_downloading()) {
            return $this->text($row->certnumber);
        }
        return html_writer::tag('strong', s($row->certnumber), ['class' => 'local-certledger-number']);
    }

    /**
     * Printed code column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_certcode($row) {
        if ($this->is_downloading()) {
            return $this->text($row->certcode);
        }
        return html_writer::tag('code', s($row->certcode), ['class' => 'local-certledger-code']);
    }

    /**
     * Last name column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_lastname($row) {
        return $this->text($row->lastname);
    }

    /**
     * First name column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_firstname($row) {
        return $this->text($row->firstname);
    }

    /**
     * Email column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_email($row) {
        return $this->text($row->email);
    }

    /**
     * Course column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_coursename($row) {
        if ($this->is_downloading()) {
            return $this->text($row->coursename);
        }
        return format_string($row->coursename, true, ['context' => \context_system::instance()]);
    }

    /**
     * Certificate column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_certname($row) {
        if ($this->is_downloading()) {
            return $this->text($row->certname);
        }
        return format_string($row->certname, true, ['context' => \context_system::instance()]);
    }

    /**
     * Cohorts column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_cohorts($row) {
        return $this->text($row->cohorts);
    }

    /**
     * Issue date column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_timeissued($row) {
        return userdate($row->timeissued, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Status column, with who and when for annulled and removed certificates.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_status($row) {
        $status = (int)$row->status;
        $label = registry::status_label($status);
        $details = '';
        if ($status !== registry::STATUS_VALID) {
            $a = (object)[
                'date' => $row->timestatus ? userdate($row->timestatus, get_string('strftimedatetimeshort', 'langconfig')) : '-',
                'by' => $this->user_name((int)$row->statusby),
                'reason' => (string)$row->statusreason,
            ];
            if ($status === registry::STATUS_ANNULLED) {
                $stringid = 'statusannulledinfo';
            } else {
                $stringid = empty($row->statusby) ? 'statusremovedinfo' : 'statusremovedbyinfo';
            }
            $details = get_string($stringid, 'local_certledger', $a);
        }
        if ($this->is_downloading()) {
            return $this->text($details === '' ? $label : $label . ' - ' . $details);
        }
        $classes = [
            registry::STATUS_VALID => 'text-bg-success',
            registry::STATUS_ANNULLED => 'text-bg-danger',
            registry::STATUS_REMOVED => 'text-bg-secondary',
        ];
        $output = html_writer::span(s($label), 'badge ' . ($classes[$status] ?? 'text-bg-secondary'));
        if ($status === registry::STATUS_ANNULLED && registry::is_blocked($row)) {
            $output .= ' ' . html_writer::span(get_string('blocked', 'local_certledger'), 'badge text-bg-dark');
        }
        if ($details !== '') {
            $output .= html_writer::div(s($details), 'small text-muted mt-1');
        }
        return $output;
    }

    /**
     * Actions column: certificate PDF and annulment.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_actions($row) {
        if ((int)$row->status === registry::STATUS_ANNULLED && $this->canannul && registry::is_blocked($row)) {
            $url = new moodle_url('/local/certledger/annul.php', ['id' => $row->id]);
            return html_writer::link(
                $url,
                get_string('unblock', 'local_certledger'),
                ['class' => 'btn btn-sm btn-outline-secondary']
            );
        }
        if ((int)$row->status !== registry::STATUS_VALID) {
            return '';
        }
        $links = [];
        if (!empty($row->cmid) && !empty($row->userid) && $this->can_download((int)$row->cmid)) {
            // Custom certificate checks again that the viewer may see the report and that the certificate exists.
            $url = new moodle_url('/mod/customcert/view.php', ['id' => $row->cmid, 'downloadissue' => $row->userid]);
            $links[] = html_writer::link($url, get_string('downloadpdf', 'local_certledger'), [
                'class' => 'btn btn-sm btn-outline-secondary',
                'target' => '_blank',
                'rel' => 'noopener',
                'aria-label' => get_string('downloadpdfof', 'local_certledger', $row->certnumber),
            ]);
        }
        if ($this->canannul) {
            $url = new moodle_url('/local/certledger/annul.php', ['id' => $row->id]);
            $links[] = html_writer::link(
                $url,
                get_string('annul', 'local_certledger'),
                ['class' => 'btn btn-sm btn-outline-danger']
            );
        }
        return html_writer::div(implode(' ', $links), 'd-flex gap-1 flex-wrap');
    }

    /**
     * Shown when no certificate matches.
     */
    public function print_nothing_to_display() {
        global $OUTPUT;
        echo $OUTPUT->notification(
            get_string($this->hasfilters ? 'nocertificates' : 'registerempty', 'local_certledger'),
            'info',
            false
        );
    }

    /**
     * Whether the viewer may download the PDFs of an activity (mod/customcert:viewreport in its context).
     *
     * @param int $cmid
     * @return bool
     */
    protected function can_download(int $cmid): bool {
        if (!isset($this->candownload[$cmid])) {
            $context = \context_module::instance($cmid, IGNORE_MISSING);
            $this->candownload[$cmid] = $context && has_capability('mod/customcert:viewreport', $context);
        }
        return $this->candownload[$cmid];
    }

    /**
     * Returns the full name of a user, cached.
     *
     * @param int $userid
     * @return string
     */
    protected function user_name(int $userid): string {
        if ($userid <= 0) {
            return '-';
        }
        if (!isset($this->usernames[$userid])) {
            $user = \core_user::get_user($userid);
            $this->usernames[$userid] = ($user && empty($user->deleted)) ? fullname($user) : get_string('unknownuser');
        }
        return $this->usernames[$userid];
    }
}
