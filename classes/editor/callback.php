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
 * What the editor says about a document, and what Moodle does with it.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\editor;

use assignsubmission_tipnc\api\nextcloud;
use assignsubmission_tipnc\log\code;
use assignsubmission_tipnc\log\logger;
use assignsubmission_tipnc\models\documents;
use assignsubmission_tipnc\models\sessions;
use core\http_client;
use dml_exception;
use Throwable;

/**
 * What the editor says about a document, and what Moodle does with it.
 *
 * The Document Server tells Moodle how an editing session went. Only two of its
 * states carry a document to store; the rest are news, and news is answered and
 * forgotten.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class callback {
    /** @var int Everything went fine. It is what the editor expects to read. */
    public const OK = 0;

    /** @var int The message is not trusted. */
    public const ERROR_FORBIDDEN = 403;

    /** @var int The document could not be stored. */
    public const ERROR_SAVE = 1;

    /** @var int The editing session ended and there is a document to store. */
    public const STATUS_READY = 2;

    /** @var int A save was forced while people keep editing. */
    public const STATUS_FORCED = 6;

    /** @var int How long we wait for the editor to hand over the document. */
    public const TIMEOUT = 60;

    /**
     * Answers the editor and ends the request.
     *
     * The Document Server reads this and only this: a JSON with an error code. If
     * it does not get a zero it will call again, which is a feature —a save is not
     * lost because Moodle was down for a minute— as long as we answer honestly.
     *
     * @param  int $error One of the constants.
     * @return void
     */
    public static function answer(int $error): void {
        header('Content-Type: application/json');
        echo json_encode(['error' => $error === self::OK ? 0 : 1]);
        die();
    }

    /** @var string[] Claims only Moodle puts in its own tokens. */
    private const MOODLE_CLAIMS = ['use', 'document', 'editorConfig'];

    /**
     * What the editor said, taken only from what it signed.
     *
     * @param  string $expectedkey Key of the session the address was issued for.
     * @return array|null The signed callback, or null when it cannot be trusted.
     * @throws dml_exception If the configuration cannot be read.
     */
    public static function body(string $expectedkey): ?array {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return null;
        }

        $signature = $body['token'] ?? self::bearer();
        if (!is_string($signature) || $signature === '') {
            return null;
        }

        $verified = token::verify($signature);
        if ($verified === null) {
            return null;
        }

        // A token in the header wraps the body in a payload claim.
        $signed = is_array($verified['payload'] ?? null) ? $verified['payload'] : $verified;

        return self::is_editor_callback($signed, $expectedkey) ? $signed : null;
    }

    /**
     * Whether a signed payload is a callback of the editor for this session.
     *
     * Moodle signs its own tokens with the same secret and hands them to anyone
     * who opens a document, so a valid signature alone proves nothing.
     *
     * @param  array  $signed      The verified payload.
     * @param  string $expectedkey Key of the session the address was issued for.
     * @return bool True when it is the editor talking about this session.
     */
    public static function is_editor_callback(array $signed, string $expectedkey): bool {
        foreach (self::MOODLE_CLAIMS as $claim) {
            if (array_key_exists($claim, $signed)) {
                return false;
            }
        }

        if (!isset($signed['key'], $signed['status']) || $expectedkey === '') {
            return false;
        }

        return hash_equals($expectedkey, (string) $signed['key']);
    }

    /**
     * Whether an address belongs to the configured Document Server.
     *
     * @param  string $url Address the editor says the result is at.
     * @return bool True when scheme, host and port match the configured server.
     * @throws dml_exception If the configuration cannot be read.
     */
    public static function is_from_docserver(string $url): bool {
        $origin = self::origin($url);

        return $origin !== '' && $origin === self::origin(token::server_url());
    }

    /**
     * Scheme, host and port of an address, normalised for comparison.
     *
     * @param  string $url The address.
     * @return string The origin, empty when the address has no scheme or host.
     */
    private static function origin(string $url): string {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    /**
     * Whether this state of the editor carries a document to store.
     *
     * @param  int $status State the editor reports.
     * @return bool True when there is something to save.
     */
    public static function wants_saving(int $status): bool {
        return in_array($status, [self::STATUS_READY, self::STATUS_FORCED], true);
    }

    /**
     * Takes the document from the editor and stores it in NextCloud.
     *
     * @param  int    $instance Assignment the document belongs to.
     * @param  string $path     Where it goes in NextCloud.
     * @param  string $url      Where the editor left the result.
     * @param  int    $status   State the editor reported.
     * @param  string $key      Key of the session that is saving.
     * @return bool True when the document is stored, or discarded on purpose.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public static function store(
        int $instance,
        string $path,
        string $url,
        int $status,
        string $key = ''
    ): bool {
        $context = [
            'operation' => $status === self::STATUS_FORCED ? 'forced_save' : 'editor_save',
            'method' => 'callback.php',
            'assignment' => $instance,
            'documentpath' => $path,
            'requesturl' => $url,
        ];

        // Moodle fetches nothing but what the Document Server serves.
        if (!self::is_from_docserver($url)) {
            logger::error(code::SAVE_FOREIGN_SOURCE, $context['operation'], $context);

            return false;
        }

        // An editor opened before the submission closed keeps saving. It is
        // acknowledged, so the Document Server does not retry, and discarded.
        if (!(new documents())->accepts_saves($path)) {
            logger::warning(code::SAVE_CLOSED, $context['operation'], $context);

            // The editor's cached copy holds the discarded text: the next opening reads the file.
            (new sessions())->retire($key);

            return true;
        }

        try {
            $content = (new http_client(['timeout' => self::TIMEOUT]))->get($url)->getBody()->getContents();
        } catch (Throwable $e) {
            logger::error(code::DOWNLOAD_FAILED, $context['operation'], array_merge($context, [
                'responsebody' => $e->getMessage(),
            ]));

            return false;
        }

        if ($content === '') {
            logger::error(code::DOWNLOAD_FAILED, $context['operation'], array_merge($context, [
                'responsebody' => get_string('log_save_empty', 'assignsubmission_tipnc'),
            ]));

            return false;
        }

        $nextcloud = new nextcloud($instance);

        $stored = $nextcloud->upload_file($path, $content, $context);
        if (!$stored->success) {
            return false;
        }

        // A forced save keeps the open session's key; a closed session's key is
        // dropped, because the editor would serve its cached copy under it.
        if ($status === self::STATUS_FORCED) {
            (new sessions())->note_save($key, $nextcloud->modified_time($path));
        } else {
            (new sessions())->retire($key);
        }

        // A save that goes well is logged too: it is the proof that what is in
        // NextCloud is what the person wrote, and when.
        logger::info(code::OK, $context['operation'], array_merge($context, [
            'responsebody' => get_string('log_save_ok', 'assignsubmission_tipnc', strlen($content)),
        ]));

        return true;
    }

    /**
     * The signature that came in the Authorization header, if any.
     *
     * @return string The signature, empty when there is none.
     */
    private static function bearer(): string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }
}
