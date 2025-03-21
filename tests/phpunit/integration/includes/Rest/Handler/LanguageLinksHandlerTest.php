<?php

namespace MediaWiki\Tests\Rest\Handler;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Interwiki\ClassicInterwikiLookup;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Rest\Handler\LanguageLinksHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Unit\DummyServicesTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\Message\MessageValue;

/**
 * @covers \MediaWiki\Rest\Handler\LanguageLinksHandler
 *
 * @group Database
 */
class LanguageLinksHandlerTest extends MediaWikiIntegrationTestCase {
	use DummyServicesTrait;
	use HandlerTestTrait;

	public function addDBData() {
		$defaults = [
			'iw_local' => 0,
			'iw_api' => '/w/api.php',
			'iw_url' => ''
		];

		$base = 'https://wiki.test/';

		$this->overrideConfigValue(
			MainConfigNames::InterwikiCache,
			ClassicInterwikiLookup::buildCdbHash( [
				[ 'iw_prefix' => 'de', 'iw_url' => $base . '/de', 'iw_wikiid' => 'dewiki' ] + $defaults,
				[ 'iw_prefix' => 'en', 'iw_url' => $base . '/en', 'iw_wikiid' => 'enwiki' ] + $defaults,
				[ 'iw_prefix' => 'fr', 'iw_url' => $base . '/fr', 'iw_wikiid' => 'frwiki' ] + $defaults
			] )
		);

		$this->editPage( __CLASS__ . '_Foo', 'Foo [[fr:Fou baux]] [[de:Füh bär]]' );
	}

	private function newHandler() {
		$languageNameUtils = new LanguageNameUtils(
			new ServiceOptions(
				LanguageNameUtils::CONSTRUCTOR_OPTIONS,
				[
					MainConfigNames::ExtraLanguageNames => [],
					MainConfigNames::UsePigLatinVariant => false,
					MainConfigNames::UseXssLanguage => false,
				]
			),
			$this->getServiceContainer()->getHookContainer()
		);

		return new LanguageLinksHandler(
			$this->getServiceContainer()->getConnectionProvider(),
			$languageNameUtils,
			$this->getDummyTitleFormatter(),
			$this->getDummyTitleParser(),
			$this->getServiceContainer()->getPageStore(),
			$this->getServiceContainer()->getPageRestHelperFactory()
		);
	}

	private function assertLink( $expected, $actual ) {
		foreach ( $expected as $key => $value ) {
			$this->assertArrayHasKey( $key, $actual );
			$this->assertSame( $value, $actual[$key], $key );
		}
	}

	public function testExecute() {
		$title = __CLASS__ . '_Foo';
		$request = new RequestData( [ 'pathParams' => [ 'title' => $title ] ] );

		$handler = $this->newHandler();
		$data = $this->executeHandlerAndGetBodyData( $handler, $request );

		$this->assertCount( 2, $data );

		$links = [];
		foreach ( $data as $row ) {
			$links[$row['code']] = $row;
		}

		$this->assertArrayHasKey( 'de', $links );
		$this->assertArrayHasKey( 'fr', $links );

		$this->assertLink( [
			'code' => 'de',
			'name' => 'Deutsch',
			'title' => 'Füh bär',
			'key' => 'Füh_bär',
		], $links['de'] );

		$this->assertLink( [
			'code' => 'fr',
			'name' => 'français',
			'title' => 'Fou baux',
			'key' => 'Fou_baux',
		], $links['fr'] );
	}

	public function testCacheControl() {
		$title = Title::newFromText( __METHOD__ );
		$this->editPage( $title, 'First' );

		$request = new RequestData( [ 'pathParams' => [ 'title' => $title->getPrefixedDBkey() ] ] );

		$handler = $this->newHandler();
		$response = $this->executeHandler( $handler, $request );

		$firstETag = $response->getHeaderLine( 'ETag' );
		$this->assertSame(
			wfTimestamp( TS_RFC2822, $title->getTouched() ),
			$response->getHeaderLine( 'Last-Modified' )
		);

		$this->editPage( $title, 'Second' );

		Title::clearCaches();
		$handler = $this->newHandler();
		$response = $this->executeHandler( $handler, $request );

		$this->assertNotEquals( $response->getHeaderLine( 'ETag' ), $firstETag );
		$this->assertSame(
			wfTimestamp( TS_RFC2822, $title->getTouched() ),
			$response->getHeaderLine( 'Last-Modified' )
		);
	}

	public function testExecute_notFound() {
		$title = __CLASS__ . '_Xyzzy';
		$request = new RequestData( [ 'pathParams' => [ 'title' => $title ] ] );

		$handler = $this->newHandler();

		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'rest-nonexistent-title' ), 404 )
		);
		$this->executeHandler( $handler, $request );
	}

	public function testExecute_forbidden() {
		// The mock PermissionHandler forbids access to pages that have "Forbidden" in the name
		$title = __CLASS__ . '_Forbidden';
		$this->editPage( $title, 'Forbidden text' );
		$request = new RequestData( [ 'pathParams' => [ 'title' => $title ] ] );

		$handler = $this->newHandler();

		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'rest-permission-denied-title' ), 403 )
		);
		$this->executeHandler( $handler, $request, [ 'userCan' => false ], [], [], [],
			$this->mockAnonAuthority( static function ( string $permission, ?PageIdentity $target ) {
				return $target && !preg_match( '/Forbidden/', $target->getDBkey() );
			} ) );
	}

}
