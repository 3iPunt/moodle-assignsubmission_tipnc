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
 * Class nextcloud
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\api;

use assignsubmission_tipnc\editor\command;
use assignsubmission_tipnc\editor\viewmode;
use assignsubmission_tipnc\log\code;
use assignsubmission_tipnc\log\logger;
use assignsubmission_tipnc\models\grants;
use assignsubmission_tipnc\models\sessions;
use assignsubmission_tipnc\tipnc;
use assignsubmission_tipnc\tipnc_enun;
use assignsubmission_tipnc\tipnc_open;
use coding_exception;
use core_user;
use dml_exception;
use SimpleXMLElement;
use stdClass;

/**
 * Class nextcloud
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nextcloud {
    /** @var int Seconds a call may take before giving up. */
    public const TIMEOUT = 30;

    /** @var int Share type NextCloud uses for a single account. */
    public const SHARE_TYPE_USER = 0;

    /** @var int Read the document. */
    public const PERMISSION_READ = 1;

    /** @var int Read, write and pass on: everything a FILE can be given.
     *
     * Not 31: create (4) and delete (8) only make sense on folders, and
     * NextCloud trims the request without saying so. Asking for what suits the
     * resource is what makes it possible to check afterwards what was granted.
     */
    public const PERMISSION_FILE_ALL = 19;

    /** @var int Counters tried before falling back to a timestamp in the name. */
    protected const FREE_NAME_TRIES = 20;

    /** @var int Instance */
    protected $instance;

    /** @var string Host */
    protected $host;

    /** @var string URL */
    protected $url;

    /** @var string User */
    protected $user;

    /** @var string Password */
    protected $password;

    /** @var document Document */
    protected $document;

    /** @var client Transport: every call goes through it and gets recorded. */
    protected client $client;

    /**
     * constructor.
     *
     * @param int $instance
     * @throws dml_exception
     */
    public function __construct(int $instance) {
        $this->host = get_config('assignsubmission_tipnc', 'host');
        $this->url = get_config('assignsubmission_tipnc', 'url');
        $this->user = get_config('assignsubmission_tipnc', 'user');
        $this->password = get_config('assignsubmission_tipnc', 'password');
        $this->instance = $instance;
        $this->document = new document($instance);
        $this->client = new client();
    }

    /**
     * WebDAV path of a document of the service account.
     *
     * @param  string $path Path inside the account.
     * @return string The path to call.
     */
    protected function dav_path(string $path): string {
        // Each segment is encoded: course and assignment names may hold # or %.
        $segments = array_map('rawurlencode', explode('/', $path));

        return '/remote.php/dav/files/' . rawurlencode((string) $this->user) . '/' . implode('/', $segments);
    }

    /**
     * Context shared by every call, so the incident can be placed in the site.
     *
     * @param  string $operation Operation in domain language.
     * @param  string $method    Point in the code.
     * @param  array  $extra     Extra context fields.
     * @return array The context for the incident log.
     */
    protected function context(string $operation, string $method, array $extra = []): array {
        return array_merge([
            'operation' => $operation,
            'method' => $method,
            'assignment' => $this->instance,
        ], $extra);
    }

    /**
     * Creates the brief of the assignment.
     *
     * @param  string|null $from Brief of another assignment to copy, or null for the template.
     * @return response The identifier of the new brief, or the failure.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function teacher_create(?string $from = null): response {
        global $USER;

        $this->ensure_folder($this->context('create_enunciate', 'teacher_create:folder'));

        $origin = $from ?? $this->document->get_template();
        $enun = $this->free_path($this->document->get_enunciate());

        $rescopy = $this->copy_file(
            $origin,
            $enun,
            $this->context(
                $from === null ? 'create_enunciate' : 'restore_document',
                'teacher_create:copy_file'
            )
        );
        if (!$rescopy->success) {
            return $rescopy;
        }

        $reslisting = $this->listing(
            $enun,
            $this->context('lookup_document', 'teacher_create:lookup')
        );
        if (!$reslisting->success) {
            return $reslisting;
        }

        // Recorded before sharing. The document already exists and is governed by
        // the service account, so the assignment works; giving up here left the
        // file orphaned in NextCloud and the assignment broken for the whole class.
        $data = new stdClass();
        $data->assignment = $this->instance;
        $data->path = $enun;
        $data->ncid = $reslisting->data;
        $data->userid = $USER->id;
        tipnc_enun::set($data);

        // Giving access to whoever creates it is what lets them write the brief.
        // A failure there is serious for that person and nobody else: it counts
        // as a warning inside a response that is still a success.
        $resshare = $this->grant_or_renew(
            $enun,
            $USER->username,
            self::PERMISSION_FILE_ALL,
            $this->context(
                'share_enunciate',
                'teacher_create:share_file',
                ['affecteduserid' => $USER->id]
            )
        );

        return $resshare->success
            ? new response(true, $data->ncid)
            : new response(true, $data->ncid, $resshare->error);
    }

    /**
     * Gives somebody who walked into the assignment access to its brief.
     *
     * Which people teach an assignment is not known when it is created —it is
     * known when they walk in—, so this is the moment. Whoever marks gets to
     * write in the brief; everybody else gets to read it.
     *
     * It is asked on every visit and costs a call on the first one only: what
     * has already been granted is written down, because NextCloud rejects
     * sharing the same document twice and view_summary() runs once per row of
     * the grading table.
     *
     * @param  stdClass $user     Who walked in.
     * @param  bool     $canwrite Whether they mark this assignment.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function grant_enunciate(stdClass $user, bool $canwrite): void {
        $this->grant_once(
            grants::ENUNCIATE,
            $this->instance,
            $this->document->enunciate_path(),
            $user,
            $canwrite,
            'grant_enunciate:share_file'
        );
    }

    /**
     * Gives whoever marks an assignment access to a submission they are opening.
     *
     * Until now the frozen copy was shared with one person: whoever happened to
     * create the brief. Anybody who started teaching afterwards could not read a
     * single submission handed in before they arrived, and if that one person had
     * no NextCloud account, nobody could read any of them.
     *
     * @param  stdClass $user       Who is opening it.
     * @param  stdClass $submission The submission being opened.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function grant_submission(stdClass $user, stdClass $submission): void {
        $this->grant_once(
            grants::SUBMISSION,
            (int) $submission->id,
            $this->document->submission_path($submission),
            $user,
            true,
            'grant_submission:share_file',
            'share_submission'
        );
    }

    /**
     * Renews the access of somebody to their own draft and frozen copy.
     *
     * Those shares have an end date like any other, so they are renewed on every
     * visit. The draft gets write access only while the submission is open.
     *
     * @param  stdClass $user       Who walked in.
     * @param  stdClass $submission Their submission.
     * @param  bool     $canwrite   Whether they may still write in the draft.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function grant_own(stdClass $user, stdClass $submission, bool $canwrite): void {
        $draft = tipnc_open::get($submission->id);
        if ($draft && !empty($draft->path)) {
            $this->grant_once(
                $canwrite ? grants::OWN_DRAFT_WRITE : grants::OWN_DRAFT_READ,
                (int) $submission->id,
                $draft->path,
                $user,
                $canwrite,
                'grant_own:draft',
                'share_draft'
            );
        }

        $frozen = tipnc::get($submission->id);
        if ($frozen && !empty($frozen->path)) {
            $this->grant_once(
                grants::OWN_FROZEN,
                (int) $submission->id,
                $frozen->path,
                $user,
                false,
                'grant_own:frozen',
                'share_submission'
            );
        }
    }

    /**
     * Gives access to a document, once per person and document.
     *
     * @param  string   $what      Which kind of document it is.
     * @param  int      $id        Assignment or submission it belongs to.
     * @param  string   $path      Path of the document in NextCloud.
     * @param  stdClass $user      Who walked in.
     * @param  bool     $canwrite  Whether they may write in it.
     * @param  string   $method    Point in the code, for the incident log.
     * @param  string   $operation Operation for the incident log.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    private function grant_once(
        string $what,
        int $id,
        string $path,
        stdClass $user,
        bool $canwrite,
        string $method,
        string $operation = 'grant_enunciate'
    ): void {
        if (grants::already($what, $id, (int) $user->id)) {
            return;
        }

        $permission = $canwrite ? self::PERMISSION_FILE_ALL : self::PERMISSION_READ;
        $context = $this->context($operation, $method, ['affecteduserid' => $user->id]);

        // Renewing comes before creating: NextCloud rejects sharing the same
        // document twice, and without this every visit after the cache expires
        // would leave a warning in the log without achieving anything.
        $answer = $this->grant_or_renew($path, $user->username, $permission, $context);

        // Only what went well is remembered: if NextCloud was down, the next
        // visit tries again instead of leaving them without access.
        if ($answer->success) {
            grants::remember($what, $id, (int) $user->id);
        }
    }

    /**
     * Gives access, or updates it when it was already given.
     *
     * NextCloud refuses to share the same document with the same person twice,
     * and «already shared» is the common case, not the exception: a document
     * keeps its name across attempts, and access is handed out every time
     * somebody walks in. Trying to create first and asking questions later would
     * fill the log with rejections that mean nothing is wrong.
     *
     * @param  string $file       Path of the document.
     * @param  string $username   Who gets it.
     * @param  int    $permission What they may do with it.
     * @param  array  $context    Context for the incident log.
     * @param  array|null $shares Shares already read, so they are not asked for twice.
     * @return response What NextCloud answered.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function grant_or_renew(
        string $file,
        string $username,
        int $permission,
        array $context = [],
        ?array $shares = null
    ): response {
        $expires = self::expiry_date();

        return $this->renew_permission($file, $username, $permission, $expires, $context, $shares)
            ?? $this->set_permission($file, $username, $permission, $context, $expires);
    }

    /**
     * Pushes back the end date of access somebody already has.
     *
     * @param  string $file       Path of the document.
     * @param  string $username   Whose access it is.
     * @param  int    $permission What they may do with it.
     * @param  string $expires    New end date, empty for none.
     * @param  array  $context    Context for the incident log.
     * @param  array|null $shares Shares already read, so they are not asked for twice.
     * @return response|null What NextCloud answered, null when they had none.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function renew_permission(
        string $file,
        string $username,
        int $permission,
        string $expires,
        array $context = [],
        ?array $shares = null
    ): ?response {
        // The list is asked for once per document, not once per person: handing out
        // access for a group is as many calls as members, and doubling them adds
        // nothing. It is not logged because it is nobody's incident.
        $shares ??= $this->shares_of($file, $context, false);

        if ($shares === null) {
            return null;
        }

        foreach ($shares as $share) {
            if (($share['share_with'] ?? '') !== $username || !isset($share['id'])) {
                continue;
            }

            $form = ['permissions' => $permission];
            if ($expires !== '') {
                $form['expireDate'] = $expires;
            }

            $answer = $this->client->request(
                'PUT',
                '/ocs/v2.php/apps/files_sharing/api/v1/shares/' . (int) $share['id'] . '?format=json',
                [
                    'headers' => ['OCS-APIRequest' => 'true'],
                    'form' => $form,
                    'context' => array_merge($context, [
                        'errorcode' => code::SHARE_FAILED,
                        'documentpath' => $file,
                    ]),
                ]
            );

            return $answer->is_success()
                ? new response(true, (string) $share['id'])
                : new response(false, null, new error(code::SHARE_FAILED, $answer->body));
        }

        return null;
    }

    /**
     * The day access given now stops working, if the site wants one.
     *
     * Access is handed out when somebody walks into the assignment, so it renews
     * itself by being used. Giving it an end date turns "find out that this
     * person stopped teaching here» —which nothing reliably tells us— into
     * «it lapses unless somebody keeps coming», which needs telling by nobody.
     *
     * @return string The date as NextCloud wants it, empty when it never ends.
     * @throws dml_exception If the configuration cannot be read.
     */
    protected static function expiry_date(): string {
        $days = (int) get_config('assignsubmission_tipnc', 'shareexpiry');

        return $days > 0 ? date('Y-m-d', time() + ($days * DAYSECS)) : '';
    }

    /**
     * Student Open Submission.
     *
     * @param stdClass $submission
     * @return response
     * @throws dml_exception
     */
    public function student_open(stdClass $submission): response {
        $this->ensure_folder($this->context(
            'open_draft',
            'student_open:folder',
            ['submission' => $submission->id]
        ));

        $enun = $this->document->enunciate_path();
        $open = $this->free_path($this->document->open_path($submission));
        $rescopy = $this->copy_file(
            $enun,
            $open,
            $this->context(
                'open_draft',
                'student_open:copy_file',
                ['submission' => $submission->id]
            )
        );
        if ($rescopy->success) {
            // To whoever it belongs to, not to whoever happened by: in a group
            // submission that is every member, and teachers open this being none.
            $ressharestudent = $this->share_with_owners(
                $open,
                $submission,
                self::PERMISSION_FILE_ALL,
                $this->context(
                    'share_draft',
                    'student_open:share_file',
                    ['submission' => $submission->id]
                )
            );
            if ($ressharestudent->success) {
                $reslisting = $this->listing(
                    $open,
                    $this->context(
                        'lookup_document',
                        'student_open:lookup',
                        ['submission' => $submission->id]
                    )
                );
                if ($reslisting->success) {
                    $tipncopen = tipnc_open::get($submission->id);
                    if ($tipncopen) {
                        $tipncopen->ncid = $reslisting->data;
                        $tipncopen->path = $open;
                        tipnc_open::update($tipncopen);
                        return new response(true, $tipncopen->ncid);
                    } else {
                        $tipncopen = new stdClass();
                        $tipncopen->assignment = $submission->assignment;
                        $tipncopen->submission = $submission->id;
                        $tipncopen->ncid = $reslisting->data;
                        $tipncopen->path = $open;
                        tipnc_open::set($tipncopen);
                        return new response(true, $tipncopen->ncid);
                    }
                } else {
                    return $reslisting;
                }
            } else {
                return $ressharestudent;
            }
        } else {
            return $rescopy;
        }
    }

    /**
     * Freezes the copy that gets marked.
     *
     * @param  stdClass $submission The submission being handed in.
     * @return response The frozen copy. It succeeds even when the share fails:
     *                  the copy exists and is what gets marked.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function student_submit(stdClass $submission): response {
        $open = $this->document->open_path($submission);

        // Handing in again replaces this submission's own frozen copy, and only that.
        $frozen = tipnc::get($submission->id);
        $replace = $frozen && !empty($frozen->path);
        $sub = $replace ? $frozen->path : $this->free_path($this->document->submission_for($submission));

        // Before freezing anything, the editor is asked to write what it holds:
        // without this the previous version is copied and the student hands in
        // what they had a minute ago, which is the bug this closes.
        $this->settle_document($open, $submission);

        $rescopy = $this->copy_file(
            $open,
            $sub,
            $this->context(
                'submit_freeze',
                'student_submit:copy_file',
                ['submission' => $submission->id]
            ),
            $replace
        );
        if (!$rescopy->success) {
            return $rescopy;
        }

        $reslisting = $this->listing(
            $sub,
            $this->context(
                'lookup_document',
                'student_submit:lookup',
                ['submission' => $submission->id]
            )
        );
        if (!$reslisting->success) {
            return $reslisting;
        }

        // The submission is recorded before handing out access: the copy is made
        // and it is what gets marked. Giving up here over a permission left
        // Moodle saying "submitted" with no submission in existence.
        $tipnc = tipnc::get($submission->id) ?: new stdClass();
        $tipnc->assignment = $submission->assignment;
        $tipnc->submission = $submission->id;
        $tipnc->ncid = $reslisting->data;
        $tipnc->path = $sub;

        isset($tipnc->id) ? tipnc::update($tipnc) : tipnc::set($tipnc);

        // Whoever handed in drops to read-only on their copy. Teachers are not
        // given it here: they get access when they open the submission to mark it,
        // which is the only thing that works when who teaches changes over time.
        $resshare = $this->share_with_owners(
            $sub,
            $submission,
            self::PERMISSION_READ,
            $this->context(
                'share_submission',
                'student_submit:share_student',
                ['submission' => $submission->id]
            )
        );

        return $resshare->success
            ? new response(true, $tipnc->ncid)
            : new response(true, $tipnc->ncid, $resshare->error);
    }

    /**
     * Copies a restored draft or submission into the assignment it now belongs to.
     *
     * The copy is a new file: the restored assignment never writes in the
     * document of the original one.
     *
     * @param  stdClass $submission The restored submission.
     * @param  string   $source     Path of the document of the original assignment.
     * @param  string   $mode       document::MODE_OPEN or document::MODE_SUBMISSION.
     * @return response The identifier of the copy, or the failure.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function restore_document(stdClass $submission, string $source, string $mode): response {
        $draft = $mode === document::MODE_OPEN;
        $destiny = $this->free_path(
            $draft ? $this->document->open_for($submission) : $this->document->submission_for($submission)
        );
        $context = $this->context('restore_document', 'restore_document', ['submission' => $submission->id]);

        $this->ensure_folder($context);

        $rescopy = $this->copy_file($source, $destiny, $context);
        if (!$rescopy->success) {
            return $rescopy;
        }

        $reslisting = $this->listing($destiny, $this->context(
            'lookup_document',
            'restore_document:lookup',
            ['submission' => $submission->id]
        ));
        if (!$reslisting->success) {
            return $reslisting;
        }

        $row = (object) [
            'assignment' => $submission->assignment,
            'submission' => $submission->id,
            'ncid' => $reslisting->data,
            'path' => $destiny,
        ];
        $draft ? tipnc_open::set($row) : tipnc::set($row);

        // A draft stays writable until it is handed in; a frozen copy is read.
        $canwrite = $draft && ($submission->status ?? '') !== ASSIGN_SUBMISSION_STATUS_SUBMITTED;

        $resshare = $this->share_with_owners(
            $destiny,
            $submission,
            $canwrite ? self::PERMISSION_FILE_ALL : self::PERMISSION_READ,
            $this->context('restore_document', 'restore_document:share', ['submission' => $submission->id])
        );

        return $resshare->success
            ? new response(true, $row->ncid)
            : new response(true, $row->ncid, $resshare->error);
    }

    /**
     * Whether a document exists, without recording the call.
     *
     * @param  string $file Path of the document.
     * @return bool True when NextCloud finds it.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function exists(string $file): bool {
        return $this->probe($file)->is_success();
    }

    /**
     * Whether NextCloud says for certain that a document is not there.
     *
     * Unlike !exists(), an unreachable server is not taken as a missing file.
     *
     * @param  string $file Path of the document.
     * @return bool True only on a 404.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function missing(string $file): bool {
        return $this->probe($file)->httpcode === 404;
    }

    /**
     * Asks for a document without recording the call.
     *
     * @param  string $file Path of the document.
     * @return http_response What NextCloud answered.
     * @throws dml_exception If the incident cannot be recorded.
     */
    private function probe(string $file): http_response {
        return $this->client->request('PROPFIND', $this->dav_path($file), [
            'headers' => ['Depth' => '0'],
            'log' => false,
            'context' => ['documentpath' => $file],
        ]);
    }

    /**
     * The given path, or the first free one with a counter before the extension.
     *
     * Names are fixed, so a document from a reset course or a removed submission
     * may already be there; it is kept and the new one gets another name.
     *
     * @param  string $path Path the document would have.
     * @return string A path nothing is at.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function free_path(string $path): string {
        if (!$this->exists($path)) {
            return $path;
        }

        $dot = strrpos($path, '.');
        $slash = strrpos($path, '/');
        $hasextension = $dot !== false && ($slash === false || $dot > $slash);
        $stem = $hasextension ? substr($path, 0, $dot) : $path;
        $extension = $hasextension ? substr($path, $dot) : '';

        for ($counter = 2; $counter <= self::FREE_NAME_TRIES; $counter++) {
            $candidate = $stem . '_' . $counter . $extension;
            if (!$this->exists($candidate)) {
                return $candidate;
            }
        }

        return $stem . '_' . time() . $extension;
    }

    /**
     * Copies a document inside the working folder.
     *
     * Never over an existing document unless asked: COPY with Overwrite F fails
     * instead of replacing.
     *
     * @param  string $origin    Source path.
     * @param  string $destiny   Destination path.
     * @param  array  $context   Context for the incident log.
     * @param  bool   $overwrite Whether an existing document may be replaced.
     * @return response Success, or the failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function copy_file(string $origin, string $destiny, array $context = [], bool $overwrite = false): response {
        $answer = $this->client->request('COPY', $this->dav_path($origin), [
            'headers' => [
                'OCS-APIRequest' => 'true',
                // The destination resolves against the same host as the call: using
                // the public URL would make NextCloud reject it as external.
                'Destination' => $this->client->base_url() . $this->dav_path($destiny),
                // WebDAV replaces by default; only a frozen copy may be replaced.
                'Overwrite' => $overwrite ? 'T' : 'F',
            ],
            'context' => array_merge($context, [
                'errorcode' => code::COPY_NOT_FOUND,
                'documentpath' => $destiny,
            ]),
        ]);

        if ($answer->is_success()) {
            return new response(true, '');
        }

        return new response(false, null, new error($this->code_of($answer, code::COPY_NOT_FOUND), $answer->body));
    }

    /**
     * Starts the draft of a reopened attempt from the previous one.
     *
     * The document of the new attempt has a name of its own, so the previous one
     * stays where it is: reopening no longer writes over the work that was
     * already handed in.
     *
     * @param  stdClass $source  The attempt being carried over.
     * @param  stdClass $destiny The attempt starting now.
     * @return response What NextCloud answered.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function reattempt_from(stdClass $source, stdClass $destiny): response {
        $this->ensure_folder($this->context(
            'open_draft',
            'reattempt:folder',
            ['submission' => $destiny->id]
        ));

        // What was handed in if it ever was, and if not, whatever is left in the
        // draft: it is the last thing that person wrote.
        $previous = tipnc::get($source->id)
            ? $this->document->submission_path($source)
            : $this->document->open_path($source);

        $draft = $this->free_path($this->document->open_for($destiny));

        $rescopy = $this->copy_file(
            $previous,
            $draft,
            $this->context(
                'open_draft',
                'reattempt:copy_file',
                ['submission' => $destiny->id]
            )
        );

        if (!$rescopy->success) {
            return $rescopy;
        }

        $resshare = $this->share_with_owners(
            $draft,
            $destiny,
            self::PERMISSION_FILE_ALL,
            $this->context(
                'share_draft',
                'reattempt:share_file',
                ['submission' => $destiny->id]
            )
        );

        if (!$resshare->success) {
            return $resshare;
        }

        $reslisting = $this->listing(
            $draft,
            $this->context(
                'lookup_document',
                'reattempt:lookup',
                ['submission' => $destiny->id]
            )
        );

        if (!$reslisting->success) {
            return $reslisting;
        }

        $row = tipnc_open::get($destiny->id) ?: new stdClass();
        $row->assignment = $destiny->assignment;
        $row->submission = $destiny->id;
        $row->ncid = $reslisting->data;
        $row->path = $draft;

        isset($row->id) ? tipnc_open::update($row) : tipnc_open::set($row);

        return new response(true, $row->ncid);
    }

    /**
     * Gives access to whoever the submission belongs to.
     *
     * An individual submission belongs to one person; a group one belongs to
     * every member of the group, and all of them work on the same document —that
     * is what a group submission is. Sharing it with only whoever clicked was
     * what made each member end up with a draft of their own.
     *
     * @param  string   $file       Document to share.
     * @param  stdClass $submission Submission it belongs to.
     * @param  int      $permission What they may do with it.
     * @param  array    $context    Context for the incident log.
     * @return response Failure of any of them is failure: a member without access
     *                  cannot work, and finding out later is worse.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function share_with_owners(
        string $file,
        stdClass $submission,
        int $permission,
        array $context = []
    ): response {
        $groupid = (int) ($submission->groupid ?? 0);

        if ($groupid === 0) {
            $owner = core_user::get_user((int) $submission->userid, 'id, username');

            if (!$owner) {
                return new response(false, null, new error(code::SHARE_NO_ACCOUNT, ''));
            }

            return $this->grant_or_renew(
                $file,
                $owner->username,
                $permission,
                array_merge($context, ['affecteduserid' => $owner->id])
            );
        }

        $members = groups_get_members($groupid, 'u.id, u.username');

        if (!$members) {
            return new response(false, null, new error(code::SHARE_NO_ACCOUNT, ''));
        }

        // A single read of the shares for the whole group.
        $shares = $this->shares_of($file, $context, false) ?? [];

        $last = new response(true);
        foreach ($members as $member) {
            $last = $this->grant_or_renew(
                $file,
                $member->username,
                $permission,
                array_merge($context, ['affecteduserid' => $member->id]),
                $shares
            );

            if (!$last->success) {
                return $last;
            }
        }

        return $last;
    }

    /**
     * Opens or closes the draft for the person it belongs to.
     *
     * Locking a submission stops Moodle accepting anything else, but the document
     * lives in NextCloud and its share does not know about that: without this, a
     * locked submission carries on being edited.
     *
     * @param  stdClass $submission The submission whose draft it is.
     * @param  bool     $canwrite   Whether they may still write in it.
     * @return response What NextCloud answered.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function set_draft_access(stdClass $submission, bool $canwrite): response {
        // The owner of the submission, not whoever runs this: teachers trigger it
        // on somebody else's document.
        $student = core_user::get_user((int) $submission->userid);

        if (!$student) {
            return new response(false, null, new error(code::SHARE_NO_ACCOUNT, ''));
        }

        // A reopened attempt has no draft until its form is opened: nothing to lock.
        $row = tipnc_open::get($submission->id);
        if (!$row || empty($row->path)) {
            return new response(true);
        }

        $draft = $row->path;
        $operation = $canwrite ? 'unlock_draft' : 'lock_draft';

        $permission = $canwrite ? self::PERMISSION_FILE_ALL : self::PERMISSION_READ;
        $context = $this->context($operation, 'set_draft_access', [
            'submission' => $submission->id,
            'affecteduserid' => $student->id,
        ]);

        // There is always an earlier share here —the one given when the draft was
        // opened— so what is needed is to change its permissions. Sharing the same
        // thing twice is rejected by NextCloud, and locking is the common case.
        return $this->grant_or_renew($draft, $student->username, $permission, $context);
    }

    /**
     * Asks the student's draft to be saved from the open editor, before the form saves.
     *
     * @param  stdClass $submission The submission being saved.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     * @throws coding_exception If a language string is missing.
     */
    public function save_draft(stdClass $submission): void {
        $row = tipnc_open::get($submission->id);
        if (!$row || empty($row->path)) {
            return;
        }

        $this->settle_document($row->path, $submission, 'save_draft', 'save_draft');
    }

    /**
     * Makes the open editor write what it holds into the draft, and waits for it.
     *
     * Only the embedded editor can be asked; in the other modes the document is taken as it is.
     *
     * @param  string   $open       Path of the draft.
     * @param  stdClass $submission The submission it belongs to.
     * @param  string   $operation  Operation for the incident log.
     * @param  string   $method     Prefix of the point in the code, for the incident log.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     * @throws coding_exception If a language string is missing.
     */
    protected function settle_document(
        string $open,
        stdClass $submission,
        string $operation = 'submit_freeze',
        string $method = 'student_submit'
    ): void {
        if (viewmode::current() !== viewmode::EDITOR) {
            return;
        }

        $note = function (bool $ok, string $step, string $string, ?string $a = null) use ($open, $submission, $operation, $method) {
            $context = $this->context($operation, $method . ':' . $step, [
                'submission' => $submission->id,
                'documentpath' => $open,
                'responsebody' => get_string($string, 'assignsubmission_tipnc', $a),
            ]);
            $ok ? logger::info(code::OK, $operation, $context)
                : logger::warning(code::SUBMIT_NOT_SAVED, $operation, $context);
        };

        $ncid = (int) (tipnc_open::get($submission->id)->ncid ?? 0);
        if ($ncid === 0) {
            $note(false, 'nodraft', 'log_submit_nodraft');
            return;
        }

        $before = $this->modified_time($open);
        $note(true, 'asksave', 'log_submit_asksave');

        // The stored key of the open session: a new one would not reach it.
        $key = (new sessions())->current($ncid);
        if ($key === '') {
            // The session already ended, and ending it saved the document.
            $note(true, 'nosession', 'log_submit_nosession');
            return;
        }

        $answer = command::force_save($key);
        if (!command::saved($answer)) {
            $note(false, 'forcesave', 'log_submit_refused', command::describe($answer));
            return;
        }

        if ($answer === command::NOTHING_TO_SAVE) {
            $note(true, 'forcesave', 'log_submit_nochanges');
            return;
        }

        // The editor answers at once and writes a moment later.
        if (!$this->wait_for_change($open, $before)) {
            $note(false, 'wait', 'log_submit_late');
            return;
        }

        $note(true, 'forcesave', 'log_submit_saved');
    }

    /**
     * When a document was last written.
     *
     * @param  string $file Path of the document.
     * @return int Timestamp, or 0 when it cannot be read.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function modified_time(string $file): int {
        $answer = $this->client->request('PROPFIND', $this->dav_path($file), [
            'headers' => ['Depth' => '0'],
            'log' => false,
            'context' => ['documentpath' => $file],
        ]);

        if (!$answer->is_success()) {
            return 0;
        }

        if (!preg_match('/<d:getlastmodified>([^<]+)</', $answer->body, $found)) {
            return 0;
        }

        return (int) strtotime($found[1]);
    }

    /**
     * Waits until the document is written again, or gives up.
     *
     * Asking the editor to save is not the same as the document being saved: the
     * editor answers straight away and writes a moment later. Freezing the copy
     * before that is exactly the bug this exists to avoid.
     *
     * @param  string $file    Path of the document.
     * @param  int    $since   Time it had before asking.
     * @param  int    $seconds How long to wait at most.
     * @return bool True when the document changed.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function wait_for_change(string $file, int $since, int $seconds = 15): bool {
        $deadline = time() + $seconds;

        do {
            sleep(1);
            if ($this->modified_time($file) > $since) {
                return true;
            }
        } while (time() < $deadline);

        return false;
    }

    /**
     * Reads the content of a document.
     *
     * @param  string $file    Path of the document.
     * @param  array  $context Context for the incident log.
     * @return response The bytes of the document, or the failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function download_file(string $file, array $context = []): response {
        $answer = $this->client->request('GET', $this->dav_path($file), [
            'context' => array_merge($context, [
                'errorcode' => code::DOWNLOAD_FAILED,
                'documentpath' => $file,
            ]),
        ]);

        if (!$answer->is_success()) {
            return new response(false, null, new error(code::DOWNLOAD_FAILED, $answer->body));
        }

        return new response(true, $answer->body);
    }

    /**
     * Writes the content of a document, replacing what was there.
     *
     * This is what makes the editor's work land in NextCloud, so a failure here
     * means somebody wrote something that nobody will find: it is an error, never
     * a silent one.
     *
     * @param  string $file    Path of the document.
     * @param  string $content The bytes to write.
     * @param  array  $context Context for the incident log.
     * @return response Success, or the failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function upload_file(string $file, string $content, array $context = []): response {
        $answer = $this->client->request('PUT', $this->dav_path($file), [
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'body' => $content,
            'context' => array_merge($context, [
                'errorcode' => code::UPLOAD_FAILED,
                'documentpath' => $file,
            ]),
        ]);

        if (!$answer->is_success()) {
            return new response(false, null, new error(code::UPLOAD_FAILED, $answer->body));
        }

        return new response(true, '');
    }

    /**
     * Moves a document, keeping what it had shared.
     *
     * A MOVE by the owner keeps the shares: whoever had access keeps it, and the
     * document simply is somewhere else.
     *
     * @param  string $origin  Where it is now.
     * @param  string $destiny Where it should be.
     * @param  array  $context Context for the incident log.
     * @return response Success, or the failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function move_file(string $origin, string $destiny, array $context = []): response {
        $answer = $this->client->request('MOVE', $this->dav_path($origin), [
            'headers' => [
                'Destination' => $this->client->base_url() . $this->dav_path($destiny),
                // Never overwrite: if something is already at the target, look before touching.
                'Overwrite' => 'F',
            ],
            'context' => array_merge($context, [
                'errorcode' => code::MOVE_FAILED,
                'documentpath' => $destiny,
            ]),
        ]);

        if ($answer->is_success()) {
            return new response(true, '');
        }

        return new response(false, null, new error(code::MOVE_FAILED, $answer->body));
    }

    /**
     * Makes sure the folder of this assignment exists.
     *
     * WebDAV does not create intermediate folders, so they go one by one, and one
     * that is already there is the result we wanted anyway.
     *
     * @param  array $context Context for the incident log.
     * @return response Success, or the first failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function ensure_folder(array $context = []): response {
        foreach ($this->document->folder_chain() as $folder) {
            // No automatic logging: creating a folder that already exists answers
            // 405, and that is not an incident but the result we were after.
            $answer = $this->client->request('MKCOL', $this->dav_path($folder), [
                'log' => false,
                'context' => array_merge($context, [
                    'errorcode' => code::FOLDER_FAILED,
                    'documentpath' => $folder,
                ]),
            ]);

            if (!$answer->is_success() && $answer->httpcode !== 405) {
                logger::error(
                    code::FOLDER_FAILED,
                    $context['operation'] ?? 'create_folder',
                    array_merge($context, [
                        'documentpath' => $folder,
                        'httpcode' => $answer->httpcode,
                        'responsebody' => $answer->body,
                    ])
                );

                return new response(false, null, new error(code::FOLDER_FAILED, $answer->body));
            }
        }

        return new response(true, '');
    }

    /**
     * Deletes a document, with whatever it had shared.
     *
     * It goes to the NextCloud trash, which is the only reason this is a
     * reasonable thing to offer at all.
     *
     * @param  string $file    Path of the document.
     * @param  array  $context Context for the incident log.
     * @return response Success, or the failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function delete_file(string $file, array $context = []): response {
        $answer = $this->client->request('DELETE', $this->dav_path($file), [
            'context' => array_merge($context, [
                'errorcode' => code::DELETE_FAILED,
                'documentpath' => $file,
            ]),
        ]);

        // It being gone already is the result we were after.
        if ($answer->is_success() || $answer->httpcode === 404) {
            return new response(true, '');
        }

        return new response(false, null, new error(code::DELETE_FAILED, $answer->body));
    }

    /**
     * Takes back every access granted to a document.
     *
     * The document stays where it is; what goes away is the way in from
     * NextCloud, which is what stops making sense when Moodle forgets it.
     *
     * @param  string $file    Path of the document.
     * @param  array  $context Context for the incident log.
     * @return response Success, or the failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function revoke_access(string $file, array $context = []): response {
        $shares = $this->shares_of($file, $context);

        if ($shares === null) {
            return new response(false, null, new error(code::UNSHARE_FAILED, ''));
        }

        foreach ($shares as $share) {
            if (isset($share['id'])) {
                $this->delete_permission((int) $share['id'], array_merge($context, [
                    'documentpath' => $file,
                ]));
            }
        }

        return new response(true, '');
    }

    /**
     * Takes one person's access to a document away, leaving everyone else's.
     *
     * @param  string $file     Path of the document.
     * @param  string $username Whose access to withdraw.
     * @param  array  $context  Context for the incident log.
     * @return bool True when there was nothing left of theirs.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function revoke_person(string $file, string $username, array $context = []): bool {
        $shares = $this->shares_of($file, $context);

        if ($shares === null) {
            return false;
        }

        $done = true;
        foreach ($shares as $share) {
            if (($share['share_with'] ?? '') !== $username || !isset($share['id'])) {
                continue;
            }

            $answer = $this->delete_permission((int) $share['id'], array_merge($context, [
                'documentpath' => $file,
            ]));
            $done = $done && $answer->success;
        }

        return $done;
    }

    /**
     * Who a document is shared with right now.
     *
     * @param  string $file    Path of the document.
     * @param  array  $context Context for the incident log.
     * @param  bool   $log     Whether to record the call in the incident log.
     * @return array|null The shares, null when they could not be read.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function shares_of(string $file, array $context = [], bool $log = true): ?array {
        $answer = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json&path='
            . rawurlencode('/' . ltrim($file, '/')),
            [
                'headers' => ['OCS-APIRequest' => 'true'],

                // At the options level, not inside the context: in there it is just
                // one more field of the log entry and does not switch it off.
                'log' => $log,
                'context' => array_merge($context, [
                    'errorcode' => code::UNSHARE_FAILED,
                    'documentpath' => $file,
                ]),
            ]
        );

        if (!$answer->is_success()) {
            return null;
        }

        $data = $answer->json();

        return (array) ($data['ocs']['data'] ?? []);
    }

    /**
     * Reads the remote identifier of a document.
     *
     * @param  string $file    Path of the document.
     * @param  array  $context Context for the incident log.
     * @return response The identifier, or the failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function listing(string $file, array $context = []): response {
        $body = '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
            . '<d:prop><oc:fileid/><d:getlastmodified/><d:getetag/></d:prop></d:propfind>';

        $answer = $this->client->request('PROPFIND', $this->dav_path($file), [
            'headers' => [
                'OCS-APIRequest' => 'true',
                'Content-Type' => 'application/xml',
                'Depth' => '0',
            ],
            'body' => $body,
            'context' => array_merge($context, [
                'errorcode' => code::LOOKUP_FAILED,
                'documentpath' => $file,
            ]),
        ]);

        if (!$answer->is_success()) {
            return new response(false, null, new error($this->code_of($answer, code::LOOKUP_FAILED), $answer->body));
        }

        $fileid = $this->extract_fileid($answer->body);
        if ($fileid === null) {
            return new response(false, null, new error(code::LOOKUP_MALFORMED, $answer->body));
        }

        return new response(true, $fileid);
    }

    /**
     * Grants access to a document.
     *
     * @param  string $file       Path of the document.
     * @param  string $username   Account the document is shared with.
     * @param  int    $permission Permissions requested.
     * @param  array  $context    Context for the incident log.
     * @param  string $expires    Expiry date, empty when the access never lapses.
     * @return response The share identifier, or the failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function set_permission(
        string $file,
        string $username,
        int $permission,
        array $context = [],
        string $expires = ''
    ): response {
        // OCS answers 404 both for an unknown account and for a missing file.
        // Telling them apart here is what keeps a missing file from marking
        // somebody as having no account.
        if ($this->missing($file)) {
            logger::error(code::SHARE_NO_DOCUMENT, (string) ($context['operation'] ?? ''), array_merge($context, [
                'documentpath' => $file,
            ]));

            return new response(false, null, new error(code::SHARE_NO_DOCUMENT, ''));
        }

        $form = [
            'path' => '/' . ltrim($file, '/'),
            'shareType' => self::SHARE_TYPE_USER,
            'shareWith' => $username,
            'permissions' => $permission,
        ];

        // A dated access switches itself off if nobody uses it again, which is
        // what keeps it from depending on finding anything out.
        if ($expires !== '') {
            $form['expireDate'] = $expires;
        }

        $answer = $this->client->request('POST', '/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json', [
            'headers' => ['OCS-APIRequest' => 'true'],
            'form' => $form,
            'context' => array_merge($context, [
                'errorcode' => code::SHARE_FAILED,
                'errorcodes' => [404 => code::SHARE_NO_ACCOUNT],
                'documentpath' => $file,
            ]),
        ]);

        // A 404 when sharing means the recipient is not a valid account: the user
        // will be able to work from Moodle, but not to open it in NextCloud.
        if ($answer->httpcode === 404) {
            return new response(false, null, new error(code::SHARE_NO_ACCOUNT, $answer->body));
        }

        if (!$answer->is_success()) {
            return new response(false, null, new error($this->code_of($answer, code::SHARE_FAILED), $answer->body));
        }

        $data = $answer->json();
        $shareid = $data['ocs']['data']['id'] ?? null;
        if ($shareid === null) {
            return new response(false, null, new error(code::SHARE_FAILED, $answer->body));
        }

        // NextCloud grants what it can, not what it is asked for: check it.
        $granted = (int) ($data['ocs']['data']['permissions'] ?? $permission);
        if ($granted !== $permission) {
            $this->settle_permission((int) $shareid, $permission, $granted, $file, $context);
        }

        return new response(true, (string) $shareid);
    }

    /**
     * Makes the access granted match the access asked for.
     *
     * Access wider than requested is a problem, not a detail: read-only on the
     * brief is what keeps a student from rewriting the assignment for the whole
     * class, so it is taken back and, if it cannot be, recorded as a failure.
     *
     * @param  int    $shareid   Share to correct.
     * @param  int    $requested Permissions that were asked for.
     * @param  int    $granted   Permissions NextCloud gave.
     * @param  string $file      Path of the document, for the log.
     * @param  array  $context   Context for the incident log.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function settle_permission(
        int $shareid,
        int $requested,
        int $granted,
        string $file,
        array $context = []
    ): void {
        $entry = array_merge($context, [
            'documentpath' => $file,
            'responsebody' => "requested=$requested granted=$granted",
        ]);
        unset($entry['errorcode'], $entry['errorcodes']);

        // Less access than asked for annoys whoever gets it; it is not dangerous.
        if (($granted & ~$requested) === 0) {
            logger::warning(code::SHARE_PERMISSIONS, $context['operation'] ?? 'share', $entry);
            return;
        }

        $answer = $this->client->request(
            'PUT',
            '/ocs/v2.php/apps/files_sharing/api/v1/shares/' . $shareid . '?format=json',
            [
                'headers' => ['OCS-APIRequest' => 'true'],
                'form' => ['permissions' => $requested],
                'context' => array_merge($entry, ['errorcode' => code::SHARE_TOO_WIDE]),
            ]
        );

        if (!$answer->is_success()) {
            logger::error(code::SHARE_TOO_WIDE, $context['operation'] ?? 'share', $entry);
        }
    }

    /**
     * Revokes access previously granted.
     *
     * @param  int   $shareid Share identifier.
     * @param  array $context Context for the incident log.
     * @return response Success, or the failure with its code.
     * @throws dml_exception If the incident cannot be recorded.
     */
    protected function delete_permission(int $shareid, array $context = []): response {
        $answer = $this->client->request(
            'DELETE',
            '/ocs/v2.php/apps/files_sharing/api/v1/shares/' . $shareid . '?format=json',
            [
                'headers' => ['OCS-APIRequest' => 'true'],
                'context' => array_merge($context, ['errorcode' => code::UNSHARE_FAILED]),
            ]
        );

        if (!$answer->is_success()) {
            return new response(false, null, new error($this->code_of($answer, code::UNSHARE_FAILED), $answer->body));
        }

        return new response(true, '');
    }

    /**
     * Reads the remote identifier out of a WebDAV answer.
     *
     * @param  string $xml The answer.
     * @return string|null The identifier, or null when the answer is unreadable.
     */
    protected function extract_fileid(string $xml): ?string {
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$parsed instanceof SimpleXMLElement) {
            return null;
        }

        foreach ($parsed->getDocNamespaces(true) as $prefix => $uri) {
            if ($prefix !== '') {
                $parsed->registerXPathNamespace($prefix, $uri);
            }
        }

        $found = $parsed->xpath('//oc:fileid');

        return empty($found) ? null : (string) $found[0];
    }

    /**
     * Code that describes a failed answer.
     *
     * A failure of the service is not the same as a failure of this document: the
     * first one must stop the batch, the second one only marks its own document.
     *
     * @param  http_response $answer   The answer.
     * @param  string        $fallback Code when the failure belongs to the document.
     * @return string A code of the catalogue.
     */
    protected function code_of(http_response $answer, string $fallback): string {
        if ($answer->is_unreachable()) {
            return code::SYSTEM_UNREACHABLE;
        }
        if ($answer->is_unauthorised()) {
            return code::SYSTEM_UNAUTHORISED;
        }
        if ($answer->is_server_error()) {
            return code::SYSTEM_ERROR;
        }

        return $fallback;
    }
}
