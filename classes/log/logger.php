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
 * Writes the incident log.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\log;

use core_text;
use dml_exception;
use stdClass;

/**
 * Writes the incident log: groups repetitions and never stores credentials.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logger {
    /** @var string Table holding the incidents. */
    public const TABLE = 'assignsubmission_tipnc_log';

    /** @var int Maximum size kept of a response body, in bytes. */
    private const BODY_LIMIT = 2048;

    /** @var string Placeholder written in place of anything secret. */
    private const MASK = '***';

    /** @var string|null Identifier shared by every entry of the current action. */
    private static ?string $traceid = null;

    /**
     * Opens a new trace, so the calls of one user action can be read together.
     *
     * @return string The new trace identifier.
     */
    public static function start_trace(): string {
        self::$traceid = substr(md5(uniqid('', true)), 0, 32);
        return self::$traceid;
    }

    /**
     * Identifier of the current trace, opening one if there is none.
     *
     * @return string The trace identifier.
     */
    public static function traceid(): string {
        return self::$traceid ?? self::start_trace();
    }

    /**
     * Records a failure that stops the user.
     *
     * @param  string $code      A code of the catalogue.
     * @param  string $operation Operation in domain language.
     * @param  array  $context   Any of the context fields of the table.
     * @return int The id of the entry, new or grouped.
     * @throws dml_exception If the entry cannot be stored.
     */
    public static function error(string $code, string $operation, array $context = []): int {
        return self::record(code::SEVERITY_ERROR, $code, $operation, $context);
    }

    /**
     * Records a failure that degrades the service without stopping it.
     *
     * @param  string $code      A code of the catalogue.
     * @param  string $operation Operation in domain language.
     * @param  array  $context   Any of the context fields of the table.
     * @return int The id of the entry, new or grouped.
     * @throws dml_exception If the entry cannot be stored.
     */
    public static function warning(string $code, string $operation, array $context = []): int {
        return self::record(code::SEVERITY_WARNING, $code, $operation, $context);
    }

    /**
     * Records a completed operation, so the history of a document can be rebuilt.
     *
     * @param  string $code      A code of the catalogue.
     * @param  string $operation Operation in domain language.
     * @param  array  $context   Any of the context fields of the table.
     * @return int The id of the entry, new or grouped.
     * @throws dml_exception If the entry cannot be stored.
     */
    public static function info(string $code, string $operation, array $context = []): int {
        return self::record(code::SEVERITY_INFO, $code, $operation, $context);
    }

    /**
     * Records an entry, grouping it with an equivalent one when there is any.
     *
     * @param  string $severity  One of the SEVERITY_* constants.
     * @param  string $code      A code of the catalogue.
     * @param  string $operation Operation in domain language.
     * @param  array  $context   Any of the context fields of the table.
     * @return int The id of the entry, new or grouped.
     * @throws dml_exception If the entry cannot be stored.
     */
    public static function record(string $severity, string $code, string $operation, array $context = []): int {
        global $DB, $USER;

        $now = time();
        $entry = new stdClass();
        $entry->severity = $severity;
        $entry->traceid = self::traceid();
        $entry->operation = $operation;
        $entry->method = (string) ($context['method'] ?? '');
        $entry->errorcode = $code;
        $entry->courseid = self::int_or_null($context['courseid'] ?? null);
        $entry->cmid = self::int_or_null($context['cmid'] ?? null);
        $entry->assignment = self::int_or_null($context['assignment'] ?? null);
        $entry->submission = self::int_or_null($context['submission'] ?? null);
        $entry->userid = self::int_or_null($context['userid'] ?? ($USER->id ?? null));
        $entry->affecteduserid = self::int_or_null($context['affecteduserid'] ?? null);
        $entry->httpmethod = isset($context['httpmethod']) ? (string) $context['httpmethod'] : null;
        $entry->requesturl = isset($context['requesturl']) ? self::mask((string) $context['requesturl'], 1333) : null;
        $entry->httpcode = self::int_or_null($context['httpcode'] ?? null);
        $entry->duration = self::int_or_null($context['duration'] ?? null);
        $entry->documentpath = isset($context['documentpath']) ? (string) $context['documentpath'] : null;
        $entry->responsebody = isset($context['responsebody'])
            ? self::mask((string) $context['responsebody'], self::BODY_LIMIT)
            : null;
        $entry->occurrences = 1;
        $entry->firstseen = $now;
        $entry->lastseen = $now;
        $entry->timecreated = $now;

        // Successes are not grouped: each one is a distinct fact in the history
        // of a document. Grouping makes sense when something fails over and over.
        $existing = $severity === code::SEVERITY_INFO ? false : self::find_equivalent($entry);

        if ($existing) {
            $existing->occurrences++;
            $existing->lastseen = $now;
            // Only the data the repetition brings is refreshed: a call with no
            // response body must not wipe the diagnosis already recorded.
            foreach (['httpcode', 'responsebody', 'duration', 'requesturl', 'httpmethod', 'documentpath'] as $field) {
                if ($entry->$field !== null) {
                    $existing->$field = $entry->$field;
                }
            }
            $DB->update_record(self::TABLE, $existing);
            return (int) $existing->id;
        }

        return (int) $DB->insert_record(self::TABLE, $entry);
    }

    /**
     * Looks for an entry describing the same problem, to group them.
     *
     * @param  stdClass $entry The entry about to be stored.
     * @return stdClass|false The equivalent entry, or false when there is none.
     * @throws dml_exception If the query fails.
     */
    private static function find_equivalent(stdClass $entry) {
        global $DB;

        $conditions = [
            'errorcode' => $entry->errorcode,
            'method' => $entry->method,
            'severity' => $entry->severity,
            'assignment' => $entry->assignment,
            'submission' => $entry->submission,
            'userid' => $entry->userid,
            'affecteduserid' => $entry->affecteduserid,
        ];

        $records = $DB->get_records(self::TABLE, $conditions, 'lastseen DESC', '*', 0, 1);

        return $records ? reset($records) : false;
    }

    /**
     * Removes anything secret and trims the text to the size the column takes.
     *
     * @param  string $text  Text to store.
     * @param  int    $limit Maximum length kept.
     * @return string The masked text.
     * @throws dml_exception If the plugin configuration cannot be read.
     */
    private static function mask(string $text, int $limit): string {
        // Credentials inside the URL itself.
        $text = preg_replace('#(://)[^/@\s]+:[^/@\s]+@#', '$1' . self::MASK . '@', $text) ?? $text;

        // Authentication headers that may come in the body or in the trace.
        $text = preg_replace('#(Authorization:\s*\w+\s+)\S+#i', '$1' . self::MASK, $text) ?? $text;

        // The service password, should the server echo it back in a message.
        $password = get_config('assignsubmission_tipnc', 'password');
        if (!empty($password)) {
            $text = str_replace($password, self::MASK, $text);
        }

        return core_text::strlen($text) > $limit ? core_text::substr($text, 0, $limit) : $text;
    }

    /**
     * Normalises a context value that must be stored as a nullable integer.
     *
     * @param  mixed $value Raw value from the context.
     * @return int|null The integer, or null when there is no value.
     */
    private static function int_or_null($value): ?int {
        return ($value === null || $value === '' || $value === 0) ? null : (int) $value;
    }
}
