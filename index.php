<?php
/***
Plugin Name: Tempest-Highlight
Plugin URI: https://github.com/edent/highlight
Description: Syntax highlighting. Based on Tempest Highlight - https://github.com/tempestphp/highlight
Author: Terence Eden
Version: 0.03
Author URI: https://edent.tel/

Tempest Highlight - https://github.com/tempestphp/highlight - is a package for server-side, high-performance, and flexible code highlighting.
***/
if ( ! defined( "ABSPATH" ) ) { exit; }

// Entry point of the plugin (after the template renders the HTML output).
add_action( "the_content", "tempest_highlight_main", 49 );

//	Load the Tempest Highlight library
require_once __DIR__ . "/autoload.php";

//	Define a theme
//	Choose from any in `src/Themes/Css/`
$css   = "light-plus";
$highlightTheme = new Tempest\Highlight\Themes\InlineTheme( __DIR__ . "/src/Themes/Css/{$css}.css");

//	Main function
function tempest_highlight_main( string $content ):string {
	//	Don't change the content on RSS / Atom feeds, nor on lists
	if ( is_feed() || !is_single() ) {
		return $content;
	}

	//	Set up variables
	global $highlightTheme;

	//	Create the highlighter.
	$highlighter = new Tempest\Highlight\Highlighter( $highlightTheme );
	//	Load the content into the appropriate DOM (PHP 8.4's HTML DOM or legacy DOMDocument).
	$dom = highlight_create_dom( $content );

	//	Select the code snippets.
	//	`<pre><code class="language-*">`
	$codeSnippets = highlight_query_code_snippets( $dom );

	//	Iterate through each snippet.
	foreach ( $codeSnippets as $code ) {

		//	What language is this written in?
		$originalClass = highlight_get_class( $code );

		//	Transform `language-whatever` into `whatever`.
		$language = explode("-", $originalClass)[1];

		//	Language names and icons may be displayed differently.
		[$language, $language_logo, $language_display] = getLanguageProperties( $language );

		//	Get the content from within the <code>.
		//	Use `textContent` to avoid HTML entities being encoded.
		$originalCode = $code->textContent;

		//	Set the attributes on the parent <pre>.
		$code->parentNode->setAttribute( "class", "tempest-highlight" );
		$code->parentNode->setAttribute( "translate", "no" );
		$code->parentNode->setAttribute( "itemscope", "" );
		$code->parentNode->setAttribute( "itemtype", "https://schema.org/SoftwareSourceCode" );

		//	Set the attributes on the <code>.
		$code->setAttribute( "class", $language );
		$code->setAttribute( "itemprop", "text" );

		//	Replace the contents of <code> with the highlighted HTML.
		highlight_set_inner_html( $dom, $code, $highlighter->parse( $originalCode, $language ) );

		//	Add the copy button.
		$copy_button = "<button class='copy' title='Copy code' onclick='navigator.clipboard.writeText( this.parentNode.getElementsByTagName(\"code\")[0].textContent );'>⧉</button>";
		//	Insert it before the <code> element.
		$code->parentNode->insertBefore( highlight_import_html( $dom, $copy_button ), $code );

		//	Add the language header before the code.
		//	Construct the HTML.
		$language_html = generateLanguageHTML( $language_logo, $language_display );
		$header = highlight_import_html( $dom, $language_html );
		if ( null != $header ) {
			//	Insert it before the <code> element.
			$code->parentNode->insertBefore( $header, $code );
		}
	}

	//	Add the base CSS to the page.
	enqueueBaseCSS();

	//	Return the altered HTML
	return highlight_save_html( $dom );
}

//	Creates a new DOM from HTML content, using PHP 8.4's Dom\HTMLDocument when available
//	and falling back to the legacy DOMDocument for PHP < 8.4.
function highlight_create_dom( string $content ) {
	if ( PHP_VERSION_ID >= 80400 ) {
		return Dom\HTMLDocument::createFromString( $content, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED, "UTF-8" );
	}

	$dom = new DOMDocument();
	$dom->encoding = 'UTF-8';
	libxml_use_internal_errors( true );
	$dom->loadHTML( $content, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED );
	libxml_clear_errors();
	return $dom;
}

