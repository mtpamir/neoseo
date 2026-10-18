<?php
/**
 * WooCommerce attribute to schema.org property mapping.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a WooCommerce product attribute to the schema.org property it
 * describes.
 *
 * Attribute names are chosen by the merchant, so there is no reliable
 * structural signal — "Colour", "Farbe" and "Shade" are all the same thing to a
 * shopper and all different strings to a plugin. Resolution runs in three
 * stages, most specific first: an explicit mapping saved on the settings
 * screen, then a built-in synonym list, then the `neoseo_attribute_property`
 * filter for anything neither covers.
 */
class AttributeMap {

	/**
	 * Schema.org properties an attribute may map to.
	 *
	 * `audience` is emitted as a PeopleAudience node rather than a string;
	 * see Product::attribute_node().
	 */
	public const PROPERTIES = array( 'color', 'size', 'material', 'pattern', 'audience' );

	/**
	 * Explicit "do not map this attribute" marker, stored by the settings UI.
	 */
	public const IGNORE = 'none';

	/**
	 * Option holding merchant overrides, keyed by normalized attribute name.
	 */
	private const OPTION = 'neoseo_attribute_map';

	/**
	 * Attribute names, normalized and lowercased, that indicate each property.
	 *
	 * Deliberately multilingual: a German or French store names its
	 * attributes in its own language, and asking every such merchant to
	 * configure the mapping by hand is a worse default than guessing well.
	 *
	 * @var array<string, string[]>
	 */
	private const SYNONYMS = array(
		'color'    => array( 'color', 'colour', 'colors', 'colours', 'farbe', 'couleur', 'colore', 'kleur', 'cor', 'kolor', 'färg', 'shade' ),
		'size'     => array( 'size', 'sizes', 'größe', 'grösse', 'groesse', 'taille', 'talla', 'taglia', 'maat', 'storlek', 'rozmiar', 'tamanho' ),
		'material' => array( 'material', 'materials', 'fabric', 'materiaal', 'matière', 'matiere', 'materiale', 'stoff', 'tejido', 'tissu' ),
		'pattern'  => array( 'pattern', 'patterns', 'print', 'muster', 'motif', 'patrón', 'patron', 'patroon', 'dessin' ),
		'audience' => array( 'audience', 'gender', 'sex', 'geschlecht', 'genre', 'género', 'genero', 'doelgroep', 'kön' ),
	);

	/**
	 * Attribute values that indicate a suggested gender.
	 *
	 * @var array<string, string[]>
	 */
	private const GENDERS = array(
		'male'   => array( 'male', 'men', 'mens', "men's", 'man', 'boy', 'boys', 'herren', 'homme', 'hombre', 'uomo' ),
		'female' => array( 'female', 'women', 'womens', "women's", 'woman', 'girl', 'girls', 'damen', 'femme', 'mujer', 'donna' ),
		'unisex' => array( 'unisex', 'both', 'all', 'any', 'universal' ),
	);

	/**
	 * Cached merchant overrides.
	 *
	 * @var array<string, string>|null
	 */
	private $overrides = null;

	/**
	 * Resolves an attribute name to a schema.org property.
	 *
	 * @param string $attribute Raw attribute name, e.g. 'pa_color' or 'Shade'.
	 * @return string One of self::PROPERTIES, or '' when unmapped.
	 */
	public function resolve( string $attribute ): string {
		$key = self::normalize( $attribute );

		if ( '' === $key ) {
			return '';
		}

		$property  = '';
		$overrides = $this->overrides();

		if ( isset( $overrides[ $key ] ) ) {
			// An explicit "none" is a decision, not an absence: stop here
			// rather than falling through to the synonym guess.
			$property = self::IGNORE === $overrides[ $key ] ? '' : $overrides[ $key ];
		} else {
			foreach ( self::SYNONYMS as $candidate => $words ) {
				if ( in_array( $key, $words, true ) ) {
					$property = $candidate;
					break;
				}
			}
		}

		/**
		 * Filters the schema.org property a product attribute maps to.
		 *
		 * @since 1.0.0
		 *
		 * @param string $property  One of AttributeMap::PROPERTIES, or ''.
		 * @param string $attribute Raw attribute name.
		 * @param string $key       Normalized attribute name.
		 */
		$property = apply_filters( 'neoseo_attribute_property', $property, $attribute, $key );

		return is_string( $property ) && in_array( $property, self::PROPERTIES, true ) ? $property : '';
	}

	/**
	 * Suggested gender for an attribute value.
	 *
	 * @param string $value Attribute value, e.g. 'Women'.
	 * @return string 'male', 'female', 'unisex', or '' when unrecognised.
	 */
	public function gender( string $value ): string {
		$key = self::normalize( $value );

		foreach ( self::GENDERS as $gender => $words ) {
			if ( in_array( $key, $words, true ) ) {
				return $gender;
			}
		}

		return '';
	}

	/**
	 * Normalizes an attribute name or value for comparison.
	 *
	 * Drops WooCommerce's `pa_` taxonomy prefix, lowercases, and flattens
	 * separators so 'pa_shoe-size' and 'Shoe Size' collapse to one key.
	 *
	 * @param string $value Raw name or value.
	 * @return string
	 */
	public static function normalize( string $value ): string {
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

		if ( 0 === strpos( $key, 'pa_' ) ) {
			$key = substr( $key, 3 );
		}

		$key = str_replace( array( '-', '_' ), ' ', $key );
		$key = preg_replace( '/\s+/', ' ', $key );

		return trim( (string) $key );
	}

	/**
	 * Merchant overrides from the settings screen.
	 *
	 * @return array<string, string>
	 */
	private function overrides(): array {
		if ( null === $this->overrides ) {
			$saved = get_option( self::OPTION, array() );

			$this->overrides = is_array( $saved ) ? $saved : array();
		}

		return $this->overrides;
	}

	/**
	 * Human labels for the mappable properties, for the settings UI.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'color'    => __( 'Colour', 'neoseo' ),
			'size'     => __( 'Size', 'neoseo' ),
			'material' => __( 'Material', 'neoseo' ),
			'pattern'  => __( 'Pattern', 'neoseo' ),
			'audience' => __( 'Audience (gender)', 'neoseo' ),
		);
	}
}
