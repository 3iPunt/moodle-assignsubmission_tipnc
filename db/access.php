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
 * Capability definitions for this module.
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021 Tresipunt
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$capabilities = [

    // The incident log shows document paths and account names from across the
    // whole site, so out of the box only administrators see it.
    //
    // The other archetypes are not declared: not having it is not having it,
    // and a site that wants to give it to its teachers can do so by override.
    // CAP_PROHIBIT would prevent that for ever, which is more than is needed
    // here —it is meant for what must never be allowed—.
    'assignsubmission/tipnc:view_errors' => [
        // Personal data from the whole site, and the same capability exports and purges it.
        'riskbitmask' => RISK_PERSONAL | RISK_DATALOSS,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
