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
 * Moves the documents of the flat folder into the folder of their assignment.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\task;

use assignsubmission_tipnc\api\document;
use assignsubmission_tipnc\api\nextcloud;
use core\task\adhoc_task;
use dml_exception;

/**
 * Moves the documents of the flat folder into the folder of their assignment.
 *
 * Runs as an ad hoc task and not in the upgrade because it talks to another
 * server: an upgrade that waits on the network is an upgrade that can hang.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migrate_documents extends adhoc_task {
    /**
     * Moves every document that is still in the flat folder.
     *
     * @return void
     * @throws dml_exception If the tables cannot be read or written.
     */
    public function execute(): void {
        global $DB;

        $folder = trim((string) get_config('assignsubmission_tipnc', 'folder'), '/');
        if ($folder === '') {
            mtrace('assignsubmission_tipnc: no folder configured, nothing to move.');
            return;
        }

        $moved = 0;
        $failed = 0;

        foreach ($this->documents() as $entry) {
            [$table, $row, $instance, $username, $prefix] = $entry;

            if (!empty($row->path)) {
                continue;
            }

            $document = new document($instance);
            $destiny = $prefix === document::PREFIX_ENUN
                ? $document->get_enunciate()
                : $document->legacy_name($prefix, $username);

            // The old ones lived loose in the base folder, with the name in front.
            $origin = $folder . '/' . basename($destiny);
            if ($origin === $destiny) {
                continue;
            }

            $nextcloud = new nextcloud($instance);
            $nextcloud->ensure_folder(['assignment' => $instance]);
            $answer = $nextcloud->move_file($origin, $destiny, ['assignment' => $instance]);

            if ($answer->success) {
                $moved++;
            } else {
                $failed++;
            }

            // The path is stored either way: if the document was already in place
            // it is the right one, and if the move failed, it is where it should be.
            $row->path = $destiny;
            $DB->update_record($table, $row);
        }

        mtrace("assignsubmission_tipnc: documents moved: $moved, with problems: $failed.");
    }

    /**
     * Every document the plugin knows about, with what is needed to place it.
     *
     * @return array[] Table, row, assignment, username and prefix.
     * @throws dml_exception If the tables cannot be read.
     */
    private function documents(): array {
        global $DB;

        $documents = [];

        foreach ($DB->get_records('assignsubmission_tipnc_enun') as $row) {
            $documents[] = ['assignsubmission_tipnc_enun', $row, (int) $row->assignment, '', document::PREFIX_ENUN];
        }

        foreach (
            [
            'assignsubmission_tipnc_open' => document::PREFIX_OPEN,
            'assignsubmission_tipnc' => document::PREFIX_SUBMISSION,
            ] as $table => $prefix
        ) {
            $rows = $DB->get_records_sql(
                "SELECT t.*, u.username
                   FROM {" . $table . "} t
                   JOIN {assign_submission} s ON s.id = t.submission
                   JOIN {user} u ON u.id = s.userid"
            );

            foreach ($rows as $row) {
                $username = $row->username;
                unset($row->username);
                $documents[] = [$table, $row, (int) $row->assignment, $username, $prefix];
            }
        }

        return $documents;
    }
}
