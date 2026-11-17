<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit\Explore;

use MediaWikiUnitTestCase;

/**
 * "더 둘러보기" is to become an extension of its own, so its code must not use the rest of
 * this extension.
 *
 * @group UnifiedExtensionForFemiwiki
 * @coversNothing
 */
class BoundaryTest extends MediaWikiUnitTestCase {

	public static function provideFiles(): iterable {
		$root = dirname( __DIR__, 4 );
		$files = array_merge(
			glob( "$root/includes/Explore/*.php" ),
			glob( "$root/tests/phpunit/*/Explore/*.php" )
		);
		foreach ( $files as $file ) {
			yield substr( $file, strlen( $root ) + 1 ) => [ $file ];
		}
	}

	/**
	 * @dataProvider provideFiles
	 */
	public function testUsesOnlyExploreClasses( string $file ) {
		preg_match_all(
			'/UnifiedExtensionForFemiwiki\\\\(?!Explore\b|Tests\\\\(?:Unit|Integration)\\\\Explore\b)\w+/',
			file_get_contents( $file ),
			$matches
		);
		$this->assertSame( [], $matches[0] );
	}
}
