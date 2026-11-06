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
 * Catalogue of incident codes.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\log;

use coding_exception;

/**
 * Catalogue of incident codes: readable text, severity and kind of failure.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class code {
    /** @var string The service failed: stops the batch and opens the circuit breaker. */
    public const KIND_SYSTEM = 'system';

    /** @var string Only this item failed: it is marked and the batch goes on. */
    public const KIND_ITEM = 'item';

    /** @var string Wrong configuration: retrying is pointless until someone fixes it. */
    public const KIND_CONFIG = 'config';

    /** @var string Severity of an incident that stops the user. */
    public const SEVERITY_ERROR = 'error';

    /** @var string Severity of an incident that degrades but does not stop. */
    public const SEVERITY_WARNING = 'warning';

    /** @var string Severity of a successful or merely informative operation. */
    public const SEVERITY_INFO = 'info';

    /** @var string The operation completed. */
    public const OK = '0000';

    /** @var string The document could not be copied: the source is missing. */
    public const COPY_NOT_FOUND = '0101';

    /** @var string The document could not be copied: unexpected answer. */
    public const COPY_FAILED = '0102';

    /** @var string The document could not be located after copying it. */
    public const LOOKUP_FAILED = '0201';

    /** @var string The remote answer could not be parsed. */
    public const LOOKUP_MALFORMED = '0202';

    /** @var string The document could not be shared. */
    public const SHARE_FAILED = '0301';

    /** @var string The recipient is not a valid account in NextCloud. */
    public const SHARE_NO_ACCOUNT = '0302';

    /** @var string The granted permissions differ from the requested ones. */
    public const SHARE_PERMISSIONS = '0303';

    /** @var string More access was granted than asked for: it had to be taken back. */
    public const SHARE_TOO_WIDE = '0304';

    /** @var string The document to share does not exist, which is not a missing account. */
    public const SHARE_NO_DOCUMENT = '0305';

    /** @var string The share could not be revoked. */
    public const UNSHARE_FAILED = '0401';

    /** @var string The document could not be deleted. */
    public const DELETE_FAILED = '0402';

    /** @var string The folder of the assignment could not be created. */
    public const FOLDER_FAILED = '0103';

    /** @var string The document could not be moved to its folder. */
    public const MOVE_FAILED = '0104';

    /** @var string The document could not be read from NextCloud. */
    public const DOWNLOAD_FAILED = '0105';

    /** @var string What the editor wrote could not be stored. */
    public const UPLOAD_FAILED = '0106';

    /** @var string The editor pointed at a result outside the configured Document Server. */
    public const SAVE_FOREIGN_SOURCE = '0107';

    /** @var string A save reached a draft whose submission is closed and was discarded. */
    public const SAVE_CLOSED = '0108';

    /** @var string NextCloud did not answer. */
    public const SYSTEM_UNREACHABLE = '0501';

    /** @var string NextCloud rejected the service credentials. */
    public const SYSTEM_UNAUTHORISED = '0502';

    /** @var string NextCloud answered with an internal error. */
    public const SYSTEM_ERROR = '0503';

    /** @var string Retries are paused: the circuit breaker is open. */
    public const SYSTEM_PAUSED = '0510';

    /** @var string The plugin has no connection details. */
    public const CONFIG_MISSING = '0601';

    /** @var string The configured template is not in the working folder. */
    public const CONFIG_NO_TEMPLATE = '0602';

    /** @var string The task has no base document yet. */
    public const TASK_NOT_PREPARED = '0701';

    /** @var string The submission was frozen before the editor saved the changes. */
    public const SUBMIT_NOT_SAVED = '0702';

    /** @var string A restored assignment brought a document that may not be copied. */
    public const RESTORE_REFUSED = '0703';

    /**
     * Severity and kind of each code.
     *
     * The kind is what drives the safeguards: a system failure stops the batch and
     * opens the circuit breaker, an item failure only marks that document.
     */
    private const CATALOGUE = [
        self::OK                  => [self::SEVERITY_INFO, self::KIND_ITEM],
        self::COPY_NOT_FOUND      => [self::SEVERITY_ERROR, self::KIND_CONFIG],
        self::COPY_FAILED         => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::FOLDER_FAILED       => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::MOVE_FAILED         => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::DOWNLOAD_FAILED     => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::UPLOAD_FAILED       => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::SAVE_FOREIGN_SOURCE => [self::SEVERITY_ERROR, self::KIND_CONFIG],
        self::SAVE_CLOSED         => [self::SEVERITY_WARNING, self::KIND_ITEM],
        self::LOOKUP_FAILED       => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::LOOKUP_MALFORMED    => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::SHARE_FAILED        => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::SHARE_NO_ACCOUNT    => [self::SEVERITY_WARNING, self::KIND_ITEM],
        self::SHARE_PERMISSIONS   => [self::SEVERITY_WARNING, self::KIND_ITEM],
        self::SHARE_TOO_WIDE      => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::SHARE_NO_DOCUMENT   => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::UNSHARE_FAILED      => [self::SEVERITY_WARNING, self::KIND_ITEM],
        self::DELETE_FAILED       => [self::SEVERITY_WARNING, self::KIND_ITEM],
        self::SYSTEM_UNREACHABLE  => [self::SEVERITY_ERROR, self::KIND_SYSTEM],
        self::SYSTEM_UNAUTHORISED => [self::SEVERITY_ERROR, self::KIND_SYSTEM],
        self::SYSTEM_ERROR        => [self::SEVERITY_ERROR, self::KIND_SYSTEM],
        self::SYSTEM_PAUSED       => [self::SEVERITY_INFO, self::KIND_SYSTEM],
        self::CONFIG_MISSING      => [self::SEVERITY_ERROR, self::KIND_CONFIG],
        self::CONFIG_NO_TEMPLATE  => [self::SEVERITY_ERROR, self::KIND_CONFIG],
        self::TASK_NOT_PREPARED   => [self::SEVERITY_WARNING, self::KIND_ITEM],
        self::SUBMIT_NOT_SAVED    => [self::SEVERITY_ERROR, self::KIND_ITEM],
        self::RESTORE_REFUSED     => [self::SEVERITY_WARNING, self::KIND_ITEM],
    ];

    /**
     * Readable description of a code.
     *
     * @param  string $code A code of the catalogue.
     * @return string The translated description, or the code itself if unknown.
     * @throws coding_exception If the language string is missing.
     */
    public static function describe(string $code): string {
        if (!self::exists($code)) {
            return $code;
        }
        return get_string('logcode_' . $code, 'assignsubmission_tipnc');
    }

    /**
     * Severity of a code.
     *
     * @param  string $code A code of the catalogue.
     * @return string One of the SEVERITY_* constants.
     */
    public static function severity(string $code): string {
        return self::CATALOGUE[$code][0] ?? self::SEVERITY_ERROR;
    }

    /**
     * Kind of failure a code represents.
     *
     * @param  string $code A code of the catalogue.
     * @return string One of the KIND_* constants.
     */
    public static function kind(string $code): string {
        return self::CATALOGUE[$code][1] ?? self::KIND_ITEM;
    }

    /**
     * Whether a system failure should pause the retries.
     *
     * @param  string $code A code of the catalogue.
     * @return bool True when the whole batch must stop.
     */
    public static function is_system_failure(string $code): bool {
        return self::kind($code) === self::KIND_SYSTEM;
    }

    /**
     * Whether the code belongs to the catalogue.
     *
     * @param  string $code Code to check.
     * @return bool True when it is known.
     */
    public static function exists(string $code): bool {
        return array_key_exists($code, self::CATALOGUE);
    }

    /**
     * Every code of the catalogue, for the filters of the incident log.
     *
     * @return string[] The codes.
     */
    public static function all(): array {
        return array_keys(self::CATALOGUE);
    }
}
