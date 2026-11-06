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
 * Who has already been given access to a document.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\models;

use assignsubmission_tipnc\log\code;
use cache;
use dml_exception;

/**
 * Who has already been given access to a document.
 *
 * Access is granted when somebody walks into the assignment, which is the only
 * moment the plugin knows who actually teaches it. But walking in happens all
 * day, and NextCloud rejects sharing the same document twice, so without this
 * every page load would be a call and an incident in the log.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grants {
    /** @var string The brief of an assignment. */
    public const ENUNCIATE = 'e';

    /** @var string The frozen copy of one submission. */
    public const SUBMISSION = 's';

    /** @var string Their own draft, still open for writing. */
    public const OWN_DRAFT_WRITE = 'dw';

    /** @var string Their own draft, closed for writing. */
    public const OWN_DRAFT_READ = 'dr';

    /** @var string Their own frozen copy. */
    public const OWN_FROZEN = 'f';

    /**
     * Whether this person already has access to that document.
     *
     * @param  string $what Which kind of document, ENUNCIATE or SUBMISSION.
     * @param  int    $id   Assignment or submission it belongs to.
     * @param  int    $userid Who is asking.
     * @return bool True when it was already granted.
     */
    public static function already(string $what, int $id, int $userid): bool {
        return (bool) self::cache()->get(self::key($what, $id, $userid));
    }

    /**
     * Writes down that this person already has access.
     *
     * @param  string $what   Which kind of document.
     * @param  int    $id     Assignment or submission it belongs to.
     * @param  int    $userid Who was given it.
     * @return void
     */
    public static function remember(string $what, int $id, int $userid): void {
        self::cache()->set(self::key($what, $id, $userid), 1);
    }

    /**
     * Forgets it, so the next visit grants access again.
     *
     * @param  string $what   Which kind of document.
     * @param  int    $id     Assignment or submission it belongs to.
     * @param  int    $userid Whose access to forget.
     * @return void
     */
    public static function forget(string $what, int $id, int $userid): void {
        self::cache()->delete(self::key($what, $id, $userid));
    }

    /**
     * Whether this person has no account in the document service.
     *
     * Answered from what already happened —their share was rejected for not
     * existing— and not by asking NextCloud: the answer does not change until
     * somebody creates the account.
     *
     * @param  int $userid Who it is about.
     * @return bool True when their share was rejected for not existing.
     * @throws dml_exception If the log cannot be read.
     */
    public static function lacks_account(int $userid): bool {
        global $DB;

        if ($userid === 0) {
            return false;
        }

        return $DB->record_exists('assignsubmission_tipnc_log', [
            'errorcode' => code::SHARE_NO_ACCOUNT,
            'affecteduserid' => $userid,
        ]);
    }

    /**
     * Where one person's access to one document is written down.
     *
     * @param  string $what   Which kind of document.
     * @param  int    $id     Assignment or submission it belongs to.
     * @param  int    $userid Who it is about.
     * @return string The key.
     */
    private static function key(string $what, int $id, int $userid): string {
        return $what . $id . '_' . $userid;
    }

    /**
     * The store holding it.
     *
     * @return cache The cache.
     */
    private static function cache(): cache {
        return cache::make('assignsubmission_tipnc', 'granted');
    }
}
