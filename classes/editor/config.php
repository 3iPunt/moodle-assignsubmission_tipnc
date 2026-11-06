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
 * What Moodle tells the editor about a document.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\editor;

use dml_exception;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * What Moodle tells the editor about a document: which one, who is opening it,
 * whether they may write, where to fetch it and where to report back.
 *
 * The whole thing travels signed, so the editor answers to Moodle and not to
 * whoever knows the address.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var string The document can be written. */
    public const MODE_EDIT = 'edit';

    /** @var string The document can only be read. */
    public const MODE_VIEW = 'view';

    /**
     * Constructor.
     *
     * @param int $instance Assignment the document belongs to.
     */
    public function __construct(
        /** @var int Assignment the document belongs to. */
        private readonly int $instance
    ) {
    }

    /**
     * The configuration of one document, ready for the editor and signed.
     *
     * @param  string   $path Path of the document in NextCloud.
     * @param  string   $key  Key of the editing session.
     * @param  stdClass $user Who is opening it.
     * @param  string   $mode MODE_EDIT or MODE_VIEW.
     * @return array The configuration, with its signature inside.
     * @throws moodle_exception If the site has no secret configured.
     * @throws dml_exception If the configuration cannot be read.
     */
    public function for_document(string $path, string $key, stdClass $user, string $mode): array {
        $canedit = $mode === self::MODE_EDIT;

        $config = [
            'document' => [
                'fileType' => ltrim(pathinfo($path, PATHINFO_EXTENSION), '.'),
                'key' => $key,
                'title' => basename($path),
                'url' => $this->document_url($path, $key)->out(false),
                'permissions' => [
                    'edit' => $canedit,
                    'download' => true,
                    'print' => true,
                ],
            ],
            'documentType' => self::document_type($path),
            'editorConfig' => [
                'lang' => current_language(),
                'mode' => $canedit ? 'edit' : 'view',
                'user' => [
                    'id' => (string) $user->id,
                    'name' => fullname($user),
                ],
                'customization' => [
                    // The editor saves when the person asks, not only on close:
                    // it is half of what makes the submission be what was written.
                    'forcesave' => true,
                ],
            ],
        ];

        // Whoever only reads gets no address to write back to.
        if ($canedit) {
            $config['editorConfig']['callbackUrl'] = $this->callback_url($path, $key)->out(false);
        }

        $config['token'] = token::sign($config);

        return $config;
    }

    /**
     * Which editor opens this document.
     *
     * @param  string $path Path of the document.
     * @return string word, cell or slide.
     */
    public static function document_type(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xls', 'xlsx', 'ods', 'csv' => 'cell',
            'ppt', 'pptx', 'odp' => 'slide',
            default => 'word',
        };
    }

    /**
     * Address where the editor fetches the document.
     *
     * It carries its own signature: the editor has no Moodle session, so the
     * signature is what stands for permission.
     *
     * @param  string $path Path of the document.
     * @param  string $key  Key of the editing session.
     * @return moodle_url The address.
     * @throws moodle_exception If the site has no secret configured.
     * @throws dml_exception If the configuration cannot be read.
     */
    public function document_url(string $path, string $key): moodle_url {
        return new moodle_url('/mod/assign/submission/tipnc/document.php', [
            'token' => token::sign([
                'instance' => $this->instance,
                'path' => $path,
                'key' => $key,
                'use' => 'download',
            ]),
        ]);
    }

    /**
     * Address where the editor reports that it saved.
     *
     * @param  string $path Path of the document.
     * @param  string $key  Key of the editing session.
     * @return moodle_url The address.
     * @throws moodle_exception If the site has no secret configured.
     * @throws dml_exception If the configuration cannot be read.
     */
    public function callback_url(string $path, string $key): moodle_url {
        return new moodle_url('/mod/assign/submission/tipnc/callback.php', [
            'token' => token::sign([
                'instance' => $this->instance,
                'path' => $path,
                'key' => $key,
                'use' => 'callback',
                'mode' => self::MODE_EDIT,
            ], DAYSECS),
        ]);
    }
}
