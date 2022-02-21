<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use Config;
use MediaWiki\User\UserOptionsManager;

class DefaultOptions implements
	\MediaWiki\Auth\Hook\LocalUserCreatedHook
	{

	/** @var Config */
	private $config;

	/** @var UserOptionsManager */
	private $userOptionsManager;

	/**
	 * @param Config $config
	 * @param UserOptionsManager $userOptionsManager
	 */
	public function __construct( Config $config, UserOptionsManager $userOptionsManager ) {
		$this->config = $config;
		$this->userOptionsManager = $userOptionsManager;
	}

	/**
	 * @inheritDoc
	 */
	public function onLocalUserCreated( $user, $autocreated ) {
		if ( !$user->isRegistered() || $autocreated ) {
			return;
		}

		$config = $this->config;
		$userOptionsManager = $this->userOptionsManager;

		foreach ( $config->get( 'UnifiedExtensionForFemiwikiSoftDefaultOptions' ) as $opt => $val ) {
			$userOptionsManager->setOption( $user, $opt, $val );
		}
	}
}
