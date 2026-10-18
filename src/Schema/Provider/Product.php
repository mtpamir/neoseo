<?php
/**
 * Product node.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

use NeoSEO\Schema\AttributeMap;
use NeoSEO\Schema\ReturnPolicy;
use NeoSEO\Schema\ShippingDetails;

defined( 'ABSPATH' ) || exit;

/**
 * Rich Product node for single WooCommerce products: Offer/AggregateOffer,
 * availability, AggregateRating and Review nodes from approved reviews.
 *
 * Replaces WooCommerce's thinner core output (GraphManager unhooks it).
 */
class Product extends Base {

	/**
	 * Maximum reviews embedded in the node.
	 */
	private const MAX_REVIEWS = 5;

	/**
	 * Maximum images embedded in the node.
	 */
	private const MAX_IMAGES = 10;

	/**
	 * Maximum variants embedded in a ProductGroup.
	 *
	 * A product with 200 variations would otherwise put 200 nested nodes in
	 * the page source. Raise it with neoseo_product_group_node if a store
	 * genuinely needs more.
	 */
	private const MAX_VARIANTS = 30;

	/**
	 * Meta keys the common feed plugins use for the manufacturer part number,
	 * in priority order. WooCommerce itself has no MPN field.
	 */
	private const MPN_META_KEYS = array( '_mpn', 'mpn' );

	/**
	 * Meta key holding an EU energy efficiency class, e.g. 'A' or 'C'.
	 */
	private const ENERGY_CLASS_META = '_energy_efficiency_class';

	/**
	 * Meta key holding an EPREL registration number.
	 */
	private const EPREL_META = '_eprel_id';

	/**
	 * WooCommerce unit slugs mapped to UN/CEFACT common codes.
	 */
	private const UNIT_CODES = array(
		'kg'  => 'KGM',
		'g'   => 'GRM',
		'lbs' => 'LBR',
		'oz'  => 'ONZ',
		'm'   => 'MTR',
		'cm'  => 'CMT',
		'mm'  => 'MMT',
		'in'  => 'INH',
		'yd'  => 'YRD',
	);

	/**
	 * Only single product pages, with WooCommerce loaded.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		return function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' );
	}

	/**
	 * Builds the Product node.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		$product = wc_get_product( get_the_ID() );

		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		$node = array(
			'@type'            => 'Product',
			'@id'              => $this->page_id( 'product' ),
			'name'             => $product->get_name(),
			'url'              => (string) $product->get_permalink(),
			'mainEntityOfPage' => array( '@id' => $this->page_id( 'webpage' ) ),
			'brand'            => $this->brand( $product ),
		);

		$description = $product->get_short_description() ? $product->get_short_description() : $product->get_description();

		if ( $description ) {
			$node['description'] = wp_strip_all_tags( $description );
		}

		if ( $product->get_sku() ) {
			$node['sku'] = $product->get_sku();
		}

		$gtin = $product->get_global_unique_id();

		if ( $gtin ) {
			$node['gtin'] = $gtin;
		}

		$mpn = $this->mpn( $product );

		if ( '' !== $mpn ) {
			$node['mpn'] = $mpn;
		}

		$images = $this->images( $product );

		if ( $images ) {
			$node['image'] = $images;
		}

		$node = array_merge( $node, $this->measurements( $product ) );
		$node = array_merge( $node, $this->attributes( $product ) );

		$certification = $this->certification( $product );

		if ( $certification ) {
			$node['hasCertification'] = $certification;
		}

		$group = $this->variant_group( $product );

		if ( $group ) {
			// A variable product is a group of purchasable things, not one
			// purchasable thing. Google reads the variants only from a
			// ProductGroup, so the parent changes type rather than gaining a
			// second, competing node.
			$node['@type'] = 'ProductGroup';
			$node          = array_merge( $node, $group );
		}

		$node['offers'] = $this->offer( $product );

		if ( $product->get_review_count() > 0 ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (float) $product->get_average_rating(),
				'reviewCount' => (int) $product->get_review_count(),
			);

			$reviews = $this->reviews( $product );

			if ( $reviews ) {
				$node['review'] = $reviews;
			}
		}

		/**
		 * Filters the Product node before it joins the graph.
		 *
		 * Integrations (e.g. photo-review plugins adding associatedMedia)
		 * hook here.
		 *
		 * @since 1.0.0
		 *
		 * @param array       $node    Product node.
		 * @param \WC_Product $product Product object.
		 */
		$node = apply_filters( 'neoseo_product_node', $node, $product );

