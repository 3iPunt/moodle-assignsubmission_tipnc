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
 * The documents of an assignment.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\models;

use assign;
use assignsubmission_tipnc\api\document;
use context_module;
use dml_exception;
use stdClass;

/**
 * The documents of an assignment: where they are and how to reach them.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class documents {
    /**
     * Heights the site can choose for the embedded document.
     *
     * They are classes and not a number because the rule then lives in
     * styles.css, which is where a rule belongs.
     */
    private const HEIGHTS = ['420', '560', '700', '860'];

    /**
     * Class that gives the embedded document the height the site asked for.
     *
     * @return string A modifier class of the frame.
     * @throws dml_exception If the configuration cannot be read.
     */
    public function frame_height_class(): string {
        $height = (string) get_config('assignsubmission_tipnc', 'frameheight');

        return 'tipnc-frame--h' . (in_array($height, self::HEIGHTS, true) ? $height : '560');
    }

    /**
     * The brief of an assignment.
     *
     * @param  int $assignment Assignment instance.
     * @return stdClass|false The record, or false when it has not been prepared.
     * @throws dml_exception If the query fails.
     */
    public function get_enunciate(int $assignment) {
        global $DB;

        return $DB->get_record('assignsubmission_tipnc_enun', ['assignment' => $assignment]);
    }

    /**
     * The document a person is working on in an assignment, if any.
     *
     * Their draft while they write, their submission once it is frozen: the one
     * that belongs to them, not the brief everybody shares.
     *
     * @param  int $instance Assignment instance.
     * @param  int $userid   Whose document.
     * @return stdClass|null Mode, NextCloud id and time of the last change.
     * @throws dml_exception If the query fails.
     */
    public function own_work(int $instance, int $userid): ?stdClass {
        global $CFG, $DB;

        // The status constants are defined by mod_assign's locallib.php, and this
        // method is called from hooks that run before anybody loads it.
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $instance,
            'userid' => $userid,
            'latest' => 1,
        ]);

        if (!$submission) {
            return null;
        }

        // The document that exists rules, not the core status: the draft is made
        // when the form opens, and until the person saves, mod_assign keeps
        // saying "no attempt" about something that already has work inside.
        $submitted = $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $frozen = $DB->get_record(
            'assignsubmission_tipnc',
            ['submission' => $submission->id],
            'ncid, path',
            IGNORE_MISSING
        );
        $draft = $DB->get_record(
            'assignsubmission_tipnc_open',
            ['submission' => $submission->id],
            'ncid, path',
            IGNORE_MISSING
        );

        if ($submitted && !empty($frozen->ncid)) {
            $row = $frozen;
        } else if (!empty($draft->ncid)) {
            $row = $draft;
            $submitted = false;
        } else {
            $row = $frozen;
        }

        if (empty($row->ncid)) {
            return null;
        }

        return (object) [
            'submission' => $submission,
            'mode' => $submitted ? document::MODE_SUBMISSION : document::MODE_OPEN,
            'ncid' => (int) $row->ncid,

            // The stored path travels with the rest: building it from the name of
            // whoever looks stopped working as soon as re-attempts and groups existed.
            'path' => (string) ($row->path ?? ''),
            'timemodified' => (int) $submission->timemodified,
        ];
    }

    /**
     * Whether a person may still write in their own draft.
     *
     * The same rule mod_assign uses for the form: locked, past the cut-off date,
     * already submitted or without the capability means read only.
     *
     * @param  int $instance Assignment instance.
     * @param  int $userid   Whose draft.
     * @return bool True while the submission is open for them.
     * @throws dml_exception If the assignment cannot be read.
     */
    public function can_write_draft(int $instance, int $userid): bool {
        return self::assign_of($instance)->can_edit_submission($userid, $userid);
    }

    /**
     * Whether a save from the editor may be written into a document.
     *
     * Saves come in with the service account, so locking the share in NextCloud
     * does not stop them: a draft only takes them while its submission is open.
     * The brief and the frozen copies are not drafts and are not affected.
     *
     * @param  string $path Path of the document being saved.
     * @return bool False only for a draft whose submission is closed.
     * @throws dml_exception If the tables cannot be read.
     */
    public function accepts_saves(string $path): bool {
        global $DB;

        $row = $DB->get_record('assignsubmission_tipnc_open', ['path' => $path], 'assignment, submission', IGNORE_MULTIPLE);
        if (!$row) {
            return true;
        }

        $submission = $DB->get_record('assign_submission', ['id' => $row->submission]);
        if (!$submission) {
            return true;
        }

        $userid = (int) $submission->userid;
        if ($userid === 0 && !empty($submission->groupid)) {
            $members = groups_get_members((int) $submission->groupid, 'u.id', 'u.id ASC');
            $userid = $members ? (int) reset($members)->id : 0;
        }
        if ($userid === 0) {
            return true;
        }

        return self::assign_of((int) $row->assignment)->submissions_open($userid, false, $submission);
    }

    /**
     * The mod_assign object of an instance.
     *
     * @param  int $instance Assignment instance.
     * @return assign The assignment.
     * @throws dml_exception If the assignment cannot be read.
     */
    private static function assign_of(int $instance): assign {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $cm = get_coursemodule_from_instance('assign', $instance, 0, false, MUST_EXIST);

        return new assign(context_module::instance($cm->id), $cm, null);
    }

    /**
     * Where the brief of an assignment is.
     *
     * @param  int $assignment Assignment instance.
     * @return string Path of the document in NextCloud.
     * @throws dml_exception If the row cannot be read.
     */
    public function enunciate_path_of(int $assignment): string {
        return (new document($assignment))->enunciate_path();
    }

    /**
     * Address where a document is read, as the browser sees it.
     *
     * @param  int $ncid Identifier of the document in NextCloud.
     * @return string The address, empty when the plugin is not configured.
     * @throws dml_exception If the configuration cannot be read.
     */
    public function view_url(int $ncid): string {
        $base = rtrim((string) get_config('assignsubmission_tipnc', 'url'), '/');
        $location = (string) get_config('assignsubmission_tipnc', 'location');

        if ($base === '' || $ncid === 0) {
            return '';
        }

        return $base . $location . $ncid;
    }

    /**
     * Name the brief of an assignment has in NextCloud.
     *
     * @param  int $assignment Assignment instance.
     * @return string The file name.
     * @throws dml_exception If the configuration cannot be read.
     */
    public function enunciate_filename(int $assignment): string {
        $path = (new document($assignment))->enunciate_path();

        return basename($path);
    }

    /**
     * Address of the working folder in NextCloud.
     *
     * @return string|null The address, null when the plugin is not configured.
     * @throws dml_exception If the configuration cannot be read.
     */
    public function folder_url(): ?string {
        $base = rtrim((string) get_config('assignsubmission_tipnc', 'url'), '/');
        $folder = trim((string) get_config('assignsubmission_tipnc', 'folder'), '/');

        if ($base === '') {
            return null;
        }

        return $base . '/index.php/apps/files/?dir=/' . rawurlencode($folder);
    }
}
