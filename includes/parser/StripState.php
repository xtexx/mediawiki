<?php
/**
 * Holder for stripped items when parsing wiki markup.
 *
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
 * @ingroup Parser
 */

namespace MediaWiki\Parser;

use Closure;
use InvalidArgumentException;

/**
 * @todo document, briefly.
 * @newable
 * @ingroup Parser
 */
class StripState {
	/** @var array[] */
	protected $data;
	/** @var string */
	protected $regex;

	protected ?Parser $parser;

	/** @var array */
	protected $circularRefGuard;
	/** @var int */
	protected $depth = 0;
	/** @var int */
	protected $highestDepth = 0;
	/** @var int */
	protected $expandSize = 0;

	/** @var int */
	protected $depthLimit = 20;
	/** @var int */
	protected $sizeLimit = 5_000_000;

	/**
	 * @stable to call
	 *
	 * @param Parser|null $parser
	 * @param array $options
	 */
	public function __construct( ?Parser $parser = null, $options = [] ) {
		$this->data = [
			'nowiki' => [],
			'general' => []
		];
		$this->regex = '/' . Parser::MARKER_PREFIX . "([^\x7f<>&'\"]+)" . Parser::MARKER_SUFFIX . '/';
		$this->circularRefGuard = [];
		$this->parser = $parser;

		if ( isset( $options['depthLimit'] ) ) {
			$this->depthLimit = $options['depthLimit'];
		}
		if ( isset( $options['sizeLimit'] ) ) {
			$this->sizeLimit = $options['sizeLimit'];
		}
	}

	/**
	 * Add a nowiki strip item
	 * @param string $marker
	 * @param string|Closure $value
	 */
	public function addNoWiki( $marker, $value ) {
		$this->addItem( 'nowiki', $marker, $value );
	}

	/**
	 * @param string $marker
	 * @param string|Closure $value
	 */
	public function addGeneral( $marker, $value ) {
		$this->addItem( 'general', $marker, $value );
	}

	/**
	 * @param string $marker
	 * @param string|Closure $value
	 * @since 1.44
	 * @internal Parsoid use only.
	 */
	public function addExtTag( $marker, $value ) {
		$this->addItem( 'exttag', $marker, $value );
	}

	/**
	 * @param string $type
	 * @param-taint $type none
	 * @param string $marker
	 * @param-taint $marker none
	 * @param string|Closure $value
	 * @param-taint $value exec_html
	 */
	protected function addItem( $type, $marker, $value ) {
		if ( !preg_match( $this->regex, $marker, $m ) ) {
			throw new InvalidArgumentException( "Invalid marker: $marker" );
		}

		$this->data[$type][$m[1]] = $value;
	}

	/**
	 * @param string $text
	 * @return mixed
	 */
	public function unstripGeneral( $text ) {
		return $this->unstripType( 'general', $text );
	}

	/**
	 * @param string $text
	 * @return mixed
	 */
	public function unstripNoWiki( $text ) {
		return $this->unstripType( 'nowiki', $text );
	}

	/**
	 * @param string $text
	 * @param callable $callback
	 * @return string
	 */
	public function replaceNoWikis( string $text, callable $callback ): string {
		// Shortcut
		if ( !count( $this->data['nowiki'] ) ) {
			return $text;
		}

		$callback = function ( $m ) use ( $callback ) {
			$marker = $m[1];
			if ( isset( $this->data['nowiki'][$marker] ) ) {
				$value = $this->data['nowiki'][$marker];
				if ( $value instanceof Closure ) {
					$value = $value();
				}

				$this->expandSize += strlen( $value );
				if ( $this->expandSize > $this->sizeLimit ) {
					return $this->getLimitationWarning( 'unstrip-size', $this->sizeLimit );
				}

				return $callback( $value );
			} else {
				return $m[0];
			}
		};

		return preg_replace_callback( $this->regex, $callback, $text );
	}

