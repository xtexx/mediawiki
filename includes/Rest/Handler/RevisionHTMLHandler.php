<?php

namespace MediaWiki\Rest\Handler;

use LogicException;
use MediaWiki\Rest\Handler\Helper\HtmlOutputRendererHelper;
use MediaWiki\Rest\Handler\Helper\PageRestHelperFactory;
use MediaWiki\Rest\Handler\Helper\RevisionContentHelper;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\StringStream;
use Wikimedia\Assert\Assert;

/**
 * A handler that returns Parsoid HTML for the following routes:
 * - /revision/{revision}/html,
 * - /revision/{revision}/with_html
 */
class RevisionHTMLHandler extends SimpleHandler {

	private ?HtmlOutputRendererHelper $htmlHelper = null;
	private PageRestHelperFactory $helperFactory;
	private RevisionContentHelper $contentHelper;

	public function __construct( PageRestHelperFactory $helperFactory ) {
		$this->helperFactory = $helperFactory;
		$this->contentHelper = $helperFactory->newRevisionContentHelper();
	}

	protected function postValidationSetup() {
		$authority = $this->getAuthority();
		$this->contentHelper->init( $authority, $this->getValidatedParams() );

		$page = $this->contentHelper->getPage();
		$revision = $this->contentHelper->getTargetRevision();

		if ( $page && $revision ) {
			$this->htmlHelper = $this->helperFactory->newHtmlOutputRendererHelper(
				$page, $this->getValidatedParams(), $authority, $revision
			);

			$request = $this->getRequest();
			$acceptLanguage = $request->getHeaderLine( 'Accept-Language' ) ?: null;
			if ( $acceptLanguage ) {
				$this->htmlHelper->setVariantConversionLanguage(
					$acceptLanguage
				);
			}
		}
	}

	/**
	 * @return Response
	 * @throws LocalizedHttpException
	 */
	public function run(): Response {
		$this->contentHelper->checkAccess();

		$page = $this->contentHelper->getPage();
		$revisionRecord = $this->contentHelper->getTargetRevision();

		// The call to $this->contentHelper->getPage() should not return null if
		// $this->contentHelper->checkAccess() did not throw.
		Assert::invariant( $page !== null, 'Page should be known' );

		// The call to $this->contentHelper->getTargetRevision() should not return null if
		// $this->contentHelper->checkAccess() did not throw.
		Assert::invariant( $revisionRecord !== null, 'Revision should be known' );

		$outputMode = $this->getOutputMode();
		$setContentLanguageHeader = true;
		switch ( $outputMode ) {
			case 'html':
				$parserOutput = $this->htmlHelper->getHtml();
				$response = $this->getResponseFactory()->create();
				// TODO: need to respect content-type returned by Parsoid.
				$response->setHeader( 'Content-Type', 'text/html' );
				$this->htmlHelper->putHeaders( $response, $setContentLanguageHeader );
				$this->contentHelper->setCacheControl( $response, $parserOutput->getCacheExpiry() );
				$response->setBody( new StringStream( $parserOutput->getRawText() ) );
				break;
			case 'with_html':
				$parserOutput = $this->htmlHelper->getHtml();
				$body = $this->contentHelper->constructMetadata();
				$body['html'] = $parserOutput->getRawText();
				$response = $this->getResponseFactory()->createJson( $body );
				// For JSON content, it doesn't make sense to set content language header
				$this->htmlHelper->putHeaders( $response, !$setContentLanguageHeader );
				$this->contentHelper->setCacheControl( $response, $parserOutput->getCacheExpiry() );
				break;
			default:
				throw new LogicException( "Unknown HTML type $outputMode" );
		}

		return $response;
	}

	/**
	 * Returns an ETag representing a page's source. The ETag assumes a page's source has changed
	 * if the latest revision of a page has been made private, un-readable for another reason,
	 * or a newer revision exists.
	 * @return string|null
	 */
	protected function getETag(): ?string {
		if ( !$this->contentHelper->isAccessible() ) {
			return null;
		}

		// Vary eTag based on output mode
		return $this->htmlHelper->getETag( $this->getOutputMode() );
	}

	protected function getLastModified(): ?string {
		if ( !$this->contentHelper->isAccessible() ) {
			return null;
		}

		return $this->htmlHelper->getLastModified();
	}

	private function getOutputMode(): string {
		return $this->getConfig()['format'];
	}

	public function needsWriteAccess(): bool {
		return false;
	}

	protected function generateResponseSpec( string $method ): array {
		$spec = parent::generateResponseSpec( $method );

		// TODO: Consider if we prefer something like:
		//    text/html; charset=utf-8; profile="https://www.mediawiki.org/wiki/Specs/HTML/2.8.0"
		//  That would be more specific, but fragile when the profile version changes. It could
		//  also be inaccurate if the page content was not in fact produced by Parsoid.
		if ( $this->getOutputMode() == 'html' ) {
			unset( $spec['200']['content']['application/json'] );
			$spec['200']['content']['text/html']['schema']['type'] = 'string';
		}

		return $spec;
	}

	public function getResponseBodySchemaFileName( string $method ): ?string {
		return 'includes/Rest/Handler/Schema/ExistingRevisionHtml.json';
	}

	public function getParamSettings(): array {
		return array_merge(
			$this->contentHelper->getParamSettings(),
			HtmlOutputRendererHelper::getParamSettings()
		);
	}

	/**
	 * @return bool
	 */
	protected function hasRepresentation() {
		return $this->contentHelper->hasContent();
	}
}
