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
 * What the plugin hands over and what it forgets.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\userlist;
use mod_assign\privacy\assign_plugin_request_data;
use stdClass;

/**
 * What the plugin hands over and what it forgets.
 *
 * The deletion is the part worth proving. A provider that only removes the rows
 * in Moodle passes any review, and leaves the person's work —with their name in
 * the file— sitting in the other system.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_tipnc\privacy\provider
 */
final class provider_test extends \mod_assign\tests\provider_testcase {
    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass Somebody who hands work in. */
    private stdClass $student;

    /** @var \assign The assignment. */
    private $assign;

    /**
     * An assignment with this plugin on and one student enrolled.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        set_config('folder', 'tasks', 'assignsubmission_tipnc');
        set_config('template', 'template.docx', 'assignsubmission_tipnc');

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assign = $this->create_instance(['course' => $this->course]);
    }

    /**
     * Gives this person a draft, a frozen copy and a trace in the log.
     *
     * @return stdClass The submission they belong to.
     */
    private function give_them_documents(): stdClass {
        global $DB;

        $this->setUser($this->student);
        $submission = $this->assign->get_user_submission($this->student->id, true);
        $assignid = $this->assign->get_instance()->id;

        foreach (['assignsubmission_tipnc_open' => 'open', 'assignsubmission_tipnc' => 'subm'] as $table => $prefix) {
            $DB->insert_record($table, (object) [
                'assignment' => $assignid,
                'submission' => $submission->id,
                'ncid' => 10,
                'path' => 'tasks/' . $prefix . '_x.docx',
            ]);
        }

        $DB->insert_record('assignsubmission_tipnc_log', (object) [
            'severity' => 'error',
            'traceid' => 'abc',
            'operation' => 'submit_freeze',
            'method' => 'test',
            'errorcode' => '0301',
            'assignment' => $assignid,
            'submission' => $submission->id,
            'userid' => $this->student->id,
            'affecteduserid' => $this->student->id,
            'occurrences' => 1,
            'firstseen' => time(),
            'lastseen' => time(),
            'timecreated' => time(),
        ]);

        return $submission;
    }

    /**
     * The plugin says what it keeps, including what leaves the site.
     *
     * @return void
     */
    public function test_it_declares_what_it_keeps_and_what_it_sends_away(): void {
        $collection = provider::get_metadata(new collection('assignsubmission_tipnc'));

        $names = [];
        foreach ($collection->get_collection() as $item) {
            $names[] = $item->get_name();
        }

        $this->assertContains('assignsubmission_tipnc', $names);
        $this->assertContains('assignsubmission_tipnc_open', $names);
        $this->assertContains('assignsubmission_tipnc_enun', $names);

        // The incident log records who did what on whose document: it is
        // personal data even though it does not look like it.
        $this->assertContains('assignsubmission_tipnc_log', $names);

        // And what a reviewer really looks at: that data leaves for another system.
        $this->assertContains('nextcloud', $names);
        $this->assertContains('docserver', $names);
    }

    /**
     * A teacher with no submission who appears in the log.
     *
     * @return stdClass The teacher.
     */
    private function teacher_in_the_log(): stdClass {
        global $DB;

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $DB->insert_record('assignsubmission_tipnc_log', (object) [
            'severity' => 'warning',
            'traceid' => 'def',
            'operation' => 'share_enunciate',
            'method' => 'test',
            'errorcode' => '0302',
            'assignment' => $this->assign->get_instance()->id,
            'userid' => $teacher->id,
            'documentpath' => 'tasks/enun_x.docx',
            'occurrences' => 1,
            'firstseen' => time(),
            'lastseen' => time(),
            'timecreated' => time(),
        ]);

        return $teacher;
    }

