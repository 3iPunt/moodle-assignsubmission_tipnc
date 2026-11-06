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
 * Prepares the brief of an assignment, at the request of whoever marks it.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_tipnc\log\code;
use assignsubmission_tipnc\task\create_enunciate;
use assignsubmission_tipnc\tipnc_enun;

require_once(__DIR__ . '/../../../../config.php');

$cmid = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');

require_login($course, false, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
require_capability('mod/assign:grade', $context);

$back = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);

// It already existing is not an error: two people can click at once, or
// cron may have got there first.
if (tipnc_enun::get((int) $cm->instance)) {
    redirect(
        $back,
        get_string('prepare_already', 'assignsubmission_tipnc'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

// A restored assignment gets back the brief it came with, if it may.
$response = create_enunciate::prepare((int) $cm->instance, (int) $USER->id);

if (!$response->success) {
    redirect(
        $back,
        get_string('prepare_failed', 'assignsubmission_tipnc'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// The brief is made and the assignment works, but whoever created it
// cannot write in it: naming the missing account saves opening the log.
if ((string) $response->error->code === code::SHARE_NO_ACCOUNT) {
    redirect(
        $back,
        get_string('prepare_noaccount', 'assignsubmission_tipnc', $USER->username),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

redirect(
    $back,
    get_string('prepare_done', 'assignsubmission_tipnc'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