//	Selects <pre><code class="language-*"> snippets using the appropriate query API
//	for the current PHP version.
function highlight_query_code_snippets( $dom ) {
	if ( PHP_VERSION_ID >= 80400 ) {
		return $dom->querySelectorAll( "pre>code[class^=language-]" );
	}

	$xpath = new DOMXPath( $dom );
	return $xpath->query( "//pre/code[starts-with(@class, 'language-')]" );
}

//	Gets the class attribute of an element using the API available in the current PHP version.
function highlight_get_class( $element ): string {
	if ( PHP_VERSION_ID >= 80400 ) {
		return $element->className;
	}

	return $element->getAttribute( 'class' );
}

//	Replaces the inner HTML of a DOM element.
//	On PHP 8.4+, uses the innerHTML property directly.
//	On older PHP, creates a temporary DOMDocument to parse the HTML,
//	then imports each child node into the main document.
function highlight_set_inner_html( $dom, $element, string $html ): void {
	if ( PHP_VERSION_ID >= 80400 ) {
		$element->innerHTML = $html;
		return;
	}

	//	Remove existing children from the element.
	while ( $element->firstChild ) {
		$element->removeChild( $element->firstChild );
	}
	if ( '' === trim( $html ) ) {
		return;
	}
	//	Create a new DOM for it.
	$tmpDoc = new DOMDocument();
	$tmpDoc->encoding = 'UTF-8';
	libxml_use_internal_errors( true );
	$tmpDoc->loadHTML(
		'<?xml encoding="UTF-8"><div>' . $html . '</div>',
		LIBXML_NOERROR
	);
	libxml_clear_errors();
	//	Remove the XML processing instruction that loadHTML adds.
	foreach ( $tmpDoc->childNodes as $child ) {
		if ( $child instanceof DOMProcessingInstruction ) {
			$tmpDoc->removeChild( $child );
		}
	}
	//	Import the specific elements and their attributes.
	$container = $tmpDoc->documentElement->getElementsByTagName( 'div' )->item( 0 );
	if ( $container ) {
		foreach ( $container->childNodes as $child ) {
			$element->appendChild( $dom->importNode( $child, true ) );
		}
	}
}

//	Parses an HTML string and imports the resulting node into the main DOM.
//	On PHP 8.4+, uses Dom\HTMLDocument::createFromString.
//	On older PHP, creates a temporary DOMDocument to parse the HTML fragment,
//	then imports the first child into the main document.
function highlight_import_html( $dom, string $html ) {
	if ( PHP_VERSION_ID >= 80400 ) {
		//	Create a new DOM for it, then import the specific element and its attributes.
		$tmp = Dom\HTMLDocument::createFromString( $html, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED, "UTF-8" );
		return $tmp->firstChild ? $dom->importNode( $tmp->firstChild, true ) : null;
	}

	//	Create a new DOM for it.
	$tmpDoc = new DOMDocument();
	$tmpDoc->encoding = 'UTF-8';
	libxml_use_internal_errors( true );
	$tmpDoc->loadHTML(
		'<?xml encoding="UTF-8"><div>' . $html . '</div>',
		LIBXML_NOERROR
	);
	libxml_clear_errors();
	//	Remove the XML processing instruction that loadHTML adds.
	foreach ( $tmpDoc->childNodes as $child ) {
		if ( $child instanceof DOMProcessingInstruction ) {
			$tmpDoc->removeChild( $child );
		}
	}
	//	Import the specific element and its attributes.
	$div = $tmpDoc->documentElement->getElementsByTagName( 'div' )->item( 0 );
	return $div && $div->firstChild ? $dom->importNode( $div->firstChild, true ) : null;
}

