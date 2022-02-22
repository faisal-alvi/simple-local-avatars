<?php
/**
 * Class: Simple_Local_Avatars
 * Adds an avatar upload field to user profiles.
 */
class Simple_Local_Avatars {

	private $user_id_being_edited, $avatar_upload_error, $remove_nonce, $avatar_ratings;
	public $options;

	/**
	 * Set up the hooks and default values
	 */
	public function __construct() {
		$this->options        = (array) get_option( 'simple_local_avatars' );
		$this->avatar_ratings = array(
			'G'  => __( 'G &#8212; Suitable for all audiences', 'simple-local-avatars' ),
			'PG' => __( 'PG &#8212; Possibly offensive, usually for audiences 13 and above', 'simple-local-avatars' ),
			'R'  => __( 'R &#8212; Intended for adult audiences above 17', 'simple-local-avatars' ),
			'X'  => __( 'X &#8212; Even more mature than above', 'simple-local-avatars' ),
		);

		$this->add_hooks();
	}

	/**
	 * Register actions and filters.
	 */
	public function add_hooks() {

		add_filter( 'plugin_action_links_' . SLA_PLUGIN_BASENAME, array( $this, 'plugin_filter_action_links' ) );

		add_filter( 'pre_get_avatar_data', array( $this, 'get_avatar_data' ), 10, 2 );

		add_action( 'admin_init', array( $this, 'admin_init' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'show_user_profile', array( $this, 'edit_user_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'edit_user_profile' ) );

		add_action( 'personal_options_update', array( $this, 'edit_user_profile_update' ) );
		add_action( 'edit_user_profile_update', array( $this, 'edit_user_profile_update' ) );
		add_action( 'admin_action_remove-simple-local-avatar', array( $this, 'action_remove_simple_local_avatar' ) );
		add_action( 'wp_ajax_assign_simple_local_avatar_media', array( $this, 'ajax_assign_simple_local_avatar_media' ) );
		add_action( 'wp_ajax_remove_simple_local_avatar', array( $this, 'action_remove_simple_local_avatar' ) );
		add_action( 'user_edit_form_tag', array( $this, 'user_edit_form_tag' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );

		add_action( 'wp_ajax_migrate_from_wp_user_avatar', array( $this, 'ajax_migrate_from_wp_user_avatar' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'simple-local-avatars migrate wp-user-avatar', array( $this, 'wp_cli_migrate_from_wp_user_avatar' ) );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'wp_ajax_sla_clear_user_cache', array( $this, 'sla_clear_user_cache' ) );
	}

	/**
	 * Add the settings action link to the plugin page.
	 *
	 * @param array $links The Action links for the plugin.
	 *
	 * @return array
	 */
	public function plugin_filter_action_links( $links ) {

		if ( ! is_array( $links ) ) {
			return $links;
		}

		$links['settings'] = sprintf(
			'<a href="%s"> %s </a>',
			esc_url( admin_url( 'options-discussion.php' ) ),
			__( 'Settings', 'simple-local-avatars' )
		);

		return $links;
	}

	/**
	 * Retrieve the local avatar for a user who provided a user ID, email address or post/comment object.
	 *
	 * @param string            $avatar      Avatar return by original function
	 * @param int|string|object $id_or_email A user ID, email address, or post/comment object
	 * @param int               $size        Size of the avatar image
	 * @param string            $default     URL to a default image to use if no avatar is available
	 * @param string            $alt         Alternative text to use in image tag. Defaults to blank
	 * @param array             $args        Optional. Extra arguments to retrieve the avatar.
	 *
	 * @return string <img> tag for the user's avatar
	 */
	public function get_avatar( $avatar = '', $id_or_email = '', $size = 96, $default = '', $alt = '', $args = array() ) {
		return apply_filters( 'simple_local_avatar', get_avatar( $id_or_email, $size, $default, $alt, $args ) );
	}

	/**
	 * Filter avatar data early to add avatar url if needed. This filter hooks
	 * before Gravatar setup to prevent wasted requests.
	 *
	 * @since 2.2.0
	 *
	 * @param array $args        Arguments passed to get_avatar_data(), after processing.
	 * @param mixed $id_or_email The Gravatar to retrieve. Accepts a user ID, Gravatar MD5 hash,
	 *                           user email, WP_User object, WP_Post object, or WP_Comment object.
	 */
	public function get_avatar_data( $args, $id_or_email ) {
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		$simple_local_avatar_url = $this->get_simple_local_avatar_url( $id_or_email, $args['size'] );
		if ( $simple_local_avatar_url ) {
			$args['url'] = $simple_local_avatar_url;
		}

		// Local only mode
		if ( ! $simple_local_avatar_url && ! empty( $this->options['only'] ) ) {
			$args['url'] = $this->get_default_avatar_url( $args['size'] );
		}

		if ( ! empty( $args['url'] ) ) {
			$args['found_avatar'] = true;
		}

		return $args;
	}

	/**
	 * Get local avatar url.
	 *
	 * @since 2.2.0
	 *
	 * @param mixed $id_or_email The Gravatar to retrieve. Accepts a user ID, Gravatar MD5 hash,
	 *                           user email, WP_User object, WP_Post object, or WP_Comment object.
	 * @param int   $size        Requested avatar size.
	 */
	public function get_simple_local_avatar_url( $id_or_email, $size ) {
		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( is_string( $id_or_email ) && ( $user = get_user_by( 'email', $id_or_email ) ) ) {
			$user_id = $user->ID;
		} elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( $id_or_email instanceof WP_Post && ! empty( $id_or_email->post_author ) ) {
			$user_id = (int) $id_or_email->post_author;
		}

		if ( empty( $user_id ) ) {
			return '';
		}

		// Fetch local avatar from meta and make sure it's properly set.
		$local_avatars = get_user_meta( $user_id, 'simple_local_avatar', true );
		if ( empty( $local_avatars['full'] ) ) {
			return '';
		}

		// check rating
		$avatar_rating = get_user_meta( $user_id, 'simple_local_avatar_rating', true );
		if ( ! empty( $avatar_rating ) && 'G' !== $avatar_rating && ( $site_rating = get_option( 'avatar_rating' ) ) ) {
			$ratings              = array_keys( $this->avatar_ratings );
			$site_rating_weight   = array_search( $site_rating, $ratings );
			$avatar_rating_weight = array_search( $avatar_rating, $ratings );
			if ( false !== $avatar_rating_weight && $avatar_rating_weight > $site_rating_weight ) {
				return '';
			}
		}

		// handle "real" media
		if ( ! empty( $local_avatars['media_id'] ) ) {
			// has the media been deleted?
			if ( ! $avatar_full_path = get_attached_file( $local_avatars['media_id'] ) ) {
				return '';
			}
		}

		$size = (int) $size;

		// Generate a new size.
		if ( ! array_key_exists( $size, $local_avatars ) ) {
			$local_avatars[ $size ] = $local_avatars['full']; // just in case of failure elsewhere

			// allow automatic rescaling to be turned off
			if ( apply_filters( 'simple_local_avatars_dynamic_resize', true ) ) :

				$upload_path = wp_upload_dir();

				// get path for image by converting URL, unless its already been set, thanks to using media library approach
				if ( ! isset( $avatar_full_path ) ) {
					$avatar_full_path = str_replace( $upload_path['baseurl'], $upload_path['basedir'], $local_avatars['full'] );
				}

				// generate the new size
				$editor = wp_get_image_editor( $avatar_full_path );
				if ( ! is_wp_error( $editor ) ) {
					$resized = $editor->resize( $size, $size, true );
					if ( ! is_wp_error( $resized ) ) {
						$dest_file = $editor->generate_filename();
						$saved     = $editor->save( $dest_file );
						if ( ! is_wp_error( $saved ) ) {
							$local_avatars[ $size ] = str_replace( $upload_path['basedir'], $upload_path['baseurl'], $dest_file );
						}
					}
				}

				// save updated avatar sizes
				update_user_meta( $user_id, 'simple_local_avatar', $local_avatars );

			endif;
		}

		if ( 'http' !== substr( $local_avatars[ $size ], 0, 4 ) ) {
			$local_avatars[ $size ] = home_url( $local_avatars[ $size ] );
		}

		return esc_url( $local_avatars[ $size ] );
	}

	/**
	 * Get default avatar url
	 *
	 * @since 2.2.0
	 *
	 * @param int $size Requested avatar size.
	 */
	public function get_default_avatar_url( $size ) {
		if ( empty( $default ) ) {
			$avatar_default = get_option( 'avatar_default' );
			if ( empty( $avatar_default ) ) {
				$default = 'mystery';
			} else {
				$default = $avatar_default;
			}
		}

		$host = is_ssl() ? 'https://secure.gravatar.com' : 'http://0.gravatar.com';

		if ( 'mystery' === $default ) {
			$default = "$host/avatar/ad516503a11cd5ca435acc9bb6523536?s={$size}"; // ad516503a11cd5ca435acc9bb6523536 == md5('unknown@gravatar.com')
		} elseif ( 'blank' === $default ) {
			$default = includes_url( 'images/blank.gif' );
		} elseif ( 'gravatar_default' === $default ) {
			$default = "$host/avatar/?s={$size}";
		} else {
			$default = "$host/avatar/?d=$default&amp;s={$size}";
		}

		return $default;
	}

	/**
	 * Register admin settings.
	 */
	public function admin_init() {
		// upgrade pre 2.0 option
		if ( $old_ops = get_option( 'simple_local_avatars_caps' ) ) {
			if ( ! empty( $old_ops['simple_local_avatars_caps'] ) ) {
				update_option( 'simple_local_avatars', array( 'caps' => 1 ) );
			}

			delete_option( 'simple_local_avatar_caps' );
		}

		register_setting( 'discussion', 'simple_local_avatars', array( $this, 'sanitize_options' ) );
		add_settings_field(
			'simple-local-avatars-only',
			__( 'Local Avatars Only', 'simple-local-avatars' ),
			array( $this, 'avatar_settings_field' ),
			'discussion',
			'avatars',
			array(
				'key'  => 'only',
				'desc' => __( 'Only allow local avatars (still uses Gravatar for default avatars)', 'simple-local-avatars' ),
			)
		);
		add_settings_field(
			'simple-local-avatars-caps',
			__( 'Local Upload Permissions', 'simple-local-avatars' ),
			array( $this, 'avatar_settings_field' ),
			'discussion',
			'avatars',
			array(
				'key'  => 'caps',
				'desc' => __( 'Only allow users with file upload capabilities to upload local avatars (Authors and above)', 'simple-local-avatars' ),
			)
		);
		add_settings_field(
			'simple-local-avatars-migration',
			__( 'Migrate Other Local Avatars', 'simple-local-avatars' ),
			array( $this, 'migrate_from_wp_user_avatar_settings_field' ),
			'discussion',
			'avatars'
		);
		add_settings_field(
			'simple-local-avatars-clear',
			esc_html__( 'Clear local avatar cache', 'simple-local-avatars' ),
			array( $this, 'avatar_settings_field' ),
			'discussion',
			'avatars',
			array(
				'key'  => 'clear_cache',
				'desc' => esc_html__( 'Clear cache of stored avatars', 'simple-local-avatars' ),
			)
		);
	}

	/**
	 * Add scripts to the profile editing page
	 *
	 * @param string $hook_suffix Page hook
	 */
	public function admin_enqueue_scripts( $hook_suffix ) {

		/**
		 * Filter the admin screens where we enqueue our scripts.
		 *
		 * @param array $screens Array of admin screens.
		 * @param string $hook_suffix Page hook.
		 * @return array
		 */
		$screens = apply_filters( 'simple_local_avatars_admin_enqueue_scripts', array( 'profile.php', 'user-edit.php', 'options-discussion.php' ), $hook_suffix );

		if ( ! in_array( $hook_suffix, $screens, true ) ) {
			return;
		}

		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		$user_id = filter_input( INPUT_GET, 'user_id', FILTER_SANITIZE_NUMBER_INT );
		$user_id = ( 'profile.php' === $hook_suffix ) ? get_current_user_id() : (int) $user_id;

		$this->remove_nonce = wp_create_nonce( 'remove_simple_local_avatar_nonce' );

		wp_enqueue_script( 'simple-local-avatars', plugins_url( '', dirname( __FILE__ ) ) . '/dist/simple-local-avatars.js', array( 'jquery' ), false, true );
		wp_localize_script(
			'simple-local-avatars',
			'i10n_SimpleLocalAvatars',
			array(
				'user_id'                         => $user_id,
				'insertMediaTitle'                => __( 'Choose an Avatar', 'simple-local-avatars' ),
				'insertIntoPost'                  => __( 'Set as avatar', 'simple-local-avatars' ),
				'selectCrop'                      => __( 'Select avatar and Crop', 'simple-local-avatars' ),
				'deleteNonce'                     => $this->remove_nonce,
				'mediaNonce'                      => wp_create_nonce( 'assign_simple_local_avatar_nonce' ),
				'migrateFromWpUserAvatarNonce'    => wp_create_nonce( 'migrate_from_wp_user_avatar_nonce' ),
				'migrateFromWpUserAvatarSuccess'  => __( 'Number of avatars successfully migrated from WP User Avatar', 'simple-local-avatars' ),
				'migrateFromWpUserAvatarFailure'  => __( 'No avatars were migrated from WP User Avatar.', 'simple-local-avatars' ),
				'migrateFromWpUserAvatarProgress' => __( 'Migration in progress.', 'simple-local-avatars' ),
			)
		);
	}

	/**
	 * Sanitize new settings field before saving
	 *
	 * @param  array|string $input Passed input values to sanitize
	 * @return array|string Sanitized input fields
	 */
	public function sanitize_options( $input ) {
		$new_input['caps'] = empty( $input['caps'] ) ? 0 : 1;
		$new_input['only'] = empty( $input['only'] ) ? 0 : 1;
		return $new_input;
	}

	/**
	 * Settings field for avatar upload capabilities
	 *
	 * @param array $args Field arguments
	 */
	public function avatar_settings_field( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'key'  => '',
				'desc' => '',
			)
		);

		if ( empty( $this->options[ $args['key'] ] ) ) {
			$this->options[ $args['key'] ] = 0;
		}

		if ( 'clear_cache' !== $args['key'] ) {
			echo '
			<label for="simple-local-avatars-' . esc_attr( $args['key'] ) . '">
				<input type="checkbox" name="simple_local_avatars[' . esc_attr( $args['key'] ) . ']" id="simple-local-avatars-' . esc_attr( $args['key'] ) . '" value="1" ' . checked( $this->options[ $args['key'] ], 1, false ) . ' />
				' . esc_html( $args['desc'] ) . '
			</label>
		';
		} else {
			echo '<button id="clear_cache_btn" class="button delete" name="clear_cache_btn" >' . esc_html__( 'Clear cache', 'simple-local-avatars' ) . '</button><br/>';
			echo '<span id="clear_cache_message" style="font-style:italic;font-size:14px;line-height:2;"></span>';
		}
	}

	/**
	 * Settings field for migrating avatars away from WP User Avatar
	 */
	public function migrate_from_wp_user_avatar_settings_field() {
		printf(
			'<p><button type="button" name="%1$s" id="%1$s" class="button button-secondary">%2$s</button></p><p class="%1$s-progress"></p>',
			esc_attr( 'simple-local-avatars-migrate-from-wp-user-avatar' ),
			esc_html__( 'Migrate avatars from WP User Avatar to Simple Local Avatars', 'simple-local-avatars' )
		);
	}

	/**
	 * Output new Avatar fields to user editing / profile screen
	 *
	 * @param object $profileuser User object
	 */
	public function edit_user_profile( $profileuser ) {
		?>
		<div id="simple-local-avatar-section">
			<h3><?php esc_html_e( 'Avatar', 'simple-local-avatars' ); ?></h3>

			<table class="form-table">
				<tr class="upload-avatar-row">
					<th scope="row"><label for="simple-local-avatar"><?php esc_html_e( 'Upload Avatar', 'simple-local-avatars' ); ?></label></th>
					<td style="width: 50px;" id="simple-local-avatar-photo">
						<?php
						add_filter( 'pre_option_avatar_rating', '__return_null' );     // ignore ratings here
						echo get_simple_local_avatar( $profileuser->ID );
						remove_filter( 'pre_option_avatar_rating', '__return_null' );
						?>
					</td>
					<td>
						<?php
						if ( ! $upload_rights = current_user_can( 'upload_files' ) ) {
							$upload_rights = empty( $this->options['caps'] );
						}

						if ( $upload_rights ) {
							do_action( 'simple_local_avatar_notices' );
							wp_nonce_field( 'simple_local_avatar_nonce', '_simple_local_avatar_nonce', false );
							$remove_url = add_query_arg(
								array(
									'action'   => 'remove-simple-local-avatar',
									'user_id'  => $profileuser->ID,
									'_wpnonce' => $this->remove_nonce,
								)
							);
							?>
							<?php
							// if user is author and above hide the choose file option
							// force them to use the WP Media Selector
							if ( ! current_user_can( 'upload_files' ) ) {
								?>
								<p style="display: inline-block; width: 26em;">
									<span class="description"><?php esc_html_e( 'Choose an image from your computer:' ); ?></span><br />
									<input type="file" name="simple-local-avatar" id="simple-local-avatar" class="standard-text" />
									<span class="spinner" id="simple-local-avatar-spinner"></span>
								</p>
							<?php } ?>
							<p>
								<?php if ( current_user_can( 'upload_files' ) && did_action( 'wp_enqueue_media' ) ) : ?>
									<a href="#" class="button hide-if-no-js" id="simple-local-avatar-media"><?php esc_html_e( 'Choose from Media Library', 'simple-local-avatars' ); ?></a> &nbsp;
								<?php endif; ?>
								<a href="<?php echo esc_url( $remove_url ); ?>" class="button item-delete submitdelete deletion" id="simple-local-avatar-remove" <?php echo empty( $profileuser->simple_local_avatar ) ? ' style="display:none;"' : ''; ?>>
									<?php esc_html_e( 'Delete local avatar', 'simple-local-avatars' ); ?>
								</a>
							</p>
							<?php
						} else {
							if ( empty( $profileuser->simple_local_avatar ) ) {
								echo '<span class="description">' . esc_html__( 'No local avatar is set. Set up your avatar at Gravatar.com.', 'simple-local-avatars' ) . '</span>';
							} else {
								echo '<span class="description">' . esc_html__( 'You do not have media management permissions. To change your local avatar, contact the blog administrator.', 'simple-local-avatars' ) . '</span>';
							}
						}
						?>
					</td>
				</tr>
				<tr class="ratings-row">
					<th scope="row"><?php esc_html_e( 'Rating' ); ?></th>
					<td colspan="2">
						<fieldset id="simple-local-avatar-ratings" <?php disabled( empty( $profileuser->simple_local_avatar ) ); ?>>
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Rating' ); ?></span></legend>
							<?php
							$this->update_avatar_ratings();

							if ( empty( $profileuser->simple_local_avatar_rating ) || ! array_key_exists( $profileuser->simple_local_avatar_rating, $this->avatar_ratings ) ) {
								$profileuser->simple_local_avatar_rating = 'G';
							}

							foreach ( $this->avatar_ratings as $key => $rating ) :
								echo "\n\t<label><input type='radio' name='simple_local_avatar_rating' value='" . esc_attr( $key ) . "' " . checked( $profileuser->simple_local_avatar_rating, $key, false ) . '/>' . esc_html( $rating ) . '</label><br />';
							endforeach;
							?>
							<p class="description"><?php esc_html_e( 'If the local avatar is inappropriate for this site, Gravatar will be attempted.', 'simple-local-avatars' ); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Ensure that the profile form has proper encoding type
	 */
	public function user_edit_form_tag() {
		echo 'enctype="multipart/form-data"';
	}

	/**
	 * Saves avatar image to a user
	 *
	 * @param int|string $url_or_media_id Local URL for avatar or ID of attachment
	 * @param int        $user_id         ID of user to assign image to
	 */
	public function assign_new_user_avatar( $url_or_media_id, $user_id ) {
		// delete the old avatar
		$this->avatar_delete( $user_id );    // delete old images if successful

		$meta_value = array();

		// set the new avatar
		if ( is_int( $url_or_media_id + 0 ) ) {
			$meta_value['media_id'] = $url_or_media_id;
			$url_or_media_id        = wp_get_attachment_url( $url_or_media_id );
		}

		$meta_value['full'] = $url_or_media_id;

		update_user_meta( $user_id, 'simple_local_avatar', $meta_value );    // save user information (overwriting old)
	}

	/**
	 * Save any changes to the user profile
	 *
	 * @param int $user_id ID of user being updated
	 */
	public function edit_user_profile_update( $user_id ) {
		// check nonces
		if ( empty( $_POST['_simple_local_avatar_nonce'] ) || ! wp_verify_nonce( $_POST['_simple_local_avatar_nonce'], 'simple_local_avatar_nonce' ) ) {
			return;
		}

		// check for uploaded files
		if ( ! empty( $_FILES['simple-local-avatar']['name'] ) ) :

			// need to be more secure since low privelege users can upload
			if ( false !== strpos( $_FILES['simple-local-avatar']['name'], '.php' ) ) {
				$this->avatar_upload_error = __( 'For security reasons, the extension ".php" cannot be in your file name.', 'simple-local-avatars' );
				add_action( 'user_profile_update_errors', array( $this, 'user_profile_update_errors' ) );
				return;
			}

			// front end (theme my profile etc) support
			if ( ! function_exists( 'media_handle_upload' ) ) {
				include_once ABSPATH . 'wp-admin/includes/media.php';
			}

			// allow developers to override file size upload limit for avatars
			add_filter( 'upload_size_limit', array( $this, 'upload_size_limit' ) );

			$this->user_id_being_edited = $user_id; // make user_id known to unique_filename_callback function
			$avatar_id                  = media_handle_upload(
				'simple-local-avatar',
				0,
				array(),
				array(
					'mimes'                    => array(
						'jpg|jpeg|jpe' => 'image/jpeg',
						'gif'          => 'image/gif',
						'png'          => 'image/png',
					),
					'test_form'                => false,
					'unique_filename_callback' => array( $this, 'unique_filename_callback' ),
				)
			);

			remove_filter( 'upload_size_limit', array( $this, 'upload_size_limit' ) );

			if ( is_wp_error( $avatar_id ) ) { // handle failures.
				$this->avatar_upload_error = '<strong>' . __( 'There was an error uploading the avatar:', 'simple-local-avatars' ) . '</strong> ' . esc_html( $avatar_id->get_error_message() );
				add_action( 'user_profile_update_errors', array( $this, 'user_profile_update_errors' ) );
				return;
			}

			$this->assign_new_user_avatar( $avatar_id, $user_id );

		endif;

		// Handle ratings
		if ( isset( $avatar_id ) || $avatar = get_user_meta( $user_id, 'simple_local_avatar', true ) ) {
			if ( empty( $_POST['simple_local_avatar_rating'] ) || ! array_key_exists( $_POST['simple_local_avatar_rating'], $this->avatar_ratings ) ) {
				$_POST['simple_local_avatar_rating'] = key( $this->avatar_ratings );
			}

			update_user_meta( $user_id, 'simple_local_avatar_rating', $_POST['simple_local_avatar_rating'] );
		}
	}

	/**
	 * Allow developers to override the maximum allowable file size for avatar uploads
	 *
	 * @param  int $bytes WordPress default byte size check
	 * @return int Maximum byte size
	 */
	public function upload_size_limit( $bytes ) {
		return apply_filters( 'simple_local_avatars_upload_limit', $bytes );
	}

	/**
	 * Runs when a user clicks the Remove button for the avatar
	 */
	public function action_remove_simple_local_avatar() {
		if ( ! empty( $_GET['user_id'] ) && ! empty( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'remove_simple_local_avatar_nonce' ) ) {
			$user_id = (int) $_GET['user_id'];

			if ( ! current_user_can( 'edit_user', $user_id ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this user.', 'simple-local-avatars' ) );
			}

			$this->avatar_delete( $user_id );    // delete old images if successful

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				echo get_simple_local_avatar( $user_id );
			}
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die;
		}
	}

	/**
	 * AJAX callback for assigning media ID fetched from media library to user
	 */
	public function ajax_assign_simple_local_avatar_media() {
		// check required information and permissions
		if ( empty( $_POST['user_id'] ) || empty( $_POST['media_id'] ) || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_user', $_POST['user_id'] ) || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'assign_simple_local_avatar_nonce' ) ) {
			die;
		}

		$media_id = (int) $_POST['media_id'];
		$user_id  = (int) $_POST['user_id'];

		// ensure the media is real is an image
		if ( wp_attachment_is_image( $media_id ) ) {
			$this->assign_new_user_avatar( $media_id, $user_id );
		}

		echo get_simple_local_avatar( $user_id );

		die;
	}

	/**
	 * Delete avatars based on a user_id
	 *
	 * @param int $user_id User ID.
	 */
	public function avatar_delete( $user_id ) {
		$old_avatars = (array) get_user_meta( $user_id, 'simple_local_avatar', true );

		if ( empty( $old_avatars ) ) {
			return;
		}

		// if it was uploaded media, don't erase the full size or try to erase an the ID
		if ( array_key_exists( 'media_id', $old_avatars ) ) {
			unset( $old_avatars['media_id'], $old_avatars['full'] );
		}

		if ( ! empty( $old_avatars ) ) {
			$upload_path = wp_upload_dir();

			foreach ( $old_avatars as $old_avatar ) {
				// derive the path for the file based on the upload directory
				$old_avatar_path = str_replace( $upload_path['baseurl'], $upload_path['basedir'], $old_avatar );
				if ( file_exists( $old_avatar_path ) ) {
					unlink( $old_avatar_path );
				}
			}
		}

		delete_user_meta( $user_id, 'simple_local_avatar' );
		delete_user_meta( $user_id, 'simple_local_avatar_rating' );
	}

	/**
	 * Creates a unique, meaningful file name for uploaded avatars.
	 *
	 * @param  string $dir  Path for file
	 * @param  string $name Filename
	 * @param  string $ext  File extension (e.g. ".jpg")
	 * @return string Final filename
	 */
	public function unique_filename_callback( $dir, $name, $ext ) {
		$user = get_user_by( 'id', (int) $this->user_id_being_edited );
		$name = $base_name = sanitize_file_name( $user->display_name . '_avatar_' . time() );

		// ensure no conflicts with existing file names
		$number = 1;
		while ( file_exists( $dir . "/$name$ext" ) ) {
			$name = $base_name . '_' . $number;
			$number++;
		}

		return $name . $ext;
	}

	/**
	 * Adds errors based on avatar upload problems.
	 *
	 * @param WP_Error $errors Error messages for user profile screen.
	 */
	public function user_profile_update_errors( WP_Error $errors ) {
		$errors->add( 'avatar_error', $this->avatar_upload_error );
	}

	/**
	 * Registers the simple_local_avatar field in the REST API.
	 */
	public function register_rest_fields() {
		register_rest_field(
			'user',
			'simple_local_avatar',
			array(
				'get_callback'    => array( $this, 'get_avatar_rest' ),
				'update_callback' => array( $this, 'set_avatar_rest' ),
				'schema'          => array(
					'description' => 'The users simple local avatar',
					'type'        => 'object',
				),
			)
		);
	}

	/**
	 * Returns the simple_local_avatar meta key for the given user.
	 *
	 * @param object $user User object
	 */
	public function get_avatar_rest( $user ) {
		$local_avatar = get_user_meta( $user['id'], 'simple_local_avatar', true );
		if ( empty( $local_avatar ) ) {
			return;
		}
		return $local_avatar;
	}

	/**
	 * Updates the simple local avatar from a REST request.
	 *
	 * Since we are just adding a field to the existing user endpoint
	 * we don't need to worry about ensuring the calling user has proper permissions.
	 * Only the user or an administrator would be able to change the avatar.
	 *
	 * @param array  $input Input submitted via REST request.
	 * @param object $user  The user making the request.
	 */
	public function set_avatar_rest( $input, $user ) {
		$this->assign_new_user_avatar( $input['media_id'], $user->ID );
	}

	/**
	 * Overwriting existing avatar_ratings so this can be called just before the rating strings would be used so that
	 * translations will work correctly.
	 * Default text-domain because the strings have already been translated
	 */
	private function update_avatar_ratings() {
		$this->avatar_ratings = array(
			'G'  => __( 'G &#8212; Suitable for all audiences' ),
			'PG' => __( 'PG &#8212; Possibly offensive, usually for audiences 13 and above' ),
			'R'  => __( 'R &#8212; Intended for adult audiences above 17' ),
			'X'  => __( 'X &#8212; Even more mature than above' ),
		);
	}

	/**
	 * Load script required for handling any actions.
	 */
	public function admin_scripts() {
		wp_enqueue_script(
			'sla_admin',
			SLA_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			SLA_VERSION,
			true
		);

		wp_localize_script(
			'sla_admin',
			'slaAdmin',
			[
				'nonce' => wp_create_nonce( 'sla_clear_cache_nonce' ),
				'error' => esc_html__( 'Something went wrong while clearing cache, please try again.', 'simple-local-avatars' ),
			]
		);
	}

	/**
	 * Clear user cache.
	 */
	public function sla_clear_user_cache() {
		check_ajax_referer( 'sla_clear_cache_nonce', 'nonce' );
		$step = isset( $_REQUEST['step'] ) ? intval( $_REQUEST['step'] ) : 1;

		// Setup defaults.
		$users_per_page = 50;
		$offset         = ( $step - 1 ) * $users_per_page;

		$users_query = new \WP_User_Query(
			array(
				'fields' => array( 'ID' ),
				'number' => $users_per_page,
				'offset' => $offset,
			)
		);

		// Total users in the site.
		$total_users = $users_query->get_total();

		// Get the users.
		$users = $users_query->get_results();

		if ( ! empty( $users ) ) {
			foreach ( $users as $user ) {
				$user_id       = $user->ID;
				$local_avatars = get_user_meta( $user_id, 'simple_local_avatar', true );
				$this->clear_user_avatar_cache( $local_avatars, $user_id, $local_avatars['media_id'] ?? '' );
			}

			wp_send_json_success(
				array(
					'step'    => $step + 1,
					'message' => sprintf(
						/* translators: 1: Offset, 2: Total users  */
						esc_html__( 'Processing %1$s/%2$s users...', 'simple-local-avatars' ),
						$offset,
						$total_users
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'step'    => 'done',
				'message' => sprintf(
					/* translators: %s Total users */
					esc_html__( 'Completed clearing cache for all %s user(s) avatars.', 'simple-local-avatars' ),
					$total_users
				),
			)
		);
	}

	/**
	 * Clear avatar cache for given user.
	 *
	 * @param array $local_avatars Local avatars.
	 * @param int   $user_id       User ID.
	 * @param mixed $media_id      Media ID.
	 */
	private function clear_user_avatar_cache( $local_avatars, $user_id, $media_id ) {
		if ( ! empty( $media_id ) ) {
			$file_name_data = pathinfo( wp_get_original_image_path( $media_id ) );
			$file_dir_name  = $file_name_data['dirname'];
			$file_name      = $file_name_data['filename'];
			$file_ext       = $file_name_data['extension'];
			foreach ( $local_avatars as $local_avatars_key => $local_avatar_value ) {
				if ( ! in_array( $local_avatars_key, [ 'media_id', 'full' ], true ) ) {
					$file_size_path = sprintf( '%1$s/%2$s-%3$sx%3$s.%4$s', $file_dir_name, $file_name, $local_avatars_key, $file_ext );
					if ( ! file_exists( $file_size_path ) ) {
						unset( $local_avatars[ $local_avatars_key ] );
					}
				}
			}

			// Update meta, remove sizes that don't exist.
			update_user_meta( $user_id, 'simple_local_avatar', $local_avatars );
		}
	}

	/**
	 * Migrate the user's avatar data from WP User Avatar/ProfilePress
	 *
	 * This function creates a new option in the wp_options table to store the processed user IDs
	 * so that we can run this command multiple times without processing the same user over and over again.
	 *
	 * Credit to Philip John for the Gist
	 *
	 * @see https://gist.github.com/philipjohn/822d3521a95481f6ad7e118a7106fbc7
	 *
	 * @return int
	 */
	public function migrate_from_wp_user_avatar() {

		global $wpdb;

		$count = 0;

		// Support single site and multisite installs.
		// Use WordPress function if running multisite.
		// Create generic class if running single site.
		if ( is_multisite() ) {
			$sites = get_sites();
		} else {
			$site          = new stdClass();
			$site->blog_id = 1;
			$sites         = array( $site );
		}

		// Bail early if we don't find sites.
		if ( empty( $sites ) ) {
			return $count;
		}

		foreach ( $sites as $site ) {
			// Get the blog ID to use in the meta key and user query.
			$blog_id = isset( $site->blog_id ) ? $site->blog_id : 1;

			// Get the name of the meta key for WP User Avatar.
			$meta_key = $wpdb->get_blog_prefix( $blog_id ) . 'user_avatar';

			// Get processed users from database.
			$migrations      = get_option( 'simple_local_avatars_migrations', array() );
			$processed_users = isset( $migrations['wp_user_avatar'] ) ? $migrations['wp_user_avatar'] : array();

			// Get all users that have a local avatar.
			$users = get_users(
				array(
					'blog_id'      => $blog_id,
					'exclude'      => $processed_users,
					'meta_key'     => $meta_key,
					'meta_compare' => 'EXISTS',
				)
			);

			// Bail early if we don't find users.
			if ( empty( $users ) ) {
				continue;
			}

			foreach ( $users as $user ) {
				// Get the existing avatar media ID.
				$avatar_id = get_user_meta( $user->ID, $meta_key, true );

				// Attach the user and media to Simple Local Avatars.
				$sla = new Simple_Local_Avatars();
				$sla->assign_new_user_avatar( (int) $avatar_id, $user->ID );

				// Check that it worked.
				$is_migrated = get_user_meta( $user->ID, 'simple_local_avatar', true );

				if ( ! empty( $is_migrated ) ) {
					// Build array of user IDs.
					$migrations['wp_user_avatar'][] = $user->ID;

					// Record the user IDs so we don't process a second time.
					$is_saved = update_option( 'simple_local_avatars_migrations', $migrations );

					// Record how many avatars we migrate to be used in our messaging.
					if ( $is_saved ) {
						$count++;
					}
				}
			}
		}

		return $count;

	}

	/**
	 * Migrate the user's avatar data away from WP User Avatar/ProfilePress via the dashboard.
	 *
	 * Sends the number of avatars processed back to the AJAX response before stopping execution.
	 *
	 * @return void
	 */
	public function ajax_migrate_from_wp_user_avatar() {
		// Bail early if nonce is not available.
		if ( empty( sanitize_text_field( $_POST['migrateFromWpUserAvatarNonce'] ) ) ) {
			die;
		}

		// Bail early if nonce is invalid.
		if ( ! wp_verify_nonce( sanitize_text_field( $_POST['migrateFromWpUserAvatarNonce'] ), 'migrate_from_wp_user_avatar_nonce' ) ) {
			die();
		}

		// Run the migration script and store the number of avatars processed.
		$count = $this->migrate_from_wp_user_avatar();

		// Create the array we send back to javascript here.
		$array_we_send_back = array( 'count' => $count );

		// Make sure to json encode the output because that's what it is expecting.
		echo wp_json_encode( $array_we_send_back );

		// Make sure you die when finished doing ajax output.
		wp_die();

	}

	/**
	 * Migrate the user's avatar data from WP User Avatar/ProfilePress via the command line.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skips the confirmations (for automated systems).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp simple-local-avatars migrate wp-user-avatar
	 *     Success: Number of avatars successfully migrated from WP User Avatar: 5
	 *
	 * @param array $args       The arguments.
	 * @param array $assoc_args The associative arguments.
	 *
	 * @return void
	 */
	public function wp_cli_migrate_from_wp_user_avatar( $args, $assoc_args ) {

		// Argument --yes to prevent confirmation (for automated systems).
		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( esc_html__( 'Do you want to migrate avatars from WP User Avatar?', 'simple-local-avatars' ) );
		}

		// Run the migration script and store the number of avatars processed.
		$count = $this->migrate_from_wp_user_avatar();

		// Error out if we don't process any avatars.
		if ( 0 === absint( $count ) ) {
			WP_CLI::error( esc_html__( 'No avatars were migrated from WP User Avatar.', 'simple-local-avatars' ) );
		}

		WP_CLI::success(
			sprintf(
				'%s: %s',
				esc_html__( 'Number of avatars successfully migrated from WP User Avatar', 'simple-local-avatars' ),
				esc_html( $count )
			)
		);
	}
}