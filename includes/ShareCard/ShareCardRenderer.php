<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\ShareCard;

use MediaWiki\Shell\CommandFactory;
use RuntimeException;

/**
 * Draws a page's share card, the og:image of a page without a page image: the page title and
 * its first paragraph on the Femiwiki purple, as a 1200×630 PNG.
 *
 * The card is SVG turned into PNG by rsvg-convert. SVG does not wrap text, so the lines are
 * broken here, at spaces as CSS `word-break: keep-all` does, with the text measured by GD's
 * FreeType in the same fonts rsvg-convert draws with.
 */
class ShareCardRenderer {

	public const WIDTH = 1200;

	public const HEIGHT = 630;

	private const PAD = 80;

	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	/**
	 * @param CommandFactory $commandFactory
	 * @param array $fonts Font files, with the keys regular, bold, han and hanBold (the last two
	 *  for Hanja, which the first two may lack), and family, the CSS font-family list that names
	 *  the same fonts to rsvg-convert
	 * @param string $imageDir The directory of logo.svg and symbol.svg
	 */
	public function __construct(
		private CommandFactory $commandFactory,
		private array $fonts,
		private string $imageDir
	) {
	}

	/**
	 * @param string $title
	 * @param string $lead The first paragraph, as plain text
	 * @return string PNG
	 */
	public function render( string $title, string $lead ): string {
		$result = $this->commandFactory->create()
			->params( 'rsvg-convert', '--format=png', '--width=' . self::WIDTH, '--height=' . self::HEIGHT )
			->input( $this->buildSvg( $title, $lead ) )
			->execute();
		$png = $result->getStdout();
		if ( $result->getExitCode() !== 0 || !str_starts_with( $png, self::PNG_SIGNATURE ) ) {
			throw new RuntimeException( 'rsvg-convert failed: ' . $result->getStderr() );
		}
		return $png;
	}

	/**
	 * @param string $title
	 * @param string $lead
	 * @return string
	 */
	public function buildSvg( string $title, string $lead ): string {
		$width = self::WIDTH;
		$height = self::HEIGHT;
		$pad = self::PAD;
		$family = htmlspecialchars( $this->fonts['family'], ENT_XML1 | ENT_QUOTES );

		// A title that takes two lines is set smaller
		$titleSize = 96;
		$titleLines = [];
		foreach ( [ 96, 76 ] as $titleSize ) {
			$titleLines = self::wrap(
				$title, $width - 2 * $pad, 2, fn ( $s ) => $this->measure( $s, $titleSize, true )
			);
			if ( count( $titleLines ) === 1 ) {
				break;
			}
		}
		$leadSize = 32;
		$leadLines = $lead === '' ? [] : self::wrap(
			$lead, $width - 2 * $pad - 40, count( $titleLines ) > 1 ? 2 : 3,
			fn ( $s ) => $this->measure( $s, $leadSize, false )
		);

		$body = [
			'<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
				. '<stop offset="0" stop-color="#5144a3"/><stop offset="1" stop-color="#302a45"/>'
				. '</linearGradient></defs>',
			"<rect width=\"$width\" height=\"$height\" fill=\"url(#bg)\"/>",
			// Large enough to read as a backdrop: the whole flag is in the card and only the pole
			// runs off the bottom edge, so it does not look cut off by mistake
			$this->embed( 'symbol.svg', 300, -170, 1050, 1050, 'opacity="0.07"' ),
			$this->embed( 'logo.svg', $pad - 6, 56, 182, 52 ),
			// The first paragraph is the page's own text under the site's license
			$this->text( $width - $pad, 92, 24, false, '#ecebfc', 'CC BY-SA 4.0', 'end' ),
		];

		// Stacked from the bottom, so the last line sits at the same height on every card. Hangul
		// ink sits about 0.8em above the baseline and 0.1em below it.
		$stack = [];
		$y = $height - $pad + 4;
		$leadStep = $leadSize * 1.65;
		foreach ( array_reverse( $leadLines ) as $line ) {
			$stack[] = $this->text( $pad, $y, $leadSize, false, '#ecebfc', $line );
			$y -= $leadStep;
		}
		if ( $leadLines ) {
			$y += $leadStep - 0.8 * $leadSize - 60 - 0.1 * $titleSize;
		}
		$titleStep = $titleSize * 1.2;
		foreach ( array_reverse( $titleLines ) as $line ) {
			$stack[] = $this->text( $pad, $y, $titleSize, true, '#ffffff', $line );
			$y -= $titleStep;
		}
		$y += $titleStep - 0.8 * $titleSize - 36;
		$stack[] = sprintf( '<rect x="%d" y="%.1f" width="96" height="10" rx="5" fill="#bad75b"/>', $pad, $y - 10 );

		return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$width\" height=\"$height\" "
			. "viewBox=\"0 0 $width $height\" font-family=\"$family\">\n"
			. implode( "\n", array_merge( $body, array_reverse( $stack ) ) )
			. "\n</svg>\n";
	}

