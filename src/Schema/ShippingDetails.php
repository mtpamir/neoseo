<?php
/**
 * Offer shipping details from WooCommerce shipping zones.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Builds OfferShippingDetails from the store's live shipping zones.
 *
 * This is the part no rival generates: every AEO plugin that emits shipping
 * markup asks the merchant to retype what WooCommerce already knows. Here the
 * zones *are* the source.
 *
 * The cost of that is honesty about what a zone can and cannot express. A flat
 * rate of "10" is a shipping rate; a flat rate of "[qty] * 2 + 5" is a formula
 * whose value depends on a cart that does not exist yet. Zones that cannot
 * produce a single defensible number are skipped rather than guessed at.
 */
class ShippingDetails {

	/**
	 * Maximum zones expressed on one offer.
	 */
	private const MAX_ZONES = 10;

	/**
	 * Maximum countries listed in one shipping destination.
	 */
	private const MAX_COUNTRIES = 20;

	/**
	 * Shipping methods that are not shipping.
	 */
	private const EXCLUDED_METHODS = array( 'local_pickup' );

	/**
	 * Builds one node per usable zone.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$out      = array();

		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			if ( count( $out ) >= self::MAX_ZONES ) {
				break;
			}

			$destination = $this->destination( isset( $zone['zone_locations'] ) ? (array) $zone['zone_locations'] : array() );

			if ( ! $destination ) {
				continue;
			}

			$rate = $this->rate( isset( $zone['shipping_methods'] ) ? (array) $zone['shipping_methods'] : array() );

			if ( null === $rate ) {
				continue;
			}

			$out[] = array(
				'@type'               => 'OfferShippingDetails',
				'shippingRate'        => array(
					'@type'    => 'MonetaryAmount',
					'value'    => $rate,
					'currency' => $currency,
				),
				'shippingDestination' => $destination,
			);
		}

		/**
		 * Filters the shipping details derived from the store's zones.
		 *
		 * @since 1.0.0
		 *
		 * @param array $out OfferShippingDetails nodes.
		 */
		$out = apply_filters( 'neoseo_offer_shipping', $out );

		return is_array( $out ) ? $out : array();
	}

	/**
	 * DefinedRegion for a zone's locations.
	 *
	 * Continent and postcode locations are skipped: a continent is not an
	 * addressCountry, and a postcode list without a country is not a region
	 * anyone can resolve.
	 *
	 * @param array<int, mixed> $locations Zone locations.
	 * @return array<string, mixed> Empty when no country can be determined.
	 */
	private function destination( array $locations ): array {
		$countries = array();
		$regions   = array();

		foreach ( $locations as $location ) {
			if ( ! is_object( $location ) || empty( $location->type ) || empty( $location->code ) ) {
				continue;
			}

			$code = (string) $location->code;

			if ( 'country' === $location->type ) {
				$countries[ strtoupper( $code ) ] = true;
			} elseif ( 'state' === $location->type && false !== strpos( $code, ':' ) ) {
				list( $country, $state ) = explode( ':', $code, 2 );

				$countries[ strtoupper( $country ) ] = true;
				$regions[ strtoupper( $state ) ]     = true;
			}
		}

		if ( ! $countries ) {
			return array();
		}

		$countries = array_slice( array_keys( $countries ), 0, self::MAX_COUNTRIES );

		$node = array(
			'@type'          => 'DefinedRegion',
			'addressCountry' => 1 === count( $countries ) ? $countries[0] : $countries,
		);

		// Only meaningful when the zone is inside a single country; a region
		// list spanning several countries is ambiguous.
		if ( $regions && 1 === count( $countries ) ) {
			$regions               = array_keys( $regions );
			$node['addressRegion'] = 1 === count( $regions ) ? $regions[0] : $regions;
		}

		return $node;
	}

	/**
	 * The cheapest defensible rate among a zone's enabled methods.
	 *
	 * @param array<int|string, mixed> $methods Zone shipping methods.
	 * @return float|null Null when no method yields a fixed number.
	 */
	private function rate( array $methods ): ?float {
		$best = null;

		foreach ( $methods as $method ) {
			if ( ! is_object( $method ) || empty( $method->id ) ) {
				continue;
			}

			if ( in_array( (string) $method->id, self::EXCLUDED_METHODS, true ) ) {
				continue;
			}

			if ( method_exists( $method, 'is_enabled' ) && ! $method->is_enabled() ) {
				continue;
			}

			$cost = $this->method_cost( $method );

			if ( null === $cost ) {
				continue;
			}

			if ( null === $best || $cost < $best ) {
				$best = $cost;
			}
		}

		return $best;
	}

	/**
	 * Fixed cost of a single shipping method.
	 *
	 * @param object $method Shipping method instance.
	 * @return float|null Null when the cost is conditional or a formula.
	 */
	private function method_cost( $method ): ?float {
		$id = (string) $method->id;

		if ( 'free_shipping' === $id ) {
			// Free shipping with a minimum-order or coupon requirement is not
			// free for this product, it is free for some carts.
			$requires = method_exists( $method, 'get_option' ) ? (string) $method->get_option( 'requires', '' ) : '';

			return '' === $requires ? 0.0 : null;
		}

		if ( ! method_exists( $method, 'get_option' ) ) {
			return null;
		}

		$cost = trim( (string) $method->get_option( 'cost', '' ) );

		if ( '' === $cost ) {
			return 'flat_rate' === $id ? 0.0 : null;
		}

		// "10" and "10.50" are rates. "[qty] * 2" is a formula that needs a
		// cart, and any store using one is better served by no markup than by
		// a number invented here.
		return is_numeric( $cost ) ? (float) $cost : null;
	}
}
