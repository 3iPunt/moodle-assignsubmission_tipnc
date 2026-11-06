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
 * How documents are named and where they live.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use advanced_testcase;
use assignsubmission_tipnc\api\document;
use stdClass;

/**
 * How documents are named and where they live.
 *
 * The name of a document is what tells one person's work from another's and one
 * attempt from the next. Getting it wrong is not a cosmetic problem: an attempt
 * that reuses the previous name writes the blank brief over work that was
 * already handed in, which is what used to happen.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_tipnc\api\document
 */
final class document_test extends advanced_testcase {
    /** @var stdClass The course holding the assignment. */
    private stdClass $course;

    /** @var stdClass The assignment. */
    private stdClass $assign;

    /** @var stdClass Somebody who hands work in. */
    private stdClass $student;

    /**
     * A course with an assignment and one student, plus the plugin configured.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        set_config('folder', 'tasks', 'assignsubmission_tipnc');
        set_config('template', 'template.docx', 'assignsubmission_tipnc');

        $this->course = $this->getDataGenerator()->create_course(['shortname' => 'TIPNCQA']);
        $this->assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $this->course->id, 'name' => 'NC assignment']
        );
        $this->student = $this->getDataGenerator()->create_user(['username' => 'alumno1']);
    }

    /**
     * A submission as the core builds one, with whatever matters here.
     *
     * @param  array $fields What this submission is: owner, group, attempt.
     * @return stdClass The submission.
     */
    private function submission(array $fields = []): stdClass {
        return (object) array_merge([
            'id' => 1,
            'assignment' => $this->assign->id,
            'userid' => $this->student->id,
            'groupid' => 0,
            'attemptnumber' => 0,
        ], $fields);
    }

    /**
     * The first attempt is named as it always was, so nothing already in
     * NextCloud has to be moved.
     *
     * @return void
     */
    public function test_first_attempt_keeps_the_name_it_always_had(): void {
        $document = new document($this->assign->id);
        $submission = $this->submission();

        $this->assertSame(
            'open_' . $this->assign->id . '_alumno1.docx',
            basename($document->open_for($submission))
        );

        $this->assertSame(
            'subm_' . $this->assign->id . '_alumno1.docx',
            basename($document->submission_for($submission))
        );
    }

    /**
     * Reopening an attempt gives a name of its own. Without this, the new
     * attempt was written over the work of the previous one.
     *
     * @return void
     */
    public function test_a_reopened_attempt_does_not_reuse_the_previous_name(): void {
        $document = new document($this->assign->id);

        $first = $document->open_for($this->submission(['attemptnumber' => 0]));
        $second = $document->open_for($this->submission(['attemptnumber' => 1]));
        $third = $document->open_for($this->submission(['attemptnumber' => 2]));

        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);

        $this->assertStringEndsWith('_alumno1_r1.docx', $second);
        $this->assertStringEndsWith('_alumno1_r2.docx', $third);
    }

    /**
     * A group submission belongs to the group, so it is named after the group
     * and there is one document, not one per member.
     *
     * @return void
     */
    public function test_a_group_submission_is_named_after_the_group(): void {
        $document = new document($this->assign->id);

        $path = $document->open_for($this->submission(['userid' => 0, 'groupid' => 12]));

        $this->assertStringEndsWith('_group12.docx', $path);
        $this->assertStringNotContainsString('alumno1', $path);
    }

    /**
     * Whoever the submission belongs to, not whoever is clicking. A teacher
     * opening a student's submission used to create documents in their name.
     *
     * @return void
     */
    public function test_the_owner_is_the_person_the_submission_belongs_to(): void {
        $teacher = $this->getDataGenerator()->create_user(['username' => 'profesor1']);
        $this->setUser($teacher);

        $document = new document($this->assign->id);

        $this->assertSame('alumno1', $document->owner_of($this->submission()));
    }

    /**
     * A submission whose owner no longer exists still gets a name, because the
     * alternative is a path built on an empty string.
     *
     * @return void
     */
    public function test_a_missing_owner_does_not_produce_a_nameless_document(): void {
        $document = new document($this->assign->id);

        $this->assertSame(
            document::UNKNOWN_OWNER,
            $document->owner_of($this->submission(['userid' => -1]))
        );
    }

    /**
     * Documents live under site, course and assignment, and each folder carries
     * its identifier first so that renaming does not change where things are
     * looked for.
     *
     * @return void
     */
    public function test_documents_live_under_site_course_and_assignment(): void {
        $document = new document($this->assign->id);

        $folder = $document->assignment_folder();

        $this->assertStringStartsWith('tasks/', $folder);
        $this->assertStringContainsString($this->course->id . ' - TIPNCQA', $folder);
        $this->assertStringContainsString($this->assign->id . ' - NC assignment', $folder);
    }

    /**
     * Names that WebDAV or Windows would refuse do not reach NextCloud.
     *
     * @return void
     */
    public function test_folder_names_drop_what_webdav_cannot_take(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'A/B: "C" <D>']);
        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'name' => 'Assignment with | pipe']
        );

        $folder = (new document($assign->id))->assignment_folder();

        foreach (['/A', '\\', ':', '*', '?', '"', '<', '>', '|'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                substr($folder, strlen('tasks/')),
                'The folder name keeps a character WebDAV does not accept: ' . $forbidden
            );
        }
    }

    /**
     * The stored path wins over the composed one: a renamed course or
     * assignment must not make existing documents unreachable.
     *
     * @return void
     */
    public function test_the_stored_path_wins_over_the_composed_one(): void {
        global $DB;

        $submission = $this->submission();

        $DB->insert_record('assignsubmission_tipnc_open', (object) [
            'assignment' => $this->assign->id,
            'submission' => $submission->id,
            'ncid' => 99,
            'path' => 'where/it/really/is.docx',
        ]);

        $this->assertSame(
            'where/it/really/is.docx',
            (new document($this->assign->id))->open_path($submission)
        );
    }

    /**
     * Documents made before names carried the attempt keep the name they have,
     * which is what lets the naming change without moving anything.
     *
     * @return void
     */
    public function test_legacy_names_are_still_composable(): void {
        $document = new document($this->assign->id);

        $this->assertStringEndsWith(
            'open_' . $this->assign->id . '_alumno1.docx',
            $document->legacy_name(document::PREFIX_OPEN, 'alumno1')
        );
    }
}