	/**
	 * Break text into lines at spaces, and inside a word only when the word alone is too wide.
	 * Text left over after $maxLines lines ends the last line with an ellipsis.
	 *
	 * @param string $text
	 * @param float $maxWidth
	 * @param int $maxLines
	 * @param callable(string):float $measure
	 * @return string[]
	 */
	public static function wrap( string $text, float $maxWidth, int $maxLines, callable $measure ): array {
		$lines = [];
		$line = '';
		foreach ( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$try = $line === '' ? $word : "$line $word";
			if ( $measure( $try ) <= $maxWidth ) {
				$line = $try;
				continue;
			}
			if ( $line !== '' ) {
				$lines[] = $line;
				$line = '';
			}
			foreach ( mb_str_split( $word ) as $char ) {
				if ( $line !== '' && $measure( $line . $char ) > $maxWidth ) {
					$lines[] = $line;
					$line = '';
				}
				$line .= $char;
			}
		}
		if ( $line !== '' ) {
			$lines[] = $line;
		}
		if ( count( $lines ) <= $maxLines ) {
			return $lines;
		}
		$lines = array_slice( $lines, 0, $maxLines );
		$last = $lines[$maxLines - 1];
		while ( $last !== '' && $measure( $last . '…' ) > $maxWidth ) {
			$last = mb_substr( $last, 0, -1 );
		}
		$lines[$maxLines - 1] = rtrim( $last ) . '…';
		return $lines;
	}

	/**
	 * Width in pixels, measuring Hanja in the Hanja fonts
	 *
	 * @param string $text
	 * @param float $size Font size in pixels
	 * @param bool $bold
	 * @return float
	 */
	private function measure( string $text, float $size, bool $bold ): float {
		$width = 0;
		foreach ( preg_split( '/(\p{Han}+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $run ) {
			$isHan = preg_match( '/^\p{Han}/u', $run );
			$font = $this->fonts[ $isHan ? ( $bold ? 'hanBold' : 'han' ) : ( $bold ? 'bold' : 'regular' ) ];
			// GD takes points at 96 dpi, so pixels × 0.75
			$box = imagettfbbox( $size * 0.75, 0, $font, $run );
			if ( $box === false ) {
				throw new RuntimeException( "Cannot measure text in $font" );
			}
			$width += $box[2] - $box[0];
		}
		return $width;
	}

	private function text(
		float $x, float $y, int $size, bool $bold, string $color, string $text, string $anchor = 'start'
	): string {
		return sprintf(
			'<text x="%.1f" y="%.1f" font-size="%d"%s fill="%s" text-anchor="%s">%s</text>',
			$x, $y, $size, $bold ? ' font-weight="700"' : '', $color, $anchor,
			htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES )
		);
	}

	private function embed( string $file, float $x, float $y, float $w, float $h, string $attrs = '' ): string {
		$svg = trim( file_get_contents( "{$this->imageDir}/$file" ) );
		return preg_replace(
			'/^<svg /',
			sprintf( '<svg x="%.1f" y="%.1f" width="%.1f" height="%.1f" %s ', $x, $y, $w, $h, $attrs ),
			$svg
		);
	}
}
