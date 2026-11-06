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
 * Backup of the NextCloud documents of each submission.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup of the NextCloud documents of each submission.
 *
 * Only where each document is travels. The identifier in NextCloud and the keys of
 * the editor belong to the original files; the restore makes copies of its own.
 * The brief travels in the settings of the assignment, which are always backed up.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_assignsubmission_tipnc_subplugin extends backup_subplugin {
    /**
     * Attaches the documents of a submission to its element.
     *
     * @return backup_subplugin_element The element.
     */
    protected function define_submission_subplugin_structure(): backup_subplugin_element {
        $subplugin = $this->get_subplugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $element = new backup_nested_element('submission_tipnc', null, ['submission', 'openpath', 'submpath']);

        $subplugin->add_child($wrapper);
        $wrapper->add_child($element);

        $element->set_source_sql(
            "SELECT s.id AS submission, o.path AS openpath, t.path AS submpath
               FROM {assign_submission} s
          LEFT JOIN {assignsubmission_tipnc_open} o ON o.submission = s.id
          LEFT JOIN {assignsubmission_tipnc} t ON t.submission = s.id
              WHERE s.id = ? AND (o.id IS NOT NULL OR t.id IS NOT NULL)",
            [backup::VAR_PARENTID]
        );

        return $subplugin;
    }
}
