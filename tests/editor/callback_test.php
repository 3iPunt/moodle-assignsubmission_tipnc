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
 * Who may write back to a document through the editor callback.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\editor;

use advanced_testcase;

/**
 * Who may write back to a document through the editor callback.
 *
 * Moodle and the Document Server sign with the same secret, and Moodle hands
 * its own tokens to anyone who opens a document. A valid signature is not
 * enough: only a callback of the editor, for this session, may be stored.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_tipnc\editor\callback
 * @covers     \assignsubmission_tipnc\editor\config
 */
final class callback_test extends advanced_testcase {
    /** @var string Key of the editing session under test. */
    private const KEY = 'abc123';

    /** @var string Address of the Document Server, as configured. */
    private const DOCSERVER = 'https://office.example.com';

    /**
     * A configured editor.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        set_config('docserverurl', self::DOCSERVER . '/', 'assignsubmission_tipnc');
        set_config('docserversecret', 'secret-for-tests', 'assignsubmission_tipnc');
    }

    /**
     * The callback the editor really sends is accepted.
     *
     * @return void
     */
    public function test_editor_callback_is_accepted(): void {
        $this->assertTrue(callback::is_editor_callback(
            ['key' => self::KEY, 'status' => callback::STATUS_READY, 'url' => self::DOCSERVER . '/cache/x.docx'],
            self::KEY
        ));
    }

    /**
     * The configuration token, which every viewer of the page holds, is refused.
     *
     * @return void
     */
    public function test_configuration_token_is_refused(): void {
        $user = $this->getDataGenerator()->create_user();
        $config = (new config(1))->for_document('tasks/open_1_student.docx', self::KEY, $user, config::MODE_VIEW);

        $signed = token::verify($config['token']);
        $this->assertNotNull($signed);

        // Even with the fields of a callback added loose next to it.
        $signed += ['status' => callback::STATUS_READY, 'url' => 'https://attacker.example.net/x'];

        $this->assertFalse(callback::is_editor_callback($signed, self::KEY));
    }

    /**
     * The tokens of the download and callback addresses are refused too.
     *
     * @return void
     */
    public function test_moodle_address_tokens_are_refused(): void {
        foreach (['download', 'callback'] as $use) {
            $signed = [
                'instance' => 1,
                'path' => 'tasks/open_1_student.docx',
                'key' => self::KEY,
                'use' => $use,
                'status' => callback::STATUS_READY,
            ];

            $this->assertFalse(callback::is_editor_callback($signed, self::KEY), $use);
        }
    }

    /**
     * A callback about another session does not write in this document.
     *
     * @return void
     */
    public function test_callback_of_another_session_is_refused(): void {
        $this->assertFalse(callback::is_editor_callback(
            ['key' => 'another', 'status' => callback::STATUS_READY],
            self::KEY
        ));
    }

    /**
     * A payload without the callback fields is refused.
     *
     * @return void
     */
    public function test_payload_without_status_is_refused(): void {
        $this->assertFalse(callback::is_editor_callback(['key' => self::KEY], self::KEY));
        $this->assertFalse(callback::is_editor_callback(['status' => 2, 'key' => self::KEY], ''));
    }

    /**
     * Only what the configured Document Server serves is downloaded.
     *
     * @return void
     */
    public function test_result_must_come_from_the_docserver(): void {
        $this->assertTrue(callback::is_from_docserver(self::DOCSERVER . '/cache/files/x/output.docx'));
        $this->assertTrue(callback::is_from_docserver('https://OFFICE.example.com:443/cache/x'));

        $this->assertFalse(callback::is_from_docserver('https://attacker.example.net/x.docx'));
        $this->assertFalse(callback::is_from_docserver('http://office.example.com/x.docx'));
        $this->assertFalse(callback::is_from_docserver('https://office.example.com:8443/x.docx'));
        $this->assertFalse(callback::is_from_docserver('https://office.example.com.attacker.net/x'));
        $this->assertFalse(callback::is_from_docserver('file:///etc/passwd'));
        $this->assertFalse(callback::is_from_docserver(''));
    }

    /**
     * Whoever only reads gets no address to write back to; whoever edits does,
     * and it says it was issued for an editing session.
     *
     * @return void
     */
    public function test_only_editing_sessions_get_a_callback(): void {
        $user = $this->getDataGenerator()->create_user();
        $config = new config(1);

        $view = $config->for_document('tasks/subm_1_student.docx', self::KEY, $user, config::MODE_VIEW);
        $this->assertArrayNotHasKey('callbackUrl', $view['editorConfig']);

        $edit = $config->for_document('tasks/open_1_student.docx', self::KEY, $user, config::MODE_EDIT);
        $this->assertArrayHasKey('callbackUrl', $edit['editorConfig']);

        parse_str((string) parse_url($edit['editorConfig']['callbackUrl'], PHP_URL_QUERY), $query);
        $address = token::verify($query['token']);

        $this->assertSame('callback', $address['use']);
        $this->assertSame(config::MODE_EDIT, $address['mode']);
    }
}
