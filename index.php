<?php
/***
Plugin Name: Tempest-Highlight
Plugin URI: https://github.com/edent/highlight
Description: Syntax highlighting. Based on Tempest Highlight - https://github.com/tempestphp/highlight
Author: Terence Eden
Version: 0.02
Author URI: https://edent.tel/

Tempest Highlight - https://github.com/tempestphp/highlight - is a package for server-side, high-performance, and flexible code highlighting.

This file originally part of WP-GeSHi-Highlight-Redux which was originally based on WP-GeSHi-Highlight
by Dr. Jan-Philip Gehrcke - https://gehrcke.de

WP-GeSHi-Highlight was originally based on WP-Syntax by Ryan McGeary.
https://web.archive.org/web/20160606232402/http://wp-syntax.com/

Copyright (C) 2024- Terence Eden
Copyright (C) 2010-2023 Dr. Jan-Philip Gehrcke
Copyright (C) 2007-2009 Ryan McGeary
	
## This is how the plugin works for all page requests

### I) the_content hook:

1. The page is about to be rendered. `$wp_query` contains information about all content potentially shown to the user.
2. This plugin iterates over the post's content and each (approved) comment belonging to the post.
3. This plugin searches for the pattern <pre><code class="language-*">CODE</code></pre> (the "language-" prefix is optional).
4. If a match is found, the information (language and CODE) is stored in a global variable, together with a match index.
5. If there is code to highlight, the occurrence of the pattern is replaced by a unique identifier containing the corresponding match index. Therefore, the content cannot be altered by any other plugin afterwards.
6. The plugin iterates over all code snippets and generates valid HTML code with inline CSS for each snippet, according to the given programming language.
8. For each code snippet, the HTML code and the corresponding match index is stored in a global variable.

### II) CSS:

The plugin instructs WordPress to print include the following resources in the head section of the HTML document:

* A style element containing the contents of template-highlight-base.css, from the plugin directory, for general styling of code blocks.

### III) content filters:

* The plugin defines three low priority filters on post text, post excerpt, and comment text.
* These filters run after most other plugins have done their job, i.e. shortly before the HTML is sent to the browser.
* The filter code searches the content for the unique identifiers stored in step I.5.
* If an identifier is found, it is replaced by the corresponding highlighted code snippet.
***/

// Entry point of the plugin (after the template renders the HTML output).
add_action( "the_content", "tempest_highlight_main", 49 );

//	Load the Tempest Highlight library
require_once __DIR__ . "/autoload.php";

//	Set up the namespace
use Tempest\Highlight\Highlighter;

//	Define a theme
//	Choose from any in `src/Themes/Css/`
$css_theme = "light-plus";

//	Main function
function tempest_highlight_main( $content ) {
	//	Don't change the content on RSS / Atom feeds, nor on lists
	if ( is_feed() || !is_single() ) {
		return $content;
	}

	//	Set up variables
	global $tempest_codesnipmatch_arrays;
	global $tempest_run_token;
	global $tempest_comments;
	$tempest_comments = array();

	// Snippets will temporarily be replaced by a unique token.
	$tempest_run_token = uniqid( rand() );

	// Filter all post/comment texts and store and replace code snippets.
	$content = tempest_filter_and_replace_code_snippets( $content );

	// If no snippets to highlight were found it is time to leave.
	if ( !$tempest_codesnipmatch_arrays || !count( $tempest_codesnipmatch_arrays ) ) return $content;

	//	`$tempest_codesnipmatch_arrays` is populated.
	//	Process the code.
	tempest_highlight_and_generate_css();

	// Add high priority filter to replace comments with the ones stored in `$tempest_comments`.
	// In `tempest_filter_and_replace_code_snippets()` the comments are queried, filtered and stored in `$tempest_comments`.
	// But, unlike posts, comments are queried again when `comments_template()` is called by the theme; so comments are read two times from the database.
	// After the second read, all changes (including the "uuid replacement") are lost.
	// The `comments_array` filter is triggered and can be used to set all comments to the state after the first filtering by tempest-highlight (as saved in `$tempest_comments`).
	add_filter( "comments_array", "tempest_insert_comments_with_uuid", 1 );

	// Add low priority filter to replace unique identifiers with highlighted code.
	add_filter( "the_content",  "tempest_insert_highlighted_code_filter", 99 );
	add_filter( "the_excerpt",  "tempest_insert_highlighted_code_filter", 99 );
	add_filter( "comment_text", "tempest_insert_highlighted_code_filter", 99 );

	//	The content has now been enhanced and can be returned
	return $content;
}

