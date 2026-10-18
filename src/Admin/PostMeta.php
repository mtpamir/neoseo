<?php
/**
 * Search appearance box on the post, page and product editors.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Admin;

use NeoSEO\Compat\Detector;
use NeoSEO\Content\Description;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an author read and override the generated meta description.
 *
 * A classic meta box rather than an editor sidebar panel: it renders in both
 * editors, and it keeps the plugin free of a JavaScript build step for the
 * sake of one text field. The counter below is the only script involved.
 */
class PostMeta {

	/**
	 * Nonce action and field name.
	 */
	private const NONCE = 'neoseo_save_description';

	/**
	 * Recommended maximum length before search engines truncate.
	 */
	private const MAX_LENGTH = 160;

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
	 * Hooks the box and its save handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post', array( $this, 'save' ) );
	}

	/**
	 * The post types that get the box.
	 *
	 * @return string[]
	 */
	private function post_types(): array {
		$types = array( 'post', 'page' );

		if ( $this->detector->woocommerce_active() ) {
			$types[] = 'product';
		}

		/**
		 * Filters the post types showing the NeoSEO description box.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types Post type slugs.
		 */
		return (array) apply_filters( 'neoseo_description_post_types', $types );
	}

	/**
	 * Registers the meta box.
	 *
	 * @return void
	 */
	public function add_box(): void {
		// Another SEO plugin owns the snippet when its module took over, and
		// two competing description fields help nobody.
		if ( ! $this->detector->module_enabled( 'meta' ) ) {
			return;
		}

		foreach ( $this->post_types() as $type ) {
			add_meta_box(
				'neoseo-description',
				__( 'NeoSEO — Search Appearance', 'neoseo' ),
				array( $this, 'render' ),
				$type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Renders the box.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return void
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$override  = Description::override( (int) $post->ID );
		$generated = Description::for_post( $post );
		$generated = '' !== $generated ? wp_trim_words( $generated, 30, '…' ) : '';
		$effective = '' !== $override ? $override : $generated;

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<div class="neoseo-snippet">
			<p class="neoseo-snippet-preview-label"><?php esc_html_e( 'Search result preview', 'neoseo' ); ?></p>

			<div class="neoseo-snippet-preview">
				<div class="neoseo-snippet-url"><?php echo esc_html( $this->preview_url( $post ) ); ?></div>
				<div class="neoseo-snippet-title"><?php echo esc_html( get_the_title( $post ) ); ?></div>
				<div class="neoseo-snippet-desc" id="neoseo-snippet-desc">
				<?php
					echo '' !== $effective
						? esc_html( $effective )
						: esc_html__( 'No description yet — search engines will choose their own text from the page.', 'neoseo' );
				?>
				</div>
			</div>

			<p>
				<label for="neoseo-description"><strong><?php esc_html_e( 'Meta description', 'neoseo' ); ?></strong></label>
			</p>

			<textarea name="neoseo_description" id="neoseo-description" rows="3" class="large-text"
				maxlength="320"
				placeholder="<?php echo esc_attr( '' !== $generated ? $generated : __( 'Describe this page in a sentence.', 'neoseo' ) ); ?>"
				data-generated="<?php echo esc_attr( $generated ); ?>"
				data-empty-label="<?php echo esc_attr__( 'No description yet — search engines will choose their own text from the page.', 'neoseo' ); ?>"
			><?php echo esc_textarea( $override ); ?></textarea>

			<p class="neoseo-snippet-help">
				<span id="neoseo-description-count" class="neoseo-count"></span>
				<?php
				if ( '' !== $generated ) {
					echo '<span class="neoseo-snippet-hint">' . esc_html__( 'Leave empty to use the text generated from this content, shown above as placeholder.', 'neoseo' ) . '</span>';
				} else {
					echo '<span class="neoseo-snippet-hint">' . esc_html__( 'This content has no text to generate a description from, so writing one here is the only way to control it.', 'neoseo' ) . '</span>';
				}
				?>
			</p>
		</div>
		<?php
		$this->print_counter_script();
	}

	/**
	 * A readable stand-in for the permalink.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return string
	 */
	private function preview_url( \WP_Post $post ): string {
		$permalink = get_permalink( $post );

		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return (string) home_url( '/' );
		}

		return (string) $permalink;
	}

	/**
	 * Live counter and preview sync.
	 *
	 * Vanilla JS on purpose: a build step for one field is not a trade worth
	 * making, and this runs only on the edit screen.
	 *
	 * @return void
	 */
	private function print_counter_script(): void {
		$script = <<<'JS'
( function () {
	var field = document.getElementById( 'neoseo-description' );
	var count = document.getElementById( 'neoseo-description-count' );
	var preview = document.getElementById( 'neoseo-snippet-desc' );

	if ( ! field || ! count ) {
		return;
	}

	var max = %d;

	function update() {
		var value = field.value.trim();
		var shown = value || field.dataset.generated || '';
		var length = shown.length;

		count.textContent = length + ' / ' + max;
		count.className = 'neoseo-count' + ( length > max ? ' is-over' : '' );

		if ( preview ) {
			preview.textContent = shown || field.dataset.emptyLabel;
		}
	}

	field.addEventListener( 'input', update );
	update();
}() );
JS;

		wp_print_inline_script_tag( sprintf( $script, self::MAX_LENGTH ) );
	}

	/**
	 * Saves the override.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save( $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$value = isset( $_POST['neoseo_description'] )
			? sanitize_textarea_field( wp_unslash( $_POST['neoseo_description'] ) )
			: '';

		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' );

		if ( '' === $value ) {
			delete_post_meta( $post_id, Description::META_KEY );

			return;
		}

		update_post_meta( $post_id, Description::META_KEY, $value );
	}
}
