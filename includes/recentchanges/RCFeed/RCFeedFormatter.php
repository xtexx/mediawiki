<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\RCFeed;

use MediaWiki\RecentChanges\RecentChange;

/**
 * Interface for RC feed formatters
 *
 * @stable to implement
 * @since 1.22
 * @ingroup RecentChanges
 */
interface RCFeedFormatter {
	/**
	 * Formats the line to be sent by an engine
	 *
	 * @param array $feed The feed, as configured in an associative array.
	 * @param RecentChange $rc The RecentChange object showing what sort
	 *                         of event has taken place.
	 * @param string|null $actionComment
	 * @return string|null The text to send.  If the formatter returns null,
	 *  the line will not be sent.
	 */
	public function getLine( array $feed, RecentChange $rc, $actionComment );
}
/** @deprecated class alias since 1.43 */
class_alias( RCFeedFormatter::class, 'RCFeedFormatter' );
