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
 * Restore of the NextCloud documents of each submission.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_tipnc\api\document;
use assignsubmission_tipnc\task\restore_document;

/**
 * Restore of the NextCloud documents of each submission.
 *
 * Nothing is written here: each document is queued to be copied, and its row is
 * created once the copy exists. Pointing the restored submission at the original
 * document would make two assignments write in the same file.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_assignsubmission_tipnc_subplugin extends restore_subplugin {
    /**
     * Paths handled at submission level.
     *
     * @return restore_path_element[] The paths.
     */
    protected function define_submission_subplugin_structure(): array {
        return [
            new restore_path_element($this->get_namefor('submission'), $this->get_pathfor('/submission_tipnc')),
        ];
    }

    /**
     * Queues the copy of the documents of one restored submission.
     *
     * @param  array|stdClass $data The element.
     * @return void
     */
    public function process_assignsubmission_tipnc_submission($data): void {
        $data = (object) $data;

        $submission = (int) $this->get_mappingid('submission', $data->submission);
        if ($submission === 0) {
            return;
        }

        $userid = (int) $this->task->get_userid();

        $documents = [
            document::MODE_OPEN => (string) ($data->openpath ?? ''),
            document::MODE_SUBMISSION => (string) ($data->submpath ?? ''),
        ];

        foreach ($documents as $mode => $source) {
            if ($source !== '') {
                restore_document::queue($submission, $mode, $source, $userid);
            }
        }
    }
}
