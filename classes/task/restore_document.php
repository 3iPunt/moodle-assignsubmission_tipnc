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
 * Copies the draft or the submission of a restored assignment.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\task;

use assignsubmission_tipnc\api\document;
use assignsubmission_tipnc\api\nextcloud;
use assignsubmission_tipnc\log\code;
use assignsubmission_tipnc\log\logger;
use assignsubmission_tipnc\models\restored;
use assignsubmission_tipnc\tipnc;
use assignsubmission_tipnc\tipnc_open;
use coding_exception;
use core\task\adhoc_task;
use core\task\manager;
use dml_exception;

/**
 * Copies the draft or the submission of a restored assignment.
 *
 * The restore only writes down what to copy: talking to NextCloud inside it would
 * make it as slow and as fragile as the network. The row is created here, once
 * the copy exists, so no submission ever points at the original document.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_document extends adhoc_task {
    /**
     * Copies the document, unless it is already there or may not be copied.
     *
     * @return void
     * @throws coding_exception If the context cannot be built.
     * @throws dml_exception If the tables cannot be read.
     */
    public function execute(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $data = (array) $this->get_custom_data();
        $submissionid = (int) ($data['submission'] ?? 0);
        $mode = (string) ($data['mode'] ?? '');
        $source = (string) ($data['source'] ?? '');

        $submission = $DB->get_record('assign_submission', ['id' => $submissionid]);
        if (!$submission || $source === '') {
            return;
        }

        // Retried tasks must not copy twice.
        $existing = $mode === document::MODE_OPEN ? tipnc_open::get($submissionid) : tipnc::get($submissionid);
        if ($existing) {
            return;
        }

        if (!(new restored())->may_copy($source, (int) $this->get_userid())) {
            logger::warning(code::RESTORE_REFUSED, 'restore_document', [
                'method' => 'restore_document:task',
                'assignment' => (int) $submission->assignment,
                'submission' => $submissionid,
                'documentpath' => $source,
            ]);
            return;
        }

        (new nextcloud((int) $submission->assignment))->restore_document($submission, $source, $mode);
    }

    /**
     * Asks for one document of a restored submission to be copied.
     *
     * @param  int    $submission The restored submission.
     * @param  string $mode       document::MODE_OPEN or document::MODE_SUBMISSION.
     * @param  string $source     Path of the document of the original assignment.
     * @param  int    $userid     Who restored, whose permissions decide the copy.
     * @return void
     */
    public static function queue(int $submission, string $mode, string $source, int $userid): void {
        $task = new self();
        $task->set_custom_data(['submission' => $submission, 'mode' => $mode, 'source' => $source]);
        $task->set_userid($userid);

        manager::queue_adhoc_task($task, true);
    }
}
