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
 * Web services of the incident log.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\external;

use assignsubmission_tipnc\models\incidents;
use assignsubmission_tipnc\output\incident_detail;
use assignsubmission_tipnc\output\incident_list;
use coding_exception;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\restricted_context_exception;
use dml_exception;
use invalid_parameter_exception;
use moodle_exception;
use required_capability_exception;

/**
 * Web services of the incident log: filtering, detail and the filter pickers.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class incidents_external extends external_api {
    /**
     * Parameters of the search.
     *
     * @return external_function_parameters The parameters.
     */
    public static function search_parameters(): external_function_parameters {
        return new external_function_parameters([
            'severity' => new external_value(PARAM_ALPHA, 'Severity to filter by', VALUE_DEFAULT, ''),
            'course' => new external_value(PARAM_INT, 'Course to filter by', VALUE_DEFAULT, 0),
            'assign' => new external_value(PARAM_INT, 'Assignment to filter by', VALUE_DEFAULT, 0),
            'user' => new external_value(PARAM_INT, 'User to filter by', VALUE_DEFAULT, 0),
            'range' => new external_value(PARAM_INT, 'Days back to look at, zero for all', VALUE_DEFAULT, 0),
            'page' => new external_value(PARAM_INT, 'Page to read, zero based', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Incidents matching the filters, already rendered.
     *
     * @param  string $severity Severity to filter by.
     * @param  int    $course   Course to filter by.
     * @param  int    $assign   Assignment to filter by.
     * @param  int    $user     User to filter by.
     * @param  int    $range    Days back to look at.
     * @param  int    $page     Page to read.
     * @return array The listing and how many incidents matched.
     * @throws coding_exception If a language string is missing.
     * @throws dml_exception If the query fails.
     * @throws invalid_parameter_exception If a parameter is not valid.
     * @throws moodle_exception If the listing cannot be rendered.
     * @throws required_capability_exception If the user cannot read the log.
     * @throws restricted_context_exception If the context is not allowed.
     */
    public static function search(
        string $severity = '',
        int $course = 0,
        int $assign = 0,
        int $user = 0,
        int $range = 0,
        int $page = 0
    ): array {
        global $PAGE;

        $params = self::validate_parameters(self::search_parameters(), compact(
            'severity',
            'course',
            'assign',
            'user',
            'range',
            'page'
        ));

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('assignsubmission/tipnc:view_errors', $context);

        $filters = self::build_filters($params);

        $model = new incidents();
        $rows = $model->decorate($model->search($filters, $params['page']));
        $total = $model->count($filters);

        $list = new incident_list($rows, $total, $params['page'], incidents::PER_PAGE, true, !empty($filters));

        $PAGE->set_context($context);
        $renderer = $PAGE->get_renderer('assignsubmission_tipnc');

        return [
            'html' => $renderer->render_incident_list($list),
            'total' => $total,
        ];
    }

    /**
     * Return description of the search.
     *
     * @return external_single_structure The structure.
     */
    public static function search_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'The listing, rendered'),
            'total' => new external_value(PARAM_INT, 'Incidents matching the filters'),
        ]);
    }

    /**
     * Parameters of the detail.
     *
     * @return external_function_parameters The parameters.
     */
    public static function detail_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Incident to read'),
        ]);
    }

    /**
     * One incident with its full trace, already rendered.
     *
     * @param  int $id Incident to read.
     * @return array The detail.
     * @throws coding_exception If a language string is missing.
     * @throws dml_exception If the incident does not exist.
     * @throws invalid_parameter_exception If a parameter is not valid.
     * @throws moodle_exception If the detail cannot be rendered.
     * @throws required_capability_exception If the user cannot read the log.
     * @throws restricted_context_exception If the context is not allowed.
     */
    public static function detail(int $id): array {
        global $PAGE, $CFG;

        $params = self::validate_parameters(self::detail_parameters(), ['id' => $id]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('assignsubmission/tipnc:view_errors', $context);

        $model = new incidents();
        $incident = $model->get($params['id']);
        $trace = $model->trace($incident->traceid);

        $decorated = $model->decorate([$incident->id => $incident]);

        // The service resolves the environment: the view reads no configuration.
        $environment = [
            'moodle' => $CFG->release,
            'plugin' => get_config('assignsubmission_tipnc', 'version'),
            'ncurl' => get_config('assignsubmission_tipnc', 'url'),
        ];

        $detail = new incident_detail(reset($decorated), $trace, $environment);

        $PAGE->set_context($context);
        $renderer = $PAGE->get_renderer('assignsubmission_tipnc');

        return ['html' => $renderer->render_incident_detail($detail)];
    }

    /**
     * Return description of the detail.
     *
     * @return external_single_structure The structure.
     */
    public static function detail_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'The detail, rendered'),
        ]);
    }

    /**
     * Parameters of the purge.
     *
     * @return external_function_parameters The parameters.
     */
    public static function purge_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Empties the incident log.
     *
     * @return array How many incidents were deleted.
     * @throws dml_exception If the deletion fails.
     * @throws invalid_parameter_exception If a parameter is not valid.
     * @throws required_capability_exception If the user cannot read the log.
     * @throws restricted_context_exception If the context is not allowed.
     */
    public static function purge(): array {
        self::validate_parameters(self::purge_parameters(), []);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('assignsubmission/tipnc:view_errors', $context);
        require_sesskey();

        return ['deleted' => (new incidents())->purge(time())];
    }

    /**
     * Return description of the purge.
     *
     * @return external_single_structure The structure.
     */
    public static function purge_returns(): external_single_structure {
        return new external_single_structure([
            'deleted' => new external_value(PARAM_INT, 'Incidents deleted'),
        ]);
    }

    /**
     * Parameters of the filter pickers.
     *
     * @return external_function_parameters The parameters.
     */
    public static function options_parameters(): external_function_parameters {
        return new external_function_parameters([
            'type' => new external_value(PARAM_ALPHA, 'One of: course, assign, user'),
            'query' => new external_value(PARAM_TEXT, 'What the user typed', VALUE_DEFAULT, ''),
            'course' => new external_value(PARAM_INT, 'Course the search is limited to', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Entities matching what the user typed in a filter.
     *
     * @param  string $type   One of: course, assign, user.
     * @param  string $query  What the user typed.
     * @param  int    $course Course the search is limited to.
     * @return array The matches.
     * @throws dml_exception If the query fails.
     * @throws invalid_parameter_exception If a parameter is not valid.
     * @throws required_capability_exception If the user cannot read the log.
     * @throws restricted_context_exception If the context is not allowed.
     */
    public static function options(string $type, string $query = '', int $course = 0): array {
        $params = self::validate_parameters(self::options_parameters(), compact('type', 'query', 'course'));

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('assignsubmission/tipnc:view_errors', $context);

        return (new incidents())->options($params['type'], $params['query'], $params['course']);
    }

    /**
     * Return description of the filter pickers.
     *
     * @return external_multiple_structure The structure.
     */
    public static function options_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'value' => new external_value(PARAM_INT, 'Identifier of the entity'),
                'label' => new external_value(PARAM_TEXT, 'Name shown to the user'),
            ])
        );
    }

    /**
     * Turns the parameters of a call into the filters of the model.
     *
     * @param  array $params The validated parameters.
     * @return array The filters, without the empty ones.
     */
    private static function build_filters(array $params): array {
        $filters = [
            'severity' => $params['severity'],
            'course' => $params['course'],
            'assignment' => $params['assign'],
            'userid' => $params['user'],
        ];

        if (!empty($params['range'])) {
            $filters['since'] = time() - ($params['range'] * DAYSECS);
        }

        return array_filter($filters);
    }
}
