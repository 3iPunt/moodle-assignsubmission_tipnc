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
 * Event observers of the plugin.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use assignsubmission_tipnc\models\cleanup;
use assignsubmission_tipnc\models\restored;
use assignsubmission_tipnc\task\create_enunciate;
use assignsubmission_tipnc\task\revoke_person;
use context;
use core\event\course_content_deleted;
use core\event\course_module_created;
use core\event\course_module_deleted;
use core\event\course_restored;
use core\event\role_unassigned;
use core\event\user_enrolment_deleted;
use dml_exception;

/**
 * Event observers of the plugin.
 *
 * Somebody who stops belonging to a course keeps whatever access to its
 * documents NextCloud gave them, because NextCloud has no idea Moodle changed
 * its mind. These observers are what closes that gap on the same day.
 *
 * They only write down what has to happen. Doing it here would mean a course
 * with five assignments and a hundred students making hundreds of calls inside
 * the click that removed one enrolment —and it would be lost for good if any of
 * them failed. The adhoc task Moodle retries on its own.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Somebody was unenrolled from a course.
     *
     * @param  user_enrolment_deleted $event The event.
     * @return void
     */
    public static function user_enrolment_deleted(user_enrolment_deleted $event): void {
        revoke_person::queue((int) $event->courseid, (int) $event->relateduserid);
    }

    /**
     * A role was taken away from somebody.
     *
     * Only roles given in a course or below matter here: the access this plugin
     * hands out is per assignment, and a role given at the site level does not
     * decide who teaches what.
     *
     * @param  role_unassigned $event The event.
     * @return void
     */
    public static function role_unassigned(role_unassigned $event): void {
        $context = context::instance_by_id($event->contextid, IGNORE_MISSING);

        if (!$context) {
            return;
        }

        $coursecontext = $context->get_course_context(false);

        if (!$coursecontext) {
            return;
        }

        revoke_person::queue((int) $coursecontext->instanceid, (int) $event->relateduserid);
    }

    /**
     * A course was restored or imported into.
     *
     * Its assignments came with the address of their brief and none of their own.
     *
     * @param  course_restored $event The event.
     * @return void
     * @throws dml_exception If the tables cannot be read.
     */
    public static function course_restored(course_restored $event): void {
        foreach ((new restored())->pending_briefs((int) $event->objectid) as $assignment) {
            create_enunciate::queue($assignment, (int) $event->userid);
        }
    }

    /**
     * A module was created, which is also what duplicating an activity triggers.
     *
     * @param  course_module_created $event The event.
     * @return void
     * @throws dml_exception If the tables cannot be read.
     */
    public static function course_module_created(course_module_created $event): void {
        if (($event->other['modulename'] ?? '') !== 'assign') {
            return;
        }

        $instance = (int) ($event->other['instanceid'] ?? 0);
        if ($instance === 0) {
            return;
        }

        foreach ((new restored())->pending_briefs(0, $instance) as $assignment) {
            create_enunciate::queue($assignment, (int) $event->userid);
        }
    }

    /**
     * An assignment was deleted: its brief goes with it.
     *
     * @param  course_module_deleted $event The event.
     * @return void
     * @throws dml_exception If the tables cannot be read.
     */
    public static function course_module_deleted(course_module_deleted $event): void {
        if (($event->other['modulename'] ?? '') !== 'assign') {
            return;
        }

        $instance = (int) ($event->other['instanceid'] ?? 0);
        if ($instance > 0) {
            (new cleanup())->forget_briefs($instance);
        }
    }

    /**
     * A course was emptied or deleted, which does not trigger the module event.
     *
     * @param  course_content_deleted $event The event.
     * @return void
     * @throws dml_exception If the tables cannot be read.
     */
    public static function course_content_deleted(course_content_deleted $event): void {
        (new cleanup())->forget_briefs();
    }
}
