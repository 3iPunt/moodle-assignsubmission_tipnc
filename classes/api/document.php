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
 * Document
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\api;

use core_text;
use core_user;
use dml_exception;
use stdClass;

/**
 * Document
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class document {
    /** @var string Prefix of the brief the teacher publishes. */
    public const PREFIX_ENUN = 'enun_';
    /** @var string Prefix of the draft a student writes in. */
    public const PREFIX_OPEN = 'open_';
    /** @var string Prefix of the frozen copy made when handing in. */
    public const PREFIX_SUBMISSION = 'subm_';

    /** @var string What stands for a group where an account name would go. */
    public const PREFIX_GROUP = 'group';

    /** @var string Owner of a submission that belongs to nobody findable. */
    public const UNKNOWN_OWNER = 'unknown';

    /** @var string The brief, as asked for by a caller. */
    public const MODE_ENUN = 'enun';
    /** @var string The draft, as asked for by a caller. */
    public const MODE_OPEN = 'open';
    /** @var string The frozen submission, as asked for by a caller. */
    public const MODE_SUBMISSION = 'submission';

    /** @var int Longest a folder name is allowed to be. */
    protected const NAME_LIMIT = 60;

    /** @var int Instance */
    protected $instance;

    /** @var string Folder */
    protected $folder;

    /** @var string|null Folder of this assignment, worked out once. */
    protected $assignmentfolder = null;

    /** @var string Template */
    protected $template;

    /** @var string Extension file */
    protected $extension;

    /**
     * Document constructor.
     *
     * @param int $instance
     * @throws dml_exception
     */
    public function __construct(int $instance) {
        $this->folder = get_config('assignsubmission_tipnc', 'folder');
        $this->template = get_config('assignsubmission_tipnc', 'template');
        $this->instance = $instance;
        $this->set_extension();
    }

    /**
     * Get Template.
     *
     * @return string
     */
    public function get_template(): string {
        return $this->folder . '/' . $this->template;
    }

    /**
     * Get Enunciate.
     *
     * @return string
     */
    public function get_enunciate(): string {
        return $this->assignment_folder() . '/' . self::PREFIX_ENUN . $this->instance . $this->extension;
    }

    /**
     * Name a document had before names told one attempt from another.
     *
     * Only the migration of the documents that already existed uses this: those
     * are all first attempts of one person, so this is what they are called.
     *
     * @param  string $prefix Which of the documents it is.
     * @param  string $owner  Account it belonged to.
     * @return string Path of the document.
     * @throws dml_exception If the assignment cannot be read.
     */
    public function legacy_name(string $prefix, string $owner): string {
        return $this->assignment_folder() . '/' . $prefix . $this->instance . '_' . $owner . $this->extension;
    }

    /**
     * Name of whoever a submission belongs to.
     *
     * A group submission belongs to the group and has no `userid`; an individual
     * one belongs to the person. Getting this from `$USER` —whoever happens to be
     * clicking— is what made a teacher opening a submission create documents in
     * their own name.
     *
     * @param  stdClass $submission The submission.
     * @return string Account name, or the group with its identifier.
     * @throws dml_exception If the account cannot be read.
     */
    public function owner_of(stdClass $submission): string {
        $groupid = (int) ($submission->groupid ?? 0);

        if ($groupid > 0) {
            // The id and not the name: a group gets renamed, and the file
            // would stop being found.
            return self::PREFIX_GROUP . $groupid;
        }

        $user = core_user::get_user((int) ($submission->userid ?? 0), 'username');

        return $user ? $user->username : self::UNKNOWN_OWNER;
    }

    /**
     * What tells one attempt from the next in a file name.
     *
     * The first attempt carries nothing, so documents that already exist keep the
     * name they have. Without this, reopening an attempt wrote the blank brief
     * over the previous attempt's work.
     *
     * @param  stdClass $submission The submission.
     * @return string Empty for the first attempt.
     */
    public function attempt_of(stdClass $submission): string {
        $attempt = (int) ($submission->attemptnumber ?? 0);

        return $attempt > 0 ? '_r' . $attempt : '';
    }

    /**
     * Name of the draft of a submission.
     *
     * @param  stdClass $submission The submission it belongs to.
     * @return string Path of the document.
     * @throws dml_exception If the account cannot be read.
     */
    public function open_for(stdClass $submission): string {
        return $this->assignment_folder() . '/' . self::PREFIX_OPEN . $this->instance . '_'
            . $this->owner_of($submission) . $this->attempt_of($submission) . $this->extension;
    }

    /**
     * Name of the frozen copy of a submission.
     *
     * @param  stdClass $submission The submission it belongs to.
     * @return string Path of the document.
     * @throws dml_exception If the account cannot be read.
     */
    public function submission_for(stdClass $submission): string {
        return $this->assignment_folder() . '/' . self::PREFIX_SUBMISSION . $this->instance . '_'
            . $this->owner_of($submission) . $this->attempt_of($submission) . $this->extension;
    }

    /**
     * Where the brief really is.
     *
     * The stored path wins over the composed one: the composed path carries the
     * name of the course and of the assignment, and renaming either of them would
     * point at something that does not exist.
     *
     * @return string Path of the document.
     * @throws dml_exception If the row cannot be read.
     */
    public function enunciate_path(): string {
        global $DB;

        $path = $DB->get_field(
            'assignsubmission_tipnc_enun',
            'path',
            ['assignment' => $this->instance],
            IGNORE_MISSING
        );

        return !empty($path) ? $path : $this->get_enunciate();
    }

    /**
     * Where the draft of a submission really is.
     *
     * The stored path wins over the composed one, which is what lets the naming
     * change without moving a single document that already exists.
     *
     * @param  stdClass $submission Submission it belongs to.
     * @return string Path of the document.
     * @throws dml_exception If the row cannot be read.
     */
    public function open_path(stdClass $submission): string {
        global $DB;

        $path = $DB->get_field(
            'assignsubmission_tipnc_open',
            'path',
            ['submission' => $submission->id],
            IGNORE_MISSING
        );

        return !empty($path) ? $path : $this->open_for($submission);
    }

    /**
     * Where the frozen copy of a submission really is.
     *
     * @param  stdClass $submission Submission it belongs to.
     * @return string Path of the document.
     * @throws dml_exception If the row cannot be read.
     */
    public function submission_path(stdClass $submission): string {
        global $DB;

        $path = $DB->get_field(
            'assignsubmission_tipnc',
            'path',
            ['submission' => $submission->id],
            IGNORE_MISSING
        );

        return !empty($path) ? $path : $this->submission_for($submission);
    }

    /**
     * Folder of this assignment: site, course and assignment.
     *
     * A flat folder with every document of the campus is unusable —and it is what
     * there was—. The identifier goes first in each name so that renaming a course
     * does not change where its documents are looked for.
     *
     * @return string Path of the folder, without a trailing slash.
     * @throws dml_exception If the assignment cannot be read.
     */
    public function assignment_folder(): string {
        global $DB, $SITE;

        if ($this->assignmentfolder !== null) {
            return $this->assignmentfolder;
        }

        $assign = $DB->get_record('assign', ['id' => $this->instance], 'id, name, course', IGNORE_MISSING);
        if (!$assign) {
            // With no assignment there is no folder to build: it stays in the base.
            $this->assignmentfolder = $this->folder;
            return $this->assignmentfolder;
        }

        $course = $DB->get_record('course', ['id' => $assign->course], 'id, shortname', IGNORE_MISSING);

        $this->assignmentfolder = implode('/', [
            $this->folder,
            self::clean_name($SITE->shortname),
            self::clean_name(($course->id ?? 0) . ' - ' . ($course->shortname ?? '')),
            self::clean_name($assign->id . ' - ' . $assign->name),
        ]);

        return $this->assignmentfolder;
    }

    /**
     * Every folder of this assignment, from the base down, in order.
     *
     * They are created one by one: WebDAV does not make intermediate folders.
     *
     * @return string[] Paths, outermost first.
     * @throws dml_exception If the assignment cannot be read.
     */
    public function folder_chain(): array {
        $parts = explode('/', $this->assignment_folder());
        $chain = [];
        $path = '';

        foreach ($parts as $part) {
            $path = $path === '' ? $part : $path . '/' . $part;
            $chain[] = $path;
        }

        return $chain;
    }

    /**
     * Makes a name safe to be a folder in NextCloud.
     *
     * @param  string $name Name as it is in Moodle.
     * @return string A name that can be a folder, never empty.
     */
    protected static function clean_name(string $name): string {
        // The characters WebDAV and file systems do not accept, plus trailing
        // dots, which Windows silently trims when syncing.
        $clean = preg_replace('/[\\/\\\\:*?"<>|]+/u', ' ', $name);
        $clean = trim(preg_replace('/\s+/u', ' ', $clean), " .\t\n\r\0\x0B");
        $clean = core_text::substr($clean, 0, self::NAME_LIMIT);

        return trim($clean, ' .') !== '' ? trim($clean, ' .') : 'unnamed';
    }

    /**
     * Set extension.
     *
     */
    protected function set_extension() {
        $extension = '';
        $pospoint = strpos($this->template, '.');
        if ($pospoint !== false) {
            $extension = substr($this->template, $pospoint);
        }
        $this->extension = $extension;
    }

    /**
     * Get URL.
     *
     * @param int $ncid
     * @return string
     * @throws dml_exception
     */
    public static function get_url(int $ncid): string {
        $host = get_config('assignsubmission_tipnc', 'url');
        $location = get_config('assignsubmission_tipnc', 'location');
        return $host . $location . $ncid;
    }
}
