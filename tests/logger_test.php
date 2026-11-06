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
 * What the incident log writes down, and what it refuses to write down.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use advanced_testcase;
use assignsubmission_tipnc\log\code;
use assignsubmission_tipnc\log\logger;

/**
 * What the incident log writes down, and what it refuses to write down.
 *
 * The log exists to be read by somebody diagnosing a problem, which means it
 * carries whatever NextCloud answered. That is exactly where a password ends up
 * if nobody takes it out —and a log is the last place anybody looks for one—.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_tipnc\log\logger
 */
final class logger_test extends advanced_testcase {
    /** @var string Stands in for the service password in these tests. */
    private const SECRET = 'ClaveDeServicio123';

    /**
     * A configured plugin, so the password is there to be masked.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        set_config('password', self::SECRET, 'assignsubmission_tipnc');
    }

    /**
     * The row that was just written.
     *
     * @return \stdClass The incident.
     */
    private function last(): \stdClass {
        global $DB;

        $rows = $DB->get_records('assignsubmission_tipnc_log', null, 'id DESC', '*', 0, 1);

        return reset($rows);
    }

    /**
     * Credentials inside an address never reach the log.
     *
     * @return void
     */
    public function test_credentials_in_an_address_are_taken_out(): void {
        logger::error(code::SYSTEM_UNAUTHORISED, 'test', [
            'requesturl' => 'https://moodle:' . self::SECRET . '@nextcloud.example.org/remote.php/dav',
        ]);

        $row = $this->last();

        $this->assertStringNotContainsString(self::SECRET, $row->requesturl);
        $this->assertStringContainsString('nextcloud.example.org', $row->requesturl);
    }

    /**
     * An authentication header never reaches the log either.
     *
     * @return void
     */
    public function test_an_authentication_header_is_taken_out(): void {
        logger::error(code::SYSTEM_UNAUTHORISED, 'test', [
            'responsebody' => 'Authorization: Basic bW9vZGxlOnNlY3JldA== rechazada',
        ]);

        $this->assertStringNotContainsString('bW9vZGxlOnNlY3JldA==', $this->last()->responsebody);
    }

    /**
     * And the service password, if the server ever echoed it back.
     *
     * @return void
     */
    public function test_the_service_password_is_taken_out_of_the_answer(): void {
        logger::error(code::SYSTEM_UNAUTHORISED, 'test', [
            'responsebody' => 'The password ' . self::SECRET . ' is not valid',
        ]);

        $row = $this->last();

        $this->assertStringNotContainsString(self::SECRET, $row->responsebody);
        $this->assertStringContainsString('is not valid', $row->responsebody);
    }

    /**
     * The same failure over and over is one line with a count, not a thousand
     * lines: a log nobody can read is a log nobody reads.
     *
     * @return void
     */
    public function test_the_same_failure_is_grouped(): void {
        global $DB;

        for ($i = 0; $i < 3; $i++) {
            logger::warning(code::SHARE_NO_ACCOUNT, 'share_draft', [
                'method' => 'test', 'assignment' => 1, 'userid' => 2,
            ]);
        }

        $this->assertSame(1, $DB->count_records(
            'assignsubmission_tipnc_log',
            ['errorcode' => code::SHARE_NO_ACCOUNT]
        ));
        $this->assertSame(3, (int) $this->last()->occurrences);
    }

    /**
     * Successes are not grouped: each one is a distinct fact in the history of
     * a document, and collapsing them would lose when things happened.
     *
     * @return void
     */
    public function test_successes_are_not_grouped(): void {
        global $DB;

        for ($i = 0; $i < 3; $i++) {
            logger::info(code::OK, 'editor_save', ['method' => 'test', 'assignment' => 1]);
        }

        $this->assertSame(3, $DB->count_records(
            'assignsubmission_tipnc_log',
            ['errorcode' => code::OK]
        ));
    }

    /**
     * A repetition refreshes what it brings and keeps what it does not: a call
     * with no answer must not wipe the diagnosis already recorded.
     *
     * @return void
     */
    public function test_a_repetition_does_not_wipe_the_diagnosis(): void {
        logger::warning(code::SHARE_FAILED, 'share_draft', [
            'method' => 'test', 'assignment' => 1, 'responsebody' => 'the original reason',
        ]);

        logger::warning(code::SHARE_FAILED, 'share_draft', [
            'method' => 'test', 'assignment' => 1,
        ]);

        $this->assertStringContainsString('the original reason', $this->last()->responsebody);
    }
}
