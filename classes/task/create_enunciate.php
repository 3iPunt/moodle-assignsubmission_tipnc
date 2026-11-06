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
 * Prepares the brief of an assignment in NextCloud.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\task;

use assignsubmission_tipnc\api\nextcloud;
use assignsubmission_tipnc\api\response;
use assignsubmission_tipnc\log\code;
use assignsubmission_tipnc\log\logger;
use assignsubmission_tipnc\models\restored;
use assignsubmission_tipnc\tipnc_enun;
use coding_exception;
use core\task\adhoc_task;
use dml_exception;

/**
 * Prepares the brief of an assignment in NextCloud.
 *
 * Copying the template, sharing it and looking up its identifier is three calls
 * over the network. Doing that while the teacher's form is being saved makes
 * saving an assignment as slow as NextCloud happens to be that day, and makes it
 * fail when NextCloud is down. So the form only writes down that it has to
 * happen, and this does it.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_enunciate extends adhoc_task {
    /**
     * Prepares the brief, unless somebody got there first.
     *
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function execute(): void {
        $data = (array) $this->get_custom_data();
        $assignment = (int) ($data['assignment'] ?? 0);

        if ($assignment === 0) {
            return;
        }

        // Between queueing and now somebody may have created it: the task is
        // retried on failure, and creating twice would leave a loose document.
        if (tipnc_enun::get($assignment)) {
            return;
        }

        $response = self::prepare($assignment, (int) $this->get_userid());

        if (!$response->success) {
            logger::error(code::TASK_NOT_PREPARED, 'create_enunciate', [
                'operation' => 'create_enunciate',
                'method' => 'create_enunciate:task',
                'assignment' => $assignment,
                'responsebody' => get_string('log_enunciate_failed', 'assignsubmission_tipnc'),
            ]);
        }
    }

    /**
     * Creates the brief: a copy of the one it came with, or the template.
     *
     * A restored or duplicated assignment carries the address of the brief of
     * the original in its settings. It is copied only when it can be trusted;
     * otherwise, or when the original is gone, the template is used.
     *
     * @param  int $assignment Assignment that needs a brief.
     * @param  int $userid     Who asked for it, whose permissions decide the copy.
     * @return response What NextCloud answered.
     * @throws coding_exception If the context cannot be built.
     * @throws dml_exception If the tables cannot be read.
     */
    public static function prepare(int $assignment, int $userid): response {
        $nextcloud = new nextcloud($assignment);
        $source = tipnc_enun::remembered_path($assignment);

        if ($source === '') {
            return $nextcloud->teacher_create();
        }

        if (!(new restored())->may_copy($source, $userid)) {
            logger::warning(code::RESTORE_REFUSED, 'restore_document', [
                'method' => 'create_enunciate:prepare',
                'assignment' => $assignment,
                'documentpath' => $source,
            ]);

            return $nextcloud->teacher_create();
        }

        $response = $nextcloud->teacher_create($source);

        // The original may have been deleted in NextCloud since the backup.
        return $response->success ? $response : $nextcloud->teacher_create();
    }

    /**
     * Asks for the brief of an assignment to be prepared.
     *
     * @param  int $assignment Assignment that needs one.
     * @param  int $userid     Who will own the document in NextCloud.
     * @return void
     */
    public static function queue(int $assignment, int $userid): void {
        $task = new self();
        $task->set_custom_data(['assignment' => $assignment]);
        $task->set_userid($userid);

        \core\task\manager::queue_adhoc_task($task, true);
    }
}
