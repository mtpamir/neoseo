<?php
/**
 * Merchant return policy node.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a MerchantReturnPolicy from the store's declared return terms.
 *
 * WooCommerce stores no return policy — verified, there is nothing to read — so
 * this is the one place NeoSEO asks the merchant for data rather than deriving
 * it. That makes the default important: **an unconfigured store emits nothing.**
 * A return policy is a commitment to the customer and a claim to Google, and
 * inventing a plausible-looking one would be worse than staying silent.
 */
class ReturnPolicy {

	/**
	 * Option holding the declared return terms.
	 */
	public const OPTION = 'neoseo_returns';

	/**
	 * Valid schema.org MerchantReturnEnumeration values.
	 */
	public const CATEGORIES = array(
		'MerchantReturnFiniteReturnWindow',
		'MerchantReturnUnlimitedWindow',
		'MerchantReturnNotPermitted',
	);

	/**
	 * Valid schema.org ReturnMethodEnumeration values.
	 */
	public const METHODS = array( 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' );

	/**
	 * Valid schema.org ReturnFeesEnumeration values.
	 */
	public const FEES = array( 'FreeReturn', 'ReturnFeesCustomerResponsibility', 'ReturnShippingFees' );

	/**
	 * Builds the node.
	 *
	 * @return array<string, mixed> Empty when the merchant has declared nothing.
	 */
	public function node(): array {
		$settings = get_option( self::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$category = isset( $settings['category'] ) ? (string) $settings['category'] : '';

		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			return array();
		}

		$node = array(
			'@type'                => 'MerchantReturnPolicy',
			'returnPolicyCategory' => 'https://schema.org/' . $category,
		);

		$country = $this->country( $settings );

		if ( '' !== $country ) {
			$node['applicableCountry'] = $country;
		}

		if ( 'MerchantReturnNotPermitted' !== $category ) {
			$node = array_merge( $node, $this->terms( $settings, $category ) );
		}

		/**
		 * Filters the merchant return policy node.
		 *
		 * @since 1.0.0
		 *
		 * @param array $node     MerchantReturnPolicy node.
		 * @param array $settings Declared return terms.
		 */
		$node = apply_filters( 'neoseo_offer_return_policy', $node, $settings );

		return is_array( $node ) ? $node : array();
	}

	/**
	 * Window, method and fee terms.
	 *
	 * @param array<string, mixed> $settings Declared return terms.
	 * @param string               $category Return policy category.
	 * @return array<string, mixed>
	 */
	private function terms( array $settings, string $category ): array {
		$out = array();

		if ( 'MerchantReturnFiniteReturnWindow' === $category ) {
			$days = isset( $settings['days'] ) ? (int) $settings['days'] : 0;

			if ( $days > 0 ) {
				$out['merchantReturnDays'] = $days;
			}
		}

		$method = isset( $settings['method'] ) ? (string) $settings['method'] : '';

		if ( in_array( $method, self::METHODS, true ) ) {
			$out['returnMethod'] = 'https://schema.org/' . $method;
		}

		$fees = isset( $settings['fees'] ) ? (string) $settings['fees'] : '';

		if ( ! in_array( $fees, self::FEES, true ) ) {
			return $out;
		}

		$out['returnFees'] = 'https://schema.org/' . $fees;

		if ( 'ReturnShippingFees' === $fees ) {
			$amount = isset( $settings['fee_amount'] ) ? (string) $settings['fee_amount'] : '';

			if ( is_numeric( $amount ) ) {
				$out['returnShippingFeesAmount'] = array(
					'@type'    => 'MonetaryAmount',
					'value'    => (float) $amount,
					'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				);
			}
		}

		return $out;
	}

	/**
	 * Country the policy applies to, falling back to the store's own base.
	 *
	 * @param array<string, mixed> $settings Declared return terms.
	 * @return string ISO 3166-1 alpha-2 code, or ''.
	 */
	private function country( array $settings ): string {
		$country = isset( $settings['country'] ) ? strtoupper( trim( (string) $settings['country'] ) ) : '';

		if ( '' === $country && function_exists( 'wc_get_base_location' ) ) {
			$base = wc_get_base_location();

			if ( is_array( $base ) && ! empty( $base['country'] ) ) {
				$country = strtoupper( (string) $base['country'] );
			}
		}

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}
}
