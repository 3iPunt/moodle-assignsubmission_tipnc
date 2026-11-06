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
 * Receives what the editor says about a document.
 *
 * This is the other half of the editor being embedded by Moodle: when the editing
 * session ends —or when a save is forced— the Document Server calls here with the
 * address of the result, and Moodle stores it in NextCloud.
 *
 * Two signatures have to agree: ours, in the address, which names the document and
 * the editing session; and the editor's, in the body, which proves the message
 * comes from it and is the only source of what to store.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../../config.php');

use assignsubmission_tipnc\editor\callback;
use assignsubmission_tipnc\editor\token;

$signature = required_param('token', PARAM_RAW);
$payload = token::verify($signature);

// Only an address issued for an editing session can write back.
if ($payload === null || ($payload['use'] ?? '') !== 'callback' || ($payload['mode'] ?? '') !== 'edit') {
    callback::answer(callback::ERROR_FORBIDDEN);
}

$instance = (int) ($payload['instance'] ?? 0);
$path = (string) ($payload['path'] ?? '');
$key = (string) ($payload['key'] ?? '');

if ($instance === 0 || $path === '' || $key === '') {
    callback::answer(callback::ERROR_FORBIDDEN);
}

// Status and address come only from what the editor signed for this session.
$body = callback::body($key);
if ($body === null) {
    callback::answer(callback::ERROR_FORBIDDEN);
}

// The editor only asks to save in two states; the rest are "still open" or
// "closed with no changes", and there is nothing to do but acknowledge it.
if (!callback::wants_saving((int) ($body['status'] ?? 0))) {
    callback::answer(callback::OK);
}

$url = (string) ($body['url'] ?? '');
if ($url === '') {
    callback::answer(callback::ERROR_SAVE);
}

// The key tells us the saving session is the one Moodle opened.
$stored = callback::store($instance, $path, $url, (int) $body['status'], $key);

callback::answer($stored ? callback::OK : callback::ERROR_SAVE);
