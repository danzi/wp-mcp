<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Admin;

use EasyMCP\ZipPostImport\Application\Interactor\GetZipPackageSpecInteractor;
use EasyMCP\ZipPostImport\Application\Interactor\ImportPostFromZipInteractor;
use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Logger\LoggerInterface;

final class ImportPage {

	private ImportPostFromZipInteractor $import;
	private GetZipPackageSpecInteractor $spec;
	private LoggerInterface $logger;

	public function __construct(
		ImportPostFromZipInteractor $import,
		GetZipPackageSpecInteractor $spec,
		LoggerInterface $logger
	) {
		$this->import = $import;
		$this->spec   = $spec;
		$this->logger = $logger;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_debug_option' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_menu(): void {
		add_management_page(
			'ZIP Post Import',
			'ZIP Post Import',
			'edit_posts',
			'empzi-zip-import',
			[ $this, 'render' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'tools_page_empzi-zip-import' ) {
			return;
		}

		wp_enqueue_style(
			'empzi-admin',
			EMPZI_PLUGIN_URL . 'assets/admin.css',
			[],
			EMPZI_VERSION
		);
		wp_enqueue_script(
			'empzi-admin',
			EMPZI_PLUGIN_URL . 'assets/admin.js',
			[],
			EMPZI_VERSION,
			true
		);
	}

	public function handle_debug_option(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['empzi_save_debug'] ) ) {
			return;
		}
		check_admin_referer( 'empzi_debug' );
		$enabled = ! empty( $_POST['empzi_debug'] );
		update_option( 'empzi_debug', $enabled ? 1 : 0 );
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$result = null;
		if ( ! empty( $_POST['empzi_do_import'] ) ) {
			check_admin_referer( 'empzi_import' );
			$result = $this->handle_upload();
		}

		$debug = $this->logger->is_debug_enabled();
		$spec  = $this->spec->execute();
		?>
		<div class="wrap empzi-page">
			<div class="empzi-head">
				<h1><?php echo esc_html__( 'ZIP Post Import', 'easy-mcp-zip-post-import' ); ?></h1>
				<button type="button" class="empzi-info-btn" id="empzi-open-spec" aria-label="ZIP package format" title="ZIP package format">i</button>
			</div>
			<p><?php echo esc_html__( 'Import a ZIP by file or by public URL. Click (i) for the required structure and post.json fields.', 'easy-mcp-zip-post-import' ); ?></p>

