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

	/** Translate display labels; Apple feed values remain in English. */
	public static function label( string $value ): string {
		return match ( $value ) {
			'Arts' => __( 'Arts', 'elementor-podcast-manager' ),
			'Books' => __( 'Books', 'elementor-podcast-manager' ),
			'Design' => __( 'Design', 'elementor-podcast-manager' ),
			'Fashion & Beauty' => __( 'Fashion & Beauty', 'elementor-podcast-manager' ),
			'Food' => __( 'Food', 'elementor-podcast-manager' ),
			'Performing Arts' => __( 'Performing Arts', 'elementor-podcast-manager' ),
			'Visual Arts' => __( 'Visual Arts', 'elementor-podcast-manager' ),
			'Business' => __( 'Business', 'elementor-podcast-manager' ),
			'Careers' => __( 'Careers', 'elementor-podcast-manager' ),
			'Entrepreneurship' => __( 'Entrepreneurship', 'elementor-podcast-manager' ),
			'Investing' => __( 'Investing', 'elementor-podcast-manager' ),
			'Management' => __( 'Management', 'elementor-podcast-manager' ),
			'Marketing' => __( 'Marketing', 'elementor-podcast-manager' ),
			'Non-Profit' => __( 'Non-Profit', 'elementor-podcast-manager' ),
			'Comedy' => __( 'Comedy', 'elementor-podcast-manager' ),
			'Comedy Interviews' => __( 'Comedy Interviews', 'elementor-podcast-manager' ),
			'Improv' => __( 'Improv', 'elementor-podcast-manager' ),
			'Stand-Up' => __( 'Stand-Up', 'elementor-podcast-manager' ),
			'Education' => __( 'Education', 'elementor-podcast-manager' ),
			'Courses' => __( 'Courses', 'elementor-podcast-manager' ),
			'How To' => __( 'How To', 'elementor-podcast-manager' ),
			'Language Learning' => __( 'Language Learning', 'elementor-podcast-manager' ),
			'Self-Improvement' => __( 'Self-Improvement', 'elementor-podcast-manager' ),
			'Fiction' => __( 'Fiction', 'elementor-podcast-manager' ),
			'Comedy Fiction' => __( 'Comedy Fiction', 'elementor-podcast-manager' ),
			'Drama' => __( 'Drama', 'elementor-podcast-manager' ),
			'Science Fiction' => __( 'Science Fiction', 'elementor-podcast-manager' ),
			'Government' => __( 'Government', 'elementor-podcast-manager' ),
			'History' => __( 'History', 'elementor-podcast-manager' ),
			'Health & Fitness' => __( 'Health & Fitness', 'elementor-podcast-manager' ),
			'Alternative Health' => __( 'Alternative Health', 'elementor-podcast-manager' ),
			'Fitness' => __( 'Fitness', 'elementor-podcast-manager' ),
			'Medicine' => __( 'Medicine', 'elementor-podcast-manager' ),
			'Mental Health' => __( 'Mental Health', 'elementor-podcast-manager' ),
			'Nutrition' => __( 'Nutrition', 'elementor-podcast-manager' ),
			'Sexuality' => __( 'Sexuality', 'elementor-podcast-manager' ),
			'Kids & Family' => __( 'Kids & Family', 'elementor-podcast-manager' ),
			'Education for Kids' => __( 'Education for Kids', 'elementor-podcast-manager' ),
			'Parenting' => __( 'Parenting', 'elementor-podcast-manager' ),
			'Pets & Animals' => __( 'Pets & Animals', 'elementor-podcast-manager' ),
			'Stories for Kids' => __( 'Stories for Kids', 'elementor-podcast-manager' ),
			'Leisure' => __( 'Leisure', 'elementor-podcast-manager' ),
			'Animation & Manga' => __( 'Animation & Manga', 'elementor-podcast-manager' ),
			'Automotive' => __( 'Automotive', 'elementor-podcast-manager' ),
			'Aviation' => __( 'Aviation', 'elementor-podcast-manager' ),
			'Crafts' => __( 'Crafts', 'elementor-podcast-manager' ),
			'Games' => __( 'Games', 'elementor-podcast-manager' ),
			'Hobbies' => __( 'Hobbies', 'elementor-podcast-manager' ),
			'Home & Garden' => __( 'Home & Garden', 'elementor-podcast-manager' ),
			'Video Games' => __( 'Video Games', 'elementor-podcast-manager' ),
			'Music' => __( 'Music', 'elementor-podcast-manager' ),
			'Music Commentary' => __( 'Music Commentary', 'elementor-podcast-manager' ),
			'Music History' => __( 'Music History', 'elementor-podcast-manager' ),
			'Music Interviews' => __( 'Music Interviews', 'elementor-podcast-manager' ),
			'News' => __( 'News', 'elementor-podcast-manager' ),
			'Business News' => __( 'Business News', 'elementor-podcast-manager' ),
			'Daily News' => __( 'Daily News', 'elementor-podcast-manager' ),
			'Entertainment News' => __( 'Entertainment News', 'elementor-podcast-manager' ),
			'News Commentary' => __( 'News Commentary', 'elementor-podcast-manager' ),
			'Politics' => __( 'Politics', 'elementor-podcast-manager' ),
			'Sports News' => __( 'Sports News', 'elementor-podcast-manager' ),
			'Tech News' => __( 'Tech News', 'elementor-podcast-manager' ),
			'Religion & Spirituality' => __( 'Religion & Spirituality', 'elementor-podcast-manager' ),
			'Buddhism' => __( 'Buddhism', 'elementor-podcast-manager' ),
			'Christianity' => __( 'Christianity', 'elementor-podcast-manager' ),
			'Hinduism' => __( 'Hinduism', 'elementor-podcast-manager' ),
			'Islam' => __( 'Islam', 'elementor-podcast-manager' ),
			'Judaism' => __( 'Judaism', 'elementor-podcast-manager' ),
			'Religion' => __( 'Religion', 'elementor-podcast-manager' ),
			'Spirituality' => __( 'Spirituality', 'elementor-podcast-manager' ),
			'Science' => __( 'Science', 'elementor-podcast-manager' ),
			'Astronomy' => __( 'Astronomy', 'elementor-podcast-manager' ),
			'Chemistry' => __( 'Chemistry', 'elementor-podcast-manager' ),
			'Earth Sciences' => __( 'Earth Sciences', 'elementor-podcast-manager' ),
			'Life Sciences' => __( 'Life Sciences', 'elementor-podcast-manager' ),
			'Mathematics' => __( 'Mathematics', 'elementor-podcast-manager' ),
			'Natural Sciences' => __( 'Natural Sciences', 'elementor-podcast-manager' ),
			'Nature' => __( 'Nature', 'elementor-podcast-manager' ),
			'Physics' => __( 'Physics', 'elementor-podcast-manager' ),
			'Social Sciences' => __( 'Social Sciences', 'elementor-podcast-manager' ),
			'Society & Culture' => __( 'Society & Culture', 'elementor-podcast-manager' ),
			'Documentary' => __( 'Documentary', 'elementor-podcast-manager' ),
			'Personal Journals' => __( 'Personal Journals', 'elementor-podcast-manager' ),
			'Philosophy' => __( 'Philosophy', 'elementor-podcast-manager' ),
			'Places & Travel' => __( 'Places & Travel', 'elementor-podcast-manager' ),
			'Relationships' => __( 'Relationships', 'elementor-podcast-manager' ),
			'Sports' => __( 'Sports', 'elementor-podcast-manager' ),
			'Baseball' => __( 'Baseball', 'elementor-podcast-manager' ),
			'Basketball' => __( 'Basketball', 'elementor-podcast-manager' ),
			'Cricket' => __( 'Cricket', 'elementor-podcast-manager' ),
			'Fantasy Sports' => __( 'Fantasy Sports', 'elementor-podcast-manager' ),
			'Football' => __( 'Football', 'elementor-podcast-manager' ),
			'Golf' => __( 'Golf', 'elementor-podcast-manager' ),
			'Hockey' => __( 'Hockey', 'elementor-podcast-manager' ),
			'Rugby' => __( 'Rugby', 'elementor-podcast-manager' ),
			'Running' => __( 'Running', 'elementor-podcast-manager' ),
			'Soccer' => __( 'Soccer', 'elementor-podcast-manager' ),
			'Swimming' => __( 'Swimming', 'elementor-podcast-manager' ),
			'Tennis' => __( 'Tennis', 'elementor-podcast-manager' ),
			'Volleyball' => __( 'Volleyball', 'elementor-podcast-manager' ),
			'Wilderness' => __( 'Wilderness', 'elementor-podcast-manager' ),
			'Wrestling' => __( 'Wrestling', 'elementor-podcast-manager' ),
			'Technology' => __( 'Technology', 'elementor-podcast-manager' ),
			'True Crime' => __( 'True Crime', 'elementor-podcast-manager' ),
			'TV & Film' => __( 'TV & Film', 'elementor-podcast-manager' ),
			'After Shows' => __( 'After Shows', 'elementor-podcast-manager' ),
			'Film History' => __( 'Film History', 'elementor-podcast-manager' ),
			'Film Interviews' => __( 'Film Interviews', 'elementor-podcast-manager' ),
			'Film Reviews' => __( 'Film Reviews', 'elementor-podcast-manager' ),
			'TV Reviews' => __( 'TV Reviews', 'elementor-podcast-manager' ),
			default => $value,
		};
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
