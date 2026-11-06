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
 * How a document is shown on screen.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\output;

use assignsubmission_tipnc\api\document;
use assignsubmission_tipnc\editor\config;
use assignsubmission_tipnc\editor\token;
use assignsubmission_tipnc\editor\viewmode;
use assignsubmission_tipnc\models\grants;
use assignsubmission_tipnc\models\sessions;
use dml_exception;
use moodle_exception;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * How a document is shown on screen: the editor itself, or a frame with the page
 * NextCloud serves.
 *
 * Both the brief and the submission show documents, and until now each carried
 * its own copy of the frame. With a third way to show one —the editor embedded by
 * Moodle— that duplication stops being harmless.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class document_viewer implements renderable, templatable {
    /** @var string It is frozen: it was handed in and no longer changes. */
    public const READONLY_FROZEN = 'frozen';

    /** @var string Submissions are closed for this person: locked or past the cut-off. */
    public const READONLY_CLOSED = 'closed';

    /** @var string This person only gets to read it. */
    public const READONLY_ROLE = 'role';

    /** @var string This person has no account in the document service. */
    public const READONLY_NOACCOUNT = 'noaccount';

    /**
     * Constructor.
     *
     * @param string     $url         Address of the NextCloud page, for the frame.
     * @param string     $title       Name of what is being shown, for accessibility.
     * @param string     $heightclass Class that sets the height of the frame.
     * @param array|null $editor      Configuration for the embedded editor, when used.
     * @param string     $readonly    Why it cannot be written in, empty when it can.
     */
    public function __construct(
        /** @var string Address of the NextCloud page, for the frame. */
        private readonly string $url,
        /** @var string Name of what is being shown. */
        private readonly string $title,
        /** @var string Class that sets the height. */
        private readonly string $heightclass = '',
        /** @var ?array Configuration for the embedded editor, when used. */
        private readonly ?array $editor = null,
        /** @var string Why it cannot be written in, empty when it can. */
        private readonly string $readonly = ''
    ) {
    }

    /**
     * Builds the viewer for one document, in whichever way the site chose.
     *
     * @param  int      $instance Assignment the document belongs to.
     * @param  string   $path     Path of the document in NextCloud.
     * @param  int      $ncid     Identifier of the document in NextCloud.
     * @param  string   $title    Name of what is being shown.
     * @param  stdClass $user     Who is looking at it.
     * @param  bool     $canedit  Whether this person may write in it.
     * @param  string   $heightclass Class that sets the height.
     * @param  string   $readonly    Why it cannot be written in, empty when it can.
     * @return self The viewer.
     * @throws dml_exception If the configuration cannot be read.
     * @throws moodle_exception If the editor is chosen and has no secret.
     */
    public static function for_document(
        int $instance,
        string $path,
        int $ncid,
        string $title,
        stdClass $user,
        bool $canedit,
        string $heightclass = '',
        string $readonly = ''
    ): self {
        $url = document::get_url($ncid);

        // With no account there is no writing possible, whatever anybody's role
        // says: this is the reason that overrules the others.
        if (grants::lacks_account((int) $user->id)) {
            $canedit = false;
            $readonly = self::READONLY_NOACCOUNT;
        } else if (!$canedit && $readonly === '') {
            $readonly = self::READONLY_ROLE;
        }

        if (viewmode::current() !== viewmode::EDITOR) {
            return new self($url, $title, $heightclass, null, $readonly);
        }

        // Opening is the only moment when the key can change: while the document
        // is open it is what allows that session to be asked to save.
        $editor = (new config($instance))->for_document(
            $path,
            (new sessions())->for_opening($ncid, $instance, $path),
            $user,
            $canedit ? config::MODE_EDIT : config::MODE_VIEW
        );

        return new self($url, $title, $heightclass, $editor, $readonly);
    }

    /**
     * Export for template.
     *
     * @param  renderer_base $output The renderer.
     * @return array The data of the viewer.
     * @throws dml_exception If the configuration cannot be read.
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'url' => $this->url,
            'frametitle' => $this->title,
            'heightclass' => $this->heightclass,
            'useeditor' => $this->editor !== null,
            'elementid' => 'tipnc-editor-' . uniqid(),
            'serverurl' => token::server_url(),
            'configjson' => $this->editor !== null ? json_encode($this->editor) : '',

            // A document that opens and cannot be written in always has a reason,
            // and saying so saves it from being discovered by trying to type.
            'isreadonly' => $this->readonly !== '',
            'readonlytext' => $this->readonly !== ''
                ? get_string('readonly_' . $this->readonly, 'assignsubmission_tipnc')
                : '',
        ];
    }
}
