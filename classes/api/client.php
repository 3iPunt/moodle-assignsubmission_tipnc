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
 * Single entry point for every call made to NextCloud.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\api;

use assignsubmission_tipnc\log\code;
use assignsubmission_tipnc\log\logger;
use assignsubmission_tipnc\models\health;
use core\http_client;
use dml_exception;
use Throwable;

/**
 * Single entry point for every call made to NextCloud: every request is timed,
 * judged by its status code and written to the incident log.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var int Seconds a call may take before giving up. */
    private const TIMEOUT = 30;

    /** @var int Seconds to wait for the connection itself. */
    private const CONNECT_TIMEOUT = 10;

    /** @var string Host used by the server to reach NextCloud. */
    private string $host;

    /** @var string Service account. */
    private string $user;

    /** @var string Service account password. */
    private string $password;

    /**
     * Constructor.
     *
     * @throws dml_exception If the plugin configuration cannot be read.
     */
    public function __construct() {
        $this->host = rtrim((string) get_config('assignsubmission_tipnc', 'host'), '/');
        $this->user = (string) get_config('assignsubmission_tipnc', 'user');
        $this->password = (string) get_config('assignsubmission_tipnc', 'password');
    }

    /**
     * Whether the plugin has the details it needs to talk to NextCloud.
     *
     * @return bool True when host, user and password are set.
     */
    public function is_configured(): bool {
        return $this->host !== '' && $this->user !== '' && $this->password !== '';
    }

    /**
     * Host the server uses to reach NextCloud, without a trailing slash.
     *
     * @return string The base URL.
     */
    public function base_url(): string {
        return $this->host;
    }

    /**
     * Makes a call and records it.
     *
     * @param  string $method  HTTP verb, including the WebDAV ones.
     * @param  string $path    Path appended to the host.
     * @param  array  $options Any of: headers, body, form, context.
     * @return http_response The answer.
     * @throws dml_exception If the incident cannot be recorded.
     */
    public function request(string $method, string $path, array $options = []): http_response {
        $url = $this->host . '/' . ltrim($path, '/');
        $context = $options['context'] ?? [];

        // Without address, account or password nothing is called: it is logged as configuration.
        if (!$this->is_configured()) {
            $answer = new http_response(0, '', 0, get_string('log_not_configured', 'assignsubmission_tipnc'));

            if (($options['log'] ?? true) !== false) {
                logger::record(
                    code::severity(code::CONFIG_MISSING),
                    code::CONFIG_MISSING,
                    (string) ($context['operation'] ?? ''),
                    array_merge($context, [
                        'httpmethod' => $method,
                        'requesturl' => $path,
                    ])
                );
            }

            return $answer;
        }

        $answer = $this->send(
            $method,
            $url,
            $options['headers'] ?? [],
            $options['body'] ?? null,
            $options['form'] ?? null
        );

        // The health check is not logged: it runs on every load of the screen
        // and would fill with noise the very log being looked at.
        if (($options['log'] ?? true) !== false) {
            $this->record($method, $url, $answer, $context);
        }

        return $answer;
    }

    /**
     * Performs the call through the core client, which honours the proxy and the
     * outgoing request restrictions of the site.
     *
     * @param  string      $method  HTTP verb.
     * @param  string      $url     Absolute URL.
     * @param  string[]    $headers Headers to send.
     * @param  string|null $body    Raw request body.
     * @param  array|null  $form    Form fields; the client builds the body and its header.
     * @return http_response The answer.
     */
    private function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?array $form = null
    ): http_response {
        $started = microtime(true);

        try {
            $client = new http_client([
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                // TLS verification is deliberately left intact: these calls
                // carry the service account credentials.
                'verify' => true,
                'http_errors' => false,
            ]);

            $request = [
                'headers' => $headers,
                'auth' => [$this->user, $this->password],
            ];
            if ($body !== null) {
                $request['body'] = $body;
            }
            if ($form !== null) {
                $request['form_params'] = $form;
            }

            $answer = $client->request($method, $url, $request);
            $duration = (int) round((microtime(true) - $started) * 1000);

            return new http_response($answer->getStatusCode(), (string) $answer->getBody(), $duration);
        } catch (Throwable $e) {
            $duration = (int) round((microtime(true) - $started) * 1000);
            return new http_response(0, '', $duration, $e->getMessage());
        }
    }

    /**
     * Writes the call to the incident log.
     *
     * @param  string   $method   HTTP verb.
     * @param  string   $url      Absolute URL.
     * @param  http_response $answer   The answer.
     * @param  array    $context  Context given by the caller.
     * @return void
     * @throws dml_exception If the incident cannot be recorded.
     */
    private function record(string $method, string $url, http_response $answer, array $context): void {
        $context['httpmethod'] = $method;
        $context['requesturl'] = $url;
        $context['httpcode'] = $answer->httpcode;
        $context['duration'] = $answer->duration;
        $context['responsebody'] = $answer->transport !== '' ? $answer->transport : $answer->body;

        $operation = (string) ($context['operation'] ?? '');
        $expected = (string) ($context['errorcode'] ?? '');
        $bystatus = (array) ($context['errorcodes'] ?? []);
        unset($context['operation'], $context['errorcode'], $context['errorcodes']);

        if ($answer->is_success()) {
            health::note(code::OK);
            logger::info(code::OK, $operation, $context);
            return;
        }

        // What a rejection means depends on the operation: a 404 when sharing
        // is not the same as a 404 when copying.
        $errorcode = $bystatus[$answer->httpcode] ?? $this->classify($answer, $expected);

        // Before logging it: if the service has gone down, the pages have to be
        // able to say so, and this is the only real call there is going to be.
        health::note($errorcode);

        logger::record(code::severity($errorcode), $errorcode, $operation, $context);
    }

    /**
     * Turns an answer into a code of the catalogue.
     *
     * The distinction that matters is system failure versus item failure: the first
     * one stops the batch, the second one only marks its own document.
     *
     * @param  http_response $answer   The answer.
     * @param  string   $expected Code the caller expects for a plain rejection.
     * @return string A code of the catalogue.
     */
    private function classify(http_response $answer, string $expected): string {
        if ($answer->is_unreachable()) {
            return code::SYSTEM_UNREACHABLE;
        }
        if ($answer->is_unauthorised()) {
            return code::SYSTEM_UNAUTHORISED;
        }
        if ($answer->is_server_error()) {
            return code::SYSTEM_ERROR;
        }
        return $expected !== '' ? $expected : code::COPY_FAILED;
    }
}
