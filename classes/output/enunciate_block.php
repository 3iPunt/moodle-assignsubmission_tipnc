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
 * The brief of the assignment, shown on its page.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\output;

use assignsubmission_tipnc\models\documents;
use coding_exception;
use dml_exception;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * The brief of the assignment: the document the teacher has to write, which the
 * core never shows them.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enunciate_block implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param string      $viewurl    Address of the document, empty when there is none.
     * @param string      $filename   Name of the document in NextCloud.
     * @param string|null $folderurl   Address of the folder holding it.
     * @param bool        $embed       Show the document, or just link to it.
     * @param string      $heightclass Class that sets the height of the frame.
     * @param document_viewer|null $viewer How the document is shown, when it is shown.
     * @param moodle_url|null $prepareurl Where to ask for the document to be made,
     *                                    only for whoever is allowed to ask.
     * @param bool        $preparing   The brief of a restored assignment is being copied.
     */
    public function __construct(
        /** @var string Address of the document, empty when there is none. */
        private readonly string $viewurl,
        /** @var string Name of the document in NextCloud. */
        private readonly string $filename,
        /** @var ?string Address of the folder holding it. */
        private readonly ?string $folderurl = null,
        /** @var bool Show the document, or just link to it. */
        private readonly bool $embed = true,
        /** @var string Class that sets the height of the frame. */
        private readonly string $heightclass = '',
        /** @var ?document_viewer How the document is shown, when it is shown. */
        private readonly ?document_viewer $viewer = null,
        /** @var ?moodle_url Where to ask for the document to be made,. */
        private readonly ?moodle_url $prepareurl = null,
        /** @var bool The brief of a restored assignment is being copied. */
        private readonly bool $preparing = false
    ) {
    }

    /**
     * Builds the block for one assignment.
     *
     * @param  int  $assignment Assignment instance.
     * @param  bool $embed      Show the document, or just link to it.
     * @param  moodle_url|null $prepareurl Where to ask for the brief to be made.
     * @param  bool $canedit    Whether this person may write in it.
     * @param  bool $preparing  The brief of a restored assignment is being copied.
     * @return self The block.
     * @throws dml_exception If the configuration cannot be read.
     */
    public static function for_assignment(
        int $assignment,
        bool $embed = true,
        ?moodle_url $prepareurl = null,
        bool $canedit = false,
        bool $preparing = false
    ): self {
        global $USER;

        $model = new documents();
        $enunciate = $model->get_enunciate($assignment);

        $path = $model->enunciate_path_of($assignment);

        // Whoever marks writes the brief; everybody else reads it. Always opening
        // it read-only forced teachers out to NextCloud to write it.
        $viewer = ($enunciate && $embed)
            ? document_viewer::for_document(
                $assignment,
                $path,
                (int) $enunciate->ncid,
                get_string('enunciate_frametitle', 'assignsubmission_tipnc'),
                $USER,
                $canedit,
                $model->frame_height_class()
            )
            : null;

        return new self(
            $enunciate ? $model->view_url((int) $enunciate->ncid) : '',
            $model->enunciate_filename($assignment),
            $model->folder_url(),
            $embed,
            $model->frame_height_class(),
            $viewer,
            $prepareurl,
            $preparing
        );
    }

    /**
     * Export for template.
     *
     * @param  renderer_base $output The renderer.
     * @return array The data of the block.
     * @throws coding_exception If a language string is missing.
     */
    public function export_for_template(renderer_base $output): array {
        $ready = $this->viewurl !== '';

        return [
            'ready' => $ready,
            'embed' => $ready && $this->embed,
            'heightclass' => $this->heightclass,
            'viewer' => $this->viewer?->export_for_template($output),
            'viewurl' => $this->viewurl,
            'filename' => $this->filename,
            'folderurl' => $this->folderurl,
            'hasfolder' => !empty($this->folderurl),

            // Whoever can fix it sees the diagnosis and the button; whoever cannot,
            // a sentence telling them what is happening and who to tell.
            'preparing' => !$ready && $this->preparing,
            'canprepare' => $this->prepareurl !== null,
            'prepareurl' => $this->prepareurl?->out(false),
            'frametitle' => get_string('enunciate_frametitle', 'assignsubmission_tipnc'),
        ];
    }
}
