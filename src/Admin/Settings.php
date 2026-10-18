<?php
/**
 * Admin screen: dashboard, settings and guide.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Admin;

use NeoSEO\Compat\Detector;
use NeoSEO\Schema\AttributeMap;
use NeoSEO\Schema\ReturnPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * The NeoSEO top-level admin screen, built on the NeoPlugin family shell.
 */
class Settings {

	/**
	 * SEO-plugin detector.
	 *
	 * @var Detector
	 */
	private $detector;

	/**
	 * Constructor.
	 *
	 * @param Detector $detector Module gate.
	 */
	public function __construct( Detector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * The tabs on the NeoSEO screen.
	 *
	 * @return array<string, array{label: string, icon: string}>
	 */
	private function tabs(): array {
		return array(
			'dashboard' => array(
				'label' => __( 'Dashboard', 'neoseo' ),
				'icon'  => 'chart-area',
			),
			'settings'  => array(
				'label' => __( 'Settings', 'neoseo' ),
				'icon'  => 'admin-generic',
			),
			'guide'     => array(
				'label' => __( 'Guide', 'neoseo' ),
				'icon'  => 'book',
			),
		);
	}

	/**
	 * Hooks the admin pieces.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NEOSEO_FILE ), array( $this, 'action_links' ) );

		( new Assets() )->register();
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=neoseo' ) ) . '">' . esc_html__( 'Settings', 'neoseo' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Adds the top-level menu.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_menu_page(
			__( 'NeoSEO', 'neoseo' ),
			__( 'NeoSEO', 'neoseo' ),
			'manage_options',
			'neoseo',
			array( $this, 'render_page' ),
			'dashicons-chart-line',
			59
		);
	}

	/**
	 * Registers the option groups.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'neoseo',
			'neoseo_organization',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_organization' ),
				'default'           => array(),
			)
		);

		register_setting(
			'neoseo',
			'neoseo_modules',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_modules' ),
				'default'           => array(),
			)
		);

		register_setting(
			'neoseo',
			ReturnPolicy::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_returns' ),
				'default'           => array(),
			)
		);

		register_setting(
			'neoseo',
			'neoseo_attribute_map',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_attribute_map' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitizes the declared return terms.
	 *
	 * An unrecognised category clears the lot: the node is only emitted when
	 * a real policy has been chosen, so a half-saved policy must not survive.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	public function sanitize_returns( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$category = isset( $value['category'] ) ? (string) $value['category'] : '';

		if ( ! in_array( $category, ReturnPolicy::CATEGORIES, true ) ) {
			return array();
		}

		$clean = array( 'category' => $category );

		$country = isset( $value['country'] ) ? strtoupper( trim( (string) $value['country'] ) ) : '';

		if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$clean['country'] = $country;
		}

		if ( 'MerchantReturnNotPermitted' === $category ) {
			return $clean;
		}

		$days = isset( $value['days'] ) ? absint( $value['days'] ) : 0;

		if ( $days > 0 ) {
			$clean['days'] = $days;
		}

		$method = isset( $value['method'] ) ? (string) $value['method'] : '';

		if ( in_array( $method, ReturnPolicy::METHODS, true ) ) {
			$clean['method'] = $method;
		}

		$fees = isset( $value['fees'] ) ? (string) $value['fees'] : '';

		if ( in_array( $fees, ReturnPolicy::FEES, true ) ) {
			$clean['fees'] = $fees;

			$amount = isset( $value['fee_amount'] ) ? trim( (string) $value['fee_amount'] ) : '';

			if ( 'ReturnShippingFees' === $fees && is_numeric( $amount ) ) {
				$clean['fee_amount'] = (float) $amount;
			}
		}

		return $clean;
	}

	/**
	 * Sanitizes the attribute mapping.
	 *
	 * Only stores a decision the merchant actually made: an empty value means
	 * "keep guessing", so it is dropped rather than saved as a blank override.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, string>
	 */
	public function sanitize_attribute_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$allowed = array_merge( AttributeMap::PROPERTIES, array( AttributeMap::IGNORE ) );
		$clean   = array();

		foreach ( $value as $attribute => $property ) {
			if ( ! is_string( $property ) || ! in_array( $property, $allowed, true ) ) {
				continue;
			}

			$key = AttributeMap::normalize( (string) $attribute );

			if ( '' !== $key ) {
				$clean[ $key ] = $property;
			}
		}

		return $clean;
	}

