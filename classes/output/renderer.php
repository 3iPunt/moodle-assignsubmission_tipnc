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
 * Class renderer
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\output;

use moodle_exception;
use plugin_renderer_base;

/**
 * Class renderer
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Defer to template.
     *
     * @param  document_viewer $viewer A document on screen.
     * @return string html for the viewer
     * @throws moodle_exception If the template cannot be rendered.
     */
    public function render_document_viewer(document_viewer $viewer): string {
        return parent::render_from_template(
            'assignsubmission_tipnc/document_viewer',
            $viewer->export_for_template($this)
        );
    }

    /**
     * Defer to template.
     *
     * @param  incident_log_page $page The incident log.
     * @return string html for the page
     * @throws moodle_exception If the template cannot be rendered.
     */
    public function render_incident_log_page(incident_log_page $page): string {
        return parent::render_from_template(
            'assignsubmission_tipnc/incident_log_page',
            $page->export_for_template($this)
        );
    }

    /**
     * Defer to template.
     *
     * @param  incident_list $list The listing, which the filters repaint on its own.
     * @return string html for the listing
     * @throws moodle_exception If the template cannot be rendered.
     */
    public function render_incident_list(incident_list $list): string {
        return parent::render_from_template(
            'assignsubmission_tipnc/incident_list',
            $list->export_for_template($this)
        );
    }

    /**
     * Defer to template.
     *
     * @param  incident_detail $detail The detail of one incident.
     * @return string html for the panel
     * @throws moodle_exception If the template cannot be rendered.
     */
    public function render_incident_detail(incident_detail $detail): string {
        return parent::render_from_template(
            'assignsubmission_tipnc/incident_detail',
            $detail->export_for_template($this)
        );
    }

    /**
     * Defer to template.
     *
     * @param  submission_summary $summary The document of a submission.
     * @return string html for the box
     * @throws moodle_exception If the template cannot be rendered.
     */
    public function render_submission_summary(submission_summary $summary): string {
        return parent::render_from_template(
            'assignsubmission_tipnc/submission_summary',
            $summary->export_for_template($this)
        );
    }

    /**
     * Defer to template.
     *
     * @param  enunciate_block $block The brief of the assignment.
     * @return string html for the block
     * @throws moodle_exception If the template cannot be rendered.
     */
    public function render_enunciate_block(enunciate_block $block): string {
        return parent::render_from_template(
            'assignsubmission_tipnc/enunciate_block',
            $block->export_for_template($this)
        );
    }
}
