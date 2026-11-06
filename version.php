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
 * Version information of the NextCloud submission plugin.
 *
 * @package     assignsubmission_tipnc
 * @copyright   2021-2026 Tresipunt <contacte@tresipunt.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026110500;                    // Plugin version (format YYYYMMDDXX).
$plugin->requires  = 2024100700;                    // Minimum Moodle version: 4.5.
$plugin->supported = [405, 501];                    // Supported Moodle branches: 4.5 to 5.1.
$plugin->component = 'assignsubmission_tipnc';      // Full Frankenstyle plugin name.
$plugin->maturity  = MATURITY_STABLE;               // Production-ready release.
$plugin->release   = '2.1.0';                       // Semantic version (Major.Minor.Patch).
