<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\HookHandlers;

use Config;
use GrowthExperiments\HelpPanelHooks;
use GrowthExperiments\HomepageHooks;
use MediaWiki\User\UserOptionsManager;

class DefaultOptions implements
	\MediaWiki\User\Hook\UserResetAllOptionsHook,
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
	public function onUserResetAllOptions(
		$user,
		&$newOptions,
		$options,
		$resetKinds
	) {
		$config = $this->config;

		foreach ( $config->get( 'UnifiedExtensionForFemiwikiSoftDefaultOptions' ) as $opt => $val ) {
			$newOptions[$opt] = $val;
		}

		if ( !\ExtensionRegistry::getInstance()->isLoaded( 'GrowthExperiments' ) ) {
			return;
		}

		if ( $config->get( 'GEHelpPanelNewAccountEnablePercentage' ) == 100 ) {
			$newOptions[HelpPanelHooks::HELP_PANEL_PREFERENCES_TOGGLE] = 1;
		}

		if ( $config->get( 'GEHomepageNewAccountEnablePercentage' ) == 100 ) {
			$newOptions[HomepageHooks::HOMEPAGE_PREF_ENABLE] = 1;
			$newOptions[HomepageHooks::HOMEPAGE_PREF_PT_LINK] = 1;
		}
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
