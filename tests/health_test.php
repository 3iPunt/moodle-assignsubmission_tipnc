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
 * Whether NextCloud is answering.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use advanced_testcase;
use assignsubmission_tipnc\log\code;
use assignsubmission_tipnc\models\health;

/**
 * Whether NextCloud is answering.
 *
 * What this has to get right is the difference between the service being down
 * and one document failing. Confusing them would stop everybody from handing
 * work in because one person's share was rejected.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_tipnc\models\health
 */
final class health_test extends advanced_testcase {
    /**
     * Nothing is known before anything has been tried.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        health::forget();
    }

    /**
     * Not knowing is not the same as being down: a site where nothing has been
     * tried yet must not refuse submissions.
     *
     * @return void
     */
    public function test_nothing_known_is_not_the_same_as_down(): void {
        $this->assertFalse(health::is_down());
        $this->assertNull(health::state());
    }

    /**
     * A failure of the service marks it down and says since when.
     *
     * @return void
     */
    public function test_a_failure_of_the_service_marks_it_down(): void {
        health::note(code::SYSTEM_UNREACHABLE);

        $this->assertTrue(health::is_down());
        $this->assertGreaterThan(0, health::state()->since);
    }

    /**
     * Credentials rejected and internal errors are the service failing too:
     * nothing anybody does in Moodle will make those calls work.
     *
     * @return void
     */
    public function test_credentials_and_internal_errors_count_as_down(): void {
        foreach ([code::SYSTEM_UNAUTHORISED, code::SYSTEM_ERROR] as $errorcode) {
            health::forget();
            health::note($errorcode);

            $this->assertTrue(
                health::is_down(),
                'Code ' . $errorcode . ' should mark the service as down.'
            );
        }
    }

    /**
     * One document failing says nothing about the service. This is the one that
     * matters: a share rejected for one person must not block the whole site.
     *
     * @return void
     */
    public function test_one_document_failing_does_not_bring_the_service_down(): void {
        foreach ([code::SHARE_NO_ACCOUNT, code::SHARE_FAILED, code::COPY_NOT_FOUND] as $errorcode) {
            health::forget();
            health::note($errorcode);

            $this->assertFalse(
                health::is_down(),
                'Code ' . $errorcode . ' is about a document, not the service.'
            );
        }
    }

    /**
     * A call that works clears it, so the plugin unblocks itself as soon as
     * NextCloud comes back.
     *
     * @return void
     */
    public function test_a_call_that_works_clears_it(): void {
        health::note(code::SYSTEM_UNREACHABLE);
        $this->assertTrue(health::is_down());

        health::note(code::OK);

        $this->assertFalse(health::is_down());
    }

    /**
     * While it stays down, «since» keeps the first moment: that is what says
     * how long it has been like this, not when it was last noticed.
     *
     * @return void
     */
    public function test_since_keeps_the_first_moment_it_failed(): void {
        health::note(code::SYSTEM_UNREACHABLE);
        $first = health::state()->since;

        health::note(code::SYSTEM_ERROR);

        $this->assertSame($first, health::state()->since);
    }
}
