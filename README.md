<h1 align="center">NextCloud Submission</h1>

<p align="center">
  <img src="https://img.shields.io/badge/version-2.1.0-informational" alt="Version">
  <a href="https://moodle.org"><img src="https://img.shields.io/badge/Moodle-4.5%20--%205.1-orange?logo=moodle" alt="Moodle"></a>
  <img src="https://img.shields.io/badge/Workplace-4.5%2B-blue" alt="Moodle Workplace">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/License-GPL--3.0-green" alt="License">
  <a href="https://tresipunt.com"><img src="https://img.shields.io/badge/made%20by-Tresipunt-F84015" alt="Made by Tresipunt"></a>
</p>

<p align="center"><b>Hand in an assignment by writing in the document, not by uploading a file.</b></p>

<p align="center"><b>🇬🇧 English</b> · <a href="README.es.md">🇪🇸 Español</a> · <a href="README.ca.md">Català</a></p>

A submission plugin that replaces the file upload with an office document hosted
in NextCloud and edited online. The teacher publishes a brief, every student gets
their own private copy, they write in it from inside Moodle, and handing in
freezes a copy that the teacher marks. Nothing is attached and nothing is
downloaded.

It does not modify the Moodle core or the theme, and it adds nothing to
assignments where it is not enabled.

---

## ✨ What it does

- **A document per student, created on its own** — the first time somebody opens
  the assignment, their copy of the template is made in NextCloud and shared with
  them. There is no step for the student to get wrong.
- **Written from inside Moodle** — the document opens embedded in the assignment
  page. The student never navigates NextCloud, and sees nothing but their own
  document.
- **Handing in freezes the work** — the submission is a separate, untouched copy.
  The student can carry on with their draft and the teacher still marks what was
  handed in.
- **Marked without leaving the grading screen** — the frozen copy opens in the
  grading panel, next to the rubric and the feedback.
- **Group assignments and re-attempts** — one document per group when the
  assignment works that way, and one per attempt when the teacher reopens it.
- **Access granted as people arrive, and withdrawn when they leave** — anybody
  who can open the assignment in Moodle gets access to the document; losing a
  role or an enrolment in the course takes it away (see the limitations below).
- **Backup, restore, import and duplication** — the brief travels with the
  assignment, and with user data so do each student's draft and submission. They
  are copied in NextCloud, so two assignments never write in the same document.
- **It says so when the service is down** — if NextCloud does not answer, the
  assignment explains it in plain words and refuses the submission instead of
  accepting work that was never saved.
- **An incident log that names what failed** — every call to NextCloud is
  recorded with a code, grouped by cause and with credentials masked, on a page
  of its own for the administrator.

## 🧭 Use cases

### A language department that marks the writing, not the file

Students submit essays. With file upload, half of them arrive in the wrong
format, some open with the fonts moved and a few turn out to be empty. The
department enables this plugin with a `.docx` template holding the heading and
the assessment criteria: everyone writes in the same document, the teacher opens
it in the grading panel and comments on it there.

### A site with no office suite on the desktops

The computers in the classroom have no word processor, and the students work from
tablets at home. The site already runs NextCloud with ONLYOFFICE. Setting the
document to open in the embedded editor means the browser is all anybody needs,
and the work stays on the institution's own servers rather than in a personal
account.

### An assignment with a brief that has to be read, not attached

The teacher prepares the brief as a document instead of a PDF attachment, and it
is shown at the top of the assignment page for everyone enrolled. Changing it
changes what everybody sees, with no new version to upload and no student working
from last week's copy.

### A site that is checked for what it stores about people

The institution answers data protection requests. The plugin declares the four
tables it keeps and the fact that documents leave the site, and a deletion
request removes both the rows in Moodle and the documents in NextCloud, rather
than leaving the work —with the person's name in it— in the other system.

## ⚙️ How it works

- The plugin talks to NextCloud with **one service account**, which owns every
  document. People never need a NextCloud password to work.
- Each assignment keeps three kinds of document in the same folder: the **brief**
  the teacher publishes, each student's **draft**, and the **frozen submission**
  made when they hand in.
- Whoever opens an assignment is given access to the document they are entitled
  to, and only that one. The share is renewed on every visit, so an access that
  lapses comes back simply by opening the assignment again.
- The document is shown in one of **three ways**, chosen by the administrator:
  the editor embedded by Moodle —the cleanest to work in, and the recommended
  one: the student sees the editor and nothing else—, the NextCloud page
  embedded, or a link that opens in a new tab, which works on any site and needs
  nothing changed in NextCloud. The first two have infrastructure requirements
  (see below).