//	Saves the DOM back to HTML, stripping the DOCTYPE that the legacy DOMDocument adds.
function highlight_save_html( $dom ): string {
	if ( PHP_VERSION_ID >= 80400 ) {
		return $dom->saveHTML();
	}

	return preg_replace( '/^<!DOCTYPE[^>]*>\s*/i', '', $dom->saveHTML() );
}

/** @return array<string> */
function getLanguageProperties( string $language ):array {

	$language = strtolower( $language );
	
	switch( $language ) {
		case "bash":
			$language         = "bash";
			$language_logo    = "bash";
			$language_display = "Bash";
			break;
		case "sh":
			$language         = "bash";
			$language_logo    = "bash";
			$language_display = "Bash";
			break;
		case "shell":
			$language         = "bash";
			$language_logo    = "bash";
			$language_display = "Bash";
			break;
		case "html":
			$language         = "html";
			$language_logo    = "html";
			$language_display = "HTML";
			break;
		case "html5":
			$language         = "html";
			$language_logo    = "html";
			$language_display = "HTML";
			break;
		case "py":
			$language         = "python";
			$language_logo    = "python";
			$language_display = "Python 3";
			break;
		case "python":
			$language         = "python";
			$language_logo    = "python";
			$language_display = "Python 3";
			break;
		case "python3":
			$language         = "python";
			$language_logo    = "python";
			$language_display = "Python 3";
			break;
		case "python2":
			$language         = "python";
			$language_logo    = "python";
			$language_display = "Python 2";
			break;
		case "json":
			$language         = "json";
			$language_logo    = "json";
			$language_display = "JSON";
			break;
		case "js":
			$language         = "javascript";
			$language_logo    = "javascript";
			$language_display = "JavaScript";
			break;
		case "sql":
			$language         = "sql";
			$language_logo    = "mysql";
			$language_display = "SQL";
			break;
		case "markdown":
			$language         = "markdown";
			$language_logo    = "markdown";
			$language_display = "Markdown";
			break;
		case "md":
			$language         = "markdown";
			$language_logo    = "markdown";
			$language_display = "Markdown";
			break;
		case "mysql":
			$language         = "sql";
			$language_logo    = "mysql";
			$language_display = "MySQL";
			break;
		case "svg":
			$language         = "xml";
			$language_logo    = "svg";
			$language_display = "SVG";
			break;
		case "_":
			$language         = "_";
			$language_logo    = "";
			$language_display = "";
			break;
		default:
			$language_logo    = $language;
			$language_display =	strtoupper( $language );
	}

	return [$language, $language_logo, $language_display];
}

function generateLanguageHTML( string $language_logo, string $language_display ):string {
	//	Display an icon if one exists.
	if ( file_exists( plugin_dir_path( __FILE__ ) . "svg/" . $language_logo . ".svg" ) ) {
		$language_icon = plugin_dir_url(  __FILE__ ) . "svg/" . $language_logo . ".svg";
	} else {
		//	Default just show a placeholder icon.
		$language_icon = plugin_dir_url(  __FILE__ ) . "svg/notepad.svg";
	}
	$language_html =
		"<span class=tempest-highlight-language>" .
			"<img src=\"{$language_icon}\" width=32 height=32 alt class=tempest-highlight-language-icon>".
			"<span itemprop=programmingLanguage> {$language_display}</span>".
		"</span>";

	return $language_html;
}

//	Enqueue any base CSS
function enqueueBaseCSS():void {

	//	Prevent the CSS being added multiple times
	static $already_added = false;
	if ( $already_added ) {
		return;
	}
	$already_added = true;

	//	CSS file in the root of the plugin's directory.
	$baseCSS = __DIR__ . "/tempest-highlight-base.css";

	//	Insert it into the head.
	if ( file_exists( $baseCSS ) ) {
		$css = file_get_contents( $baseCSS );
		wp_register_style(   "tempest-highlight-base", false );
		wp_enqueue_style(    "tempest-highlight-base" );
		wp_add_inline_style( "tempest-highlight-base", $css );
	}
}
