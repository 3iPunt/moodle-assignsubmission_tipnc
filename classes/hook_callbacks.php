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
 * Hook callbacks of the plugin.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc;

use assignsubmission_tipnc\api\document;
use assignsubmission_tipnc\api\nextcloud;
use assignsubmission_tipnc\editor\viewmode;
use assignsubmission_tipnc\models\cleanup;
use assignsubmission_tipnc\models\documents;
use assignsubmission_tipnc\models\health;
use assignsubmission_tipnc\output\document_viewer;
use assignsubmission_tipnc\output\enunciate_block;
use assignsubmission_tipnc\output\submission_summary;
use assignsubmission_tipnc\output\unavailable;
use assignsubmission_tipnc\task\create_enunciate;
use assignsubmission_tipnc\tipnc_enun;
use cm_info;
use coding_exception;
use core\hook\output\after_standard_main_region_html_generation;
use core\hook\output\before_http_headers;
use core\hook\output\before_standard_top_of_body_html_generation;
use dml_exception;
use html_writer;
use moodle_url;

/**
 * Hook callbacks of the plugin.
 *
 * mod_assign offers a submission subplugin no place of its own on the assignment
 * page: the submission status box requires mod/assign:viewownsubmissionsummary,
 * which only students hold. These callbacks put the brief where everybody sees
 * it —students included— and in the same place for all of them.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var string In the activity header, next to the activity description. */
    public const PLACE_HEADER = 'header';

    /** @var string At the end of the main region, which every theme has. */
    public const PLACE_MAIN = 'main';

    /** @var string Wherever the site says: the browser moves it there. */
    public const PLACE_SELECTOR = 'selector';

    /** @var string Leave the width the theme gives the page. */
    public const WIDTH_THEME = '0';

    /** @var string As wide as the window allows. */
    public const WIDTH_FULL = 'full';

    /**
     * Puts the brief in the activity header, with the activity description.
     *
     * This hook is the only window into the header: it fires at the start of
     * core_renderer::header(), after mod_assign has set the description and
     * before the theme layout exports it.
     *
     * @param  before_http_headers $hook The hook, unused: the brief goes into the
     *                                   activity header, not into the response.
     * @return void
     * @throws dml_exception If the configuration cannot be read.
     */
    public static function add_enunciate_to_activity_header(before_http_headers $hook): void {
        global $PAGE;

        if (self::placement() !== self::PLACE_HEADER) {
            return;
        }

        $html = self::enunciate_html();
        if ($html === null) {
            return;
        }

        $header = $PAGE->activityheader;
        $description = $header->export_for_template($PAGE->get_renderer('core'))['description'] ?? null;
        if ($description === null) {
            // The theme paints no activity header on this page.
            return;
        }

        $header->set_description($description . $html);
    }

    /**
     * Puts the brief at the end of the main region, or wherever the site says.
     *
     * @param  after_standard_main_region_html_generation $hook The hook.
     * @return void
     * @throws dml_exception If the configuration cannot be read.
     */
    public static function add_enunciate_to_main_region(
        after_standard_main_region_html_generation $hook
    ): void {
        global $PAGE;

        $placement = self::placement();
        if ($placement === self::PLACE_HEADER) {
            return;
        }

        $html = self::enunciate_html();
        if ($html === null) {
            return;
        }

        // The custom destination is resolved in the browser: it is the only one that
        // can know what the theme has painted.
        if ($placement === self::PLACE_SELECTOR) {
            $selector = trim((string) get_config('assignsubmission_tipnc', 'placementselector'));
            if ($selector !== '') {
                $PAGE->requires->js_call_amd(
                    'assignsubmission_tipnc/placement',
                    'init',
                    [$selector]
                );
            }
        }

        $hook->add_html($html);
    }

    /**
     * Widens the assignment page, so the document has room to be read.
     *
     * The width is a site value, so the rule is written here: styles.css cannot
     * take a parameter. It is scoped to this page and to assignments that use
     * this plugin, and it only exists when the site asks for it.
     *
     * @param  before_standard_top_of_body_html_generation $hook The hook.
     * @return void
     * @throws dml_exception If the configuration cannot be read.
     */
    public static function widen_assign_page(
        before_standard_top_of_body_html_generation $hook
    ): void {
        global $PAGE;

        $width = self::page_width();

        // Every page of the assignment, not just the first: the document is also
        // edited and marked, and the theme's reading width squeezes it just the same.
        if ($width === null || !str_starts_with($PAGE->pagetype, 'mod-assign-')) {
            return;
        }

        $cm = $PAGE->cm;
        if (!$cm instanceof cm_info || !assign::is_submission_nextcloud($cm)) {
            return;
        }

        $hook->add_html(html_writer::tag(
            'style',
            '@media (min-width: 768px) {'
            . 'body[id^="page-mod-assign-"].pagelayout-standard #page.drawers .main-inner,'
            . 'body[id^="page-mod-assign-"].limitedwidth #page.drawers .main-inner'
            . '{max-width:' . $width . '}'
            . '}'
        ));
    }

    /**
     * Warns, before deleting a submission, that a document goes with it.
     *
     * The core asks «are you sure?» about the submission; what it cannot know is
     * that this site also deletes the document in NextCloud. Better said here
     * than discovered afterwards.
     *
     * @param  before_standard_top_of_body_html_generation $hook The hook.
     * @return void
     * @throws dml_exception If the configuration cannot be read.
     * @throws coding_exception If a language string is missing.
     */
    public static function warn_before_removing_submission(
        before_standard_top_of_body_html_generation $hook
    ): void {
        global $OUTPUT, $PAGE;

        if ($PAGE->pagetype !== 'mod-assign-removesubmissionconfirm') {
            return;
        }

        $cm = $PAGE->cm;
        if (!$cm instanceof cm_info || !assign::is_submission_nextcloud($cm)) {
            return;
        }

        $policy = cleanup::submission_policy();
        if ($policy === cleanup::KEEP) {
            return;
        }

        $message = $policy === cleanup::DROP_ALL
            ? get_string('delete_warning_all', 'assignsubmission_tipnc')
            : get_string('delete_warning_frozen', 'assignsubmission_tipnc');

        $hook->add_html($OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING));
    }

    /**
     * Asks the student to confirm the document is saved before submitting.
     *
     * The office editor writes to NextCloud in its own time, so submitting right
     * after typing freezes the previous version. Nothing on this side can detect
     * that, so what this does is stop the submission being one click away: it is
     * a confirmation, not a check, and it goes away when the plugin embeds the
     * editor itself and can force the save.
     *
     * @param  before_standard_top_of_body_html_generation $hook The hook.
     * @return void
     * @throws dml_exception If the configuration cannot be read.
     * @throws coding_exception If a language string is missing.
     */
    public static function confirm_document_saved(
        before_standard_top_of_body_html_generation $hook
    ): void {
        global $DB, $OUTPUT, $PAGE;

        if (!in_array($PAGE->pagetype, ['mod-assign-submit', 'mod-assign-editsubmission'], true)) {
            return;
        }

        if (!get_config('assignsubmission_tipnc', 'confirmsaved')) {
            return;
        }

        $cm = $PAGE->cm;
        if (!$cm instanceof cm_info || !assign::is_submission_nextcloud($cm)) {
            return;
        }

        // With no drafts, the form's "Save changes" submits straight away: it is the
        // last moment at which somebody can say that their document is saved, so
        // the confirmation has to be there and not on a confirmation screen that
        // in that configuration never comes to exist.
        $drafts = (int) $DB->get_field('assign', 'submissiondrafts', ['id' => $cm->instance], IGNORE_MISSING);
        if ($PAGE->pagetype === 'mod-assign-editsubmission' && $drafts) {
            return;
        }

        // How the document is flushed depends on who is in charge of the editor: with
        // the embedded editor the plugin forces the save and there is a button; with
        // the NextCloud page it depends on its settings, and we do not know them.
        $hook->add_html($OUTPUT->render_from_template(
            'assignsubmission_tipnc/confirm_save',
            [
                'alsosubmits' => $PAGE->pagetype === 'mod-assign-editsubmission',
                'cansave' => viewmode::current() === viewmode::EDITOR,
            ]
        ));
        $PAGE->requires->js_call_amd('assignsubmission_tipnc/confirmsave', 'init');
    }

    /**
     * Maximum width the site wants for the assignment page.
     *
     * @return string|null A CSS length, or null to leave the theme alone.
     * @throws dml_exception If the configuration cannot be read.
     */
    private static function page_width(): ?string {
        $width = (string) get_config('assignsubmission_tipnc', 'pagewidth');

        if ($width === self::WIDTH_FULL) {
            return 'none';
        }

        return (int) $width > 0 ? (int) $width . 'px' : null;
    }

    /**
     * Where the site wants the brief.
     *
     * @return string One of the PLACE_* constants.
     * @throws dml_exception If the configuration cannot be read.
     */
    private static function placement(): string {
        $placement = (string) get_config('assignsubmission_tipnc', 'placement');

        return in_array($placement, [self::PLACE_HEADER, self::PLACE_MAIN, self::PLACE_SELECTOR], true)
            ? $placement
            : self::PLACE_HEADER;
    }

    /**
     * The brief of the assignment being viewed, for anyone who reaches the page.
     *
     * @return string|null The html, or null when this page carries no brief.
     * @throws dml_exception If the configuration cannot be read.
     */
    private static function enunciate_html(): ?string {
        global $PAGE, $USER;

        if ($PAGE->pagetype !== 'mod-assign-view') {
            return null;
        }

        // The module is read into a variable: moodle_page has __get but not
        // __isset, so empty($PAGE->cm) is true even when the cm is set.
        $cm = $PAGE->cm;
        if (!$cm instanceof cm_info || !assign::is_submission_nextcloud($cm)) {
            return null;
        }

        $instance = (int) $cm->instance;
        $output = $PAGE->get_renderer('assignsubmission_tipnc');

        // First of all: building the editor against a service that does not answer
        // leaves the editor to take the blame, with a "download error" that does not
        // say who to tell, nor whether the work is still there.
        $state = health::state();
        if ($state !== null) {
            $candiagnose = has_capability('assignsubmission/tipnc:view_errors', $cm->context);

            return $output->render(new unavailable(
                (int) ($state->since ?? 0),
                $candiagnose,
                $candiagnose ? new moodle_url('/mod/assign/submission/tipnc/view_errors.php') : null
            ));
        }

        $model = new documents();

        // Entering the assignment is the moment when it is known who teaches it and
        // who takes it, so that is when access to the brief is handed out: write
        // for whoever marks, read for whoever does not. It costs one call the first
        // time for each person and none afterwards.
        $cangrade = has_capability('mod/assign:grade', $cm->context);

        $preparing = false;

        if ($model->get_enunciate($instance)) {
            (new nextcloud($instance))->grant_enunciate($USER, $cangrade);
        } else if (tipnc_enun::remembered_path($instance) !== '') {
            // A restored assignment waiting for the copy of its brief. Only whoever
            // marks asks for it: the copy is made with their permissions.
            if ($cangrade) {
                create_enunciate::queue($instance, (int) $USER->id);
            }
            $preparing = true;
        }

        $own = $model->own_work($instance, (int) $USER->id);

        // Whoever already has a draft or a submission works on their own document:
        // that is what is shown, and the brief stays above as a linked reference.
        $embed = viewmode::embeds();

        // Without a brief the assignment does not work, and until now the only way
        // out was going into NextCloud. Whoever marks can ask for it from here.
        $prepare = $cangrade
            ? new moodle_url(
                '/mod/assign/submission/tipnc/prepare.php',
                ['id' => $cm->id, 'sesskey' => sesskey()]
            )
            : null;

        $html = $output->render(enunciate_block::for_assignment(
            $instance,
            $own === null && $embed,
            $preparing ? null : $prepare,
            $cangrade,
            $preparing
        ));

        if ($own !== null && $own->path !== '') {
            $path = $own->path;
            $frozen = $own->mode === document::MODE_SUBMISSION;

            // A draft is written only while mod_assign would let the form be used:
            // locked, past the cut-off or already submitted means read only.
            $canwrite = !$frozen && $model->can_write_draft($instance, (int) $USER->id);

            // Their own shares lapse like any other, so they are renewed here.
            (new nextcloud($instance))->grant_own($USER, $own->submission, $canwrite);

            $readonly = '';
            if ($frozen) {
                // Frozen, not "not yours": handing in is what closed it.
                $readonly = document_viewer::READONLY_FROZEN;
            } else if (!$canwrite) {
                $readonly = document_viewer::READONLY_CLOSED;
            }

            $viewer = $embed ? document_viewer::for_document(
                $instance,
                $path,
                $own->ncid,
                basename($path),
                $USER,
                $canwrite,
                $model->frame_height_class(),
                $readonly
            ) : null;

            $html .= $output->render(new submission_summary(
                $model->view_url($own->ncid),
                $own->mode,
                $embed,
                $own->timemodified,
                basename($path),
                true,
                $model->frame_height_class(),
                $viewer
            ));
        }

        return $html;
    }
}
