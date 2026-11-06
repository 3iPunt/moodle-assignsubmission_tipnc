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
 * What a restored assignment copies, and on whose behalf.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use advanced_testcase;
use assignsubmission_tipnc\api\document;
use assignsubmission_tipnc\models\restored;
use assignsubmission_tipnc\task\create_enunciate;
use assignsubmission_tipnc\task\restore_document;
use backup;
use backup_controller;
use core\task\manager;
use restore_controller;
use restore_dbops;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * What a restored assignment copies, and on whose behalf.
 *
 * Nothing here talks to NextCloud: restoring only writes down what to copy, and
 * that is what is checked. The copies themselves are checked against a real
 * NextCloud.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_tipnc\models\restored
 * @covers     \assignsubmission_tipnc\observer
 * @covers     \backup_assignsubmission_tipnc_subplugin
 * @covers     \restore_assignsubmission_tipnc_subplugin
 */
final class restored_test extends advanced_testcase {
    /** @var string Path of the brief of the original assignment. */
    private const BRIEF = 'tasks/site/1 - c/1 - a/enun_1.docx';

    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass Somebody who teaches it. */
    private stdClass $teacher;

    /** @var stdClass Somebody who takes it. */
    private stdClass $student;

    /** @var stdClass The assignment, with this plugin on and a brief. */
    private stdClass $assign;

    /**
     * An assignment with this plugin on, a brief and nothing queued.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        set_config('folder', 'tasks', 'assignsubmission_tipnc');
        set_config('template', 'template.docx', 'assignsubmission_tipnc');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');

        $this->assign = $generator->create_module('assign', [
            'course' => $this->course->id,
            'assignsubmission_tipnc_enabled' => 1,
            'assignsubmission_onlinetext_enabled' => 0,
            'assignsubmission_file_enabled' => 0,
        ]);

        tipnc_enun::set((object) [
            'assignment' => $this->assign->id,
            'ncid' => 1,
            'userid' => $this->teacher->id,
            'path' => self::BRIEF,
        ]);

        // Creating the assignment queued its brief; that is not what is checked.
        $this->clear_tasks();
    }

    /**
     * Removes every queued task of this plugin.
     *
     * @return void
     */
    private function clear_tasks(): void {
        global $DB;

        $DB->delete_records_select('task_adhoc', $DB->sql_like('classname', ':c'), ['c' => '%assignsubmission_tipnc%']);
    }

    /**
     * Custom data and owner of the queued tasks of a class.
     *
     * @param  string $classname The task.
     * @return array[] One [customdata, userid] per task.
     */
    private function queued(string $classname): array {
        return array_map(
            fn($task) => [(array) $task->get_custom_data(), (int) $task->get_userid()],
            array_values(manager::get_adhoc_tasks($classname))
        );
    }

    /**
     * A document is copied only if this site knows it and the person could
     * have backed up the assignment it belongs to.
     *
     * @return void
     */
    public function test_who_may_copy_what(): void {
        $restored = new restored();

        $this->assertSame((int) $this->assign->id, $restored->owner_of(self::BRIEF));
        $this->assertSame(0, $restored->owner_of('tasks/somewhere/else.docx'));

        $this->assertTrue($restored->may_copy(self::BRIEF, (int) $this->teacher->id));
        $this->assertFalse($restored->may_copy(self::BRIEF, (int) $this->student->id));
        $this->assertFalse($restored->may_copy('tasks/somewhere/else.docx', (int) $this->teacher->id));

        // A teacher of another course cannot pull the documents of this one.
        $other = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_and_enrol($other, 'editingteacher');
        $this->assertFalse($restored->may_copy(self::BRIEF, (int) $stranger->id));
    }

    /**
     * Only assignments that came with a brief and have none are waiting.
     *
     * @return void
     */
    public function test_which_assignments_wait_for_a_brief(): void {
        $restored = new restored();

        // It has its own brief: nothing to wait for.
        $this->assertSame([], $restored->pending_briefs((int) $this->course->id));

        tipnc_enun::delete((int) $this->assign->id);
        tipnc_enun::remember_path((int) $this->assign->id, self::BRIEF);

        $this->assertSame([(int) $this->assign->id], $restored->pending_briefs((int) $this->course->id));
        $this->assertSame([(int) $this->assign->id], $restored->pending_briefs(0, (int) $this->assign->id));
    }

