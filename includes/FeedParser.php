<?php
/**
 * Podcast RSS parser (Layer 1).
 *
 * Turns a podcast feed from any host (Spotify for Creators, Buzzsprout,
 * Libsyn, Podbean, Transistor, Acast, Podigee, WordPress plugins, …) into
 * plain arrays the importer can map onto episodes and podcast settings.
 *
 * Tolerant by design: namespace URIs are matched case-insensitively (some
 * hosts serve variants of the iTunes URI), CDATA and HTML are both
 * accepted, byte-order marks and stray ampersands are repaired, and
 * missing optional tags never fail the parse. External entities are never
 * loaded (LIBXML_NONET, no DTD loading).
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FeedParser {

	/**
	 * Canonical namespace URIs by key (lowercase for matching).
	 */
	private const NAMESPACES = [
		'itunes'     => 'http://www.itunes.com/dtds/podcast-1.0.dtd',
		'content'    => 'http://purl.org/rss/1.0/modules/content/',
		'podcast'    => 'https://podcastindex.org/namespace/1.0',
		'atom'       => 'http://www.w3.org/2005/atom',
		'googleplay' => 'http://www.google.com/schemas/play-podcasts/1.0',
		'media'      => 'http://search.yahoo.com/mrss/',
		'dc'         => 'http://purl.org/dc/elements/1.1/',
		'psc'        => 'http://podlove.org/simple-chapters',
	];

	/**
	 * Other URIs publishers use for the same namespaces.
	 */
	private const ALIASES = [
		'podcast' => [
			'https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/1.0.md',
			'https://podcastindex.org/namespace/1.0/',
		],
	];

	/**
	 * Namespace URIs declared by the document being parsed, by key.
	 *
	 * @var array<string, string[]>
	 */
	private array $ns = [];

	/**
	 * Parse a feed document.
	 *
	 * @param string $xml Raw feed body.
	 * @return array{channel: array<string, mixed>, items: array<int, array<string, mixed>>}|\WP_Error
	 */
	public function parse( string $xml ) {
		$xml = $this->clean( $xml );

		if ( '' === trim( $xml ) ) {
			return new \WP_Error( 'epm_feed_empty', __( 'The feed is empty.', 'elementor-podcast-manager' ) );
		}

		$doc = $this->load( $xml );
		if ( ! $doc ) {
			$doc = $this->load( $this->repair( $xml ) );
		}

		if ( ! $doc ) {
			return new \WP_Error( 'epm_feed_invalid', __( 'This address does not return a valid RSS feed.', 'elementor-podcast-manager' ) );
		}

		$this->register_namespaces( $doc );

		if ( 'feed' === strtolower( $doc->getName() ) ) {
			return new \WP_Error( 'epm_feed_atom', __( 'This is an Atom feed. Podcast apps and this importer need the podcast’s RSS feed; your host lists it with the directory links.', 'elementor-podcast-manager' ) );
		}

		if ( 'rss' !== strtolower( $doc->getName() ) || ! isset( $doc->channel ) ) {
			return new \WP_Error( 'epm_feed_not_rss', __( 'This address returns XML, but not an RSS podcast feed.', 'elementor-podcast-manager' ) );
		}

		$channel = $doc->channel;
		$items   = [];
		foreach ( $channel->item as $item ) {
			$parsed = $this->item( $item );
			if ( null !== $parsed ) {
				$items[] = $parsed;
			}
		}

		return [
			'channel' => $this->channel( $channel ),
			'items'   => $items,
		];
	}

	/**
	 * Strip byte-order marks and leading garbage before the XML declaration.
	 *
	 * @param string $xml Raw body.
	 * @return string
	 */
	private function clean( string $xml ): string {
		$xml = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $xml );
		$pos = strpos( $xml, '<' );

		if ( false === $pos ) {
			return '';
		}

		$xml = substr( $xml, $pos );

		// Undeclared legacy encoding: bytes that are not UTF-8 are almost
		// always Windows-1252 (feeds exported from old CMSs).
		$declared = preg_match( '/^<\?xml[^>]*encoding=["\']([^"\']+)/i', $xml, $m ) ? strtolower( $m[1] ) : 'utf-8';
		if ( 'utf-8' === $declared && function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $xml, 'UTF-8' ) ) {
			$xml = (string) mb_convert_encoding( $xml, 'UTF-8', 'Windows-1252' );
		}

		// Control characters are not allowed in XML 1.0.
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xml );
	}

	/**
	 * Repair the two most common publisher mistakes: unescaped ampersands
	 * and HTML named entities (&nbsp;, &rsquo;) that XML does not define.
	 *
	 * @param string $xml XML.
	 * @return string
	 */
	private function repair( string $xml ): string {
		$xml = (string) preg_replace_callback(
			'/&([a-zA-Z][a-zA-Z0-9]*);/',
			static function ( $m ) {
				if ( in_array( $m[1], [ 'amp', 'lt', 'gt', 'quot', 'apos' ], true ) ) {
					return $m[0];
				}
				$char = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( $char === $m[0] || ! function_exists( 'mb_ord' ) ) {
					return '&amp;' . $m[1] . ';';
				}
				$out = '';
				foreach ( preg_split( '//u', $char, -1, PREG_SPLIT_NO_EMPTY ) as $c ) {
					$out .= '&#' . mb_ord( $c, 'UTF-8' ) . ';';
				}
				return $out;
			},
			$xml
		);

		return (string) preg_replace( '/&(?!(?:#\d+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);)/', '&amp;', $xml );
	}

	/**
	 * Load XML safely (no network, no external entities).
	 *
	 * @param string $xml XML.
	 * @return \SimpleXMLElement|null
	 */
	private function load( string $xml ): ?\SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $doc instanceof \SimpleXMLElement ? $doc : null;
	}

	/**
	 * Map declared namespace URIs onto our keys (case-insensitive, and
	 * tolerant of http/https and trailing-slash variants).
	 *
	 * @param \SimpleXMLElement $doc Document.
	 * @return void
	 */
	private function register_namespaces( \SimpleXMLElement $doc ): void {
		$this->ns = [];
		$declared = $doc->getDocNamespaces( true );

		foreach ( self::NAMESPACES as $key => $canonical ) {
			$this->ns[ $key ] = [ $canonical ];
			$known            = array_merge( [ $canonical ], self::ALIASES[ $key ] ?? [] );
			foreach ( $declared as $uri ) {
				foreach ( $known as $candidate ) {
					if ( $this->same_uri( (string) $uri, $candidate ) && ! in_array( (string) $uri, $this->ns[ $key ], true ) ) {
						$this->ns[ $key ][] = (string) $uri;
					}
				}
			}
		}
	}

	/**
	 * Whether two namespace URIs are equivalent.
	 *
	 * @param string $a URI.
	 * @param string $b URI.
	 * @return bool
	 */
	private function same_uri( string $a, string $b ): bool {
		$norm = static function ( string $uri ): string {
			return rtrim( (string) preg_replace( '#^https?://#', '', strtolower( trim( $uri ) ) ), '/' );
		};

		return $norm( $a ) === $norm( $b );
	}

	/**
	 * Child elements in one of our namespaces.
	 *
	 * @param \SimpleXMLElement $el   Parent.
	 * @param string            $ns   Namespace key.
	 * @param string            $name Local name.
	 * @return \SimpleXMLElement[]
	 */
	private function children( \SimpleXMLElement $el, string $ns, string $name ): array {
		$out = [];
		foreach ( $this->ns[ $ns ] ?? [] as $uri ) {
			$children = $el->children( $uri );
			foreach ( $children as $child ) {
				if ( strtolower( $child->getName() ) === strtolower( $name ) ) {
					$out[] = $child;
				}
			}
		}

		return $out;
	}

	/**
	 * First child in a namespace, or null.
	 *
	 * @param \SimpleXMLElement $el   Parent.
	 * @param string            $ns   Namespace key ('' for none).
	 * @param string            $name Local name.
	 * @return \SimpleXMLElement|null
	 */
	private function first( \SimpleXMLElement $el, string $ns, string $name ): ?\SimpleXMLElement {
		if ( '' === $ns ) {
			return isset( $el->{$name} ) ? $el->{$name}[0] : null;
		}

		$all = $this->children( $el, $ns, $name );

		return $all[0] ?? null;
	}

	/**
	 * Trimmed text of the first matching child.
	 *
	 * @param \SimpleXMLElement $el   Parent.
	 * @param string            $ns   Namespace key.
	 * @param string            $name Local name.
	 * @return string
	 */
	private function text( \SimpleXMLElement $el, string $ns, string $name ): string {
		$node = $this->first( $el, $ns, $name );

		return null === $node ? '' : trim( (string) $node );
	}

	/**
	 * Attribute of the first matching child.
	 *
	 * @param \SimpleXMLElement $el   Parent.
	 * @param string            $ns   Namespace key.
	 * @param string            $name Local name.
	 * @param string            $attr Attribute.
	 * @return string
	 */
	private function attr( \SimpleXMLElement $el, string $ns, string $name, string $attr ): string {
		$node = $this->first( $el, $ns, $name );

		return null === $node ? '' : self::a( $node, $attr );
	}

	/**
	 * Unqualified attribute of an element.
	 *
	 * SimpleXML resolves $node['name'] in the element's own namespace, so
	 * for <itunes:image href> it would look for itunes:href. Podcast tags
	 * use unqualified attributes.
	 *
	 * @param \SimpleXMLElement $node Element.
	 * @param string            $name Attribute.
	 * @return string
	 */
	private static function a( \SimpleXMLElement $node, string $name ): string {
		$attributes = $node->attributes();
		if ( null !== $attributes && isset( $attributes[ $name ] ) ) {
			return trim( (string) $attributes[ $name ] );
		}

		return trim( (string) $node[ $name ] );
	}

	/**
	 * Parse channel metadata.
	 *
	 * @param \SimpleXMLElement $c Channel.
	 * @return array<string, mixed>
	 */
	private function channel( \SimpleXMLElement $c ): array {
		$description_html = $this->text( $c, '', 'description' );
		if ( '' === $description_html ) {
			$description_html = $this->text( $c, 'itunes', 'summary' );
		}

		$image = $this->attr( $c, 'itunes', 'image', 'href' );
		if ( '' === $image ) {
			$image = $this->attr( $c, 'podcast', 'image', 'href' );
		}
		if ( '' === $image && isset( $c->image ) ) {
			// WordPress feeds may list the 32 px site icon first: the last
			// <image> is the podcast artwork.
			foreach ( $c->image as $node ) {
				if ( '' !== trim( (string) $node->url ) ) {
					$image = trim( (string) $node->url );
				}
			}
		}
		if ( '' === $image ) {
			$image = $this->attr( $c, 'googleplay', 'image', 'href' );
		}

		$categories = [];
		foreach ( $this->children( $c, 'itunes', 'category' ) as $category ) {
			$top = self::a( $category, 'text' );
			if ( '' === $top ) {
				continue;
			}
			$sub = '';
			foreach ( $this->children( $category, 'itunes', 'category' ) as $child ) {
				$sub = self::a( $child, 'text' );
				break;
			}
			$categories[] = [ html_entity_decode( $top, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), html_entity_decode( $sub, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ];
		}

		$owner       = $this->first( $c, 'itunes', 'owner' );
		$owner_name  = $owner ? $this->text( $owner, 'itunes', 'name' ) : '';
		$owner_email = $owner ? $this->text( $owner, 'itunes', 'email' ) : '';

		$self = '';
		$next = '';
		foreach ( $this->children( $c, 'atom', 'link' ) as $link ) {
			$rel = strtolower( self::a( $link, 'rel' ) );
			if ( 'self' === $rel && '' === $self ) {
				$self = self::a( $link, 'href' );
			} elseif ( 'next' === $rel && '' === $next ) {
				$next = self::a( $link, 'href' );
			}
		}

		$funding = [];
		foreach ( $this->children( $c, 'podcast', 'funding' ) as $node ) {
			$url = self::a( $node, 'url' );
			if ( '' !== $url ) {
				$funding[] = [ 'url' => $url, 'label' => trim( (string) $node ) ];
			}
		}

		$author = $this->text( $c, 'itunes', 'author' );
		if ( '' === $author ) {
			$author = $this->text( $c, 'googleplay', 'author' );
		}
		if ( '' === $author ) {
			$author = $owner_name;
		}

		// Owner email fallbacks: podcast:locked@owner, then managingEditor
		// ("mail@example.com (Name)").
		if ( '' === $owner_email ) {
			$owner_email = $this->attr( $c, 'podcast', 'locked', 'owner' );
		}
		if ( '' === $owner_email && preg_match( '/[^\s()<>]+@[^\s()<>]+/', (string) $c->managingEditor, $mail ) ) {
			$owner_email = $mail[0];
		}

		return [
			'title'            => $this->plain( (string) $c->title ),
			'link'             => trim( (string) $c->link ),
			'description'      => $this->plain( $description_html ),
			'description_html' => $description_html,
			'subtitle'         => $this->plain( $this->text( $c, 'itunes', 'subtitle' ) ),
			'language'         => trim( (string) $c->language ),
			'copyright'        => $this->plain( (string) $c->copyright ),
			'author'           => $this->plain( $author ),
			'owner_name'       => $this->plain( $owner_name ),
			'owner_email'      => sanitize_email( $owner_email ),
			'image'            => $image,
			'categories'       => $categories,
			'explicit'         => $this->explicit( $this->text( $c, 'itunes', 'explicit' ) ),
			'type'             => 'serial' === strtolower( $this->text( $c, 'itunes', 'type' ) ) ? 'serial' : 'episodic',
			'new_feed_url'     => $this->text( $c, 'itunes', 'new-feed-url' ),
			'self'             => $self,
			// Paged feeds (e.g. SoundCloud: 500 items per page).
			'next'             => $next,
			'podcast_guid'     => $this->text( $c, 'podcast', 'guid' ),
			'locked'           => 'yes' === strtolower( trim( $this->text( $c, 'podcast', 'locked' ) ) ),
			'funding'          => $funding,
			'generator'        => $this->plain( (string) $c->generator ),
			'complete'         => in_array( strtolower( $this->text( $c, 'itunes', 'complete' ) ), [ 'yes', 'true' ], true ),
			'persons'          => $this->persons( $c ),
		];
	}

	/**
	 * Parse one item. Items without any usable identity are skipped.
	 *
	 * @param \SimpleXMLElement $i Item.
	 * @return array<string, mixed>|null
	 */
	private function item( \SimpleXMLElement $i ): ?array {
		$enclosure = isset( $i->enclosure ) ? $i->enclosure[0] : null;
		$audio_url = $enclosure ? trim( (string) $enclosure['url'] ) : '';
		$audio_len = $enclosure ? (int) preg_replace( '/\D/', '', (string) $enclosure['length'] ) : 0;
		$audio_typ = $enclosure ? strtolower( trim( (string) $enclosure['type'] ) ) : '';

		// Some feeds carry the media only in <media:content>.
		if ( '' === $audio_url ) {
			foreach ( $this->children( $i, 'media', 'content' ) as $media ) {
				$type   = strtolower( self::a( $media, 'type' ) );
				$medium = strtolower( self::a( $media, 'medium' ) );
				if ( 'image' === $medium || 0 === strpos( $type, 'image/' ) ) {
					continue;
				}
				if ( '' === $type || 0 === strpos( $type, 'audio/' ) || 0 === strpos( $type, 'video/' ) ) {
					$audio_url = self::a( $media, 'url' );
					$audio_len = (int) self::a( $media, 'fileSize' );
					$audio_typ = $type;
					break;
				}
			}
		}

		if ( '' !== $audio_url ) {
			$audio_typ = AudioMetadata::normalize_mime( $audio_typ, $audio_url );
		}

		$guid_node = isset( $i->guid ) ? $i->guid[0] : null;
		$guid      = $guid_node ? trim( (string) $guid_node ) : '';
		$link      = trim( (string) $i->link );

		// Identity fallback chain (the same one podcast apps use).
		if ( '' === $guid ) {
			$guid = '' !== $audio_url ? $audio_url : $link;
		}

		$title = $this->plain( (string) $i->title );
		if ( '' === $title ) {
			$title = $this->plain( $this->text( $i, 'itunes', 'title' ) );
		}

		if ( '' === $guid || ( '' === $title && '' === $audio_url ) ) {
			return null;
		}

		$content = $this->text( $i, 'content', 'encoded' );
		$desc    = trim( (string) $i->description );
		$summary = $this->text( $i, 'itunes', 'summary' );

		$html = '' !== $content ? $content : ( '' !== $desc ? $desc : $summary );

		$pub = trim( (string) $i->pubDate );
		if ( '' === $pub ) {
			$pub = $this->text( $i, 'dc', 'date' );
		}
		$timestamp = '' !== $pub ? strtotime( $pub ) : false;

		$image = $this->attr( $i, 'itunes', 'image', 'href' );
		if ( '' === $image ) {
			$image = $this->attr( $i, 'googleplay', 'image', 'href' );
		}
		if ( '' === $image ) {
			foreach ( $this->children( $i, 'media', 'thumbnail' ) as $thumb ) {
				$image = self::a( $thumb, 'url' );
				break;
			}
		}
		if ( '' === $image ) {
			foreach ( $this->children( $i, 'media', 'content' ) as $media ) {
				if ( 'image' === strtolower( self::a( $media, 'medium' ) ) || 0 === strpos( strtolower( self::a( $media, 'type' ) ), 'image/' ) ) {
					$image = self::a( $media, 'url' );
					break;
				}
			}
		}

		// Some hosts (Podigee) use href= instead of the standard url=.
		$chapters_url = $this->attr( $i, 'podcast', 'chapters', 'url' );
		if ( '' === $chapters_url ) {
			$chapters_url = $this->attr( $i, 'podcast', 'chapters', 'href' );
		}
		$chapters_type = $this->attr( $i, 'podcast', 'chapters', 'type' );

		$transcripts = [];
		foreach ( $this->children( $i, 'podcast', 'transcript' ) as $node ) {
			$url = self::a( $node, 'url' );
			if ( '' !== $url ) {
				$transcripts[] = [
					'url'      => $url,
					'type'     => strtolower( self::a( $node, 'type' ) ),
					'language' => self::a( $node, 'language' ),
				];
			}
		}

		$type = strtolower( $this->text( $i, 'itunes', 'episodeType' ) );

		// Podcasting 2.0 numbering when iTunes numbering is missing (whole
		// numbers only: 100.5 would collide with episode 100).
		$episode = $this->text( $i, 'itunes', 'episode' );
		if ( '' === $episode ) {
			$candidate = $this->text( $i, 'podcast', 'episode' );
			$episode   = preg_match( '/^\d+$/', $candidate ) ? $candidate : '';
		}
		$season = $this->text( $i, 'itunes', 'season' );
		if ( '' === $season ) {
			$season = $this->text( $i, 'podcast', 'season' );
		}

		// Podlove Simple Chapters inline in the item.
		$inline_chapters = [];
		$psc             = $this->first( $i, 'psc', 'chapters' );
		if ( null !== $psc ) {
			foreach ( $this->children( $psc, 'psc', 'chapter' ) as $chapter ) {
				$chapter_title = $this->plain( self::a( $chapter, 'title' ) );
				if ( '' === $chapter_title ) {
					continue;
				}
				$inline_chapters[] = [
					'start' => self::duration_seconds( self::a( $chapter, 'start' ) ),
					'title' => $chapter_title,
					'url'   => self::a( $chapter, 'href' ),
				];
			}
		}

		return [
			'guid'          => $guid,
			'title'         => $title,
			'link'          => $link,
			'pub_date'      => false === $timestamp ? 0 : (int) $timestamp,
			'html'          => $html,
			'summary'       => $this->summary( $i, $desc, $summary ),
			'audio_url'     => $audio_url,
			'audio_type'    => $audio_typ,
			'audio_length'  => max( 0, $audio_len ),
			'duration'      => self::duration_seconds( $this->text( $i, 'itunes', 'duration' ) ),
			'episode'       => max( 0, (int) $episode ),
			'season'        => max( 0, (int) $season ),
			'episode_type'  => in_array( $type, [ 'full', 'trailer', 'bonus' ], true ) ? $type : 'full',
			'explicit'      => $this->explicit( $this->text( $i, 'itunes', 'explicit' ) ),
			'image'         => $image,
			'author'        => $this->plain( $this->text( $i, 'itunes', 'author' ) ),
			'chapters_url'  => $chapters_url,
			'chapters_type' => $chapters_type,
			'transcripts'   => $transcripts,
			'chapters'      => $inline_chapters,
			'persons'       => $this->persons( $i ),
			'block'         => 'yes' === strtolower( $this->text( $i, 'itunes', 'block' ) ),
		];
	}

	/**
	 * Podcasting 2.0 persons (hosts, guests …).
	 *
	 * @param \SimpleXMLElement $el Channel or item.
	 * @return array<int, array{name: string, role: string, img: string, href: string}>
	 */
	private function persons( \SimpleXMLElement $el ): array {
		$out = [];
		foreach ( $this->children( $el, 'podcast', 'person' ) as $node ) {
			$name = $this->plain( (string) $node );
			if ( '' === $name ) {
				continue;
			}
			$role  = strtolower( self::a( $node, 'role' ) );
			$out[] = [
				'name' => $name,
				'role' => '' !== $role ? $role : 'host',
				'img'  => self::a( $node, 'img' ),
				'href' => self::a( $node, 'href' ),
			];
		}

		return $out;
	}

	/**
	 * Short plain-text summary: itunes:subtitle when set, otherwise the
	 * start of the plain description.
	 *
	 * @param \SimpleXMLElement $i       Item.
	 * @param string            $desc    RSS description.
	 * @param string            $summary itunes:summary.
	 * @return string
	 */
	private function summary( \SimpleXMLElement $i, string $desc, string $summary ): string {
		$subtitle = $this->plain( $this->text( $i, 'itunes', 'subtitle' ) );
		if ( '' !== $subtitle ) {
			return $subtitle;
		}

		$plain = $this->plain( '' !== $summary ? $summary : $desc );

		return wp_trim_words( $plain, 40, '…' );
	}

	/**
	 * Normalize explicit flags (yes/true/explicit vs no/false/clean).
	 *
	 * @param string $value Raw value.
	 * @return string explicit|clean|'' (unknown).
	 */
	private function explicit( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( in_array( $value, [ 'yes', 'true', 'explicit', '1' ], true ) ) {
			return 'explicit';
		}
		if ( in_array( $value, [ 'no', 'false', 'clean', '0' ], true ) ) {
			return 'clean';
		}

		return '';
	}

	/**
	 * Plain text from possibly HTML/entity-encoded feed text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function plain( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_strip_all_tags( $text );
		// Decode twice: some hosts double-encode (&amp;amp;). Strip again so
		// an encoded "&lt;script&gt;" never turns into markup.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_strip_all_tags( $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Parse an itunes:duration value into seconds.
	 * Accepts "3723", "3723.5", "62:03", "1:02:03", "01:02:03.500".
	 *
	 * @param string $value Raw value.
	 * @return int
	 */
	public static function duration_seconds( string $value ): int {
		// "06: 58" occurs in the wild.
		$value = (string) preg_replace( '/\s+/', '', $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( is_numeric( $value ) ) {
			return max( 0, (int) round( (float) $value ) );
		}

		if ( ! preg_match( '/^\d+(?::\d{1,2}){1,2}(?:\.\d+)?$/', $value ) ) {
			return 0;
		}

		$parts   = array_map( 'floatval', explode( ':', $value ) );
		$seconds = 0.0;
		foreach ( $parts as $part ) {
			$seconds = $seconds * 60 + $part;
		}

		return max( 0, (int) round( $seconds ) );
	}
}
