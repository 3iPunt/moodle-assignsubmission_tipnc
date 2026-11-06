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
 * The catalogue of things that can go wrong.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use advanced_testcase;
use assignsubmission_tipnc\log\code;
use core_component;

/**
 * The catalogue of things that can go wrong.
 *
 * Every code ends up on a screen somebody reads while trying to work out why a
 * person could not hand their work in. A code with no text shows as
 * `[[logcode_0304]]`, which is worse than useless: it tells the reader the
 * plugin is broken instead of telling them what happened.
 *
 * That has bitten this plugin more than once, and it is invisible to any check
 * that looks for strings written literally in the code, because these names are
 * built at run time.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_tipnc\log\code
 */
final class code_test extends advanced_testcase {
    /**
     * Every code in the catalogue says what it means, in every language the
     * plugin ships.
     *
     * @return void
     */
    public function test_every_code_says_what_it_means(): void {
        $manager = get_string_manager();

        foreach (code::all() as $codevalue) {
            foreach (['en', 'es', 'ca'] as $language) {
                $this->assertTrue(
                    $manager->string_exists('logcode_' . $codevalue, 'assignsubmission_tipnc'),
                    'Code ' . $codevalue . ' has no text: it would show as [[logcode_'
                        . $codevalue . ']] in the log.'
                );

                $text = $manager->get_string(
                    'logcode_' . $codevalue,
                    'assignsubmission_tipnc',
                    null,
                    $language
                );

                $this->assertNotSame(
                    '',
                    trim($text),
                    'Code ' . $codevalue . ' has an empty text in ' . $language . '.'
                );
            }
        }
    }

    /**
     * Every code is classified, because that classification decides whether one
     * failure stops everything or only marks its own document.
     *
     * @return void
     */
    public function test_every_code_is_classified(): void {
        $kinds = [code::KIND_SYSTEM, code::KIND_ITEM, code::KIND_CONFIG];
        $severities = [code::SEVERITY_ERROR, code::SEVERITY_WARNING, code::SEVERITY_INFO];

        foreach (code::all() as $codevalue) {
            $this->assertContains(
                code::kind($codevalue),
                $kinds,
                'Code ' . $codevalue . ' is not classified.'
            );

            $this->assertContains(
                code::severity($codevalue),
                $severities,
                'Code ' . $codevalue . ' has no severity.'
            );
        }
    }

    /**
     * Only the failures of the service itself are system failures. Getting this
     * wrong stops everybody from working because one share was rejected.
     *
     * @return void
     */
    public function test_only_the_service_failing_is_a_system_failure(): void {
        foreach ([code::SYSTEM_UNREACHABLE, code::SYSTEM_UNAUTHORISED, code::SYSTEM_ERROR] as $codevalue) {
            $this->assertTrue(code::is_system_failure($codevalue));
        }

        foreach ([code::OK, code::SHARE_NO_ACCOUNT, code::SHARE_FAILED] as $codevalue) {
            $this->assertFalse(
                code::is_system_failure($codevalue),
                'Code ' . $codevalue . ' is about a document, not the service.'
            );
        }
    }

    /**
     * A code that is not in the catalogue is shown as it is, rather than as a
     * missing string.
     *
     * @return void
     */
    public function test_an_unknown_code_is_shown_as_it_is(): void {
        $this->assertFalse(code::exists('9999'));
        $this->assertSame('9999', code::describe('9999'));
    }

    /**
     * Every operation the code records has a name on screen. These are built at
     * run time —`operation_` plus the operation— so nothing that reads the
     * source for literal strings can see them.
     *
     * @return void
     */
    public function test_every_operation_has_a_name_on_screen(): void {
        global $CFG;

        $manager = get_string_manager();
        $root = $CFG->dirroot . '/mod/assign/submission/tipnc';
        $operations = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/classes'));

        foreach ($files as $file) {
            if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            preg_match_all("/context\(\s*'([a-z_]+)'/", $source, $matches);
            foreach ($matches[1] as $operation) {
                $operations[$operation] = true;
            }

            preg_match_all("/'operation'\s*=>\s*'([a-z_]+)'/", $source, $matches);
            foreach ($matches[1] as $operation) {
                $operations[$operation] = true;
            }
        }

        $this->assertNotEmpty($operations, 'No operation was found to check.');

        foreach (array_keys($operations) as $operation) {
            $this->assertTrue(
                $manager->string_exists('operation_' . $operation, 'assignsubmission_tipnc'),
                'Operation ' . $operation . ' has no name: it would show as [[operation_'
                    . $operation . ']] in the log.'
            );
        }
    }
}
