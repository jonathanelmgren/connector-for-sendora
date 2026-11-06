<?php
/**
 * Tests for writing a text part from HTML.
 *
 * @package SendoraWP
 */

declare(strict_types=1);

namespace SendoraWP\Tests\Integration;

use SendoraWP\Mail\HtmlToText;

/**
 * The text a reader of the HTML would see, with link addresses kept.
 */
final class HtmlToTextTest extends TestCase {

	public function test_blocks_and_line_breaks_become_lines(): void {
		$this->assertSame(
			"Hello Anna,\n\nYour order\nhas shipped.\n\nThanks",
			HtmlToText::convert( "<h1>Hello Anna,</h1>\n<p>Your order<br>has   shipped.</p><div>Thanks</div>" )
		);
	}

	public function test_hidden_elements_are_left_out(): void {
		$this->assertSame(
			'Body',
			HtmlToText::convert( '<html><head><title>Mail</title><style>p { color: red; }</style></head><body><script>x()</script><p>Body</p></body></html>' )
		);
	}

	public function test_a_link_keeps_its_address_after_its_label(): void {
		$this->assertSame(
			'Read it online (https://example.com/news?a=1&b=2). Unsubscribe ({{ unsubscribe_url }})',
			HtmlToText::convert( '<a href="https://example.com/news?a=1&amp;b=2"><b>Read it online</b></a>. <a class="x" href=\'{{ unsubscribe_url }}\'>Unsubscribe</a>' )
		);
	}

	public function test_an_unquoted_address_is_kept(): void {
		$this->assertSame( 'Unsubscribe ({{unsubscribe_url}})', HtmlToText::convert( '<a href={{unsubscribe_url}}>Unsubscribe</a>' ) );
	}

	public function test_only_the_href_attribute_is_the_address(): void {
		$this->assertSame(
			'Unsubscribe ({{ unsubscribe_url }})',
			HtmlToText::convert( '<a data-href="https://example.com/original" title="href=x" href="{{ unsubscribe_url }}">Unsubscribe</a>' )
		);
	}

	public function test_a_link_without_an_address_is_its_label(): void {
		$this->assertSame( 'Top', HtmlToText::convert( '<a name="top">Top</a>' ) );
	}

	public function test_an_address_with_encoded_characters_is_decoded_once(): void {
		$this->assertSame( 'Shop (https://example.com/?q=a&amp;b)', HtmlToText::convert( '<a href="https://example.com/?q=a&amp;amp;b">Shop</a>' ) );
	}

	public function test_a_link_whose_label_is_its_address_is_written_once(): void {
		$this->assertSame(
			'https://example.com and anna@example.com',
			HtmlToText::convert( '<a href="https://example.com">https://example.com</a> and <a href="mailto:anna@example.com">anna@example.com</a>' )
		);
	}

	public function test_a_link_without_a_label_is_its_address(): void {
		$this->assertSame( '{{ unsubscribe_url }}', HtmlToText::convert( '<a href="{{ unsubscribe_url }}"><img src="x.png"></a>' ) );
	}

	public function test_list_items_become_dashed_lines_and_entities_are_decoded(): void {
		$this->assertSame(
			"Caf&eacute;s:\n\n- Björn &amp; Co\n- Søren",
			HtmlToText::convert( '<p>Caf&amp;eacute;s:</p><ul><li>Bj&ouml;rn &amp;amp; Co</li><li>S&oslash;ren&nbsp;</li></ul>' )
		);
	}
}