// Parse all posts and comments related to the current query.
// While iterating over these texts, do the following:
// - Detect <pre><code class="language-*">CODE</code></pre> patterns.
// - Store these patterns in a global variable.
// - Modify post/comment texts: replace code patterns by a unique identifier.
function tempest_filter_and_replace_code_snippets( $content ) {
	global $wp_query;
	global $tempest_comments;

	$content = tempest_filter_replace_code( $content );

	//	Enrich the comments
	$post = get_post();
	// Iterate over all approved comments belonging to this post.
	// Store comments with uuid (code replacement) in `$tempest_comments`.
	$comments = get_approved_comments( $post->ID );
	foreach ( $comments as $comment ) {
		$tempest_comments[$comment->comment_ID] =
			tempest_filter_replace_code( $comment->comment_content );
	}

	return $content;
}

// This is called from the comments_array filter.
// Replace comments from the second DB read with the ones stored in `$tempest_comments`.
function tempest_insert_comments_with_uuid( $comments_2nd_read ) {
	global $tempest_comments;
	
	// Iterate over comments from 2nd read.
	// Call by reference, otherwise the changes have no effect.
	foreach ( $comments_2nd_read as &$comment ) {
		if (array_key_exists( $comment->comment_ID, $tempest_comments )) {
			// Replace the comment content from 2nd read with the content that was created after the 1st read.
			$comment->comment_content = $tempest_comments[$comment->comment_ID];
		}
	}
	return $comments_2nd_read;
}

// Search all <pre><code class="language-*"></code></pre> occurrences.
// Store them globally.
// Replace them with unique identifiers (uuid+snippet ID).
// Call `tempest_store_and_substitute($match)` for each match.
// A `$match` is an array, following the sub-pattern of the regex:
// 0: all
// 1: language
// 2: code
function tempest_filter_replace_code($s) {
	return preg_replace_callback(
		//	Match `language-whatever` and `whatever`
        '/\s*<pre><code(?:class=["\'](?:language\-)?([\w-]+)["\']|\s)+>(.*)<\/code><\/pre>\s*/siU',
		"tempest_store_and_substitute",
		$s
	);
}

// Store snippet data. Return identifier for this snippet.
function tempest_store_and_substitute( $match_array ) {
	global $tempest_run_token, $tempest_codesnipmatch_arrays;

	// count() returns 0 if the variable is not set already.
	// Index is required for building the identifier for this code snippet.
	$match_index = $tempest_codesnipmatch_arrays ? count( $tempest_codesnipmatch_arrays ) : 0;

	// Elements of `$match_array` are strings matching the sub-expressions in the regular expression search from `tempest_filter_replace_code()`.
	// They contain the language and the code snippet itself.
	// Store this array for later usage.
	// Append the match index to `$match_array`.
	$match_array[] = $match_index;
	$tempest_codesnipmatch_arrays[$match_index] = $match_array;

	// Return a string that identifies the match.
	// This string replaces the <pre><code class="language-*>…</code></pre> pattern.
	return "<p>" . $tempest_run_token . "_" .
		sprintf("%06d", $match_index) . "</p>";
}

