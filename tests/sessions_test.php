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
 * The key that names an editing session.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use advanced_testcase;
use assignsubmission_tipnc\models\sessions;

/**
 * The key that names an editing session.
 *
 * This is the rule that took three attempts. Calculated, it changed with every
 * save and left the live session unreachable, so the submission froze the
 * previous version. Fixed for good, the editor kept serving the copy it had and
 * refused orders about a session it considered finished.
 *
 * What has to hold: it changes when the document changed, and only then.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_tipnc\models\sessions
 */
final class sessions_test extends advanced_testcase {
    /** @var int Identifier of the document in NextCloud. */
    private const NCID = 1234;

    /** @var sessions The model under test. */
    private sessions $sessions;

    /**
     * A registered draft, which is what the key hangs from.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();

        $this->resetAfterTest();
        $this->sessions = new sessions();

        $DB->insert_record('assignsubmission_tipnc_open', (object) [
            'assignment' => 1,
            'submission' => 1,
            'ncid' => self::NCID,
            'path' => 'tasks/open_1_alumno1.docx',
        ]);
    }

    /**
     * Opening the same unchanged document twice gives the same key, so the
     * session stays reachable and can be told to save.
     *
     * @return void
     */
    public function test_the_key_holds_while_the_document_does_not_change(): void {
        $first = $this->sessions->key_for(self::NCID, 1000);
        $second = $this->sessions->key_for(self::NCID, 1000);

        $this->assertSame($first, $second);
    }

    /**
     * A document that changed gets a new key, or the editor keeps serving the
     * copy it had cached and says the version changed.
     *
     * @return void
     */
    public function test_a_changed_document_gets_a_new_key(): void {
        $before = $this->sessions->key_for(self::NCID, 1000);
        $after = $this->sessions->key_for(self::NCID, 2000);

        $this->assertNotSame($before, $after);
    }

    /**
     * The save of the open session moves the state along without changing the
     * key. This is the one that was missing: without it the next render renewed
     * the key and orphaned the very session that had just saved.
     *
     * @return void
     */
    public function test_a_save_by_the_open_session_keeps_its_key(): void {
        $key = $this->sessions->key_for(self::NCID, 1000);

        $this->sessions->note_save($key, 2000);

        $this->assertSame($key, $this->sessions->key_for(self::NCID, 2000));
    }

    /**
     * What the submission asks for is the key of the session that is open, never
     * a new one: a key nobody is using is a key the editor rejects.
     *
     * @return void
     */
    public function test_the_current_key_is_read_and_never_minted(): void {
        $key = $this->sessions->key_for(self::NCID, 1000);

        $this->assertSame($key, $this->sessions->current(self::NCID));
        $this->assertSame($key, $this->sessions->current(self::NCID));
    }

    /**
     * A document nobody ever opened has no session, and saying so is what lets
     * the submission explain itself instead of asking for an impossible save.
     *
     * @return void
     */
    public function test_a_document_never_opened_has_no_current_key(): void {
        $this->assertSame('', $this->sessions->current(self::NCID));
    }

    /**
     * A document that is not registered still opens: its session will not
     * survive a reload, which is better than not opening at all.
     *
     * @return void
     */
    public function test_an_unregistered_document_still_gets_a_key(): void {
        $key = $this->sessions->key_for(999999, 1000);

        $this->assertNotSame('', $key);
        $this->assertSame('', $this->sessions->current(999999));
    }

    /**
     * Noting a save of a key nobody has changes nothing.
     *
     * @return void
     */
    public function test_noting_a_save_of_an_unknown_key_changes_nothing(): void {
        $key = $this->sessions->key_for(self::NCID, 1000);

        $this->sessions->note_save('unknownkey', 2000);

        $this->assertSame($key, $this->sessions->current(self::NCID));
    }

    /**
     * A session that ended gives up its key, and the next opening gets a new one.
     *
     * @return void
     */
    public function test_an_ended_session_gives_up_its_key(): void {
        $key = $this->sessions->key_for(self::NCID, 1000);

        $this->sessions->retire($key);

        $this->assertSame('', $this->sessions->current(self::NCID));
        $this->assertNotSame($key, $this->sessions->key_for(self::NCID, 1000));
    }
}
