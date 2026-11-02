<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Admin;

use EasyMCP\PostMeta\Domain\Repository\ExtraFieldsRepositoryInterface;

final class MetaBox {

	private ExtraFieldsRepositoryInterface $fields;

	/** @var string[] */
	private array $networks = [ 'facebook' ];

	public function __construct( ExtraFieldsRepositoryInterface $fields ) {
		$this->fields = $fields;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
		add_action( 'save_post_post', [ $this, 'save' ], 10, 2 );
	}

	public function add_box(): void {
		add_meta_box(
			'empm_extra_fields',
			'News sources and social titles',
			[ $this, 'render' ],
			'post',
			'normal',
			'high'
		);
	}

	public function render( \WP_Post $post ): void {
		wp_nonce_field( 'empm_extra_fields', 'empm_extra_fields_nonce' );
		$data    = $this->fields->get( (int) $post->ID );
		$sources = implode( "\n", $data['source_urls'] );
		$titles  = implode( "\n", $data['social_titles'] );
		?>
		<p><strong>1. Social posts</strong> — one URL field per network. Empty = not posted. More networks can be added later.</p>
		<?php foreach ( $this->networks as $network ) :
			$url = isset( $data['social_posts'][ $network ] ) ? $data['social_posts'][ $network ] : '';
			?>
			<p><label><?php echo esc_html( ucfirst( $network ) ); ?> post URL<br />
			<input type="url" class="large-text" name="empm_social_network[<?php echo esc_attr( $network ); ?>]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://…" /></label></p>
		<?php endforeach; ?>
		<p><strong>2. Social clickbait titles</strong> (one per line). Usually 3 Facebook-group headlines with matching emojis at start and end.</p>
		<textarea name="empm_social_titles" rows="4" class="large-text" placeholder="😢 Headline that fits the story 💔"><?php echo esc_textarea( $titles ); ?></textarea>
		<p><strong>3. Source URLs</strong> (one per line). Used to check whether this news story is already on the site.</p>
		<textarea name="empm_source_urls" rows="4" class="large-text" placeholder="https://example.com/story"><?php echo esc_textarea( $sources ); ?></textarea>
		<?php
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['empm_extra_fields_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['empm_extra_fields_nonce'] ) ), 'empm_extra_fields' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$urls   = isset( $_POST['empm_source_urls'] ) ? wp_unslash( (string) $_POST['empm_source_urls'] ) : '';
		$titles = isset( $_POST['empm_social_titles'] ) ? wp_unslash( (string) $_POST['empm_social_titles'] ) : '';
		$posts  = [];
		$raw    = isset( $_POST['empm_social_network'] ) && is_array( $_POST['empm_social_network'] )
			? wp_unslash( $_POST['empm_social_network'] )
			: [];
		foreach ( $raw as $network => $url ) {
			$key = sanitize_key( (string) $network );
			if ( $key === '' ) {
				continue;
			}
			$link = esc_url_raw( trim( (string) $url ) );
			if ( $link !== '' ) {
				$posts[ $key ] = $link;
			}
		}
		$this->fields->save( $post_id, preg_split( '/\r\n|\r|\n/', $urls ) ?: [], preg_split( '/\r\n|\r|\n/', $titles ) ?: [], $posts );
	}
}
