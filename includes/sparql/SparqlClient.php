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

namespace MediaWiki\Sparql;

use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\AtEase\AtEase;

/**
 * Simple SPARQL client
 *
 * @author Stas Malyshev
 */
class SparqlClient {

	/**
	 * Limit on how long can be the query to be sent by GET.
	 */
	public const MAX_GET_SIZE = 2048;

	/**
	 * User agent for HTTP requests.
	 */
	private string $userAgent;

	/**
	 * Query timeout (seconds)
	 */
	private int $timeout = 30;

	/**
	 * SPARQL endpoint URL
	 */
	private string $endpoint;

	/**
	 * Client options
	 */
	private array $options = [];

	private HttpRequestFactory $requestFactory;

	/**
	 * @param string $url SPARQL Endpoint
	 * @param HttpRequestFactory $requestFactory
	 */
	public function __construct( string $url, HttpRequestFactory $requestFactory ) {
		$this->endpoint = $url;
		$this->requestFactory = $requestFactory;
		$this->userAgent = $requestFactory->getUserAgent() . " SparqlClient";
	}

	/**
	 * Set query timeout (in seconds)
	 * @param int $timeout
	 * @return $this
	 */
	public function setTimeout( int $timeout ): SparqlClient {
		if ( $timeout >= 0 ) {
			$this->timeout = $timeout;
		}
		return $this;
	}

	/**
	 * @param array $options
	 * @return $this
	 */
	public function setClientOptions( array $options ): SparqlClient {
		$this->options = $options;
		return $this;
	}

	/**
	 * Get current user agent.
	 */
	public function getUserAgent(): string {
		return $this->userAgent;
	}

	/**
	 * Note it is not recommended to completely override user agent for
	 * most applications.
	 * @see appendUserAgent() for recommended way of specifying user agent.
	 *
	 * @return $this
	 */
	public function setUserAgent( string $agent ): SparqlClient {
		$this->userAgent = $agent;
		return $this;
	}

	/**
	 * Append specific string to user agent.
	 *
	 * This is the recommended way of specifying the user agent
	 * for specific applications of the SparqlClient inside MediaWiki
	 * and extension code.
	 *
	 * @return $this
	 */
	public function appendUserAgent( string $agent ): SparqlClient {
		$this->userAgent .= ' ' . $agent;
		return $this;
	}

	/**
	 * Query SPARQL endpoint
	 *
	 * @param string $sparql query
	 * @param bool $rawData Whether to return only values or full data objects
	 *
	 * @return array[] List of results, one row per array element
	 *               Each row will contain fields indexed by variable name.
	 * @throws SparqlException
	 */
	public function query( string $sparql, bool $rawData = false ): array {
		if ( !$this->endpoint ) {
			throw new SparqlException( 'Endpoint URL can not be empty' );
		}
		$queryData = [ "query" => $sparql, "format" => "json" ];
		$options = array_merge( [ 'method' => 'GET' ], $this->options );

		if ( empty( $options['userAgent'] ) ) {
			$options['userAgent'] = $this->userAgent;
		}

		if ( $this->timeout >= 0 ) {
			// Blazegraph setting, see https://wiki.blazegraph.com/wiki/index.php/REST_API
			$queryData['maxQueryTimeMillis'] = $this->timeout * 1000;
			$options['timeout'] = $this->timeout;
		}

		if ( strlen( $sparql ) > self::MAX_GET_SIZE ) {
			// big requests go to POST
			$options['method'] = 'POST';
			$options['postData'] = 'query=' . urlencode( $sparql );
			unset( $queryData['query'] );
		}

		$url = wfAppendQuery( $this->endpoint, $queryData );
		$request = $this->requestFactory->create( $url, $options, __METHOD__ );

		$status = $request->execute();

		if ( !$status->isOK() ) {
			throw new SparqlException( 'HTTP error: ' . $status->getWikiText( false, false, 'en' ) );
		}
		$result = $request->getContent();
		AtEase::suppressWarnings();
		$data = json_decode( $result, true );
		AtEase::restoreWarnings();
		if ( $data === null || $data === false ) {
			throw new SparqlException( "HTTP request failed, response:\n" .
				substr( $result, 1024 ) );
		}

		return $this->extractData( $data, $rawData );
	}

	/**
	 * Extract data from SPARQL response format.
	 * The response must be in format described in:
	 * https://www.w3.org/TR/sparql11-results-json/
	 *
	 * @param array $data SPARQL result
	 * @param bool $rawData Whether to return only values or full data objects
	 *
	 * @return array[] List of results, one row per element.
	 */
	private function extractData( array $data, bool $rawData = false ): array {
		$result = [];
		if ( $data && !empty( $data['results'] ) ) {
			$vars = $data['head']['vars'];
			$resrow = [];
			foreach ( $data['results']['bindings'] as $row ) {
				foreach ( $vars as $var ) {
					if ( !isset( $row[$var] ) ) {
						$resrow[$var] = null;
						continue;
					}
					if ( $rawData ) {
						$resrow[$var] = $row[$var];
					} else {
						$resrow[$var] = $row[$var]['value'];
					}
				}
				$result[] = $resrow;
			}
		}
		return $result;
	}

}
