# Changelog — assignsubmission_tipnc

All notable changes to this plugin are documented here.

## 2.1.0 — 2026-11-05 (`2026110500`)

Answers the Moodle Marketplace review. Adds backup and restore, closes a hole in
the editor callback, and fixes how documents and their access live in NextCloud.
Requires Moodle 4.5+ (supported: 4.5 – 5.1).

### Security

- **The editor callback only stores what the Document Server signed.** Tokens
  issued by Moodle are refused in the body; status and result address are read
  from the verified payload only; the signed key must match the callback
  address; the result is downloaded only from the configured Document Server.
- **Read-only sessions get no callback address**, and the callback only accepts
  addresses issued for an editing session.
- **A closed submission cannot be edited.** When the submission is locked, past
  its cut-off date or handed in, the draft opens read only, and saves that still
  arrive from an editor opened earlier are discarded and logged.
- `assignsubmission/tipnc:view_errors` declares `RISK_PERSONAL | RISK_DATALOSS`.

### Added

- **«Save changes» saves the document.** With the embedded editor, saving the
  submission form asks the editor to write the draft into NextCloud and waits for
  it, as handing in already did. What is saved before the submission closes is
  what counts.
- **Backup, restore, import and duplication.** The brief is copied in NextCloud
  into the new assignment; with user data, so are each student's draft and
  submission. A document is copied only if it belongs to this site and the
  person restoring could have backed up its assignment; otherwise the brief
  starts from the template. While the copy is made, the assignment says the brief
  is being prepared.
- **Privacy:** the Document Server is declared as an external location, and the
  incident log entries about a person are exported and deleted with their data.

### Fixed

- **Resetting a course no longer leaves its assignments without a brief.** Only
  the submissions are removed; the brief goes when the assignment is deleted.
- **No document is written over.** Copies never replace an existing file: a taken
  name gets a numbered one. Only a submission's own frozen copy is replaced when
  it is handed in again.
- **Course or assignment names with `#` or `%`** no longer break the creation of
  documents.
- **Access to one's own draft and submission is renewed on every visit**, as it
  already was for the brief, instead of lapsing for good.
- **A missing document is no longer taken for a missing account** when sharing,
  so a student is not shown as having no account because a file is not there.
- **A document edited and closed opens with its changes.** After the editing
  session ends, the next opening gets a new editor key, so the editor no longer
  serves its cached copy of the previous version.
- When an assignment is deleted, its brief is found by its stored path.
- Missing `cachedef_*` language strings.

### Changed

- The remaining Spanish text in the code is now in English or in language
  strings, including the names used for new documents: `group`, `unknown` and
  `unnamed` instead of `grupo`, `desconocido` and `sin nombre`. Existing
  documents keep their names.

## 2.0.1 — 2026-09-11 (`2026091101`)

Housekeeping release for publication. **No behaviour changes**: every comment in
the source was translated, and the code itself was verified to be untouched —the
59 changed files hash identically to 2.0.0 once comments are stripped.

### Changed

- **The whole source is now in English**, comments included, as the Moodle
  Marketplace requires. This covers PHP, CSS, JavaScript and the Mustache
  templates.
- The AMD bundles were rebuilt, so the source maps no longer carry the previous
  comments.

### Added

- **`LICENSE`** shipped inside the package, stating GPL v3 or later.
- **`.gitattributes`** pinning line endings to LF, so a clone on Windows does not
  rewrite every file.

## 2.0.0 — 2026-09-11 (`2026091100`)

Major release. The plugin now answers for what it does: it tells the core
whether there is anything to hand in, refuses the submission when the document
service is down, keeps an incident log that names what failed, and answers data
protection requests in both systems. Requires Moodle 4.5+ (supported: 4.5 – 5.1).

### ⚠️ Breaking changes

- **An empty submission is no longer accepted.** The plugin used to tell the core
  that every submission had content, which let students hand in work that did not
  exist. It now answers truthfully: somebody who never opened their document
  cannot submit, and the assignment says so. On sites where this was happening,
  submissions that were empty will stop being shown as complete.
- **The old error table is dropped.** `assignsubmission_tipnc_error` is removed on
  upgrade and its rows are **not migrated**: they held a method, a code and a
  message that was usually an empty response —no URL, no HTTP status, no
  document— which is of no use for diagnosis. Anything reading that table
  directly must move to `assignsubmission_tipnc_log`.
- **Minimum Moodle version is now 4.5.** The plugin declared 3.10 while using
  APIs that do not exist there.

### Added

- **The document can be embedded by Moodle itself.** A new display mode opens the
  ONLYOFFICE editor inside the assignment page, with nothing of NextCloud around
  it: the student sees the document and no file browser. The previous modes
  —embedding the NextCloud page, or a link in a new tab— are still there and
  chosen by the administrator.
