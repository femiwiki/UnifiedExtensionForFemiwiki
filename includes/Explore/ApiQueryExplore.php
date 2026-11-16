<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Explore;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiPageSet;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryGeneratorBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Config\Config;
use MediaWiki\Linker\LinkTargetLookup;
use MediaWiki\Page\PageStore;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * list=femiwikiexplore: the pages "더 둘러보기" shows below an article. See ExploreFeed.
 */
class ApiQueryExplore extends ApiQueryGeneratorBase {

	public const MORE_OPTION = 'femiwiki-explore-more';
	public const LESS_OPTION = 'femiwiki-explore-less';

	/** TextExtracts gives at most 20 extracts a request */
	private const MAX_LIMIT = 20;

	private ExploreFeed $feed;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		Config $config,
		IConnectionProvider $connectionProvider,
		LinkTargetLookup $linkTargetLookup,
		WANObjectCache $cache,
		private UserOptionsLookup $userOptionsLookup,
		private PageStore $pageStore
	) {
		parent::__construct( $query, $moduleName, 'fwe' );
		$this->feed = new ExploreFeed(
			$connectionProvider,
			$linkTargetLookup,
			$cache,
			str_replace( ' ', '_', $config->get( 'UnifiedExtensionForFemiwikiExploreTopicPrefix' ) )
		);
	}

	/** @inheritDoc */
	public function execute() {
		$this->run();
	}

	/** @inheritDoc */
	public function executeGenerator( $resultPageSet ) {
		$this->run( $resultPageSet );
	}

	/**
	 * @param string $name One of the options
	 * @return string[] Topics in the option, a JSON list
	 */
	private function getTopicOption( string $name ): array {
		$user = $this->getUser();
		if ( !$user->isNamed() ) {
			return [];
		}
		$topics = json_decode( (string)$this->userOptionsLookup->getOption( $user, $name ), true );
		return is_array( $topics ) ? array_filter( $topics, 'is_string' ) : [];
	}

	private function run( ?ApiPageSet $resultPageSet = null ): void {
		$params = $this->extractRequestParams();
		$title = Title::newFromText( $params['title'] );
		if ( !$title || !$title->canExist() ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}
		if ( !$title->exists() ) {
			$this->dieWithError( [ 'apierror-missingtitle-byname', wfEscapeWikiText( $params['title'] ) ] );
		}

		$pages = $this->feed->getPages(
			$title,
			$params['offset'],
			$params['limit'],
			$this->getTopicOption( self::MORE_OPTION ),
			$this->getTopicOption( self::LESS_OPTION ),
			$params['exclude'] ?? []
		);
		$topics = $this->feed->getTopics( array_keys( $pages ) );
		// The feed never ends: random pages follow the rest
		$this->setContinueEnumParameter( 'offset', $params['offset'] + $params['limit'] );

		$titles = [];
		if ( $pages ) {
			$records = $this->pageStore->newSelectQueryBuilder()
				->wherePageIds( array_keys( $pages ) )
				->caller( __METHOD__ )
				->fetchPageRecords();
			foreach ( $records as $record ) {
				$titles[$record->getId()] = Title::newFromPageIdentity( $record );
			}
		}

		$index = $params['offset'];
		$result = $this->getResult();
		$path = [ 'query', $this->getModuleName() ];
		foreach ( $pages as $id => $source ) {
			if ( !isset( $titles[$id] ) ) {
				continue;
			}
			$data = [ 'index' => ++$index, 'source' => $source, 'topics' => $topics[$id] ?? [] ];
			ApiResult::setIndexedTagName( $data['topics'], 'topic' );
			if ( $resultPageSet ) {
				$resultPageSet->setGeneratorData( $titles[$id], $data );
			} else {
				ApiQueryGeneratorBase::addTitleInfo( $data, $titles[$id] );
				$data['pageid'] = $id;
				$result->addValue( $path, null, $data );
			}
		}
		if ( $resultPageSet ) {
			$resultPageSet->populateFromTitles( array_values( $titles ) );
		} else {
			$result->addIndexedTagName( $path, 'page' );
		}
	}

	/** @inheritDoc */
	public function getCacheMode( $params ) {
		// Readers' topic options change the pages
		return 'anon-public-user-private';
	}

	/** @inheritDoc */
	protected function getAllowedParams() {
		return [
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'offset' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 0,
				IntegerDef::PARAM_MIN => 0,
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
			'limit' => [
				ParamValidator::PARAM_TYPE => 'limit',
				ParamValidator::PARAM_DEFAULT => 18,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => self::MAX_LIMIT,
				IntegerDef::PARAM_MAX2 => self::MAX_LIMIT,
			],
			'exclude' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => 500,
				ParamValidator::PARAM_ISMULTI_LIMIT2 => 500,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=query&list=femiwikiexplore&fwetitle=Main%20Page'
				=> 'apihelp-query+femiwikiexplore-example-simple',
			'action=query&generator=femiwikiexplore&gfwetitle=Main%20Page&prop=pageimages|extracts'
				=> 'apihelp-query+femiwikiexplore-example-generator',
		];
	}
}