    /**
     * Duplicating keeps the address of the brief and asks for a copy of it,
     * on behalf of whoever duplicated.
     *
     * @return void
     */
    public function test_duplicating_queues_the_copy_of_the_brief(): void {
        $this->setAdminUser();

        $cm = get_coursemodule_from_instance('assign', $this->assign->id);
        $newcm = duplicate_module($this->course, get_fast_modinfo($this->course)->get_cm($cm->id));

        $this->assertNotNull($newcm);
        $instance = (int) $newcm->instance;

        $this->assertFalse(tipnc_enun::get($instance));
        $this->assertSame(self::BRIEF, tipnc_enun::remembered_path($instance));

        $tasks = $this->queued(create_enunciate::class);
        $this->assertCount(1, $tasks);
        $this->assertSame($instance, (int) $tasks[0][0]['assignment']);
        $this->assertSame((int) get_admin()->id, $tasks[0][1]);
    }

    /**
     * Restoring with user data queues one copy per document, and creates no row
     * that would point at the documents of the original course.
     *
     * @return void
     */
    public function test_restoring_with_user_data_queues_the_documents(): void {
        global $DB, $USER;

        $submission = $DB->insert_record('assign_submission', (object) [
            'assignment' => $this->assign->id,
            'userid' => $this->student->id,
            'status' => 'submitted',
            'timecreated' => time(),
            'timemodified' => time(),
            'latest' => 1,
            'attemptnumber' => 0,
            'groupid' => 0,
        ]);

        $paths = [
            document::MODE_OPEN => 'tasks/site/1 - c/1 - a/open_1_student.docx',
            document::MODE_SUBMISSION => 'tasks/site/1 - c/1 - a/subm_1_student.docx',
        ];
        $DB->insert_record('assignsubmission_tipnc_open', (object) [
            'assignment' => $this->assign->id, 'submission' => $submission, 'ncid' => 2, 'path' => $paths['open'],
        ]);
        $DB->insert_record('assignsubmission_tipnc', (object) [
            'assignment' => $this->assign->id, 'submission' => $submission, 'ncid' => 3,
            'path' => $paths[document::MODE_SUBMISSION],
        ]);

        $this->setAdminUser();

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $this->course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $basepath = $bc->get_plan()->get_basepath();
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        // A general backup is zipped: the restore reads the unzipped folder.
        if (!file_exists($basepath . '/moodle_backup.xml')) {
            $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $basepath);
        }

        $newcourseid = restore_dbops::create_new_course('Restored', 'RESTORED', $this->course->category);
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $newassign = $DB->get_record('assign', ['course' => $newcourseid], 'id', MUST_EXIST);
        $newsubmission = $DB->get_field('assign_submission', 'id', ['assignment' => $newassign->id], MUST_EXIST);

        // No row points at the documents of the original course.
        $this->assertFalse($DB->record_exists('assignsubmission_tipnc_open', ['assignment' => $newassign->id]));
        $this->assertFalse($DB->record_exists('assignsubmission_tipnc', ['assignment' => $newassign->id]));
        $this->assertFalse(tipnc_enun::get((int) $newassign->id));

        // One copy per document, for the new submission, on behalf of whoever restored.
        $queued = [];
        foreach ($this->queued(restore_document::class) as [$data, $userid]) {
            $this->assertSame((int) $newsubmission, (int) $data['submission']);
            $this->assertSame((int) $USER->id, $userid);
            $queued[$data['mode']] = $data['source'];
        }
        ksort($queued);
        ksort($paths);
        $this->assertSame($paths, $queued);

        // And the brief, through the event of the restored course.
        $briefs = array_map(fn($task) => (int) $task[0]['assignment'], $this->queued(create_enunciate::class));
        $this->assertContains((int) $newassign->id, $briefs);
    }
}