// Iterate through all match arrays in `$tempest_codesnipmatch_arrays`.
// Perform highlighting operation and store the resulting HTML in `$tempest_highlighted_matches[$match_index]`.
function tempest_highlight_and_generate_css() {
	global $tempest_codesnipmatch_arrays;
	global $tempest_highlighted_matches;
	global $css_theme;

	//	Define the theme.
	$theme = new Tempest\Highlight\Themes\InlineTheme( __DIR__ . "/src/Themes/Css/{$css_theme}.css");
	//	Create the highlighter.
	$highlighter = new Tempest\Highlight\Highlighter( $theme );

	//	Iterate over the code snippets which have been found.
	foreach ( $tempest_codesnipmatch_arrays as $match_index => $match ) {
		//	Process match details.
		//	The array structure is explained in `tempest_filter_replace_code()`.
		$language = strtolower( trim( $match[1] ) );
		//	Some Markdown parsers use "language-python"
		$language = str_replace( "language-", "", $language );

		//	Get the code
		$code = $match[2];

        //	Special ltrim because leading whitespace matters on 1st line of content.
        $code = preg_replace( "/^\s*\n/siU", "", $code );
        $code = rtrim( $code );
		$code = htmlspecialchars_decode( $code );
		//	Get rid of any badly encoded posts
		$code = html_entity_decode( $code );

		//	Rename some languages for better support.
		switch( $language ) {
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
				$language         = "javascript";
				$language_logo    = "json";
				$language_display = "JSON";
				break;
			case "js":
				$language         = "javascript";
				$language_logo    = "javascript";
				$language_display = "JavaScript";
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

		//	Start the output!

		//	Display an icon if one exists.
		if ( file_exists(    plugin_dir_path( __FILE__ ) . "/svg/" . $language_logo . ".svg" ) ) {
			$language_icon = plugin_dir_url(  __FILE__ ) . "/svg/" . $language_logo . ".svg";
			$language_html = 
				"<span class=tempest-highlight-language>" .
			    	"<img src=\"{$language_icon}\" width=32 height=32 alt class=tempest-highlight-language-icon>".
					"<span itemprop=programmingLanguage> {$language_display}</span>".
				"</span>";
		} else {
			//	If this is the null language (_) don't show anything
			if ( $language_display != "" ){
				$language_html = "<span class=\"tempest-highlight-language\" itemprop=\"programmingLanguage\"> {$language_display}</span>";
			} else {
				$language_html = "";
			}
		}

		//	Add a comment so we know it has worked
		$output  = "<!-- Tempest-Highlight {$language} -->\n";

		//	Add semantic microdata
		$output .= "<pre class=tempest-highlight itemscope itemtype=https://schema.org/SoftwareSourceCode>";
		$output .=    $language_html;
		$output .=    "<code class={$language} itemprop=text>";

		//	Create highlighted HTML code.
		$output .= $highlighter->parse( $code, $language );

		//	Close the code block.
		$output .= "</code></pre>";

		//	Store highlighted HTML code for later usage.
		$tempest_highlighted_matches[$match_index] = $output;

		//	Enqueue the CSS
		tempest_base_css();
	}

	// All code snippets have been parsed.
	// Highlighted code is stored.
	// CSS code has been generated.
	// Delete what is not required any more.
	unset( $tempest_codesnipmatch_arrays );
}

// Replace snippet IDs with highlighted HTML.
function tempest_insert_highlighted_code_filter( $content ) {
	global $tempest_run_token;
	return preg_replace_callback(
		"/<p>\s*" . $tempest_run_token . "_(\d{6})\s*<\/p>/si",
		"tempest_get_highlighted_code",
		$content
	);
}

//	Add matches to the highlighted code
function tempest_get_highlighted_code( $match ) {
	global $tempest_highlighted_matches;
	//	Found a unique identifier.
	//	Extract code snippet match index.
	$match_index = intval( $match[1] );
	//	Return corresponding highlighted code.
	return $tempest_highlighted_matches[$match_index];
}

//	Enqueue any base CSS
function tempest_base_css() {

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
