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
 * This file contains the definition for the library class for file submission plugin
 *
 * This class provides all the functionality for the new assign module.
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_tipnc\api\document;
use assignsubmission_tipnc\api\nextcloud;
use assignsubmission_tipnc\api\error as error_log;
use assignsubmission_tipnc\editor\viewmode;
use assignsubmission_tipnc\log\code;
use assignsubmission_tipnc\models\cleanup;
use assignsubmission_tipnc\models\documents;
use assignsubmission_tipnc\models\health;
use assignsubmission_tipnc\output\document_viewer;
use assignsubmission_tipnc\output\submission_summary;
use assignsubmission_tipnc\output\unavailable;
use assignsubmission_tipnc\task\create_enunciate;
use assignsubmission_tipnc\tipnc;
use assignsubmission_tipnc\tipnc_enun;
use assignsubmission_tipnc\tipnc_error;
use assignsubmission_tipnc\tipnc_open;

/**
 * Library class for file submission plugin extending submission plugin base class
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_tipnc extends assign_submission_plugin {
    /**
     * Get the name of the file submission plugin
     * @return string
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string('pluginname', 'assignsubmission_tipnc');
    }

    /**
     * Get the default setting for file submission plugin
     *
     * @param MoodleQuickForm $mform
     */
    public function get_settings(MoodleQuickForm $mform) {
    }

    /**
     * Makes sure the assignment has a brief, whenever this plugin is turned on.
     *
     * The core only calls this for plugins that are enabled, so it is the exact
     * moment the assignment starts needing a document —whether it is being
     * created or the plugin is being switched on years later, which used to leave
     * the assignment permanently broken.
     *
     * @param  stdClass $data Settings of the assignment form.
     * @return bool Always true: the brief is prepared out of band.
     * @throws dml_exception If the tables cannot be read.
     */
    public function save_settings(stdClass $data): bool {
        global $USER;

        $assignment = (int) $this->assignment->get_instance()->id;

        if ($assignment !== 0 && !tipnc_enun::get($assignment)) {
            create_enunciate::queue($assignment, (int) $USER->id);
        }

        return true;
    }

    /**
     * Add elements to submission form [ OPEN ]
     *
     * @param mixed $submission stdClass|null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return bool
     * @throws coding_exception|dml_exception
     * @throws moodle_exception
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data): bool {
        global $PAGE, $USER;

        // With no service there is no document to open, and opening the form would
        // create one: better to say so than to leave a gap where the work should be.
        $notice = $this->unavailable_notice($submission);
        if ($notice !== '') {
            $mform->addElement('html', $notice);

            return true;
        }

        $tipncenun = tipnc_enun::get($submission->assignment);
        if (!empty($tipncenun->ncid)) {
            $tipncopen = tipnc_open::get($submission->id);
            if ($tipncopen) {
                if (empty($tipncopen->ncid)) {
                    tipnc_error::log(
                        'get_form_elements',
                        new error_log('1000', 'The NextCloud ID could not be retrieved'),
                        $submission->assignment,
                        $submission->id
                    );
                    return false;
                } else {
                    $ncid = $tipncopen->ncid;
                }
            } else {
                $nextcloud = new nextcloud($submission->assignment);
                $response = $nextcloud->student_open($submission);
                if ($response->success) {
                    $ncid = $response->data;
                } else {
                    return false;
                }
            }
            $urlopen = document::get_url($ncid);
            $output = $PAGE->get_renderer('assignsubmission_tipnc');
            $model = new documents();

            // This is where the student writes: the same viewer as on the
            // assignment page, so they do not look like two different documents.
            $openpath = (new document($submission->assignment))->open_path($submission);

            // This is where the student writes: the editor opens in edit mode.
            $viewer = document_viewer::for_document(
                (int) $submission->assignment,
                $openpath,
                (int) $ncid,
                basename($openpath),
                $USER,
                true,
                $model->frame_height_class()
            );

            $render = $output->render(new submission_summary(
                $urlopen,
                document::MODE_OPEN,
                true,
                (int) ($submission->timemodified ?? 0),
                basename($openpath),
                true,
                $model->frame_height_class(),
                $viewer
            ));
            // As html and not as a static element: a form element reserves the
            // label column, and there is no label to put here.
            $mform->addElement('html', $render);
            return true;
        } else {
            if (isset($data->files_filemanager)) {
                return true;
            } else {
                tipnc_error::log(
                    'get_form_elements',
                    new error_log('1001', 'The Enunciate does not exist'),
                    $submission->assignment,
                    $submission->id
                );
                return false;
            }
        }
    }

    /**
     * Save the files and trigger plagiarism plugin, if enabled,
     * to scan the uploaded files via events trigger
     *
     * @param stdClass $submission
     * @param stdClass $data
     * @return bool
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function save(stdClass $submission, stdClass $data): bool {
        $tipncenun = tipnc_enun::get($submission->assignment);
        if (!$tipncenun) {
            if (isset($data->files_filemanager)) {
                return true;
            } else {
                tipnc_error::log(
                    'save',
                    new error_log('1100', 'The Enunciate does not exist'),
                    $submission->assignment,
                    $submission->id
                );
                return false;
            }
        }
        $tipncopen = tipnc_open::get($submission->id);
        if (!$tipncopen) {
            if (isset($data->files_filemanager)) {
                return true;
            } else {
                tipnc_error::log(
                    'save',
                    new error_log('1101', 'The Open Submission does not exist'),
                    $submission->assignment,
                    $submission->id
                );
                return false;
            }
        }
        // Saving means saving the draft. Freezing the submission is a different
        // thing and happens at another point —submit_for_grading()— even though
        // with no drafts the core chains them and they look like the same click.
        if ($submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return $this->freeze($submission);
        }

        // What is in the open editor counts as saved from this moment.
        (new nextcloud($submission->assignment))->save_draft($submission);

        return true;
    }

    /**
     * Freezes the submission when the work is handed in for marking.
     *
     * With «Require students to click the submit button» —the usual setting— the
     * core never passes SUBMITTED to save(): it calls this instead. Without it,
     * students clicked submit, Moodle marked the work as handed in, and in
     * NextCloud nothing at all happened.
     *
     * @param  stdClass $submission The submission being handed in.
     * @return bool True when the copy was frozen.
     * @throws dml_exception If the incident cannot be recorded.
     * @throws moodle_exception If the assignment cannot be read.
     */
    public function submit_for_grading($submission): bool {
        return $this->freeze($submission);
    }

    /**
     * Copies the draft into the submission that gets marked.
     *
     * @param  stdClass $submission The submission being handed in.
     * @return bool True when the copy was frozen.
     * @throws dml_exception If the incident cannot be recorded.
     * @throws moodle_exception If the assignment cannot be read.
     */
    private function freeze(stdClass $submission): bool {
        $enunciate = tipnc_enun::get($submission->assignment);

        if (!$enunciate) {
            tipnc_error::log(
                'freeze',
                new error_log('1100', 'The Enunciate does not exist'),
                $submission->assignment,
                $submission->id
            );

            return false;
        }

        if (!tipnc_open::get($submission->id)) {
            tipnc_error::log(
                'freeze',
                new error_log('1101', 'The Open Submission does not exist'),
                $submission->assignment,
                $submission->id
            );

            return false;
        }

        $response = (new nextcloud($submission->assignment))->student_submit($submission);

        if (!$response->success) {
            tipnc_error::log('freeze', $response->error, $submission->assignment, $submission->id);

            return false;
        }

        // It may have gone well and still carry a warning: the copy is made, but
        // somebody could not be given access. It is recorded and the submission
        // is still valid, because what gets marked already exists.
        //
        // A clean response carries a filler error with code "0", which is not the
        // catalogue's "operation successful": both have to be discarded.
        if (!in_array((string) $response->error->code, ['', '0', code::OK], true)) {
            tipnc_error::log(
                'freeze:share',
                $response->error,
                $submission->assignment,
                $submission->id
            );
        }

        return true;
    }

    /**
     * Remove files from this submission.
     *
     * @param stdClass $submission
     * @return bool
     * @throws moodle_exception
     */
    public function remove(stdClass $submission): bool {
        $submissionid = $submission ? $submission->id : 0;
        if ($submissionid) {
            try {
                // NextCloud first: the rows deleted next are the ones that say
                // which documents belonged to this submission.
                (new cleanup())->on_submission_removed((int) $submission->assignment, $submission);
                tipnc::delete_by_submissionid($submission->id, $submission->assignment);
                tipnc_open::delete_by_submissionid($submission->id, $submission->assignment);
            } catch (moodle_exception $e) {
                tipnc_error::log(
                    'remove',
                    new error_log('1200', $e->getMessage()),
                    $submission->assignment,
                    $submission->id
                );
                return false;
            }
        }
        return true;
    }

    /**
     * Produce a list of files suitable for export that represent this feedback or submission
     *
     * @param stdClass $submission The submission
     * @param stdClass $user The user record - unused
     * @return array - return an array of files indexed by filename
     */
    public function get_files(stdClass $submission, stdClass $user): array {
        return [];
    }

    /**
     * Display the list of files in the submission status table
     *
     * @param stdClass $submission
     * @param bool $showviewlink Set this to true if the list of files is long
     * @return string
     * @throws dml_exception
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function view_summary(stdClass $submission, &$showviewlink): string {
        global $PAGE, $USER;

        // First of all, because if the service does not answer nothing below is
        // going to be able to tell the truth about the document.
        $notice = $this->unavailable_notice($submission);
        if ($notice !== '') {
            return $notice;
        }

        $tipncenun = tipnc_enun::get($submission->assignment);
        if (!$tipncenun) {
            tipnc_error::log(
                'view_summary',
                new error_log('1300', 'The Enunciate does not exist. ' .
                'Check if the task also has the delivery as a file activated'),
                $submission->assignment,
                $submission->id
            );
            return '';
        }
        $isteacher = \assignsubmission_tipnc\assign::is_teacher($submission->assignment);

        // The grading table builds the submission record by hand and gives it no
        // status (gradingtable.php), so here it is resolved against the real one.
        $status = $submission->status
            ?? \assignsubmission_tipnc\assign::get_submission($submission->id)->status
            ?? null;

        if ($isteacher) {
            if (!is_null($status)) {
                switch ($status) {
                    case ASSIGN_SUBMISSION_STATUS_DRAFT:
                    case ASSIGN_SUBMISSION_STATUS_REOPENED:
                    case ASSIGN_SUBMISSION_STATUS_NEW:
                        // What matters to whoever marks is what this person is
                        // working on, not seeing the brief again: if they
                        // already have a draft, that is what they are writing.
                        $tipncopen = tipnc_open::get($submission->id);
                        if (!empty($tipncopen->ncid)) {
                            $mode = document::MODE_OPEN;
                            $ncid = $tipncopen->ncid;
                            break;
                        }

                        // With no draft they have not started. That is said, rather
                        // than showing the brief, which looks like a blank handed in.
                        return $PAGE->get_renderer('assignsubmission_tipnc')->render(
                            new unavailable(0, false, null, unavailable::NOT_STARTED)
                        );
                    case ASSIGN_SUBMISSION_STATUS_SUBMITTED:
                        $mode = document::MODE_SUBMISSION;
                        $tipncsub = tipnc::get($submission->id);
                        $ncid = $tipncsub->ncid;
                        break;
                    default:
                        tipnc_error::log(
                            'view_summary',
                            new error_log('1301', 'Assign status unknown'),
                            $submission->assignment,
                            $submission->id
                        );
                        return '';
                }
            }
        } else {
            if (!is_null($status)) {
                switch ($status) {
                    case ASSIGN_SUBMISSION_STATUS_DRAFT:
                    case ASSIGN_SUBMISSION_STATUS_REOPENED:
                        // Painting a status box must not create a document:
                        // copying, sharing and locating it are three requests
                        // with a thirty second timeout each, and this is painted
                        // on every visit. The draft is born when the form is
                        // opened, which is when somebody has asked for it.
                        $tipncopen = tipnc_open::get($submission->id);

                        if (empty($tipncopen->ncid)) {
                            $mode = document::MODE_ENUN;
                            $ncid = $tipncenun->ncid;
                            break;
                        }

                        $mode = document::MODE_OPEN;
                        $ncid = $tipncopen->ncid;
                        break;
                    case ASSIGN_SUBMISSION_STATUS_NEW:
                        // If there is already a draft, that is what they are
                        // working on, whatever the status says: the document is
                        // created when the form opens and the status only
                        // changes when they save.
                        $tipncopen = tipnc_open::get($submission->id);
                        if (!empty($tipncopen->ncid)) {
                            $mode = document::MODE_OPEN;
                            $ncid = $tipncopen->ncid;
                            break;
                        }

                        // Access to the brief is handed out when entering the
                        // assignment and remembered; asking for it here meant one
                        // call per row of the grading table.
                        $mode = document::MODE_ENUN;
                        $ncid = $tipncenun->ncid;
                        break;
                    case ASSIGN_SUBMISSION_STATUS_SUBMITTED:
                        $mode = document::MODE_SUBMISSION;
                        $tipncsub = tipnc::get($submission->id);
                        $ncid = $tipncsub->ncid;
                        break;
                    default:
                        tipnc_error::log(
                            'view_summary',
                            new error_log('1302', 'Assign status unknown'),
                            $submission->assignment,
                            $submission->id
                        );
                        return '';
                }
            }
        }

        // Moodle says there is a submission and NextCloud has no document. This
        // happens when the freeze half failed, and leaving it blank made it look
        // like "not handed in yet", which is the opposite of what happened.
        if (empty($ncid)) {
            tipnc_error::log(
                'view_summary',
                new error_log('1303', 'The NextCloud ID could not be retrieved'),
                $submission->assignment,
                $submission->id
            );

            $candiagnose = has_capability(
                'assignsubmission/tipnc:view_errors',
                $this->assignment->get_context()
            );

            return $PAGE->get_renderer('assignsubmission_tipnc')->render(new unavailable(
                0,
                $candiagnose,
                $candiagnose ? new moodle_url('/mod/assign/submission/tipnc/view_errors.php') : null,
                unavailable::NO_DOCUMENT
            ));
        }

        $url = document::get_url($ncid);
        $output = $PAGE->get_renderer('assignsubmission_tipnc');
        $own = (int) ($submission->userid ?? 0) === (int) $USER->id;

        // The document name is only shown when it comes for free: in the grading
        // table it would have to be looked up once per row.
        $filename = null;
        if ($own) {
            $document = new document($submission->assignment);
            $filename = basename(match ($mode) {
                document::MODE_OPEN => $document->open_path($submission),
                document::MODE_SUBMISSION => $document->submission_path($submission),
                default => $document->get_enunciate(),
            });
        }

        // When marking, the document opens where the grade is being given: going
        // out to NextCloud to read it splits the marking across two screens.
        [$viewer, $marking] = $this->marking_viewer($submission, $mode, (int) $ncid);

        return $output->render(new submission_summary(
            $url,
            $mode,
            $viewer !== null,
            (int) ($submission->timemodified ?? 0),
            $marking ?? $filename,
            $own,
            (new documents())->frame_height_class(),
            $viewer
        ));
    }

    /**
     * The submitted document, opened where it is being marked.
     *
     * Only in the grading panel. The grading table calls view_summary() once per
     * row, and one editor per row would be a page full of editors.
     *
     * @param  stdClass $submission The submission being looked at.
     * @param  string   $mode       Which of the documents it is.
     * @param  int      $ncid       Identifier of the document in NextCloud.
     * @return array The viewer and the file name, both null when nothing is opened.
     * @throws dml_exception If the configuration cannot be read.
     * @throws moodle_exception If the editor is chosen and has no secret.
     */
    private function marking_viewer(stdClass $submission, string $mode, int $ncid): array {
        global $PAGE, $USER;

        // The core sets the page type from the action, and the grading panel is
        // painted as a fragment with its own: that is what tells it from the table.
        if ($PAGE->pagetype !== 'mod-assign-gradingpanel' || $mode !== document::MODE_SUBMISSION) {
            return [null, null];
        }

        // With no service there is no document to open: building the editor only
        // gets it to throw an error of its own that explains nothing.
        if (health::is_down()) {
            return [null, null];
        }

        // With blind marking the file name carries the student's name inside, so
        // it is neither opened nor shared here: NextCloud would reveal who it is.
        if ($this->assignment->is_blind_marking()) {
            return [null, null];
        }

        $student = core_user::get_user((int) $submission->userid);
        if (!$student) {
            return [null, null];
        }

        // Reaching this screen is the check in itself: the core requires
        // mod/assign:grade to paint it. Here we only apply what Moodle decided.
        // It goes before looking at how the document is shown, because whoever
        // opens it in another tab needs access just as much as whoever embeds it.
        (new nextcloud((int) $submission->assignment))->grant_submission($USER, $submission);

        if (!viewmode::embeds()) {
            return [null, null];
        }

        $path = (new document((int) $submission->assignment))->submission_path($submission);

        // Teachers have write permission on the submission from the moment it is
        // frozen: marking on the document is what that permission exists for.
        return [
            document_viewer::for_document(
                (int) $submission->assignment,
                $path,
                $ncid,
                basename($path),
                $USER,
                true,
                (new documents())->frame_height_class()
            ),
            basename($path),
        ];
    }

    /**
     * The full view of a submission, which this plugin does not have.
     *
     * Everything there is to show —the document, its state and where to open it—
     * already fits in the summary, so nothing ever sets $showviewlink and the core
     * never reaches this. It stays because it is part of the contract.
     *
     * @param  stdClass $submission The submission, unused.
     * @return string Always empty.
     */
    public function view(stdClass $submission): string {
        return '';
    }

    /**
     * Return true if this plugin can upgrade an old Moodle 2.2 assignment of this type
     * and version.
     *
     * @param string $type
     * @param int $version
     * @return bool True if upgrade is possible
     */
    public function can_upgrade($type, $version): bool {
        return false;
    }

    /**
     * Upgrade the settings from the old assignment
     * to the new plugin based one
     *
     * @param context $oldcontext - the old assignment context
     * @param stdClass $oldassignment - the old assignment data record
     * @param string $log record log events here
     * @return bool Was it a success? (false will trigger rollback)
     */
    public function upgrade_settings(context $oldcontext, stdClass $oldassignment, &$log): bool {
        return true;
    }

    /**
     * Upgrade the submission from the old assignment to the new one
     *
     * @param context $oldcontext The context of the old assignment
     * @param stdClass $oldassignment The data record for the old oldassignment
     * @param stdClass $oldsubmission The data record for the old submission
     * @param stdClass $submission The data record for the new submission
     * @param string $log Record upgrade messages in the log
     * @return bool true or false - false will trigger a rollback
     */
    public function upgrade(
        context $oldcontext,
        stdClass $oldassignment,
        stdClass $oldsubmission,
        stdClass $submission,
        &$log
    ): bool {
        return true;
    }

    /**
     * Removes the submissions, on deletion and on course reset alike.
     *
     * The brief stays: a reset keeps the assignment. The observer removes it once
     * the assignment is really gone.
     *
     * @return bool
     * @throws moodle_exception
     */
    public function delete_instance(): bool {
        $instance = (int) $this->assignment->get_instance()->id;

        try {
            (new cleanup())->on_submissions_deleted($instance);
            tipnc::delete($instance);
            tipnc_open::delete($instance);
            return true;
        } catch (moodle_exception $e) {
            tipnc_error::log(
                'delete_instance',
                new error_log('1400', $e->getMessage()),
                $this->assignment->get_instance()->id
            );
            return false;
        }
    }


    /**
     * Stops the submission while the documents cannot be reached.
     *
     * The core shows what this returns on the confirmation screen and refuses to
     * go on. Letting it through would freeze a copy of a document nobody could
     * read: the student would be told they had handed in work that is not there.
     *
     * @param  stdClass $submission The submission about to be handed in.
     * @return bool|string True to allow it, or why it cannot be done now.
     * @throws coding_exception If a language string is missing.
     */
    public function precheck_submission($submission) {
        if (health::is_down()) {
            return get_string('unavailable_submit', 'assignsubmission_tipnc');
        }

        return true;
    }

    /**
     * What is said instead of a document that cannot be reached.
     *
     * @param  stdClass|null $submission The submission being looked at, if any.
     * @return string The notice, empty when everything is answering.
     * @throws coding_exception If a language string is missing.
     * @throws moodle_exception If the renderer cannot be built.
     */
    private function unavailable_notice(?stdClass $submission = null): string {
        global $PAGE;

        // Somebody who does not exist in NextCloud cannot be given their document
        // nor write in it: that is their own, permanent impediment, not a passing
        // outage, and until an account is created for them there is nothing to do.
        if ($submission !== null && $this->owner_missing($submission)) {
            return $PAGE->get_renderer('assignsubmission_tipnc')->render(
                new unavailable(0, false, null, unavailable::NO_ACCOUNT)
            );
        }

        $state = health::state();

        if ($state === null) {
            return '';
        }

        $candiagnose = has_capability('assignsubmission/tipnc:view_errors', $this->assignment->get_context());

        return $PAGE->get_renderer('assignsubmission_tipnc')->render(new unavailable(
            (int) ($state->since ?? 0),
            $candiagnose,
            $candiagnose ? new moodle_url('/mod/assign/submission/tipnc/view_errors.php') : null
        ));
    }

    /**
     * Whether the person a submission belongs to has no account in NextCloud.
     *
     * It is answered from what already happened —the incident that says their
     * share was rejected— and not by asking NextCloud on every page: the answer
     * is the same until somebody creates the account.
     *
     * @param  stdClass $submission The submission.
     * @return bool True when their share was rejected for not existing.
     * @throws dml_exception If the log cannot be read.
     */
    private function owner_missing(stdClass $submission): bool {
        global $DB;

        $userid = (int) ($submission->userid ?? 0);

        if ($userid === 0 || empty($submission->id)) {
            return false;
        }

        // Who it affected, not which submission it happened in: the rejection may
        // have been somebody else's —a teacher, say— and then it says nothing
        // about whoever handed in.
        return $DB->record_exists('assignsubmission_tipnc_log', [
            'errorcode' => code::SHARE_NO_ACCOUNT,
            'submission' => $submission->id,
            'affecteduserid' => $userid,
        ]);
    }

    /**
     * Closes the document when the submission is locked.
     *
     * @param  stdClass $submission The submission being locked.
     * @param  stdClass $flags      Flags of the submission, unused.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function lock($submission, stdClass $flags): void {
        if (!empty($submission->id)) {
            (new nextcloud($submission->assignment))->set_draft_access($submission, false);
        }
    }

    /**
     * Opens the document again when the submission is unlocked.
     *
     * @param  stdClass $submission The submission being unlocked.
     * @param  stdClass $flags      Flags of the submission, unused.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function unlock($submission, stdClass $flags): void {
        if (!empty($submission->id)) {
            (new nextcloud($submission->assignment))->set_draft_access($submission, true);
        }
    }

    /**
     * Gives the document back when the submission returns to draft.
     *
     * Sending it back to draft is telling somebody to carry on working: if the
     * document stays read only, that invitation is empty.
     *
     * @param  stdClass $submission The submission going back to draft.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function revert_to_draft(stdClass $submission): void {
        if (!empty($submission->id)) {
            (new nextcloud($submission->assignment))->set_draft_access($submission, true);
        }
    }

    /**
     * Whether there is nothing in this submission.
     *
     * What this plugin contributes is a document, so it is empty exactly when no
     * document has been registered for that submission —neither draft nor frozen
     * copy—. Answering «never empty», as it used to, made the core believe every
     * student had handed something in, including those who never opened it.
     *
     * @param  stdClass $submission The submission to look at.
     * @return bool True when there is no document.
     * @throws dml_exception If the tables cannot be read.
     */
    public function is_empty(stdClass $submission): bool {
        $id = (int) ($submission->id ?? 0);

        if ($id === 0) {
            return true;
        }

        return !tipnc::get($id) && !tipnc_open::get($id);
    }

    /**
     * Whether what is about to be saved is empty.
     *
     * The core refuses the submission when every plugin says yes, so this only
     * says yes when it is sure: no submission on record means nobody ever opened
     * the document, and there is nothing to hand in.
     *
     * @param  stdClass $data The submission data of the form.
     * @return bool True when there is nothing to submit.
     * @throws dml_exception If the tables cannot be read.
     */
    public function submission_is_empty(stdClass $data): bool {
        global $USER;

        $userid = (int) ($data->userid ?? 0) ?: (int) $USER->id;
        $submission = $this->assignment->get_user_submission($userid, false);

        return !$submission || $this->is_empty($submission);
    }

    /**
     * Starts a reopened attempt from the work of the previous one.
     *
     * The core offers «base the new attempt on the last submission» and this is
     * what makes that true. Answering yes without copying anything, as it used
     * to, sent the student back to a blank brief.
     *
     * @param  stdClass $sourcesubmission The attempt being carried over.
     * @param  stdClass $destsubmission   The attempt starting now.
     * @return bool True when the document is ready.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function copy_submission(stdClass $sourcesubmission, stdClass $destsubmission): bool {
        $response = (new nextcloud((int) $destsubmission->assignment))
            ->reattempt_from($sourcesubmission, $destsubmission);

        if (!$response->success) {
            tipnc_error::log(
                'copy_submission',
                $response->error,
                $destsubmission->assignment,
                $destsubmission->id
            );

            return false;
        }

        return true;
    }

    /**
     * Determine if the plugin allows image file conversion
     * @return bool
     */
    public function allow_image_conversion(): bool {
        return true;
    }
}