		return array( $node );
	}

	/**
	 * Brand node for the product.
	 *
	 * Prefers WooCommerce's core `product_brand` taxonomy, which is what a
	 * reseller actually populates. Only falls back to the store's own
	 * Organization when the product carries no brand term — a shop that
	 * resells is not the manufacturer of what it sells.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array<string, mixed>
	 */
	private function brand( \WC_Product $product ): array {
		if ( taxonomy_exists( 'product_brand' ) ) {
			$terms = get_the_terms( $product->get_id(), 'product_brand' );

			if ( is_array( $terms ) && $terms ) {
				$term = reset( $terms );

				if ( $term instanceof \WP_Term ) {
					return array(
						'@type' => 'Brand',
						'name'  => $term->name,
					);
				}
			}
		}

		return array( '@id' => $this->site_id( 'organization' ) );
	}

	/**
	 * Manufacturer part number.
	 *
	 * WooCommerce has no native MPN field, so read the meta keys the common
	 * feed plugins write and let integrations override.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string Empty when unknown.
	 */
	private function mpn( \WC_Product $product ): string {
		$mpn = '';

		foreach ( self::MPN_META_KEYS as $key ) {
			$value = $product->get_meta( $key );

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$mpn = trim( $value );
				break;
			}
		}

		/**
		 * Filters the manufacturer part number for a product.
		 *
		 * @since 1.0.0
		 *
		 * @param string      $mpn     Manufacturer part number, '' when unknown.
		 * @param \WC_Product $product Product object.
		 */
		$mpn = apply_filters( 'neoseo_product_mpn', $mpn, $product );

		return is_string( $mpn ) ? trim( $mpn ) : '';
	}

	/**
	 * Image URLs for the product: featured first, then the gallery.
	 *
	 * Google reads an array and shows more than one, so send the gallery
	 * rather than the featured image alone.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array<int, string>
	 */
	private function images( \WC_Product $product ): array {
		$ids      = array();
		$featured = (int) $product->get_image_id();

		if ( $featured ) {
			$ids[] = $featured;
		}

		foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
			$gallery_id = (int) $gallery_id;

			if ( $gallery_id && ! in_array( $gallery_id, $ids, true ) ) {
				$ids[] = $gallery_id;
			}
		}

		$urls = array();

		foreach ( array_slice( $ids, 0, self::MAX_IMAGES ) as $id ) {
			$src = wp_get_attachment_image_src( $id, 'full' );

			if ( $src && ! empty( $src[0] ) ) {
				$urls[] = $src[0];
			}
		}

		return $urls;
	}

	/**
	 * Descriptive properties mapped from the product's own attributes.
	 *
	 * Only single-valued attributes belong on the product itself. An
	 * attribute with several values is what the variations differ *by*, so
	 * it describes the group rather than any one item, and emitting
	 * "color: Blue, Green, Red" on the parent would be a claim no single
	 * purchasable thing satisfies.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array<string, mixed>
	 */
	private function attributes( \WC_Product $product ): array {
		$map  = new AttributeMap();
		$out  = array();
		$seen = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$property = $map->resolve( $attribute->get_name() );

			if ( '' === $property || in_array( $property, $seen, true ) ) {
				continue;
			}

			$values = $this->attribute_values( $attribute );

			if ( 1 !== count( $values ) ) {
				continue;
			}

			$node = $this->attribute_node( $property, $values[0], $map );

			if ( $node ) {
				$out    = array_merge( $out, $node );
				$seen[] = $property;
			}
		}

		return $out;
	}

	/**
	 * Values of a product attribute, whether taxonomy-backed or custom.
	 *
	 * @param \WC_Product_Attribute $attribute Attribute object.
	 * @return array<int, string>
	 */
	private function attribute_values( \WC_Product_Attribute $attribute ): array {
		$values = array();

		if ( $attribute->is_taxonomy() ) {
			$terms = $attribute->get_terms();

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( $term instanceof \WP_Term ) {
						$values[] = $term->name;
					}
				}
			}
		} else {
			foreach ( $attribute->get_options() as $option ) {
				if ( is_string( $option ) && '' !== trim( $option ) ) {
					$values[] = trim( $option );
				}
			}
		}

		return $values;
	}

	/**
	 * Single mapped property as it appears in the node.
	 *
	 * `audience` is the odd one out: schema.org expects an Audience object,
	 * not a string, and Google reads `suggestedGender` inside it.
	 *
	 * @param string       $property Schema.org property.
	 * @param string       $value    Attribute value.
	 * @param AttributeMap $map      Resolver, for gender lookups.
	 * @return array<string, mixed> Empty when the value cannot be expressed.
	 */
	private function attribute_node( string $property, string $value, AttributeMap $map ): array {
		if ( 'audience' === $property ) {
			$gender = $map->gender( $value );

			if ( '' === $gender ) {
				return array();
			}

			return array(
				'audience' => array(
					'@type'           => 'PeopleAudience',
					'suggestedGender' => $gender,
				),
			);
		}

		return array( $property => $value );
	}

	/**
	 * Weight and dimension properties, in the store's configured units.
	 *
	 * WooCommerce's "length" is the front-to-back measurement, which
	 * schema.org calls `depth`.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array<string, mixed>
	 */
	private function measurements( \WC_Product $product ): array {
		$out = array();

		$weight = $product->get_weight();

		if ( '' !== $weight && null !== $weight ) {
			$node = $this->quantitative( (string) $weight, (string) get_option( 'woocommerce_weight_unit', 'kg' ) );

			if ( $node ) {
				$out['weight'] = $node;
			}
		}

		$dimension_unit = (string) get_option( 'woocommerce_dimension_unit', 'cm' );

		$dimensions = array(
			'depth'  => $product->get_length(),
			'width'  => $product->get_width(),
			'height' => $product->get_height(),
		);

		foreach ( $dimensions as $property => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			$node = $this->quantitative( (string) $value, $dimension_unit );

			if ( $node ) {
				$out[ $property ] = $node;
			}
		}

		return $out;
	}

	/**
	 * QuantitativeValue node for a measurement.
	 *
	 * Uses the UN/CEFACT code where one is known, and always carries the
	 * store's own unit string so the value stays readable without it.
	 *
	 * @param string $value Raw WooCommerce measurement.
	 * @param string $unit  Store unit, e.g. 'kg' or 'cm'.
	 * @return array<string, mixed> Empty when the value is not numeric.
	 */
	private function quantitative( string $value, string $unit ): array {
		if ( ! is_numeric( $value ) ) {
			return array();
		}

		$node = array(
			'@type' => 'QuantitativeValue',
			'value' => (float) $value,
		);

		if ( '' !== $unit ) {
			$node['unitText'] = $unit;

			if ( isset( self::UNIT_CODES[ $unit ] ) ) {
				$node['unitCode'] = self::UNIT_CODES[ $unit ];
			}
		}

		return $node;
	}

	/**
	 * Certification node, when the store has the data for one.
	 *
	 * Google retired `EnergyConsumptionDetails` in favour of the broader
	 * `Certification` type and set an April 2026 migration deadline, so any EU
	 * store selling appliances needs this — and WooCommerce records none of it.
	 *
	 * That makes this **entirely data-gated**: nothing is emitted unless the
	 * store already holds an EPREL identifier or an efficiency class. The meta
	 * keys below are conventions rather than a standard, so the filter is the
	 * real integration point and the keys are a convenience for stores that
	 * happen to match.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array<string, mixed>|array<int, array<string, mixed>> Empty when unknown.
	 */
	private function certification( \WC_Product $product ): array {
		$node  = array();
		$class = trim( (string) $product->get_meta( self::ENERGY_CLASS_META ) );
		$eprel = trim( (string) $product->get_meta( self::EPREL_META ) );

		if ( '' !== $class || '' !== $eprel ) {
			$node = array(
				'@type'    => 'Certification',
				'name'     => 'EPREL',
				'issuedBy' => array(
					'@type' => 'Organization',
					'name'  => 'European Commission',
				),
			);

			if ( '' !== $eprel ) {
				$node['certificationIdentification'] = $eprel;
			}

			if ( '' !== $class ) {
				$node['certificationRating'] = array(
					'@type'       => 'Rating',
					'ratingValue' => $class,
				);
			}
		}

		/**
		 * Filters the product certification node.
		 *
		 * Return a single Certification node, or a list of them for a product
		 * carrying more than one. Return an empty array to emit nothing.
		 *
		 * @since 1.0.0
		 *
		 * @param array       $node    Certification node, or ''-equivalent empty array.
		 * @param \WC_Product $product Product object.
		 */
		$node = apply_filters( 'neoseo_product_certification', $node, $product );

		return is_array( $node ) ? $node : array();
	}

	/**
	 * ProductGroup properties for a variable product.
	 *
	 * `variesBy` deliberately omits `suggestedGender`: Google places it on the
	 * group rather than the variant, and A4 already puts a single-valued
	 * gender attribute on the parent node.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array<string, mixed> Empty unless the product is variable and has usable variations.
	 */
	protected function variant_group( \WC_Product $product ): array {
		if ( ! $product->is_type( 'variable' ) || ! method_exists( $product, 'get_children' ) ) {
			return array();
		}

		$map      = new AttributeMap();
		$group_id = $product->get_sku() ? $product->get_sku() : (string) $product->get_id();
		$variants = array();

		foreach ( array_slice( $product->get_children(), 0, self::MAX_VARIANTS ) as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$variant = $this->variant_node( $variation, $group_id, $map );

			if ( $variant ) {
				$variants[] = $variant;
			}
		}

		if ( ! $variants ) {
			return array();
		}

		$group = array(
			'productGroupID' => $group_id,
			'hasVariant'     => $variants,
		);

		$varies = $this->varies_by( $product, $map );

		if ( $varies ) {
			$group['variesBy'] = $varies;
		}

		/**
		 * Filters the ProductGroup properties before they join the node.
		 *
		 * @since 1.0.0
		 *
		 * @param array       $group   productGroupID, hasVariant and variesBy.
		 * @param \WC_Product $product Parent product object.
		 */
		$group = apply_filters( 'neoseo_product_group_node', $group, $product );

		return is_array( $group ) ? $group : array();
	}

	/**
	 * Schema.org property URLs the variations differ by.
	 *
	 * @param \WC_Product  $product Product object.
	 * @param AttributeMap $map     Attribute resolver.
	 * @return array<int, string>
	 */
	private function varies_by( \WC_Product $product, AttributeMap $map ): array {
		$varies = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}

			$property = $map->resolve( $attribute->get_name() );

			if ( '' === $property || 'audience' === $property ) {
				continue;
			}

			$varies[ 'https://schema.org/' . $property ] = true;
		}

		return array_keys( $varies );
	}

	/**
	 * One variant Product node.
	 *
	 * Every variant must carry an `offers` object or the whole group fails
	 * validation, so a variation without a price is skipped rather than
	 * emitted incomplete.
	 *
	 * @param \WC_Product_Variation $variation Variation object.
	 * @param string                $group_id  Parent group identifier.
	 * @param AttributeMap          $map       Attribute resolver.
	 * @return array<string, mixed> Empty when the variation cannot be offered.
	 */
	private function variant_node( \WC_Product_Variation $variation, string $group_id, AttributeMap $map ): array {
		if ( '' === (string) $variation->get_price() ) {
			return array();
		}

		$node = array(
			'@type'                => 'Product',
			'@id'                  => $this->page_id( 'variant-' . $variation->get_id() ),
			'name'                 => $variation->get_name(),
			'url'                  => (string) $variation->get_permalink(),
			'inProductGroupWithID' => $group_id,
		);

		if ( $variation->get_sku() ) {
			$node['sku'] = $variation->get_sku();
		}

		$gtin = $variation->get_global_unique_id();

		if ( $gtin ) {
			$node['gtin'] = $gtin;
		}

		$images = $this->images( $variation );

		if ( $images ) {
			$node['image'] = $images;
		}

		foreach ( $this->variation_properties( $variation, $map ) as $property => $value ) {
			$node[ $property ] = $value;
		}

		$node['offers'] = $this->offer( $variation );

		return $node;
	}

	/**
	 * Mapped descriptive properties for a single variation.
	 *
	 * Variation attributes are stored as slugs, so taxonomy-backed values are
	 * resolved back to the term name a shopper would recognise.
	 *
	 * @param \WC_Product_Variation $variation Variation object.
	 * @param AttributeMap          $map       Attribute resolver.
	 * @return array<string, mixed>
	 */
	private function variation_properties( \WC_Product_Variation $variation, AttributeMap $map ): array {
		$out = array();

		foreach ( $variation->get_attributes() as $taxonomy => $value ) {
			$value = (string) $value;

			if ( '' === $value ) {
				// "Any" — this variation does not pin the attribute down.
				continue;
			}

			$property = $map->resolve( (string) $taxonomy );

			if ( '' === $property || 'audience' === $property || isset( $out[ $property ] ) ) {
				continue;
			}

			$label = $value;

			if ( taxonomy_exists( (string) $taxonomy ) ) {
				$term = get_term_by( 'slug', $value, (string) $taxonomy );

				if ( $term instanceof \WP_Term ) {
					$label = $term->name;
				}
			}

			$out = array_merge( $out, $this->attribute_node( $property, $label, $map ) );
		}

		return $out;
	}

	/**
	 * Offer (simple or variation) or AggregateOffer (variable) for the product.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array<string, mixed>
	 */
	private function offer( \WC_Product $product ): array {
		$currency     = get_woocommerce_currency();
		$availability = $this->availability( $product );

		/**
		 * Filters the schema.org item condition for a product's offer.
		 *
		 * WooCommerce has no condition field, so new is the only defensible
		 * default; refurbished and used stores override here.
		 *
		 * @since 1.0.0
		 *
		 * @param string      $condition Schema.org OfferItemCondition URL.
		 * @param \WC_Product $product   Product object.
		 */
		$condition = apply_filters( 'neoseo_product_condition', 'https://schema.org/NewCondition', $product );
		$condition = is_string( $condition ) ? $condition : '';

		// Prices are valid at least until the end of the current year;
		// Google requires the property on Offer.
		$price_valid_until = gmdate( 'Y-12-31' );

		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
			$min = $product->get_variation_price( 'min' );
			$max = $product->get_variation_price( 'max' );

			$offer = array(
				'@type'           => 'AggregateOffer',
				'lowPrice'        => (float) wc_get_price_to_display( $product, array( 'price' => $min ) ),
				'highPrice'       => (float) wc_get_price_to_display( $product, array( 'price' => $max ) ),
				'priceCurrency'   => $currency,
				'availability'    => $availability,
				'priceValidUntil' => $price_valid_until,
				'url'             => (string) $product->get_permalink(),
				'seller'          => array( '@id' => $this->site_id( 'organization' ) ),
			);

			$offer_count = $this->purchasable_count( $product );

			if ( $offer_count > 0 ) {
				$offer['offerCount'] = $offer_count;
			}

			if ( '' !== $condition ) {
				$offer['itemCondition'] = $condition;
			}

			return array_merge( $offer, $this->policies() );
		}

		$offer = array(
			'@type'           => 'Offer',
			'price'           => (float) wc_get_price_to_display( $product ),
			'priceCurrency'   => $currency,
			'availability'    => $availability,
			'priceValidUntil' => $price_valid_until,
			'url'             => (string) $product->get_permalink(),
			'seller'          => array( '@id' => $this->site_id( 'organization' ) ),
		);

		if ( '' !== $condition ) {
			$offer['itemCondition'] = $condition;
		}

		return array_merge( $offer, $this->policies() );
	}

	/**
	 * How many variations of a variable product can actually be bought.
	 *
	 * `offerCount` should describe purchasable offers, not rows in the
	 * database, so variations without a price are excluded — the same rule
	 * that keeps them out of `hasVariant`.
	 *
	 * @param \WC_Product $product Product object.
	 * @return int
	 */
	private function purchasable_count( \WC_Product $product ): int {
		if ( ! method_exists( $product, 'get_children' ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );

			if ( $variation instanceof \WC_Product_Variation && '' !== (string) $variation->get_price() ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Shipping and return policy properties shared by every offer.
	 *
	 * Both are store-wide rather than per-product, so they are built once and
	 * attached wherever an Offer appears — including each variant's.
	 *
	 * @return array<string, mixed>
	 */
	private function policies(): array {
		$out = array();

		$shipping = ( new ShippingDetails() )->nodes();

		if ( $shipping ) {
			$out['shippingDetails'] = 1 === count( $shipping ) ? $shipping[0] : $shipping;
		}

		$returns = ( new ReturnPolicy() )->node();

		if ( $returns ) {
			$out['hasMerchantReturnPolicy'] = $returns;
		}

		return $out;
	}

	/**
	 * Schema.org availability for the product stock status.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	private function availability( \WC_Product $product ): string {
		switch ( $product->get_stock_status() ) {
			case 'instock':
				return 'https://schema.org/InStock';
			case 'onbackorder':
				return 'https://schema.org/BackOrder';
			default:
				return 'https://schema.org/OutOfStock';
		}
	}

	/**
	 * Review nodes from the most recent approved reviews.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array<int, array<string, mixed>>
	 */
	private function reviews( \WC_Product $product ): array {
		$comments = get_comments(
			array(
				'post_id' => $product->get_id(),
				'status'  => 'approve',
				'number'  => self::MAX_REVIEWS,
				'type'    => 'review',
			)
		);

		if ( ! is_array( $comments ) ) {
			return array();
		}

		$reviews = array();

		foreach ( $comments as $comment ) {
			$review = array(
				'@type'         => 'Review',
				'author'        => array(
					'@type' => 'Person',
					'name'  => $comment->comment_author,
				),
				'datePublished' => mysql2date( 'c', $comment->comment_date_gmt, false ),
				'reviewBody'    => wp_strip_all_tags( $comment->comment_content ),
			);

			$rating = get_comment_meta( (int) $comment->comment_ID, 'rating', true );

			if ( '' !== $rating ) {
				$review['reviewRating'] = array(
					'@type'       => 'Rating',
					'ratingValue' => (float) $rating,
					'bestRating'  => 5,
				);
			}

			$reviews[] = $review;
		}

		return $reviews;
	}
}