			<?php if ( is_array( $result ) ) : ?>
				<?php if ( ! empty( $result['success'] ) ) : ?>
					<div class="notice notice-success"><p>
						<?php echo esc_html( (string) $result['message'] ); ?>
						<?php if ( ! empty( $result['warning'] ) ) : ?>
							<?php echo ' ' . esc_html( (string) $result['warning'] ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $result['edit_link'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $result['edit_link'] ); ?>"><?php echo esc_html__( 'Edit post', 'easy-mcp-zip-post-import' ); ?></a>
						<?php endif; ?>
					</p></div>
				<?php else : ?>
					<div class="notice notice-error"><p><?php echo esc_html( (string) $result['error'] ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'empzi_import' ); ?>
				<input type="hidden" name="empzi_do_import" value="1" />
				<div class="empzi-drop" id="empzi-drop">
					<label for="empzi_zip"><?php echo esc_html__( 'Drop a ZIP here or choose a file', 'easy-mcp-zip-post-import' ); ?></label>
					<p class="description"><?php echo esc_html__( 'Required: post.json + images/. Thumbnail.jpg is optional featured image.', 'easy-mcp-zip-post-import' ); ?></p>
					<input type="file" id="empzi_zip" name="empzi_zip" accept=".zip,application/zip" />
					<p class="empzi-filename" id="empzi-filename"></p>
				</div>
				<p class="empzi-or"><?php echo esc_html__( 'or import from URL', 'easy-mcp-zip-post-import' ); ?></p>
				<p>
					<label for="empzi_zip_url"><strong><?php echo esc_html__( 'ZIP URL', 'easy-mcp-zip-post-import' ); ?></strong></label><br />
					<input type="url" class="large-text" id="empzi_zip_url" name="empzi_zip_url" placeholder="https://example.com/article-slug.zip" />
				</p>
				<p class="description"><?php echo esc_html__( 'Public http(s) link only. Localhost and private networks are blocked. Max 50 MB. File upload wins if both are set.', 'easy-mcp-zip-post-import' ); ?></p>
				<?php submit_button( __( 'Import ZIP', 'easy-mcp-zip-post-import' ) ); ?>
			</form>

			<?php $this->render_spec_modal( $spec ); ?>

			<hr />
			<h2><?php echo esc_html__( 'Debug logging', 'easy-mcp-zip-post-import' ); ?></h2>
			<p><?php echo esc_html__( 'When enabled, detailed steps are written to the PHP error log. You can also define EMPZI_DEBUG in wp-config.php.', 'easy-mcp-zip-post-import' ); ?></p>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'empzi_debug' ); ?>
					<input type="hidden" name="empzi_save_debug" value="1" />
					<label>
						<input type="checkbox" name="empzi_debug" value="1" <?php checked( $debug ); ?> />
						<?php echo esc_html__( 'Enable debug logging', 'easy-mcp-zip-post-import' ); ?>
					</label>
					<?php submit_button( __( 'Save', 'easy-mcp-zip-post-import' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private function render_spec_modal( array $spec ): void {
		$layout  = isset( $spec['layout'] ) && is_array( $spec['layout'] ) ? $spec['layout'] : [];
		$rules   = isset( $spec['rules'] ) && is_array( $spec['rules'] ) ? $spec['rules'] : [];
		$fields  = isset( $spec['fields'] ) && is_array( $spec['fields'] ) ? $spec['fields'] : [];
		$example = isset( $spec['example'] ) ? wp_json_encode( $spec['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '{}';
		?>
		<div class="empzi-modal" id="empzi-spec-modal" aria-hidden="true" role="dialog" aria-labelledby="empzi-spec-title">
			<div class="empzi-modal__backdrop" data-empzi-close></div>
			<div class="empzi-modal__panel empzi-spec">
				<button type="button" class="button empzi-modal__close" data-empzi-close><?php echo esc_html__( 'Close', 'easy-mcp-zip-post-import' ); ?></button>
				<h2 id="empzi-spec-title"><?php echo esc_html__( 'ZIP package format', 'easy-mcp-zip-post-import' ); ?></h2>
				<p><?php echo esc_html__( 'Build the archive like this. post.json may sit at the ZIP root or inside one first-level folder.', 'easy-mcp-zip-post-import' ); ?></p>

				<h3><?php echo esc_html__( 'Structure', 'easy-mcp-zip-post-import' ); ?></h3>
				<pre><?php echo esc_html( implode( "\n", $layout ) ); ?></pre>

				<h3><?php echo esc_html__( 'Rules', 'easy-mcp-zip-post-import' ); ?></h3>
				<ul>
					<?php foreach ( $rules as $rule ) : ?>
						<li><?php echo esc_html( (string) $rule ); ?></li>
					<?php endforeach; ?>
				</ul>

				<h3><?php echo esc_html__( 'post.json fields', 'easy-mcp-zip-post-import' ); ?></h3>
				<table>
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Field', 'easy-mcp-zip-post-import' ); ?></th>
							<th><?php echo esc_html__( 'Meaning', 'easy-mcp-zip-post-import' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $fields as $name => $meaning ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $name ); ?></code></td>
								<td><?php echo esc_html( (string) $meaning ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php echo esc_html__( 'Example post.json', 'easy-mcp-zip-post-import' ); ?></h3>
				<pre><?php echo esc_html( is_string( $example ) ? $example : '{}' ); ?></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array<string, mixed>
	 */
	private function handle_upload(): array {
		$has_file = ! empty( $_FILES['empzi_zip']['tmp_name'] ) && is_uploaded_file( (string) $_FILES['empzi_zip']['tmp_name'] );
		$zip_url  = isset( $_POST['empzi_zip_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['empzi_zip_url'] ) ) ) : '';

		try {
			if ( $has_file ) {
				$file = $_FILES['empzi_zip'];
				if ( ! empty( $file['error'] ) ) {
					return [
						'success' => false,
						'error'   => 'Upload error code: ' . (int) $file['error'],
						'code'    => 'upload_error',
					];
				}
				return $this->import->execute_from_path( (string) $file['tmp_name'] );
			}
			if ( $zip_url !== '' ) {
				return $this->import->execute_from_url( $zip_url );
			}
			return [
				'success' => false,
				'error'   => 'Choose a ZIP file or paste a public ZIP URL.',
				'code'    => 'missing_zip',
			];
		} catch ( ImportException $e ) {
			$this->logger->error( $e->getMessage(), [ 'code' => $e->error_code() ] );
			return [
				'success' => false,
				'error'   => $e->getMessage(),
				'code'    => $e->error_code(),
			];
		} catch ( \Throwable $e ) {
			$this->logger->error( $e->getMessage() );
			return [
				'success' => false,
				'error'   => $e->getMessage(),
				'code'    => 'unexpected',
			];
		}
	}
}
