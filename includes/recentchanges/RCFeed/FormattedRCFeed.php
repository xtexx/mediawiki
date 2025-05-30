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
 * Base class for RCFeed implementations that use RCFeedFormatter.
 *
 * Parameters:
 *  - formatter: [required] Which RCFeedFormatter class to use.
 *
 * @see $wgRCFeeds
 * @since 1.29
 * @ingroup RecentChanges
 */
abstract class FormattedRCFeed extends RCFeed {
	private array $params;

	public function __construct( array $params ) {
		$this->params = $params;
	}

	/**
	 * Send some text to the specified feed.
	 *
	 * @param array $feed The feed, as configured in an associative array
	 * @param string $line The text to send
	 * @return bool Success
	 */
	abstract public function send( array $feed, $line );

	/**
	 * @param RecentChange $rc
	 * @param string|null $actionComment
	 * @return bool Success
	 */
	public function notify( RecentChange $rc, $actionComment = null ) {
		$params = $this->params;
		/** @var RCFeedFormatter $formatter */
		$formatter = is_object( $params['formatter'] ) ? $params['formatter'] : new $params['formatter'];

		$line = $formatter->getLine( $params, $rc, $actionComment );
		if ( !$line ) {
			// @codeCoverageIgnoreStart
			// T109544 - If a feed formatter returns null, this will otherwise cause an
			// error in at least RedisPubSubFeedEngine. Not sure best to handle this.
			// @phan-suppress-next-line PhanTypeMismatchReturnProbablyReal
			return;
			// @codeCoverageIgnoreEnd
		}
		return $this->send( $params, $line );
	}
}
/** @deprecated class alias since 1.43 */
class_alias( FormattedRCFeed::class, 'FormattedRCFeed' );
