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
 * What a restored assignment may copy, and which ones are waiting for it.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\models;

use assignsubmission_tipnc\tipnc_enun;
use coding_exception;
use context_module;
use dml_exception;

/**
 * What a restored assignment may copy, and which ones are waiting for it.
 *
 * A backup file can be edited by hand, so the paths it carries are not trusted:
 * a document is only copied if this site knows it and the person who restored
 * could have backed up the assignment it belongs to.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restored {
    /** @var string[] Tables that hold the path of a document. */
    private const TABLES = [
        'assignsubmission_tipnc_enun',
        'assignsubmission_tipnc_open',
        'assignsubmission_tipnc',
    ];

    /** @var string[] Either of these proves the person could have made the backup. */
    private const CAPABILITIES = [
        'moodle/backup:backupactivity',
        'moodle/backup:backuptargetimport',
    ];

    /**
     * Assignment a known document belongs to.
     *
     * @param  string $path Path of the document in NextCloud.
     * @return int The assignment instance, 0 when this site does not know the document.
     * @throws dml_exception If the tables cannot be read.
     */
    public function owner_of(string $path): int {
        global $DB;

        if ($path === '') {
            return 0;
        }

        foreach (self::TABLES as $table) {
            $assignment = $DB->get_field($table, 'assignment', ['path' => $path], IGNORE_MULTIPLE);
            if ($assignment) {
                return (int) $assignment;
            }
        }

        return 0;
    }

    /**
     * Whether a document may be copied on behalf of somebody.
     *
     * @param  string $path   Path of the document in NextCloud.
     * @param  int    $userid Who restored.
     * @return bool True when the document is known and they could have backed it up.
     * @throws coding_exception If the context cannot be built.
     * @throws dml_exception If the tables cannot be read.
     */
    public function may_copy(string $path, int $userid): bool {
        $assignment = $this->owner_of($path);
        if ($assignment === 0 || $userid === 0) {
            return false;
        }

        $cm = get_coursemodule_from_instance('assign', $assignment, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return false;
        }

        return has_any_capability(self::CAPABILITIES, context_module::instance($cm->id), $userid);
    }

    /**
     * Assignments that came with the address of a brief and have none of their own.
     *
     * @param  int $courseid   Course to look in, 0 for any.
     * @param  int $assignment One assignment, 0 for all of the course.
     * @return int[] Assignment instances.
     * @throws dml_exception If the tables cannot be read.
     */
    public function pending_briefs(int $courseid = 0, int $assignment = 0): array {
        global $DB;

        $params = [
            'plugin' => 'tipnc',
            'subtype' => 'assignsubmission',
            'name' => tipnc_enun::CONFIG_BRIEFPATH,
            'eplugin' => 'tipnc',
            'esubtype' => 'assignsubmission',
            'ename' => 'enabled',
            'one' => '1',
        ];

        $where = '';
        if ($courseid > 0) {
            $where .= ' AND a.course = :course';
            $params['course'] = $courseid;
        }
        if ($assignment > 0) {
            $where .= ' AND a.id = :assignment';
            $params['assignment'] = $assignment;
        }

        $enabled = $DB->sql_compare_text('en.value');

        $sql = "SELECT a.id
                  FROM {assign} a
                  JOIN {assign_plugin_config} bp ON bp.assignment = a.id
                       AND bp.plugin = :plugin AND bp.subtype = :subtype AND bp.name = :name
                  JOIN {assign_plugin_config} en ON en.assignment = a.id
                       AND en.plugin = :eplugin AND en.subtype = :esubtype AND en.name = :ename
             LEFT JOIN {assignsubmission_tipnc_enun} e ON e.assignment = a.id
                 WHERE e.id IS NULL AND $enabled = :one $where";

        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }
}
