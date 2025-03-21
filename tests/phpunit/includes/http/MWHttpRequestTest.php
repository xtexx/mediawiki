<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Http\TelemetryHeadersInterface;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MWHttpRequest
 */
class MWHttpRequestTest extends PHPUnit\Framework\TestCase {
	use MediaWikiCoversValidator;

	/**
	 * Feeds URI to test a long regular expression in MWHttpRequest::isValidURI
	 */
	public static function provideURI() {
		/** Format: 'boolean expectation', 'URI to test', 'Optional message' */
		return [
			[ false, '¿non sens before!! http://a', 'Allow anything before URI' ],

			# (http|https) - only two schemes allowed
			[ true, 'http://www.example.org/' ],
			[ true, 'https://www.example.org/' ],
			[ true, 'http://www.example.org', 'URI without directory' ],
			[ true, 'http://a', 'Short name' ],
			[ true, 'http://étoile', 'Allow UTF-8 in hostname' ], # 'étoile' is french for 'star'
			[ false, '\\host\directory', 'CIFS share' ],
			[ false, 'gopher://host/dir', 'Reject gopher scheme' ],
			[ false, 'telnet://host', 'Reject telnet scheme' ],

			# :\/\/ - double slashes
			[ false, 'http//example.org', 'Reject missing colon in protocol' ],
			[ false, 'http:/example.org', 'Reject missing slash in protocol' ],
			[ false, 'http:example.org', 'Must have two slashes' ],
			# Following fail since hostname can be made of anything
			[ false, 'http:///example.org', 'Must have exactly two slashes, not three' ],

			# (\w+:{0,1}\w*@)? - optional user:pass
			[ true, 'http://user@host', 'Username provided' ],
			[ true, 'http://user:@host', 'Username provided, no password' ],
			[ true, 'http://user:pass@host', 'Username and password provided' ],

			# (\S+) - host part is made of anything not whitespaces
			// commented these out in order to remove @group Broken
			// @todo are these valid tests? if so, fix MWHttpRequest::isValidURI so it can handle them
			// [ false, 'http://!"èèè¿¿¿~~\'', 'hostname is made of any non whitespace' ],
			// [ false, 'http://exam:ple.org/', 'hostname cannot use colons!' ],

			# (:[0-9]+)? - port number
			[ true, 'http://example.org:80/' ],
			[ true, 'https://example.org:80/' ],
			[ true, 'http://example.org:443/' ],
			[ true, 'https://example.org:443/' ],

			# Part after the hostname is / or / with something else
			[ true, 'http://example/#' ],
			[ true, 'http://example/!' ],
			[ true, 'http://example/:' ],
			[ true, 'http://example/.' ],
			[ true, 'http://example/?' ],
			[ true, 'http://example/+' ],
			[ true, 'http://example/=' ],
			[ true, 'http://example/&' ],
			[ true, 'http://example/%' ],
			[ true, 'http://example/@' ],
			[ true, 'http://example/-' ],
			[ true, 'http://example//' ],
			[ true, 'http://example/&' ],

			# Fragment
			[ true, 'http://exam#ple.org', ], # This one is valid, really!
			[ true, 'http://example.org:80#anchor' ],
			[ true, 'http://example.org/?id#anchor' ],
			[ true, 'http://example.org/?#anchor' ],

			[ false, 'http://a ¿non !!sens after', 'Allow anything after URI' ],
		];
	}

	/**
	 * T29854 : MWHttpRequest::isValidURI is too lax
	 * @dataProvider provideURI
	 * @covers \MWHttpRequest::isValidURI
	 */
	public function testIsValidUri( $expect, $uri, $message = '' ) {
		$this->assertSame( $expect, MWHttpRequest::isValidURI( $uri ), $message );
	}

	public function testSetReverseProxy() {
		$req = TestingAccessWrapper::newFromObject(
			MediaWikiServices::getInstance()->getHttpRequestFactory()->create( 'https://example.org/path?query=string' )
		);
		$req->setReverseProxy( 'http://localhost:1234' );
		$this->assertSame( 'http://localhost:1234/path?query=string', $req->url );
		$this->assertSame( 'example.org', $req->reqHeaders['Host'] );
	}

	public function testItInjectsTelemetryHeaders() {
		$telemetry = $this->createMock( TelemetryHeadersInterface::class );
		$telemetry->expects( $this->once() )
			->method( 'getRequestHeaders' )
			->willReturn( [
				'X-Request-Id' => 'request_identifier',
				'tracestate' => 'tracestate_value',
				'traceparent' => 'traceparent_value',
			] );

		$httpRequest = $this->getMockForAbstractClass(
			MWHttpRequest::class,
			[
				'http://localhost/test',
				[
					'timeout' => 30,
					'connectTimeout' => 30
				]
			]
		);
		$httpRequest->addTelemetry( $telemetry );

		$accessWrapper = TestingAccessWrapper::newFromObject( $httpRequest );
		$requestHeaders = $accessWrapper->reqHeaders;

		$this->assertEquals( 'request_identifier', $requestHeaders['X-Request-Id'] );
		$this->assertEquals( 'tracestate_value', $requestHeaders['tracestate'] );
		$this->assertEquals( 'traceparent_value', $requestHeaders['traceparent'] );
	}
}
