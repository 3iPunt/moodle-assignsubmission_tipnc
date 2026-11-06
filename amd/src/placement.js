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
 * Moves the brief to the place the site chose.
 *
 * Only used when the site picks a custom destination: the brief is printed at the
 * end of the main region and travels from there, so a wrong selector leaves it
 * where it was instead of losing it.
 *
 * @module     assignsubmission_tipnc/placement
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    block: '[data-region="tipnc-enunciate"]',
};

/**
 * Moves the brief into the given destination.
 *
 * @param {String} selector CSS selector of the destination, from the site settings.
 */
export const init = (selector) => {
    const block = document.querySelector(SELECTORS.block);
    if (!block || !selector) {
        return;
    }

    let destination;
    try {
        destination = document.querySelector(selector);
    } catch (error) {
        // A mistyped selector leaves the brief where it is.
        window.console.warn('assignsubmission_tipnc: invalid destination', selector);
        return;
    }

    if (destination && destination !== block && !destination.contains(block)) {
        destination.appendChild(block);
    }
};
