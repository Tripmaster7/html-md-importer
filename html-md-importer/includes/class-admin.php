<?php
/**
 * Admin screen under Tools.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Tools → HTML & Markdown Import.
 */
final class Admin {

	private const SLUG = 'html-md-importer';

	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function menu(): void {
		$hook = add_management_page(
			__( 'HTML & Markdown Importer', 'html-md-importer' ),
			__( 'HTML & Markdown Import', 'html-md-importer' ),
			'upload_files',
			self::SLUG,
			array( self::class, 'render' )
		);
		self::$hook = (string) $hook;
	}

	public static function assets( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'hmi-admin', HMI_URL . 'assets/admin.css', array(), HMI_VERSION );
		wp_enqueue_script( 'hmi-admin', HMI_URL . 'assets/admin.js', array(), HMI_VERSION, array( 'in_footer' => true ) );

		$max_upload = (int) wp_max_upload_size();
		$chunk      = (int) min( 8 * MB_IN_BYTES, max( 256 * KB_IN_BYTES, (int) floor( $max_upload * 0.9 ) ) );

		wp_localize_script(
			'hmi-admin',
			'HMI',
			array(
				'root'       => esc_url_raw( rest_url( Rest::NAMESPACE ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'chunkSize'  => $chunk,
				'extensions' => Paths::allowed_extensions(),
				'docExt'     => Paths::DOC_EXTENSIONS,
				'canPages'   => current_user_can( 'edit_pages' ),
				'canPosts'   => current_user_can( 'edit_posts' ),
				'i18n'       => array(
					'noFiles'        => __( 'Please choose a folder or a ZIP file first.', 'html-md-importer' ),
					'noDocs'         => __( 'The selection contains no HTML or Markdown files.', 'html-md-importer' ),
					'uploading'      => __( 'Uploading %1$d of %2$d files…', 'html-md-importer' ),
					'uploadingZip'   => __( 'Uploading ZIP: %d%%', 'html-md-importer' ),
					'extracting'     => __( 'Extracting ZIP…', 'html-md-importer' ),
					'scanning'       => __( 'Analysing documents…', 'html-md-importer' ),
					'importingMedia' => __( 'Uploading image %1$d of %2$d to the Media Library…', 'html-md-importer' ),
					'importingItem'  => __( 'Creating %1$d of %2$d: %3$s', 'html-md-importer' ),
					'finalizing'     => __( 'Rewriting links between the new pages…', 'html-md-importer' ),
					'done'           => __( 'Import finished.', 'html-md-importer' ),
					'doneLinks'      => __( '%1$d pages with internal links updated, %2$d links without target removed.', 'html-md-importer' ),
					'error'          => __( 'Error', 'html-md-importer' ),
					'skippedFiles'   => __( '%d files skipped (unsupported type).', 'html-md-importer' ),
					'duplicate'      => __( 'Duplicate, skipped: %s', 'html-md-importer' ),
					'missing'        => __( 'Missing image: %s', 'html-md-importer' ),
					'existing'       => __( 'Imported before – will be updated', 'html-md-importer' ),
					'container'      => __( 'Folder without document', 'html-md-importer' ),
					'links'          => __( '%d internal links', 'html-md-importer' ),
					'created'        => __( 'created', 'html-md-importer' ),
					'updated'        => __( 'updated', 'html-md-importer' ),
					'skipped'        => __( 'skipped', 'html-md-importer' ),
					'category'       => __( 'category', 'html-md-importer' ),
					'edit'           => __( 'Edit', 'html-md-importer' ),
					'view'           => __( 'View', 'html-md-importer' ),
					'confirmLeave'   => __( 'An import is running. Leave the page?', 'html-md-importer' ),
					'summary'        => __( '%1$d pages/posts, %2$d images', 'html-md-importer' ),
				),
			)
		);
	}

	public static function render(): void {
		if ( ! Rest::can_import() ) {
			wp_die( esc_html__( 'You are not allowed to import content.', 'html-md-importer' ) );
		}
		$can_pages = current_user_can( 'edit_pages' );
		$can_posts = current_user_can( 'edit_posts' );
		?>
		<div class="wrap hmi">
			<h1><?php esc_html_e( 'HTML & Markdown Importer', 'html-md-importer' ); ?></h1>
			<p class="hmi-intro"><?php esc_html_e( 'Import a folder of HTML or Markdown files as pages or posts. Images are uploaded to the Media Library and the links are updated automatically. The folder structure becomes the page hierarchy.', 'html-md-importer' ); ?></p>

			<section class="hmi-card" id="hmi-step-source">
				<h2><span class="hmi-step">1</span> <?php esc_html_e( 'Choose source', 'html-md-importer' ); ?></h2>
				<fieldset class="hmi-source">
					<label><input type="radio" name="hmi-source" value="folder" checked> <?php esc_html_e( 'Folder', 'html-md-importer' ); ?></label>
					<label><input type="radio" name="hmi-source" value="zip"> <?php esc_html_e( 'ZIP file', 'html-md-importer' ); ?></label>
				</fieldset>
				<p class="hmi-picker" data-source="folder">
					<input type="file" id="hmi-folder" webkitdirectory directory multiple>
				</p>
				<p class="hmi-picker" data-source="zip" hidden>
					<input type="file" id="hmi-zip" accept=".zip,application/zip">
				</p>
				<p class="description" id="hmi-selection"></p>
				<p><button type="button" class="button button-primary" id="hmi-upload"><?php esc_html_e( 'Upload and analyse', 'html-md-importer' ); ?></button></p>
			</section>

			<section class="hmi-card" id="hmi-step-plan" hidden>
				<h2><span class="hmi-step">2</span> <?php esc_html_e( 'Check and adjust', 'html-md-importer' ); ?></h2>
				<div class="hmi-settings">
					<fieldset>
						<legend><?php esc_html_e( 'Import as', 'html-md-importer' ); ?></legend>
						<label><input type="radio" name="post_type" value="page" <?php checked( $can_pages ); ?> <?php disabled( ! $can_pages ); ?>> <?php esc_html_e( 'Pages (folders become parent pages)', 'html-md-importer' ); ?></label><br>
						<label><input type="radio" name="post_type" value="post" <?php checked( ! $can_pages ); ?> <?php disabled( ! $can_posts ); ?>> <?php esc_html_e( 'Posts (folders become categories)', 'html-md-importer' ); ?></label>
					</fieldset>
					<p>
						<label for="hmi-status"><?php esc_html_e( 'Status', 'html-md-importer' ); ?></label><br>
						<select id="hmi-status" name="status">
							<option value="draft"><?php esc_html_e( 'Draft', 'html-md-importer' ); ?></option>
							<option value="pending"><?php esc_html_e( 'Pending review', 'html-md-importer' ); ?></option>
							<option value="private"><?php esc_html_e( 'Private', 'html-md-importer' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'html-md-importer' ); ?></option>
						</select>
					</p>
					<p data-for="page">
						<label for="parent_page"><?php esc_html_e( 'Parent page for the top level', 'html-md-importer' ); ?></label><br>
						<?php
						echo wp_dropdown_pages( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							array(
								'name'              => 'parent_page',
								'id'                => 'parent_page',
								'show_option_none'  => esc_html__( '— None —', 'html-md-importer' ),
								'option_none_value' => '0',
								'post_status'       => array( 'publish', 'draft', 'private', 'pending' ),
								'echo'              => 0,
							)
						);
						?>
					</p>
					<p data-for="post" hidden>
						<label for="parent_cat"><?php esc_html_e( 'Parent category', 'html-md-importer' ); ?></label><br>
						<?php
						wp_dropdown_categories(
							array(
								'name'              => 'parent_cat',
								'id'                => 'parent_cat',
								'show_option_none'  => esc_html__( '— None —', 'html-md-importer' ),
								'option_none_value' => '0',
								'hide_empty'        => 0,
								'hierarchical'      => 1,
							)
						);
						?>
					</p>
					<fieldset class="hmi-options">
						<legend><?php esc_html_e( 'Options', 'html-md-importer' ); ?></legend>
						<label><input type="checkbox" name="strip_h1" checked> <?php esc_html_e( 'Use the first heading (H1) as title and remove it from the content', 'html-md-importer' ); ?></label><br>
						<label><input type="checkbox" name="update_existing" checked> <?php esc_html_e( 'Update pages imported before from the same source instead of creating duplicates', 'html-md-importer' ); ?></label><br>
						<label><input type="checkbox" name="featured_image"> <?php esc_html_e( 'Use the first image as featured image', 'html-md-importer' ); ?></label><br>
						<label data-for="page"><input type="checkbox" name="containers" checked> <?php esc_html_e( 'Create empty parent pages for folders without a document', 'html-md-importer' ); ?></label>
						<label data-for="post" hidden><input type="checkbox" name="folder_categories" checked> <?php esc_html_e( 'Create categories from folders', 'html-md-importer' ); ?></label>
					</fieldset>
				</div>

				<table class="widefat striped hmi-plan">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="hmi-all" checked aria-label="<?php esc_attr_e( 'Select all', 'html-md-importer' ); ?>"></td>
							<th scope="col"><?php esc_html_e( 'Title', 'html-md-importer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Source', 'html-md-importer' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Images', 'html-md-importer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Notes', 'html-md-importer' ); ?></th>
						</tr>
					</thead>
					<tbody id="hmi-plan-rows"></tbody>
				</table>
				<p class="hmi-actions">
					<button type="button" class="button button-primary" id="hmi-import"><?php esc_html_e( 'Import', 'html-md-importer' ); ?></button>
					<button type="button" class="button" id="hmi-cancel"><?php esc_html_e( 'Start over', 'html-md-importer' ); ?></button>
					<span id="hmi-summary" class="description"></span>
				</p>
			</section>

			<section class="hmi-card" id="hmi-step-progress" hidden>
				<h2><span class="hmi-step">3</span> <?php esc_html_e( 'Import', 'html-md-importer' ); ?></h2>
				<progress id="hmi-progress" max="100" value="0"></progress>
				<p id="hmi-status-text" aria-live="polite"></p>
				<table class="widefat striped hmi-results" hidden>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Title', 'html-md-importer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'html-md-importer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Notes', 'html-md-importer' ); ?></th>
						</tr>
					</thead>
					<tbody id="hmi-result-rows"></tbody>
				</table>
				<ul id="hmi-log" class="hmi-log"></ul>
				<p><button type="button" class="button" id="hmi-restart" hidden><?php esc_html_e( 'Import more', 'html-md-importer' ); ?></button></p>
			</section>
		</div>
		<?php
	}
}
