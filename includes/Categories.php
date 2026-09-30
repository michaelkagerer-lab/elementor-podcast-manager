<?php
/**
 * Apple Podcasts category taxonomy (Layer 1).
 *
 * Single source of truth for the settings select, the feed's nested
 * <itunes:category> output and the readiness report. Values are the exact
 * English strings Apple expects in the feed — they are never translated.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Categories {

	/**
	 * Separator used by the settings select to encode "Category::Subcategory".
	 */
	public const SEPARATOR = '::';

	/**
	 * Top-level categories mapped to their subcategories.
	 *
	 * @return array<string, string[]>
	 */
	public static function all(): array {
		return apply_filters(
			'epm_podcast_categories',
			[
				'Arts'                    => [ 'Books', 'Design', 'Fashion & Beauty', 'Food', 'Performing Arts', 'Visual Arts' ],
				'Business'                => [ 'Careers', 'Entrepreneurship', 'Investing', 'Management', 'Marketing', 'Non-Profit' ],
				'Comedy'                  => [ 'Comedy Interviews', 'Improv', 'Stand-Up' ],
				'Education'               => [ 'Courses', 'How To', 'Language Learning', 'Self-Improvement' ],
				'Fiction'                 => [ 'Comedy Fiction', 'Drama', 'Science Fiction' ],
				'Government'              => [],
				'History'                 => [],
				'Health & Fitness'        => [ 'Alternative Health', 'Fitness', 'Medicine', 'Mental Health', 'Nutrition', 'Sexuality' ],
				'Kids & Family'           => [ 'Education for Kids', 'Parenting', 'Pets & Animals', 'Stories for Kids' ],
				'Leisure'                 => [ 'Animation & Manga', 'Automotive', 'Aviation', 'Crafts', 'Games', 'Hobbies', 'Home & Garden', 'Video Games' ],
				'Music'                   => [ 'Music Commentary', 'Music History', 'Music Interviews' ],
				'News'                    => [ 'Business News', 'Daily News', 'Entertainment News', 'News Commentary', 'Politics', 'Sports News', 'Tech News' ],
				'Religion & Spirituality' => [ 'Buddhism', 'Christianity', 'Hinduism', 'Islam', 'Judaism', 'Religion', 'Spirituality' ],
				'Science'                 => [ 'Astronomy', 'Chemistry', 'Earth Sciences', 'Life Sciences', 'Mathematics', 'Natural Sciences', 'Nature', 'Physics', 'Social Sciences' ],
				'Society & Culture'       => [ 'Documentary', 'Personal Journals', 'Philosophy', 'Places & Travel', 'Relationships' ],
				'Sports'                  => [ 'Baseball', 'Basketball', 'Cricket', 'Fantasy Sports', 'Football', 'Golf', 'Hockey', 'Rugby', 'Running', 'Soccer', 'Swimming', 'Tennis', 'Volleyball', 'Wilderness', 'Wrestling' ],
				'Technology'              => [],
				'True Crime'              => [],
				'TV & Film'               => [ 'After Shows', 'Film History', 'Film Interviews', 'Film Reviews', 'TV Reviews' ],
			]
		);
	}

	/**
	 * Whether a top-level category is known.
	 *
	 * @param string $category Category name.
	 * @return bool
	 */
	public static function is_valid( string $category ): bool {
		return array_key_exists( $category, self::all() );
	}

	/**
	 * Whether a subcategory belongs to the given category.
	 *
	 * @param string $category    Category name.
	 * @param string $subcategory Subcategory name.
	 * @return bool
	 */
	public static function is_valid_subcategory( string $category, string $subcategory ): bool {
		$all = self::all();

		return isset( $all[ $category ] ) && in_array( $subcategory, $all[ $category ], true );
	}

	/**
	 * Split an encoded "Category::Subcategory" select value.
	 *
	 * Plain category names (the pre-1.2 free-text setting) stay supported.
	 *
	 * @param string $value Encoded value.
	 * @return array{0: string, 1: string} Category and subcategory ('' when none).
	 */
	public static function decode( string $value ): array {
		$parts = explode( self::SEPARATOR, $value, 2 );

		return [ trim( $parts[0] ), trim( $parts[1] ?? '' ) ];
	}

	/**
	 * Encode a category/subcategory pair for the settings select.
	 *
	 * @param string $category    Category.
	 * @param string $subcategory Subcategory.
	 * @return string
	 */
	public static function encode( string $category, string $subcategory = '' ): string {
		return '' === $subcategory ? $category : $category . self::SEPARATOR . $subcategory;
	}
}