	/**
	 * Sanitizes the organization settings.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	public function sanitize_organization( $value ): array {
		$value = is_array( $value ) ? $value : array();

		$clean = array(
			'name'    => isset( $value['name'] ) ? sanitize_text_field( (string) $value['name'] ) : '',
			'logo_id' => isset( $value['logo_id'] ) ? absint( $value['logo_id'] ) : 0,
		);

		$clean['same_as'] = '';

		if ( isset( $value['same_as'] ) && is_string( $value['same_as'] ) ) {
			$urls = array_filter( array_map( 'trim', explode( "\n", $value['same_as'] ) ) );
			$urls = array_map( 'esc_url_raw', $urls );

			$clean['same_as'] = implode( "\n", array_filter( $urls ) );
		}

		return $clean;
	}

	/**
	 * Sanitizes the module toggles.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, bool>
	 */
	public function sanitize_modules( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$clean = array();

		foreach ( Modules::slugs() as $module ) {
			$clean[ $module ] = ! empty( $value[ $module ] );
		}

		return $clean;
	}

	/**
	 * Renders the screen shell and the active tab.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'dashboard';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NeoSEO', 'neoseo' ); ?></h1>

			<div class="neoplugin-shell">
				<div class="neoplugin-nav">
					<?php foreach ( $tabs as $slug => $tab ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=neoseo&tab=' . $slug ) ); ?>"
							class="<?php echo esc_attr( $active === $slug ? 'active' : '' ); ?>"
							<?php echo $active === $slug ? 'aria-current="page"' : ''; ?>>
							<span class="dashicons dashicons-<?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $tab['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>

				<div class="neoplugin-content">
					<?php
					switch ( $active ) {
						case 'settings':
							$this->render_settings_tab();
							break;
						case 'guide':
							$this->render_guide_tab();
							break;
						default:
							( new Dashboard( $this->detector ) )->render();
							break;
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The settings form.
	 *
	 * @return void
	 */
	private function render_settings_tab(): void {
		$organization = get_option( 'neoseo_organization', array() );
		$seo_plugin   = $this->detector->seo_plugin();
		?>
		<h2><?php esc_html_e( 'Settings', 'neoseo' ); ?></h2>

		<?php if ( $seo_plugin ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: detected SEO plugin slug. */
						esc_html__( 'Another SEO plugin (%s) is active. Overlapping NeoSEO modules are off by default so nothing is output twice — re-enable individual modules below only if you know they do not collide.', 'neoseo' ),
						esc_html( $seo_plugin )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php
		$schema_plugin = $this->detector->schema_plugin();
		if ( $schema_plugin && $this->detector->module_enabled( 'schema' ) ) :
			?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: detected schema plugin slug. */
						esc_html__( 'A structured-data plugin (%s) is active alongside NeoSEO, and both are writing schema. Two Product nodes on one page is the most common cause of rich results being ignored — switch off the JSON-LD module below, or the other plugin\'s output.', 'neoseo' ),
						'<code>' . esc_html( $schema_plugin ) . '</code>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form action="options.php" method="post">
			<?php settings_fields( 'neoseo' ); ?>

			<table class="neo-unified-table">
				<tr class="neo-section-header-row">
					<td colspan="2">
						<h3><?php esc_html_e( 'Organization', 'neoseo' ); ?></h3>
						<p class="neo-section-description"><?php esc_html_e( 'Identifies your site in the structured-data graph. Search engines use this to connect your pages to your brand.', 'neoseo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="neoseo-org-name"><?php esc_html_e( 'Organization name', 'neoseo' ); ?></label></th>
					<td>
						<input name="neoseo_organization[name]" id="neoseo-org-name" type="text" class="regular-text"
							value="<?php echo esc_attr( $organization['name'] ?? '' ); ?>"
							placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave empty to use your site title.', 'neoseo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="neoseo-org-logo"><?php esc_html_e( 'Logo attachment ID', 'neoseo' ); ?></label></th>
					<td>
						<input name="neoseo_organization[logo_id]" id="neoseo-org-logo" type="number" class="small-text"
							value="<?php echo esc_attr( (string) ( $organization['logo_id'] ?? '' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave empty to use the site icon or the theme logo.', 'neoseo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="neoseo-org-sameas"><?php esc_html_e( 'Profiles (sameAs)', 'neoseo' ); ?></label></th>
					<td>
						<textarea name="neoseo_organization[same_as]" id="neoseo-org-sameas" class="large-text" rows="4"
							placeholder="https://x.com/yourstore&#10;https://facebook.com/yourstore"><?php echo esc_textarea( $organization['same_as'] ?? '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One social or profile URL per line.', 'neoseo' ); ?></p>
					</td>
				</tr>

				<?php if ( $this->detector->woocommerce_active() ) : ?>
					<?php
					$returns = get_option( ReturnPolicy::OPTION, array() );
					$returns = is_array( $returns ) ? $returns : array();
					?>
					<tr class="neo-section-header-row">
						<td colspan="2">
							<h3><?php esc_html_e( 'Returns', 'neoseo' ); ?></h3>
							<p class="neo-section-description"><?php esc_html_e( 'Google increasingly expects a return policy before it will show a product with price and availability. WooCommerce does not store one, so NeoSEO asks here — and publishes nothing until you choose a policy, because this is a promise to your customers as well as a signal to Google.', 'neoseo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="neoseo-returns-category"><?php esc_html_e( 'Returns accepted', 'neoseo' ); ?></label></th>
						<td>
							<?php $category = isset( $returns['category'] ) ? (string) $returns['category'] : ''; ?>
							<select id="neoseo-returns-category" name="<?php echo esc_attr( ReturnPolicy::OPTION ); ?>[category]">
								<option value="" <?php selected( '', $category ); ?>><?php esc_html_e( 'Do not publish a return policy', 'neoseo' ); ?></option>
								<option value="MerchantReturnFiniteReturnWindow" <?php selected( 'MerchantReturnFiniteReturnWindow', $category ); ?>><?php esc_html_e( 'Yes, within a set number of days', 'neoseo' ); ?></option>
								<option value="MerchantReturnUnlimitedWindow" <?php selected( 'MerchantReturnUnlimitedWindow', $category ); ?>><?php esc_html_e( 'Yes, with no time limit', 'neoseo' ); ?></option>
								<option value="MerchantReturnNotPermitted" <?php selected( 'MerchantReturnNotPermitted', $category ); ?>><?php esc_html_e( 'No, returns are not accepted', 'neoseo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="neoseo-returns-days"><?php esc_html_e( 'Return window (days)', 'neoseo' ); ?></label></th>
						<td>
							<input type="number" min="0" step="1" class="small-text" id="neoseo-returns-days"
								name="<?php echo esc_attr( ReturnPolicy::OPTION ); ?>[days]"
								value="<?php echo esc_attr( (string) ( $returns['days'] ?? '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Only used when returns are accepted within a set number of days.', 'neoseo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="neoseo-returns-method"><?php esc_html_e( 'How items come back', 'neoseo' ); ?></label></th>
						<td>
							<?php $method = isset( $returns['method'] ) ? (string) $returns['method'] : ''; ?>
							<select id="neoseo-returns-method" name="<?php echo esc_attr( ReturnPolicy::OPTION ); ?>[method]">
								<option value="" <?php selected( '', $method ); ?>><?php esc_html_e( 'Not specified', 'neoseo' ); ?></option>
								<option value="ReturnByMail" <?php selected( 'ReturnByMail', $method ); ?>><?php esc_html_e( 'By post', 'neoseo' ); ?></option>
								<option value="ReturnInStore" <?php selected( 'ReturnInStore', $method ); ?>><?php esc_html_e( 'In store', 'neoseo' ); ?></option>
								<option value="ReturnAtKiosk" <?php selected( 'ReturnAtKiosk', $method ); ?>><?php esc_html_e( 'At a kiosk or drop-off point', 'neoseo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="neoseo-returns-fees"><?php esc_html_e( 'Who pays return postage', 'neoseo' ); ?></label></th>
						<td>
							<?php $fees = isset( $returns['fees'] ) ? (string) $returns['fees'] : ''; ?>
							<select id="neoseo-returns-fees" name="<?php echo esc_attr( ReturnPolicy::OPTION ); ?>[fees]">
								<option value="" <?php selected( '', $fees ); ?>><?php esc_html_e( 'Not specified', 'neoseo' ); ?></option>
								<option value="FreeReturn" <?php selected( 'FreeReturn', $fees ); ?>><?php esc_html_e( 'We do — returns are free', 'neoseo' ); ?></option>
								<option value="ReturnFeesCustomerResponsibility" <?php selected( 'ReturnFeesCustomerResponsibility', $fees ); ?>><?php esc_html_e( 'The customer does', 'neoseo' ); ?></option>
								<option value="ReturnShippingFees" <?php selected( 'ReturnShippingFees', $fees ); ?>><?php esc_html_e( 'The customer pays a fixed fee', 'neoseo' ); ?></option>
							</select>
							<input type="text" class="small-text" id="neoseo-returns-fee-amount"
								name="<?php echo esc_attr( ReturnPolicy::OPTION ); ?>[fee_amount]"
								value="<?php echo esc_attr( (string) ( $returns['fee_amount'] ?? '' ) ); ?>"
								placeholder="<?php esc_attr_e( 'Fee', 'neoseo' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="neoseo-returns-country"><?php esc_html_e( 'Applies to country', 'neoseo' ); ?></label></th>
						<td>
							<input type="text" class="small-text" maxlength="2" id="neoseo-returns-country"
								name="<?php echo esc_attr( ReturnPolicy::OPTION ); ?>[country]"
								value="<?php echo esc_attr( (string) ( $returns['country'] ?? '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Two-letter country code. Leave empty to use your store address.', 'neoseo' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>

				<?php $attributes = $this->global_attributes(); ?>
				<?php if ( $attributes ) : ?>
					<?php $attribute_map = get_option( 'neoseo_attribute_map', array() ); ?>
					<?php $attribute_map = is_array( $attribute_map ) ? $attribute_map : array(); ?>
					<tr class="neo-section-header-row">
						<td colspan="2">
							<h3><?php esc_html_e( 'Product attributes', 'neoseo' ); ?></h3>
							<p class="neo-section-description"><?php esc_html_e( 'Tells search engines what each attribute means. NeoSEO recognises the usual names on its own — set one here only when it guesses wrong, or when your attribute has a name of your own.', 'neoseo' ); ?></p>
						</td>
					</tr>
					<?php foreach ( $attributes as $slug => $label ) : ?>
						<?php $current = isset( $attribute_map[ $slug ] ) && is_string( $attribute_map[ $slug ] ) ? $attribute_map[ $slug ] : ''; ?>
						<tr>
							<th scope="row"><label for="neoseo-attr-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<select id="neoseo-attr-<?php echo esc_attr( $slug ); ?>" name="neoseo_attribute_map[<?php echo esc_attr( $slug ); ?>]">
									<option value="" <?php selected( '', $current ); ?>><?php esc_html_e( 'Detect automatically', 'neoseo' ); ?></option>
									<?php foreach ( AttributeMap::labels() as $property => $property_label ) : ?>
										<option value="<?php echo esc_attr( $property ); ?>" <?php selected( $property, $current ); ?>><?php echo esc_html( $property_label ); ?></option>
									<?php endforeach; ?>
									<option value="<?php echo esc_attr( AttributeMap::IGNORE ); ?>" <?php selected( AttributeMap::IGNORE, $current ); ?>><?php esc_html_e( 'Do not describe this attribute', 'neoseo' ); ?></option>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>

				<tr class="neo-section-header-row">
					<td colspan="2">
						<h3><?php esc_html_e( 'Modules', 'neoseo' ); ?></h3>
						<p class="neo-section-description"><?php esc_html_e( 'Switch off anything another plugin already handles. The Dashboard tab shows what each module is currently doing.', 'neoseo' ); ?></p>
					</td>
				</tr>
				<?php foreach ( Modules::all() as $module => $info ) : ?>
					<tr>
						<th scope="row"><label for="neoseo-module-<?php echo esc_attr( $module ); ?>"><?php echo esc_html( $info['label'] ); ?></label></th>
						<td>
							<?php
							// A hidden field first so an unchecked toggle still
							// submits a value; the sanitizer reads truthiness.
							?>
							<input type="hidden" name="neoseo_modules[<?php echo esc_attr( $module ); ?>]" value="0" />
							<label class="neo-toggle">
								<input type="checkbox"
									id="neoseo-module-<?php echo esc_attr( $module ); ?>"
									name="neoseo_modules[<?php echo esc_attr( $module ); ?>]"
									value="1"
									<?php checked( $this->detector->module_enabled( $module ) ); ?> />
								<span class="neo-toggle-slider"></span>
							</label>
							<p class="description"><?php echo esc_html( $info['description'] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * WooCommerce global attributes, as normalized key => label.
	 *
	 * Only global (taxonomy) attributes appear. Per-product custom attributes
	 * are unbounded and store-specific, so they stay on automatic detection
	 * and the `neoseo_attribute_property` filter.
	 *
	 * @return array<string, string>
	 */
	private function global_attributes(): array {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}

		$taxonomies = wc_get_attribute_taxonomies();

		if ( ! is_array( $taxonomies ) ) {
			return array();
		}

		$out = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->attribute_name ) ) {
				continue;
			}

			$key = AttributeMap::normalize( (string) $taxonomy->attribute_name );

			if ( '' === $key ) {
				continue;
			}

			$out[ $key ] = ! empty( $taxonomy->attribute_label )
				? (string) $taxonomy->attribute_label
				: (string) $taxonomy->attribute_name;
		}

		return $out;
	}

	/**
	 * The guide tab.
	 *
	 * @return void
	 */
	private function render_guide_tab(): void {
		?>
		<h2><?php esc_html_e( 'How NeoSEO works', 'neoseo' ); ?></h2>

		<ul class="neoplugin-guide-list">
			<li>
				<strong><?php esc_html_e( 'It runs automatically', 'neoseo' ); ?></strong>
				<?php esc_html_e( 'There is nothing to add to your pages. NeoSEO reads the content you already have and prints the structured data in the page head.', 'neoseo' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'One connected graph', 'neoseo' ); ?></strong>
				<?php esc_html_e( 'Every node is linked by @id, so search engines see one coherent description of the page instead of disconnected fragments.', 'neoseo' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'FAQ schema from Details blocks', 'neoseo' ); ?></strong>
				<?php esc_html_e( 'Add two or more Details blocks to a page — question in the summary, answer inside — and NeoSEO turns them into FAQ schema.', 'neoseo' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'WooCommerce products', 'neoseo' ); ?></strong>
				<?php esc_html_e( 'Price, currency, stock availability, SKU, ratings and reviews are read straight from the product. WooCommerce\'s own thinner markup is removed so there is exactly one product description.', 'neoseo' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Answer engines and AI search', 'neoseo' ); ?></strong>
				<?php esc_html_e( 'Articles carry speakable hints, and /llms.txt gives AI crawlers a compact, always-current summary of your site.', 'neoseo' ); ?>
			</li>
		</ul>

		<div class="neoplugin-faq-container">
			<h3><?php esc_html_e( 'Frequently asked questions', 'neoseo' ); ?></h3>

			<details class="neoplugin-faq">
				<summary><?php esc_html_e( 'How do I check my schema is valid?', 'neoseo' ); ?></summary>
				<p><?php esc_html_e( 'Use the Schema inspector on the Dashboard tab to see the exact output, then follow the validator links below it to confirm with Google or Schema.org.', 'neoseo' ); ?></p>
			</details>

			<details class="neoplugin-faq">
				<summary><?php esc_html_e( 'I already use Yoast or Rank Math. Is this a conflict?', 'neoseo' ); ?></summary>
				<p><?php esc_html_e( 'No. NeoSEO detects them and switches off its JSON-LD, meta description and Open Graph modules automatically, so none of that is output twice. Product variant markup stays on, because none of them produce it, and so does llms.txt. If your SEO plugin also generates an llms.txt, switch one of the two off.', 'neoseo' ); ?></p>
			</details>

			<details class="neoplugin-faq">
				<summary><?php esc_html_e( 'Do I need WooCommerce?', 'neoseo' ); ?></summary>
				<p><?php esc_html_e( 'No. Product schema only activates when WooCommerce is installed; everything else works on any WordPress site.', 'neoseo' ); ?></p>
			</details>

			<details class="neoplugin-faq">
				<summary><?php esc_html_e( 'Why does my page show fewer nodes than another?', 'neoseo' ); ?></summary>
				<p><?php esc_html_e( 'Each node describes something real. A page with no FAQ blocks has no FAQ node, and a post with no featured image has no image node. That is expected and correct.', 'neoseo' ); ?></p>
			</details>
		</div>

		<div class="neo-support-box">
			<strong><?php esc_html_e( 'Need help?', 'neoseo' ); ?></strong>
			<p><?php esc_html_e( 'Documentation and support for the whole NeoPlugin family:', 'neoseo' ); ?>
				<a href="https://neoplugin.com/docs/" target="_blank" rel="noreferrer">neoplugin.com/docs</a>
			</p>
		</div>
		<?php
	}
}
