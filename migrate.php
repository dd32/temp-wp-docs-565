<?php
ini_set('max_execution_time', 0);
ini_set('memory_limit', -1);


$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'developer.wordpress.org';

include dirname( __DIR__ ) . '/wp-load.php';
global $wpdb;


$data = $wpdb->get_results(
	"SELECT `ID`, `post_name`, `post_content`, `post_type`

	FROM `{$wpdb->posts}`
	
	
	WHERE 
	`post_content` LIKE '%<!--%' AND
	(
		`post_content` LIKE '%wp:syntaxhighlighter/code%'
		OR `post_content` LIKE '%[php]%'
		OR `post_content` LIKE '%[js]%'
		OR `post_content` LIKE '%[xml]%'
	) AND (`post_status` = 'publish' )",
	ARRAY_A
);

function make_alterations( $content ) {

	// On-the-fly fix wp:preformatted with shortcodes
	$content = preg_replace_callback(
		'~<!-- wp:preformatted.*?-->.*?<pre.+?>\[(?P<lang>php|js|xml)\](?<content>.+?)\[/\\1\]</pre>.*?<!-- /wp:preformatted -->~ism',
		function( $m ) {
			$lang    = esc_attr( $m['lang'] );
			$content = $m['content'];

			return "<!-- wp:code {\"language\":\"{$lang}\"} -->\n<pre class=\"wp-block-code\"><code lang=\"{$lang}\" class=\"language-{$lang}\">{$content}</code></pre>\n<!-- /wp:code -->";

		},
		$content
	);

	// On-the-fly migrate syntaxhighlighter/code to wp/code
	$content = preg_replace_callback(
		'~<!-- wp:syntaxhighlighter/code (?P<params>{[^}]+})?\s*-->.*?<pre.+?>(?<content>.+?)</pre>.*?<!-- /wp:syntaxhighlighter/code -->~ism',
		function( $m ) {
			$content = trim( $m['content'] );
			$details = json_decode( $m['params'] ?? '{}' );
			$lang    = esc_attr( $details->language ?? '' );
			$class   = esc_attr( $details->className ?? '' );

			if ( ! $lang && str_contains( $class, 'brush:' ) ) {
				preg_match( '/brush:\s*(\w+)(;|$)/i', $class, $class_match );
				$lang = $class_match[1] ?? '';
			}

			//$lang = $lang ?: 'php'; // Default to PHP highlighting.

			if ( ! $lang ) {
				// Instead of defaulting to PHP, attempt to find PHP-like code blocks.
				if (
					// A block with a PHPDoc comment is likely PHP.
					str_contains( $content, '/**' ) ||
					// A block with a function declaration is likely PHP.
					preg_match( '/function \S+\(/i', $content ) ||
					// A block with a '<?' in it is likely PHP.
					str_contains( $content, '<?' ) ||
					// If PHP thinks it's PHP..
					false !== highlight_string( '<?php ' . $content, true )
				) {
					$lang = 'php';
				}
				if (
					str_contains( $content, 'const ' )
				) {
					$lang = 'js';
				}
				if (
					str_starts_with( $content, '#: ' ) // .po file.
				) {
					$lang = '';
				}
			}

			$class = str_replace( [ "brush:$lang", "brush: $lang" ], '', $class );
			$class = ltrim( $class, ' ;' );

			if ( 'jscript' === $lang ) {
				$lang = 'js';
			}

			$lang_attr = '';
			$class_attr = '';
			if ( $lang ) {
				$class = trim( "language-{$lang} $class" );
				$lang_attr = " lang=\"{$lang}\"";
			}
			if ( $class ) {
				$class_attr = " class=\"$class\"";
			}

			$params = json_encode( array_filter( array(
				'language'  => $lang,
				'className' => preg_replace( '/^language-\S+\s*/', '', $class )
			) ) );
			if ( $params === '{}' || $params === '[]' ) {
				$params = '';
			}
			if ( $params ) {
				$params .= ' ';
			}

			return "<!-- wp:code {$params}-->\n<pre class=\"wp-block-code\"><code{$lang_attr}{$class_attr}>{$content}</code></pre>\n<!-- /wp:code -->";
		},
		$content
	);

	return $content;
}

foreach ( $data as $r ) {
	$name = __DIR__ . "/{$r['post_type']}/{$r['ID']}-{$r['post_name']}.txt";

	wp_mkdir_p( dirname( $name ) );

	$r['post_content'] = make_alterations( $r['post_content']  );

	file_put_contents( $name, get_permalink( $r['ID'] ) . "\n\n" . $r['post_content'] );

	echo $name . "\n";
}
