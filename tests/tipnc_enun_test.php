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
 * Where the brief is, as the settings of the assignment remember it.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use advanced_testcase;

/**
 * Where the brief is, as the settings of the assignment remember it.
 *
 * The settings travel in every backup, so they are what a copy of the
 * assignment uses to find the brief. They must never disagree with the table.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_tipnc\tipnc_enun
 */
final class tipnc_enun_test extends advanced_testcase {
    /**
     * Creating, moving and deleting the brief keep the settings in step.
     *
     * @return void
     */
    public function test_settings_follow_the_brief(): void {
        $this->resetAfterTest();

        $assignment = 42;

        tipnc_enun::set((object) [
            'assignment' => $assignment,
            'ncid' => 7,
            'userid' => 2,
            'path' => 'tasks/site/1 - c/42 - a/enun_42.docx',
        ]);
        $this->assertSame('tasks/site/1 - c/42 - a/enun_42.docx', tipnc_enun::remembered_path($assignment));

        tipnc_enun::remember_path($assignment, 'tasks/site/1 - c/42 - renamed/enun_42.docx');
        $this->assertSame('tasks/site/1 - c/42 - renamed/enun_42.docx', tipnc_enun::remembered_path($assignment));

        tipnc_enun::delete($assignment);
        $this->assertSame('', tipnc_enun::remembered_path($assignment));
    }

    /**
     * Remembering twice keeps a single setting.
     *
     * @return void
     */
    public function test_one_setting_per_assignment(): void {
        global $DB;

        $this->resetAfterTest();

        tipnc_enun::remember_path(5, 'a.docx');
        tipnc_enun::remember_path(5, 'b.docx');

        $this->assertSame(1, $DB->count_records('assign_plugin_config', [
            'assignment' => 5,
            'plugin' => 'tipnc',
            'name' => tipnc_enun::CONFIG_BRIEFPATH,
        ]));
        $this->assertSame('b.docx', tipnc_enun::remembered_path(5));
    }
}