- **Marking is done on the submitted document itself**, and the teacher decides
  whether to write in it. The frozen submission opens in the grading panel ready
  to edit: annotating the work is the point, so corrections are saved into that
  same file and the plugin keeps no earlier copy. The consequence is worth
  knowing before you rely on it: once a submission has been marked, what exists
  is the document as the teacher left it, not as the student handed it in. Sites
  that need to be able to prove exactly what was submitted should turn on
  **file versioning in NextCloud**, which keeps the previous revisions.
- **A closed submission is read only.** Once the submission is locked, past its
  cut-off date or handed in, the draft opens read only, and the embedded editor
  cannot save into it any more. With the embedded editor, what counts is what was
  saved («Save changes») or handed in before the submission closed: text still
  open in the editor at that moment is not kept.
- **Deletion is a choice, not a default.** Deleting a submission or an assignment
  in Moodle leaves the documents in NextCloud unless the administrator asks
  otherwise: a deletion here would be irreversible for the other system.
  Resetting the course removes the submissions and keeps the brief.
- **No document is ever written over.** If a name is already taken in NextCloud
  —a kept document of a removed submission, or last year's after a reset— the
  new document gets a numbered name and the old one stays untouched.
- **Restored documents are only copied when they can be trusted.** A document in a
  backup is copied if it is one of this site's documents and the person restoring
  could have backed up its assignment. Otherwise the brief starts from the
  template and the drafts are not copied, and the incident log says so.
- Everything the plugin does against NextCloud is written to an **incident log**
  with a code, kept for as long as the administrator says.

### Limitations

- **Losing one enrolment or one role withdraws all access in the course.** The
  plugin does not check whether the person is still enrolled another way (for
  example, by cohort as well as manually) or keeps another role. Their access to
  their own documents comes back the next time they open the assignment.

### What the site needs, depending on how the document is shown

Requirements are cumulative: read the row you are using.

| Display | What the site needs |
|---|---|
| Link, opened in a new tab | Nothing beyond a NextCloud session. Works everywhere |
| NextCloud page embedded | Reverse proxy **and** same domain as Moodle **and** `https` on both |
| Document Server embedded | The editor reachable by Moodle and by the browser, both over `https` |

> **The embedded Document Server is the best of the three to work in, and the
> one to choose if the site can meet its requirements.** What the student sees is
> the editor and nothing else: the ONLYOFFICE toolbar and their document, inside
> the assignment page. None of NextCloud's interface comes with it —no file
> manager, no navigation, no sidebar, no other documents— because Moodle embeds
> the editor directly rather than the page that contains it.
>
> That is not only tidier, it is narrower: there is no way to wander off from
> there into other files, and nothing to explain about a second system, because
> as far as the student is concerned there is no second system. Saving is the
> editor's job and it does it on its own, so there is nothing to remember before
> handing in either.
>
> Embedding the **NextCloud page** instead is the middle option, and the weakest:
> it asks for everything the section below describes *and* still shows NextCloud's
> own interface around the document. It is there for sites whose editor cannot be
> reached directly.

> **The new-tab mode needs none of the above.** Everything described in the rest
> of this section exists because a document shown *inside* Moodle travels in a
> frame. Opening it in a new tab is ordinary navigation: `X-Frame-Options` and
> `frame-ancestors` never come into play, so **nothing has to be changed in
> NextCloud**; the session cookie travels as it always does, so Moodle and
> NextCloud need not share a domain; and there is no reverse proxy to set up.
>
> This is the mode to use when the NextCloud is not yours to configure, or when
> its administrators will not put a proxy in front of it. What you give up is
> having the document inside the page: the student works in another tab and
> comes back to Moodle to hand in.

Embedding the NextCloud page has three conditions, and each one fails in its own
way:

**1. A reverse proxy** in front of NextCloud, dropping `X-Frame-Options` and
widening `frame-ancestors`. NextCloud sets both from its own `.htaccess`, so they
cannot be changed from NextCloud itself. Apache, because the fix edits *part* of
a header value:

```apache
Header unset X-Frame-Options
Header always unset X-Frame-Options
Header edit Content-Security-Policy "frame-ancestors 'self'" "frame-ancestors 'self' https://your-moodle"
```

`Header edit` replaces only that fragment: replacing the whole header would
destroy the one-time value authorising NextCloud's own scripts.

**2. NextCloud under the same registrable domain as Moodle** —
`moodle.school.edu` and `nc.school.edu`. NextCloud session cookies are
`SameSite=Lax`, and a frame counts as a request from the containing site: from a
different domain the browser neither sends the existing session nor accepts the
one the login creates, so users get a login inside the frame **that never lets
them in**, however right their password is. Rewriting cookies to
`SameSite=None; Secure` at the proxy is possible and not advised: it leaves the
result to each browser's third-party cookie policy and tampers with NextCloud's
CSRF protection from the outside.

**3. `https` on both sides**, or the browser blocks the frame as mixed content.
And `https` is not only about the addresses you type: **every piece behind the
proxy builds its own links from what it believes it is**, and behind a proxy none
of them knows. If one of them stays on `http`, the browser blocks its calls and
the symptom never mentions the protocol —a document that downloads instead of
opening, an empty frame, a generic download error—:

| Piece | How it is told | If it is missing |
|---|---|---|
| NextCloud | `overwritehost`, `overwriteprotocol` | Builds its URLs on `http`; the browser blocks its own requests |
| Document Server | `X-Forwarded-Proto: https` from the proxy | Asks for its own resources on `http` |
| ONLYOFFICE app | `DocumentServerUrl` (browser), `DocumentServerInternalUrl` (NextCloud), `StorageUrl` (editor) | The editor cannot be reached, or cannot fetch the document |

When something does not open, the useful diagnosis is in the **browser console**,
not in the server logs: a `Mixed Content … has been blocked` names the offending
URL, and with it the piece that stayed on `http`. If the editor's own log shows
nothing after trying to open a document, the request never got that far.

If any of the three cannot be met, use the link mode: it always works.

## 📋 Requirements

| Requirement | Version |
|---|---|
| Moodle | 4.5 – 5.1 (Moodle Workplace 4.5+) |
| PHP | 8.1+ |
| Other plugins | None |
| NextCloud | A reachable instance, and a service account able to create and share documents |
| Online editor | ONLYOFFICE or Collabora installed in NextCloud, for the embedded modes |

## 🚀 Installation

1. Copy the code into `mod/assign/submission/tipnc/` (in Moodle 5.x,
   `public/mod/assign/submission/tipnc/`).
2. Complete the installation from **Site administration › Notifications**
   (or by CLI: `php admin/cli/upgrade.php --non-interactive`).
3. Purge the caches (**Site administration › Development › Purge caches**
   or `php admin/cli/purge_caches.php`).
4. Fill in the connection settings (below). Until the address, the account and
   the password are set, the plugin reports that it is not configured rather than
   pretending to work.

In NextCloud, the service account needs a **working folder** and a **template**
inside it, named exactly as the corresponding settings say.

## 🔧 Settings

In **Site administration › Plugins › Assignment submission plugins › NextCloud
Submission**:

| Setting | Effect |
|---|---|
| **Connection with NextCloud** | |
| URL NextCloud | The address people's browsers use. What they see in the address bar |
| Domain NextCloud | The address the Moodle server uses to reach NextCloud. Often the same; not always, behind a proxy |
| User NextCloud | The service account that owns every document |
| Password | Its password. Stored masked, and never written to the log |
| **Documents** | |
| Folder name | Working folder inside the service account's space |
| Template name | Document each copy is made from |
| Path to view a document | Part of the NextCloud URL that opens a document in the online editor |
| **In assignments** | |
| Enabled by default in new assignments | Whether new assignments come with this submission type already on |
| **The editor** | |
| How the document is opened | Embedded editor, embedded NextCloud page, or a link in a new tab. See the requirements above |
| Address of the Document Server | Where the editor lives; for the embedded editor only |
| Signing secret | Shared with the Document Server, so Moodle and the editor recognise each other |
| Ask to confirm the document is saved | Asks the student to confirm before handing in. Not needed with the embedded editor, which saves on its own |
| **Access to the documents** | |
| Access lapses after | How long a share lasts. It is renewed on every visit, so an access that lapses comes back by opening the assignment again. Shorter is safer; «Never» leaves access in place forever |
| **When something is deleted** | |
| When a submission is deleted | Whether to keep the documents, delete the frozen submission, or delete everything |
| When an assignment is deleted | Whether to keep or delete its documents in NextCloud |
| **How the brief is shown** | |
| Where the brief is shown | In the activity header, in the main region, or at a destination of your own |
| Destination of the brief | A CSS selector, for the destination of your own |
| Maximum width of the assignment page | Widens the page so the embedded document is usable |
| Height of the embedded document | How tall the frame is |
| **Incident log** | |
| Keep incidents for | Days an incident is kept. A daily task removes the rest |

The log itself is at **Site administration › Plugins › Assignment submission
plugins › Incident log**, and needs the `assignsubmission/tipnc:view_errors`
capability — granted to managers by default.

## 🗑️ Uninstallation

Removing the plugin deletes its four tables and everything Moodle stored about
these documents. **The documents in NextCloud are not touched**: they belong to
the service account and stay in its folder, to be dealt with from NextCloud.

## 🛠️ Development

```bash
# Unit tests
vendor/bin/phpunit --testsuite assignsubmission_tipnc_testsuite

# JavaScript, after changing anything under amd/src/ (run it from this folder)
npx grunt amd
```

## 📄 License

[GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html) — 2026 [Tresipunt](https://tresipunt.com) (contacte@tresipunt.com)

---

<p align="center">
  <a href="https://tresipunt.com"><img src="pix/tresipunt_logo.png" alt="Tresipunt" width="160"></a>
</p>