    /**
     * Whoever appears in the log is found, even without a submission.
     *
     * @return void
     */
    public function test_people_in_the_log_are_found_without_a_submission(): void {
        $teacher = $this->teacher_in_the_log();
        $context = $this->assign->get_context();

        $contexts = provider::get_contexts_for_userid((int) $teacher->id)->get_contextids();
        $this->assertContains((int) $context->id, array_map('intval', $contexts));

        $userlist = new userlist($context, 'assignsubmission_tipnc');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $teacher->id, array_map('intval', $userlist->get_userids()));
    }

    /**
     * Their entries of the log are handed over in the assignment.
     *
     * @return void
     */
    public function test_the_log_is_exported(): void {
        $teacher = $this->teacher_in_the_log();
        $context = $this->assign->get_context();

        provider::export_user_data(
            new \core_privacy\local\request\approved_contextlist($teacher, 'assignsubmission_tipnc', [$context->id])
        );

        $writer = \core_privacy\local\request\writer::with_context($context);
        $data = $writer->get_data([
            get_string('pluginname', 'assignsubmission_tipnc'),
            get_string('privacy:export:log', 'assignsubmission_tipnc'),
        ]);

        $this->assertCount(1, $data->entries);
        $this->assertSame('tasks/enun_x.docx', $data->entries[0]->document);
    }

    /**
     * Forgetting them removes their entries and nobody else's.
     *
     * @return void
     */
    public function test_the_log_of_a_person_without_submission_is_forgotten(): void {
        global $DB;

        $this->give_them_documents();
        $teacher = $this->teacher_in_the_log();
        $context = $this->assign->get_context();

        provider::delete_data_for_user(
            new \core_privacy\local\request\approved_contextlist($teacher, 'assignsubmission_tipnc', [$context->id])
        );

        $this->assertFalse($DB->record_exists('assignsubmission_tipnc_log', ['userid' => $teacher->id]));
        $this->assertTrue($DB->record_exists('assignsubmission_tipnc_log', ['userid' => $this->student->id]));
    }

    /**
     * Forgetting one person takes away their documents and their traces.
     *
     * @return void
     */
    public function test_forgetting_a_person_takes_their_documents_and_traces(): void {
        global $DB;

        $submission = $this->give_them_documents();
        $assignid = $this->assign->get_instance()->id;

        $requestdata = new assign_plugin_request_data($this->assign->get_context(), $this->assign);
        $requestdata->set_userids([$this->student->id]);
        $requestdata->populate_submissions_and_grades();

        provider::delete_submissions($requestdata);

        $this->assertFalse($DB->record_exists(
            'assignsubmission_tipnc',
            ['submission' => $submission->id]
        ));
        $this->assertFalse($DB->record_exists(
            'assignsubmission_tipnc_open',
            ['submission' => $submission->id]
        ));
        $this->assertSame(0, $DB->count_records_select(
            'assignsubmission_tipnc_log',
            'assignment = :a AND (userid = :u OR affecteduserid = :au)',
            ['a' => $assignid, 'u' => $this->student->id, 'au' => $this->student->id]
        ));
    }

    /**
     * Forgetting one person leaves everybody else alone. Deleting too much is
     * as wrong as deleting too little, and far harder to notice.
     *
     * @return void
     */
    public function test_forgetting_one_person_leaves_the_others_alone(): void {
        global $DB;

        $this->give_them_documents();

        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($other);
        $othersubmission = $this->assign->get_user_submission($other->id, true);

        $DB->insert_record('assignsubmission_tipnc_open', (object) [
            'assignment' => $this->assign->get_instance()->id,
            'submission' => $othersubmission->id,
            'ncid' => 20,
            'path' => 'tasks/open_otro.docx',
        ]);

        $requestdata = new assign_plugin_request_data($this->assign->get_context(), $this->assign);
        $requestdata->set_userids([$this->student->id]);
        $requestdata->populate_submissions_and_grades();

        provider::delete_submissions($requestdata);

        $this->assertTrue($DB->record_exists(
            'assignsubmission_tipnc_open',
            ['submission' => $othersubmission->id]
        ));
    }

    /**
     * Forgetting a whole assignment leaves nothing of it, the brief included.
     *
     * @return void
     */
    public function test_forgetting_an_assignment_leaves_nothing_of_it(): void {
        global $DB;

        $this->give_them_documents();
        $assignid = $this->assign->get_instance()->id;

        $DB->insert_record('assignsubmission_tipnc_enun', (object) [
            'assignment' => $assignid,
            'ncid' => 30,
            'userid' => $this->student->id,
            'path' => 'tasks/enun_x.docx',
        ]);

        $requestdata = new assign_plugin_request_data($this->assign->get_context(), $this->assign);

        provider::delete_submission_for_context($requestdata);

        foreach (
            ['assignsubmission_tipnc', 'assignsubmission_tipnc_open',
                  'assignsubmission_tipnc_enun', 'assignsubmission_tipnc_log'] as $table
        ) {
            $this->assertSame(
                0,
                $DB->count_records($table, ['assignment' => $assignid]),
                'Quedan filas en ' . $table
            );
        }
    }

    /**
     * Whoever prepared the brief is in this plugin's tables without having
     * handed anything in, so the core's own queries would never find them.
     *
     * @return void
     */
    public function test_whoever_prepared_the_brief_is_found(): void {
        global $DB;

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $DB->insert_record('assignsubmission_tipnc_enun', (object) [
            'assignment' => $this->assign->get_instance()->id,
            'ncid' => 30,
            'userid' => $teacher->id,
            'path' => 'tasks/enun_x.docx',
        ]);

        $userlist = new userlist($this->assign->get_context(), 'assignsubmission_tipnc');
        provider::get_userids_from_context($userlist);

        $this->assertContains((int) $teacher->id, $userlist->get_userids());
    }

    /**
     * The brief itself is not deleted with a person —it belongs to the
     * assignment— but it stops saying who made it.
     *
     * @return void
     */
    public function test_the_brief_survives_but_forgets_who_made_it(): void {
        global $DB;

        $assignid = $this->assign->get_instance()->id;

        $DB->insert_record('assignsubmission_tipnc_enun', (object) [
            'assignment' => $assignid,
            'ncid' => 30,
            'userid' => $this->student->id,
            'path' => 'tasks/enun_x.docx',
        ]);

        $requestdata = new assign_plugin_request_data($this->assign->get_context(), $this->assign);
        $requestdata->set_userids([$this->student->id]);
        $requestdata->populate_submissions_and_grades();

        provider::delete_submissions($requestdata);

        $enun = $DB->get_record('assignsubmission_tipnc_enun', ['assignment' => $assignid]);

        $this->assertNotFalse($enun, 'The brief belongs to the assignment, not to a person.');
        $this->assertSame(0, (int) $enun->userid);
    }
}