	/**
	 * Split the given text by strip markers, returning an array that
	 * alternates between plain text and strip marker information.  The
	 * strip marker information includes 'type', and 'content'.  The
	 * resulting array will always be at least 1 element long and contain
	 * an odd number of elements.
	 * @return array<string|array{type:string,content:string}>
	 */
	public function split( string $text ): array {
		$pieces = preg_split( $this->regex, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		for ( $i = 1; $i < count( $pieces ); $i += 2 ) {
			$marker = $pieces[$i];
			foreach ( $this->data as $type => $items ) {
				if ( isset( $items[$marker] ) ) {
					$pieces[$i] = [
						'type' => $type,
						'content' => $items[$marker],
					];
					continue 2;
				}
			}
			$pieces[$i] = [
				'marker' => $marker,
				'type' => 'unknown',
				'content' => null,
			];
		}
		return $pieces;
	}

	/**
	 * @param string $text
	 * @return mixed
	 */
	public function unstripBoth( $text ) {
		$text = $this->unstripType( 'general', $text );
		$text = $this->unstripType( 'nowiki', $text );
		return $text;
	}

	/**
	 * @param string $type
	 * @param string $text
	 * @return mixed
	 */
	protected function unstripType( $type, $text ) {
		// Shortcut
		if ( !count( $this->data[$type] ) ) {
			return $text;
		}

		$callback = function ( $m ) use ( $type ) {
			$marker = $m[1];
			if ( isset( $this->data[$type][$marker] ) ) {
				if ( isset( $this->circularRefGuard[$marker] ) ) {
					return $this->getWarning( 'parser-unstrip-loop-warning' );
				}

				if ( $this->depth > $this->highestDepth ) {
					$this->highestDepth = $this->depth;
				}
				if ( $this->depth >= $this->depthLimit ) {
					return $this->getLimitationWarning( 'unstrip-depth', $this->depthLimit );
				}

				$value = $this->data[$type][$marker];
				if ( $value instanceof Closure ) {
					$value = $value();
				}

				$this->expandSize += strlen( $value );
				if ( $this->expandSize > $this->sizeLimit ) {
					return $this->getLimitationWarning( 'unstrip-size', $this->sizeLimit );
				}

				$this->circularRefGuard[$marker] = true;
				$this->depth++;
				$ret = $this->unstripType( $type, $value );
				$this->depth--;
				unset( $this->circularRefGuard[$marker] );

				return $ret;
			} else {
				return $m[0];
			}
		};

		$text = preg_replace_callback( $this->regex, $callback, $text );
		return $text;
	}

	/**
	 * Get warning HTML and register a limitation warning with the parser
	 *
	 * @param string $type
	 * @param int|string $max
	 * @return string
	 */
	private function getLimitationWarning( $type, $max = '' ) {
		if ( $this->parser ) {
			$this->parser->limitationWarn( $type, $max );
		}
		return $this->getWarning( "$type-warning", $max );
	}

	/**
	 * Get warning HTML
	 *
	 * @param string $message
	 * @param int|string $max
	 * @return string
	 */
	private function getWarning( $message, $max = '' ) {
		return '<span class="error">' .
			wfMessage( $message )
				->numParams( $max )->inContentLanguage()->text() .
			'</span>';
	}

	/**
	 * Get an array of parameters to pass to ParserOutput::setLimitReportData()
	 *
	 * @internal Should only be called by Parser
	 * @return array
	 */
	public function getLimitReport() {
		return [
			[ 'limitreport-unstrip-depth',
				[
					$this->highestDepth,
					$this->depthLimit
				],
			],
			[ 'limitreport-unstrip-size',
				[
					$this->expandSize,
					$this->sizeLimit
				],
			]
		];
	}

	/**
	 * Remove any strip markers found in the given text.
	 *
	 * @param string $text
	 * @return string
	 */
	public function killMarkers( $text ) {
		return preg_replace( $this->regex, '', $text );
	}
}

/** @deprecated class alias since 1.43 */
class_alias( StripState::class, 'StripState' );