- **Marking without leaving the grading screen.** The frozen submission opens in
  the grading panel.
- **An incident log.** Every call to NextCloud is recorded with a code from a
  catalogue, grouped by cause with a count instead of one row per repetition, and
  with credentials masked out of URLs, headers and responses. It has a page of its
  own under Site administration, a new `assignsubmission/tipnc:view_errors`
  capability (granted to managers), and a daily task that removes incidents older
  than the retention setting.
- **The plugin notices when NextCloud is down** and says so in the assignment,
  in plain words, instead of showing a broken editor. While the service does not
  answer, the submission is refused rather than accepted empty.
- **Access to documents is granted on arrival and withdrawn on departure.**
  Anybody who can open the assignment in Moodle is given access to the document
  they are entitled to. Losing the role or the enrolment takes it away, through an
  event observer backed by a scheduled task so a missed event does not leave
  access behind.
- **Shares expire.** A new setting sets how long access lasts; it is renewed on
  every visit, so an access that lapses comes back by opening the assignment
  again.
- **Group assignments and re-attempts** each get their own document.
- **Privacy provider.** The plugin declares the four tables it keeps and that
  documents leave the site, and a deletion request removes both the rows in
  Moodle and the documents in NextCloud.
- **A read-only document says why it is read-only**, including when the reason is
  that the person has no account in the document service.
- **The brief can be placed** in the activity header, in the main region or at a
  CSS selector of your own, and the assignment page can be widened so the
  embedded document is usable.

### Fixed

- **The editor asked to save into a session that was already closed**, losing the
  last edits when handing in.
- **The editing session key** now changes when the document changes and stays put
  while the document is open. Before, it either never changed —so the editor kept
  serving a stale copy— or changed too often, and the editor reported that the
  version had changed.
- **Sharing a document twice** is no longer an error: an existing share is
  renewed instead of created again.
- **Every successful submission wrote a false error** to the log, because a
  success code was compared against the wrong value.
- **A failure warning could reach the wrong person**: incidents were matched by
  submission instead of by the affected user.
- **The brief opened read-only for everybody**, including whoever could edit it.
- **Documents could not be found after renaming a course or an assignment**: the
  real path of each document is now stored instead of rebuilt from names.
- **A fresh install created a dead table** that an upgrade had already removed.

### Changed

- **All traffic to NextCloud goes through Moodle's own HTTP client**, so it
  honours the site's proxy and outgoing request restrictions.
- **Documents are organised by site, course and assignment** instead of sitting
  loose in one folder.
- **Teachers are no longer given access when a student submits.** Access is
  worked out from what the person can do in Moodle, when they open the
  assignment.
- **Listing a document's shares** is done once per document instead of once per
  group member.
- **Settings have been reorganised** into the order a site is set up, with the
  ones that do not apply hidden.
- The plugin ships strings in English, Spanish and Catalan, error codes included.

### Security

- **Credentials never reach the incident log.** Passwords inside URLs,
  authorisation headers and the service password echoed back in a response are
  masked before anything is written.
- **The incident log requires a capability** that no role holds by default except
  manager. It shows what failed and to whom, so it is not for everyone.
- **TLS verification is never relaxed** on calls to NextCloud: they carry the
  service account credentials.

### Removed

- `assignsubmission_tipnc_error` and the class that wrote to it, replaced by the
  incident log.

### Notes

- **Existing documents are reorganised in the background.** The upgrade queues an
  ad-hoc task that moves them into the new folder structure; it is not done during
  the upgrade because that would make the upgrade wait on the network. **Cron must
  run** for it to finish. Until it does, those documents stay where they are and
  keep working.
- **Check the settings after upgrading.** The connection section now separates the
  address browsers use from the address the Moodle server uses to reach NextCloud;
  behind a proxy they are not the same.
- **Embedding the document has infrastructure requirements** —a reverse proxy,
  the same domain as Moodle and `https` on both sides— described in the README.
  Sites that cannot meet them should use the new-tab mode, which always works.
- **Known limitation: marking is done on the submitted document itself.** The
  teacher opens the frozen submission ready to edit, and whatever they write is
  saved into that same file; the plugin keeps no earlier copy. Annotating the
  work is the point, and the teacher decides whether to write in it, but it means
  that once a submission has been marked, what exists is the document as the
  teacher left it. **Turn on file versioning in NextCloud** if your institution
  needs to prove exactly what was handed in.

## 1.0.0 — 2023-02-17 (`2023021701`)

First published version: submissions as office documents hosted in NextCloud,
opened through the NextCloud page.
