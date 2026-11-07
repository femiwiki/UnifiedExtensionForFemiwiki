<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\ShareCard;

use MediaWiki\Api\ApiMain;
use MediaWiki\Config\Config;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\StringStream;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Shell\CommandFactory;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * GET /femiwiki/v1/share-card/{revision}: the share card of the page whose latest revision
 * that is, as PNG. The revision is in the path so that the card can be cached for good; an
 * older revision of the page redirects to the latest one, so each edit draws at most one card.
 */
class ShareCardHandler extends SimpleHandler {

	public const PATH = '/femiwiki/v1/share-card/';

	public function __construct(
		private Config $config,
		private RevisionLookup $revisionLookup,
		private NamespaceInfo $namespaceInfo,
		private TitleFormatter $titleFormatter,
		private CommandFactory $commandFactory
	) {
	}

	public function run( int $revision ): Response {
		if ( !$this->config->get( 'UnifiedExtensionForFemiwikiShareCard' ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'unifiedextensionforfemiwiki-share-card-disabled' ), 404
			);
		}
		$rev = $this->revisionLookup->getRevisionById( $revision );
		if ( !$rev
			|| !$rev->audienceCan( RevisionRecord::DELETED_TEXT, RevisionRecord::FOR_PUBLIC )
			|| !$this->namespaceInfo->isContent( $rev->getPage()->getNamespace() )
		) {
			throw new LocalizedHttpException( new MessageValue( 'rest-nonexistent-revision', [ $revision ] ), 404 );
		}

		$latest = $this->revisionLookup->getRevisionByTitle( $rev->getPage() );
		if ( $latest && $latest->getId() !== $rev->getId() ) {
			$response = $this->getResponseFactory()->createTemporaryRedirect(
				$this->getRouteUrl( [ 'revision' => (string)$latest->getId() ] )
			);
			$response->setHeader( 'Cache-Control', 'public, max-age=300' );
			return $response;
		}

		$title = $this->titleFormatter->getPrefixedText( $rev->getPage() );
		$renderer = new ShareCardRenderer(
			$this->commandFactory,
			$this->config->get( 'UnifiedExtensionForFemiwikiShareCardFonts' ),
			dirname( __DIR__, 2 ) . '/images/share-card'
		);
		$response = $this->getResponseFactory()->create();
		$response->setHeader( 'Content-Type', 'image/png' );
		// A revision's card never changes
		$response->setHeader( 'Cache-Control', 'public, max-age=31536000, immutable' );
		$response->setBody( new StringStream( $renderer->render( $title, $this->getLead( $title ) ) ) );
		return $response;
	}

	/**
	 * The first paragraph of the page as TextExtracts gives it, or nothing without TextExtracts
	 *
	 * @param string $title
	 * @return string
	 */
	private function getLead( string $title ): string {
		$api = new ApiMain( new FauxRequest( [
			'action' => 'query',
			'prop' => 'extracts',
			'titles' => $title,
			'exintro' => true,
			'explaintext' => true,
			'formatversion' => 2,
		] ) );
		$api->execute();
		// Keyed by page ID: the list formatversion 2 prints is only made by the printer
		$pages = $api->getResult()->getResultData( [ 'query', 'pages' ], [ 'Strip' => 'all' ] );
		$page = is_array( $pages ) ? reset( $pages ) : false;
		$extract = is_array( $page ) ? ( $page['extract'] ?? '' ) : '';
		// TextExtracts gives [ '*' => text ] to internal callers, as for XML
		$extract = trim( (string)( is_array( $extract ) ? ( $extract['*'] ?? '' ) : $extract ) );
		return $extract === '' ? '' : trim( explode( "\n", $extract )[0] );
	}

	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'revision' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/** @inheritDoc */
	public function needsWriteAccess() {
		return false;
	}
}
