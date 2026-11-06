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
 * The editing session of a document.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\models;

use assignsubmission_tipnc\api\nextcloud;
use dml_exception;
use stdClass;

/**
 * The editing session of a document: the name Moodle and the editor use to talk
 * about the same thing.
 *
 * The key answers to two rules that look contradictory. It may not be reused for
 * content the editor has already seen —it would keep serving the copy it had, say
 * «the version has changed», and refuse orders about a session it considers
 * finished—; and it may not change while a document is open, or there is no way
 * to ask that session to save what it holds, which is what makes a submission
 * carry what was actually written.
 *
 * Both hold at once by renewing it exactly when the document changed since the
 * key was minted, which is why what was minted for is stored next to it. The
 * editor's own saves change the document but not the key: that session is alive
 * and stays reachable until somebody opens the document again.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sessions {
    /** @var int Length of a key, within what the editor accepts. */
    private const LENGTH = 20;

    /**
     * Tables that hold a document, in the order they are looked up.
     */
    private const TABLES = [
        'assignsubmission_tipnc',
        'assignsubmission_tipnc_open',
        'assignsubmission_tipnc_enun',
    ];

    /**
     * The key to open a document with, renewed if the document changed.
     *
     * @param  int    $ncid       Identifier of the document in NextCloud.
     * @param  int    $assignment Assignment the document belongs to.
     * @param  string $path       Path of the document in NextCloud.
     * @return string The key to hand to the editor.
     * @throws dml_exception If the key cannot be read or stored.
     */
    public function for_opening(int $ncid, int $assignment, string $path): string {
        return $this->key_for($ncid, (new nextcloud($assignment))->modified_time($path));
    }

    /**
     * The key for a document in the state it is in now.
     *
     * The rule lives apart from the call that asks NextCloud what state that is,
     * because the rule is the part that took three attempts to get right and the
     * part worth proving: renew when the document changed, keep it otherwise.
     *
     * @param  int $ncid    Identifier of the document in NextCloud.
     * @param  int $version State the document is in, as its modification time.
     * @return string The key to hand to the editor.
     * @throws dml_exception If the key cannot be read or stored.
     */
    public function key_for(int $ncid, int $version): string {
        global $DB;

        [$table, $record] = $this->find($ncid);

        if ($record === null) {
            // An unrecorded document still opens, with a session that does not survive a reload.
            return self::mint();
        }

        if (!empty($record->editorkey) && (int) $record->editorversion === $version) {
            return $record->editorkey;
        }

        $record->editorkey = self::mint();
        $record->editorversion = $version;
        $DB->update_record($table, $record);

        return $record->editorkey;
    }

    /**
     * The key of the session a document is open in, without touching it.
     *
     * @param  int $ncid Identifier of the document in NextCloud.
     * @return string The key, empty when the document was never opened.
     * @throws dml_exception If the key cannot be read.
     */
    public function current(int $ncid): string {
        [, $record] = $this->find($ncid);

        return (string) ($record->editorkey ?? '');
    }

    /**
     * Writes down that the open session itself changed the document.
     *
     * Without this the key would be renewed on the next render —the document did
     * change, after all— and the session that made the change would be left
     * unreachable, so there would be no way to ask it to save again.
     *
     * @param  string $key     Key of the session that saved.
     * @param  int    $version State the document is in now.
     * @return void
     * @throws dml_exception If the row cannot be updated.
     */
    public function note_save(string $key, int $version): void {
        global $DB;

        if ($key === '' || $version === 0) {
            return;
        }

        foreach (self::TABLES as $table) {
            if ($DB->record_exists($table, ['editorkey' => $key])) {
                $DB->set_field($table, 'editorversion', $version, ['editorkey' => $key]);

                return;
            }
        }
    }

    /**
     * Drops the key of a session that has ended, so the next opening gets a new one.
     *
     * @param  string $key Key of the session that ended.
     * @return void
     * @throws dml_exception If the row cannot be updated.
     */
    public function retire(string $key): void {
        global $DB;

        if ($key === '') {
            return;
        }

        foreach (self::TABLES as $table) {
            if ($DB->record_exists($table, ['editorkey' => $key])) {
                $DB->set_field($table, 'editorkey', null, ['editorkey' => $key]);

                return;
            }
        }
    }

    /**
     * A key nobody has used before.
     *
     * @return string The key, within the length the editor accepts.
     */
    private static function mint(): string {
        return random_string(self::LENGTH);
    }

    /**
     * The row that holds a document.
     *
     * @param  int $ncid Identifier of the document in NextCloud.
     * @return array The table it is in and the row, both null when there is none.
     * @throws dml_exception If the query fails.
     */
    private function find(int $ncid): array {
        global $DB;

        foreach (self::TABLES as $table) {
            $record = $DB->get_record(
                $table,
                ['ncid' => $ncid],
                'id, editorkey, editorversion',
                IGNORE_MULTIPLE
            );

            if ($record instanceof stdClass) {
                return [$table, $record];
            }
        }

        return [null, null];
    }
}
