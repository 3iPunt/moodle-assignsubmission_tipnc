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
 * Incident log screen.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_tipnc\output;

use assignsubmission_tipnc\log\code;
use coding_exception;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Incident log screen: what is happening, who it affects and what to do about it.
 *
 * @package    assignsubmission_tipnc
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class incident_log_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param incident_list $list        The listing, which the filters repaint on its own.
     * @param array         $counters    Counters of the header.
     * @param stdClass|null $commoncause The dominant failure, when there is one.
     * @param stdClass      $connection  State of the connection with NextCloud.
     * @param array         $filters     Filters currently applied.
     * @param array         $filternames Names of the entities picked in the filters.
     */
    public function __construct(
        /** @var incident_list The listing, which the filters repaint on its own. */
        private readonly incident_list $list,
        /** @var array Counters of the header. */
        private readonly array $counters,
        /** @var ?stdClass The dominant failure, when there is one. */
        private readonly ?stdClass $commoncause,
        /** @var stdClass State of the connection with NextCloud. */
        private readonly stdClass $connection,
        /** @var array Filters currently applied. */
        private readonly array $filters = [],
        /** @var array Names of the entities picked in the filters. */
        private readonly array $filternames = []
    ) {
    }

    /**
     * Export for template.
     *
     * @param  renderer_base $output The renderer.
     * @return array The data of the screen.
     * @throws coding_exception If a language string is missing.
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'logourl' => $output->image_url('tresipunt_logo', 'assignsubmission_tipnc')->out(false),
            'logourlwhite' => $output->image_url('tresipunt_logo_white', 'assignsubmission_tipnc')->out(false),
            'connection' => $this->export_connection(),
            'counters' => [
                'lastday' => $this->counters['lastday'] ?? 0,
                'assignments' => $this->counters['assignments'] ?? 0,
                'users' => $this->counters['users'] ?? 0,
                'total' => $this->counters['total'] ?? 0,
                'oldest' => empty($this->counters['oldest'])
                    ? ''
                    : userdate($this->counters['oldest'], get_string('log_dateformat', 'assignsubmission_tipnc')),
            ],
            'severities' => $this->export_severities(),
            'ranges' => $this->export_ranges(),
            'course' => $this->export_selected('course'),
            'assign' => $this->export_selected('assign'),
            'user' => $this->export_selected('user'),
            'isfiltered' => !empty($this->filters),
            'diagnosis' => $this->export_diagnosis(),
            'list' => $this->list->export_for_template($output),
            'settingsurl' => (new moodle_url(
                '/admin/settings.php',
                ['section' => 'assignsubmission_tipnc']
            ))->out(false),
            // The export honours the applied filters and carries a sesskey: it is a
            // data download, not a public page.
            'exporturl' => (new moodle_url(
                '/mod/assign/submission/tipnc/export.php',
                array_merge($this->filters, ['sesskey' => sesskey()])
            ))->out(false),
        ];
    }

    /**
     * The severity filter, as a group of options.
     *
     * @return array The options, with the active one marked.
     * @throws coding_exception If a language string is missing.
     */
    private function export_severities(): array {
        $current = $this->filters['severity'] ?? '';
        $options = [
            ['value' => '', 'label' => get_string('log_filter_all', 'assignsubmission_tipnc')],
            ['value' => code::SEVERITY_ERROR, 'label' => get_string('severity_error', 'assignsubmission_tipnc')],
            ['value' => code::SEVERITY_WARNING, 'label' => get_string('severity_warning', 'assignsubmission_tipnc')],
            ['value' => code::SEVERITY_INFO, 'label' => get_string('severity_info', 'assignsubmission_tipnc')],
        ];

        foreach ($options as &$option) {
            $option['active'] = $option['value'] === $current;
        }

        return $options;
    }

    /**
     * The date filter, as a group of options.
     *
     * @return array The options, with the active one marked.
     * @throws coding_exception If a language string is missing.
     */
    private function export_ranges(): array {
        $current = (int) ($this->filters['range'] ?? 0);
        $options = [
            ['value' => 1, 'label' => get_string('log_range_today', 'assignsubmission_tipnc')],
            ['value' => 7, 'label' => get_string('log_range_week', 'assignsubmission_tipnc')],
            ['value' => 30, 'label' => get_string('log_range_month', 'assignsubmission_tipnc')],
            ['value' => 0, 'label' => get_string('log_range_all', 'assignsubmission_tipnc')],
        ];

        foreach ($options as &$option) {
            $option['active'] = $option['value'] === $current;
        }

        return $options;
    }

    /**
     * The entity picked in one of the autocompleted filters.
     *
     * @param  string $type One of: course, assign, user.
     * @return array The chosen value and its name, empty when there is none.
     */
    private function export_selected(string $type): array {
        $id = (int) ($this->filters[$type] ?? 0);
        if ($id === 0) {
            return ['id' => 0, 'name' => ''];
        }

        return ['id' => $id, 'name' => (string) ($this->filternames[$type] ?? '')];
    }

    /**
     * State of the connection, which answers "is this happening now?".
     *
     * @return array The state and its message.
     * @throws coding_exception If a language string is missing.
     */
    private function export_connection(): array {
        if (!$this->connection->configured) {
            return [
                'state' => 'unset',
                'label' => get_string('conn_unset', 'assignsubmission_tipnc'),
                'detail' => get_string('conn_unset_detail', 'assignsubmission_tipnc'),
            ];
        }

        if ($this->connection->ok) {
            return [
                'state' => 'ok',
                'label' => get_string('conn_ok', 'assignsubmission_tipnc'),
                'detail' => get_string('conn_ok_detail', 'assignsubmission_tipnc'),
            ];
        }

        return [
            'state' => 'down',
            'label' => get_string('conn_down', 'assignsubmission_tipnc'),
            'detail' => $this->connection->detail,
        ];
    }

    /**
     * The dominant failure turned into one actionable sentence.
     *
     * @return array|null The diagnosis, or null when there is no clear pattern.
     * @throws coding_exception If a language string is missing.
     */
    private function export_diagnosis(): ?array {
        if ($this->commoncause === null) {
            return null;
        }

        return [
            'text' => get_string('log_commoncause', 'assignsubmission_tipnc', (object) [
                'occurrences' => $this->commoncause->occurrences,
                'total' => $this->commoncause->total,
                'reason' => code::describe($this->commoncause->errorcode),
            ]),
            'repairable' => $this->commoncause->repairable,
            'assignments' => $this->commoncause->assignments,
        ];
    }
}
