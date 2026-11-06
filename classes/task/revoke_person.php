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
 * Takes away the access of somebody who left a course.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\task;

use assignsubmission_tipnc\api\nextcloud;
use assignsubmission_tipnc\models\grants;
use core\task\adhoc_task;
use core_user;
use dml_exception;

/**
 * Takes away the access of somebody who left a course.
 *
 * Leaving a course is an event, and an event can be missed —a plugin that fails,
 * an enrolment removed straight in the database, a restore. So the observer only
 * writes down that this has to happen, and this does it: if it fails, Moodle
 * retries it on its own, which is the whole reason it is not done inline.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class revoke_person extends adhoc_task {
    /**
     * Withdraws every document of the course from that person.
     *
     * @return void
     * @throws dml_exception If the documents cannot be read.
     */
    public function execute(): void {
        global $DB;

        $data = (array) $this->get_custom_data();
        $courseid = (int) ($data['courseid'] ?? 0);
        $userid = (int) ($data['userid'] ?? 0);

        if ($courseid === 0 || $userid === 0) {
            return;
        }

        $user = core_user::get_user($userid, 'id, username');
        if (!$user) {
            return;
        }

        // The recorded documents of the course: briefs, drafts and submissions.
        // The stored paths are walked rather than rebuilt, which is the only
        // thing that works with re-attempts and group submissions.
        $sql = "SELECT id, assignment, 0 AS submissionid, path FROM {assignsubmission_tipnc_enun}
                 WHERE path IS NOT NULL AND assignment IN (SELECT id FROM {assign} WHERE course = :c1)
             UNION ALL
                SELECT id, assignment, submission AS submissionid, path FROM {assignsubmission_tipnc}
                 WHERE path IS NOT NULL AND assignment IN (SELECT id FROM {assign} WHERE course = :c2)
             UNION ALL
                SELECT id, assignment, submission AS submissionid, path FROM {assignsubmission_tipnc_open}
                 WHERE path IS NOT NULL AND assignment IN (SELECT id FROM {assign} WHERE course = :c3)";

        $rows = $DB->get_recordset_sql(
            $sql,
            ['c1' => $courseid, 'c2' => $courseid, 'c3' => $courseid]
        );

        $context = ['operation' => 'revoke_assignment', 'affecteduserid' => $userid];

        foreach ($rows as $row) {
            (new nextcloud((int) $row->assignment))
                ->revoke_person(
                    $row->path,
                    $user->username,
                    array_merge($context, ['assignment' => (int) $row->assignment])
                );

            // So that the next visit hands access out again if this person turns
            // out to still have a reason for it. Both kinds: the brief goes by
            // assignment and submissions by submission, and they arrive mixed.
            grants::forget(grants::ENUNCIATE, (int) $row->assignment, $userid);

            if ((int) $row->submissionid > 0) {
                foreach ([grants::SUBMISSION, grants::OWN_DRAFT_WRITE, grants::OWN_DRAFT_READ, grants::OWN_FROZEN] as $what) {
                    grants::forget($what, (int) $row->submissionid, $userid);
                }
            }
        }

        $rows->close();
    }

    /**
     * Asks for somebody's access to a course's documents to be withdrawn.
     *
     * @param  int $courseid Course they left.
     * @param  int $userid   Who left it.
     * @return void
     */
    public static function queue(int $courseid, int $userid): void {
        if ($courseid === 0 || $userid === 0) {
            return;
        }

        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'userid' => $userid]);

        \core\task\manager::queue_adhoc_task($task, true);
    }
}
