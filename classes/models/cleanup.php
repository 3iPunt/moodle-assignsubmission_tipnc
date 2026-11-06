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
 * What happens in NextCloud when Moodle forgets a submission or an assignment.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\models;

use assignsubmission_tipnc\api\document;
use assignsubmission_tipnc\api\nextcloud;
use assignsubmission_tipnc\tipnc_enun;
use dml_exception;
use stdClass;

/**
 * What happens in NextCloud when Moodle forgets a submission or an assignment.
 *
 * Deleting in Moodle and deleting in NextCloud are two different things, and the
 * documents are not all the same: a draft is somebody's work and a frozen copy is
 * an artefact this plugin made. Each site decides how far the cleaning goes; the
 * default never destroys anything.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup {
    /** @var string Take back the access, keep every document. */
    public const KEEP = 'keep';

    /** @var string Delete the frozen copy, keep the draft. */
    public const DROP_FROZEN = 'frozen';

    /** @var string Delete both documents of that person. */
    public const DROP_ALL = 'all';

    /** @var string Keep the documents of a deleted assignment. */
    public const ASSIGN_KEEP = 'keep';

    /** @var string Delete every document of a deleted assignment. */
    public const ASSIGN_DELETE = 'delete';

    /**
     * What the site wants done when a submission is removed.
     *
     * @return string One of KEEP, DROP_FROZEN or DROP_ALL.
     * @throws dml_exception If the configuration cannot be read.
     */
    public static function submission_policy(): string {
        $policy = (string) get_config('assignsubmission_tipnc', 'ondeletesubmission');

        return in_array($policy, [self::KEEP, self::DROP_FROZEN, self::DROP_ALL], true)
            ? $policy
            : self::KEEP;
    }

    /**
     * What the site wants done when a whole assignment is deleted.
     *
     * @return string ASSIGN_KEEP or ASSIGN_DELETE.
     * @throws dml_exception If the configuration cannot be read.
     */
    public static function assignment_policy(): string {
        return (string) get_config('assignsubmission_tipnc', 'ondeleteassign') === self::ASSIGN_DELETE
            ? self::ASSIGN_DELETE
            : self::ASSIGN_KEEP;
    }

    /**
     * Cleans NextCloud after a submission is removed in Moodle.
     *
     * Called before the plugin rows are deleted: they are what says which
     * documents belonged to that submission.
     *
     * @param  int      $instance   Assignment instance.
     * @param  stdClass $submission Submission being removed.
     * @return void
     * @throws dml_exception If the configuration cannot be read.
     */
    public function on_submission_removed(int $instance, stdClass $submission): void {
        $policy = self::submission_policy();
        $document = new document($instance);
        $nextcloud = new nextcloud($instance);

        // The stored path, not a rebuilt one: with re-attempts and groups the
        // name can no longer be guessed from the student's, and deleting by
        // guesswork means deleting somebody else's document, or none at all.
        $draft = $document->open_path($submission);
        $frozen = $document->submission_path($submission);

        $context = ['assignment' => $instance, 'affecteduserid' => (int) $submission->userid];

        if ($policy === self::DROP_ALL) {
            $nextcloud->delete_file($draft, $context + ['operation' => 'delete_draft']);
            $nextcloud->delete_file($frozen, $context + ['operation' => 'delete_submission']);
            return;
        }

        if ($policy === self::DROP_FROZEN) {
            $nextcloud->delete_file($frozen, $context + ['operation' => 'delete_submission']);
            $nextcloud->revoke_access($draft, $context + ['operation' => 'revoke_draft']);
            return;
        }

        $nextcloud->revoke_access($draft, $context + ['operation' => 'revoke_draft']);
        $nextcloud->revoke_access($frozen, $context + ['operation' => 'revoke_submission']);
    }

    /**
     * Deletes documents because somebody asked to be forgotten.
     *
     * This ignores the site's deletion policy on purpose. That policy is about
     * what to do when work is removed in the normal course of a term —where
     * keeping a copy is often what the site wants—. An erasure request is not
     * that: it says the data must go, and «the administrator preferred to keep
     * it» is not an answer to it.
     *
     * @param  array $paths      Documents to delete, as stored.
     * @param  int   $assignment Assignment they belong to, for the incident log.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    public static function erase(array $paths, int $assignment = 0): void {
        if (!$paths) {
            return;
        }

        $nextcloud = new nextcloud($assignment);
        $context = ['assignment' => $assignment, 'operation' => 'delete_assignment'];

        foreach (array_unique($paths) as $path) {
            if ((string) $path !== '') {
                $nextcloud->delete_file($path, $context);
            }
        }
    }

    /**
     * Removes the drafts and submissions of an assignment.
     *
     * Core asks for this both when the assignment is deleted and when the course
     * is reset, so the brief is left alone: see forget_briefs().
     *
     * @param  int $instance Assignment instance.
     * @return void
     * @throws dml_exception If the configuration cannot be read.
     */
    public function on_submissions_deleted(int $instance): void {
        global $DB;

        $nextcloud = new nextcloud($instance);
        $delete = self::assignment_policy() === self::ASSIGN_DELETE;
        $context = ['assignment' => $instance];

        // The recorded documents are walked, not the people: it is the only
        // list that includes re-attempts and group submissions.
        $paths = $DB->get_fieldset_sql(
            "SELECT path FROM {assignsubmission_tipnc} WHERE assignment = :a1 AND path IS NOT NULL
             UNION
             SELECT path FROM {assignsubmission_tipnc_open} WHERE assignment = :a2 AND path IS NOT NULL",
            ['a1' => $instance, 'a2' => $instance]
        );

        foreach ($paths as $file) {
            if ($delete) {
                $nextcloud->delete_file($file, $context + ['operation' => 'delete_assignment']);
            } else {
                $nextcloud->revoke_access($file, $context + ['operation' => 'revoke_assignment']);
            }
        }
    }

    /**
     * Removes the briefs of assignments that no longer exist.
     *
     * The stored path is used, not the composed one: the course or the
     * assignment may have been renamed since the brief was created.
     *
     * @param  int $instance Only this assignment, or 0 for every orphan.
     * @return void
     * @throws dml_exception If the tables cannot be read.
     */
    public function forget_briefs(int $instance = 0): void {
        global $DB;

        $sql = "SELECT e.assignment, e.path
                  FROM {assignsubmission_tipnc_enun} e
             LEFT JOIN {assign} a ON a.id = e.assignment
                 WHERE a.id IS NULL";
        $params = [];
        if ($instance > 0) {
            $sql .= " AND e.assignment = :instance";
            $params['instance'] = $instance;
        }

        $delete = self::assignment_policy() === self::ASSIGN_DELETE;

        foreach ($DB->get_records_sql($sql, $params) as $brief) {
            $assignment = (int) $brief->assignment;
            if (!empty($brief->path)) {
                $nextcloud = new nextcloud($assignment);
                $context = ['assignment' => $assignment];
                if ($delete) {
                    $nextcloud->delete_file($brief->path, $context + ['operation' => 'delete_assignment']);
                } else {
                    $nextcloud->revoke_access($brief->path, $context + ['operation' => 'revoke_assignment']);
                }
            }
            tipnc_enun::delete($assignment);
        }
    }
}
