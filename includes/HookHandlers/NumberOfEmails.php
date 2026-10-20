<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\EmailCounter;
use MediaWiki\Parser\CoreParserFunctions;
use MediaWiki\Parser\MagicWordFactory;
use MediaWiki\Parser\Parser;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * {{NUMBEROFEMAILS}}, with the options of {{NUMBEROFEMAILS:authenticated}} and
 * {{NUMBEROFEMAILS:R}} in any order.
 */
class NumberOfEmails implements
	\MediaWiki\Parser\Hook\GetMagicVariableIDsHook,
	\MediaWiki\Parser\Hook\ParserFirstCallInitHook,
	\MediaWiki\Parser\Hook\ParserGetVariableValueSwitchHook
	{

	private EmailCounter $counter;

	public function __construct(
		IConnectionProvider $dbProvider,
		WANObjectCache $cache,
		private MagicWordFactory $magicWordFactory
	) {
		$this->counter = new EmailCounter( $dbProvider, $cache );
	}

	/** @inheritDoc */
	public function onGetMagicVariableIDs( &$variableIDs ) {
		$variableIDs[] = 'numberofemails';
	}

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'numberofemails', [ $this, 'numberOfEmails' ], Parser::SFH_NO_HASH );
	}

	/** @inheritDoc */
	public function onParserGetVariableValueSwitch( $parser, &$variableCache, $magicWordId, &$ret, $frame ) {
		if ( $magicWordId === 'numberofemails' ) {
			$ret = $variableCache[$magicWordId] = $this->numberOfEmails( $parser );
		}
	}

	/**
	 * @param Parser $parser
	 * @param string ...$options
	 * @return string
	 */
	public function numberOfEmails( Parser $parser, string ...$options ): string {
		$authenticated = false;
		$raw = null;
		$authenticatedWord = $this->magicWordFactory->get( 'numberofemails_authenticated' );
		foreach ( $options as $option ) {
			$option = trim( $option );
			if ( $authenticatedWord->matchStartToEnd( $option ) ) {
				$authenticated = true;
			} else {
				$raw = $option;
			}
		}
		$parser->getOutput()->updateCacheExpiry( EmailCounter::TTL );
		return CoreParserFunctions::formatRaw(
			$this->counter->count( $authenticated ),
			$raw,
			$parser->getTargetLanguage(),
			$this->magicWordFactory
		);
	}
}
