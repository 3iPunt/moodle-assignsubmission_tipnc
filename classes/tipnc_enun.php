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
 * tipnc_enun
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use assignsubmission_tipnc\api\error;
use dml_exception;
use moodle_exception;
use stdClass;

/**
 * tipnc_enun
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tipnc_enun {
    /** @var string Table holding the briefs. */
    public const TABLE_TIPNC_ENUN = 'assignsubmission_tipnc_enun';

    /** @var string Setting of the assignment that names where its brief is. */
    public const CONFIG_BRIEFPATH = 'briefpath';

    /**
     * Notes where the brief of an assignment is, in its own settings.
     *
     * The settings are the only part of this plugin that travels in every backup,
     * duplication and import: this is what lets a copy find the brief to copy.
     *
     * @param  int    $assignment Assignment instance.
     * @param  string $path       Path of the brief in NextCloud, empty to forget it.
     * @return void
     * @throws dml_exception If the settings cannot be written.
     */
    public static function remember_path(int $assignment, string $path): void {
        global $DB;

        $conditions = [
            'assignment' => $assignment,
            'plugin' => 'tipnc',
            'subtype' => 'assignsubmission',
            'name' => self::CONFIG_BRIEFPATH,
        ];

        if ($path === '') {
            $DB->delete_records('assign_plugin_config', $conditions);
            return;
        }

        $id = $DB->get_field('assign_plugin_config', 'id', $conditions);
        if ($id) {
            $DB->set_field('assign_plugin_config', 'value', $path, ['id' => $id]);
            return;
        }

        $DB->insert_record('assign_plugin_config', (object) ($conditions + ['value' => $path]));
    }

    /**
     * Where the settings of an assignment say its brief is.
     *
     * @param  int $assignment Assignment instance.
     * @return string The path, empty when the settings say nothing.
     * @throws dml_exception If the settings cannot be read.
     */
    public static function remembered_path(int $assignment): string {
        global $DB;

        return (string) $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assignment,
            'plugin' => 'tipnc',
            'subtype' => 'assignsubmission',
            'name' => self::CONFIG_BRIEFPATH,
        ]);
    }

    /**
     * Get tipnc enunciate information from the database
     *
     * @param int $assignment
     * @return mixed
     * @throws dml_exception
     */
    public static function get(int $assignment) {
        global $DB;
        return $DB->get_record(self::TABLE_TIPNC_ENUN, ['assignment' => $assignment]);
    }

    /**
     * Set tipnc enunciate
     *
     * @param stdClass $data
     * @throws dml_exception
     */
    public static function set(stdClass $data) {
        global $DB;
        try {
            $DB->insert_record(self::TABLE_TIPNC_ENUN, $data);

            if (!empty($data->assignment) && !empty($data->path)) {
                self::remember_path((int) $data->assignment, (string) $data->path);
            }
        } catch (moodle_exception $e) {
            $assignment = empty($data->assignment) ? 0 : $data->assignment;
            $submission = empty($data->submission) ? null : $data->submission;
            tipnc_error::log('tipnc_enun:set', new error('2100', $e->getMessage()), $assignment, $submission);
        }
    }

    /**
     * Delete
     *
     * @param int $instance
     * @throws dml_exception
     */
    public static function delete(int $instance) {
        global $DB;
        try {
            $DB->delete_records(self::TABLE_TIPNC_ENUN, ['assignment' => $instance]);
            self::remember_path($instance, '');
        } catch (moodle_exception $e) {
            tipnc_error::log('tipnc_enun:delete', new error('2101', $e->getMessage()), $instance);
        }
    }
}
