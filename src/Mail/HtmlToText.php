<?php
/**
 * A plain-text part written from an HTML part.
 *
 * @package ConnectorForSendora
 */

declare(strict_types=1);

namespace ConnectorForSendora\Mail;

/**
 * Turns an email's HTML into readable text: blocks and line breaks become
 * lines, list items become `- ` lines, and a link keeps its address after
 * its label, `Label (https://…)`, so an address that only the link carries,
 * such as `{{ unsubscribe_url }}`, is in the text too.
 */
final class HtmlToText {

	/** Elements whose content is never text the reader sees. */
	private const HIDDEN_ELEMENTS = '#<(head|title|style|script)\b[^>]*>.*?</\1\s*>#is';

	/** A link: its opening tag, and its label. */
	private const LINK = '#(<a\b[^>]*>)(.*?)</a\s*>#is';

	private const LINE_BREAK = '#<br\b[^>]*>#i';

	private const LIST_ITEM = '#<li\b[^>]*>#i';

	/** Tags that open or close a block, which stands apart from the text around it. */
	private const BLOCK_TAG = '#</?(p|div|h[1-6]|ul|ol|table|tr|blockquote|pre|section|article|header|footer|hr)\b[^>]*>#i';

	/** Spaces, tabs and no-break spaces, which a line keeps one of. */
	private const HORIZONTAL_SPACE = '/[ \t\x{00A0}]+/u';

	/** More than one empty line in a row. */
	private const EMPTY_LINES = "/\n{3,}/";

	private const PARAGRAPH_BREAK = "\n\n";

	private const LIST_ITEM_MARK = "\n- ";

	/**
	 * The HTML as text.
	 *
	 * @param string $html The HTML part, in UTF-8.
	 */
	public static function convert( string $html ): string {
		$text = self::replace( self::HIDDEN_ELEMENTS, '', $html );
		$text = preg_replace_callback( self::LINK, static fn ( array $link ): string => self::link( $link[1], $link[2] ), $text ) ?? $text;
		$text = self::replace( self::LINE_BREAK, "\n", $text );
		$text = self::replace( self::LIST_ITEM, self::LIST_ITEM_MARK, $text );
		$text = self::replace( self::BLOCK_TAG, self::PARAGRAPH_BREAK, $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines = array_map( static fn ( string $line ): string => trim( self::replace( self::HORIZONTAL_SPACE, ' ', $line ) ), explode( "\n", str_replace( "\r", '', $text ) ) );
		return trim( self::replace( self::EMPTY_LINES, self::PARAGRAPH_BREAK, implode( "\n", $lines ) ) );
	}

	/**
	 * A link as text: its label, then its address in brackets, unless the
	 * label is the address already. A link without an address is its label.
	 *
	 * @param string $tag   The link's opening tag.
	 * @param string $label The link's content, as HTML.
	 */
	private static function link( string $tag, string $label ): string {
		// WordPress's own HTML parser reads the attribute: quoted or not, and never a lookalike such as data-href.
		$processor = new \WP_HTML_Tag_Processor( $tag );
		$href      = $processor->next_tag() ? $processor->get_attribute( 'href' ) : null;
		if ( ! is_string( $href ) || '' === trim( $href ) ) {
			return $label;
		}
		$href = trim( $href );

		$plain_label = trim( html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		// The address is decoded already; encoded again, it survives the decoding of the whole text unchanged.
		$encoded_href = htmlspecialchars( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === $plain_label ) {
			return $encoded_href;
		}
		if ( $plain_label === $href || 'mailto:' . $plain_label === $href ) {
			return $label;
		}
		return $label . ' (' . $encoded_href . ')';
	}

	/**
	 * The text with every match of the pattern replaced; the text as it was
	 * when the pattern cannot run on it.
	 *
	 * @param non-empty-string $pattern     The pattern.
	 * @param string           $replacement The replacement.
	 * @param string           $text        The text.
	 */
	private static function replace( string $pattern, string $replacement, string $text ): string {
		return preg_replace( $pattern, $replacement, $text ) ?? $text;
	}
}
