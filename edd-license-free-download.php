<?php

/**
 * Plugin Name: Easy Digital Downloads - License Free Download
 * Plugin URI: http://w3guy.com/
 * Description: Offer free product downloads to users with an active license of a previous product(s).
 * Author: Agbonghama Collins
 * Author URI: http://w3guy.com
 * Version: 1.0
 * Text Domain: edd_lfd
 * Domain Path: languages
 */


 // Load the EDD license handler only if not already loaded. Must be placed in the main plugin file
if( ! class_exists( 'EDD_License' ) ) {
		include( dirname( __FILE__ ) . '/includes/EDD_License_Handler.php' );
}


   class EDD_lfd {

	public static $edd_fdl_errors;

	public static function init() {

		load_plugin_textdomain( 'edd_lfd', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_filter( 'edd_cart_item_price', array( __CLASS__, 'set_price' ), 10, 3 );

		add_action( 'edd_empty_cart', array( __CLASS__, 'delete_saved_free_download_in_cart' ), 10, 3 );
		add_action( 'edd_post_remove_from_cart', array( __CLASS__, 'delete_free_downloads_on_cart_removal' ), 20, 2 );

		add_action( 'init', array( __CLASS__, 'process_download' ) );

		add_action( 'edd_cart_contents', array( __CLASS__, 'remove_duplicate_order' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

		add_action( 'save_post', array( __CLASS__, 'save_data' ) );

		add_shortcode( 'edd_lfd', array( __CLASS__, 'shortcode_form' ) );


		add_filter( 'edd_settings_extensions', array( __CLASS__, 'settings_page' ) );

	}


	/**
	 * Add meta-box to WP dashboard
	 */
	public static function add_meta_box() {

		add_meta_box(
			'lfd_id',
			__( 'License Holders Free Download', 'edd_lfd' ),
			array( __CLASS__, 'meta_box_callback' ),
			'download'
		);

	}


	/**
	 * Meta-box callback function.
	 *
	 * @param $post
	 */
	public static function meta_box_callback( $post ) {

		// Add an nonce field so we can check for it later.
		wp_nonce_field( 'edd_lfd_products_nonce', 'edd_lfd_products_nonce' );

		$activate = get_post_meta( $post->ID, '_edd_lfd_activate', true );
		$values   = get_post_meta( $post->ID, '_edd_lfd_products', true );

		$product_list = get_posts(
			array(
				'post_type'      => 'download',
				'posts_per_page' => - 1,
				'nopaging'       => true
			)
		);

		?>

		<p>
			<label for="lfd_activate"><strong><?php _e('Activate Free Download', 'edd_lfd'); ?></strong></label>
			<input id="lfd_activate" type="checkbox" name="edd_lfd_activate" value="yes" <?php checked( 'yes', $activate ); ?>>
		</p>
		<label for="products"><?php esc_html_e( 'Products whose license holders will have access to freely download this product.', 'edd_lfd' ); ?></label>
		<p>
		<?php
		echo EDD()->html->product_dropdown(
			array(
				'chosen'     => true,
				'multiple'   => true,
				'bundles'    => false,
				'name'       => 'edd_lfd_products[]',
				'selected'   => $values,
				'variations' => true,
			)
		);
		?>
		</p>
		<?php
	}


	/**
	 * Save meta box data
	 *
	 * @param int $post_id
	 */
	public static function save_data( $post_id ) {
		// Check if our nonce is set.
		if ( ! isset( $_POST['edd_lfd_products_nonce'] ) ) {
			return;
		}

		// Verify that the nonce is valid.
		if ( ! wp_verify_nonce( $_POST['edd_lfd_products_nonce'], 'edd_lfd_products_nonce' ) ) {
			return;
		}

		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check the user's permissions.
		if ( isset( $_POST['post_type'] ) && 'download' == $_POST['post_type'] ) {

			if ( ! current_user_can( 'edit_page', $post_id ) ) {
				return;
			}

		} else {

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
		}

		// Sanitize user input.
		$products = array_map( 'absint', (array) $_POST['edd_lfd_products'] );

		$activated = esc_attr( $_POST['edd_lfd_activate'] );

		// Update the meta field in the database.
		update_post_meta( $post_id, '_edd_lfd_products', $products );
		update_post_meta( $post_id, '_edd_lfd_activate', $activated );
	}


	/**
	 * License validation form.
	 */
	public static function shortcode_form( $atts ) {
		global $post;
		$atts = shortcode_atts(
			array(
				'id'          => absint( is_singular( 'download' ) ? $post->ID : '' ),
				'placeholder' => __('Enter license key', 'edd_lfd'),
				'button'      => __('Download Free', 'edd_lfd')
			),
			$atts
		);

		$error = '';
		if ( is_wp_error( self::$edd_fdl_errors ) ) {
			$error = '<div class="edd_lfd_error">' . self::$edd_fdl_errors->get_error_message() . '</div>';
		}
		ob_start();
		?>
	<form method="post">
		<p>
		<input name='edd_lfd_license_key' size="34" type="text" class="lfd_input" id="lfd_input_id" placeholder="<?php esc_attr_e( $atts['placeholder'] ); ?>"/>
		<input type="hidden" name="edd_lfd_download_id" value="<?php echo $atts['id']; ?>">
		<input type="submit" name="edd_lfd_validation" value="<?php esc_attr_e( $atts['button'] ); ?>" class="lfd_submit" id="lfd_submit_id">
		</p>
		</form>
		<?php

		$form = ob_get_clean();

		return $error . $form;
	}


	/**
	 * Process the free download.
	 */
	public static function process_download() {

		if ( ! isset( $_POST['edd_lfd_validation'] ) || empty( $_POST['edd_lfd_download_id'] ) ) {
			return;
		}

		global $edd_options;

		$download_id = absint( $_POST['edd_lfd_download_id'] );

		if ( ! isset( $_POST['edd_lfd_license_key'] ) || empty( $_POST['edd_lfd_license_key'] ) ) {
			$msg                  = ! empty( $edd_options['edd_lfd_license_missing'] ) ? $edd_options['edd_lfd_license_missing'] : apply_filters( 'lfd_license_missing', __( 'License key is missing', 'edd_lfd' ) );
			self::$edd_fdl_errors = new WP_Error( 'lfd_license_missing', $msg );

			return;
		} else {

			// Determine if the product is available for free download
			$product_status = get_post_meta( $download_id, '_edd_lfd_activate', true );
			if ( empty( $product_status ) ) {
				$msg = ! empty( $edd_options['edd_lfd_product_not_free'] ) ? $edd_options['edd_lfd_product_not_free'] : __( 'Product is not available for free.', 'edd_lfd' );

				self::$edd_fdl_errors = new WP_Error( 'lfd_products_not_free', $msg );

				return;
			}

			// License key
			$license_key = esc_attr( $_POST['edd_lfd_license_key'] );

			// verify license key
			if ( self::validate_license( $license_key ) ) {

				// check if the license key has access to download the cart product
				if ( self::comparison( $license_key, $download_id ) ) {
					self::add_to_cart_and_checkout( $download_id );
				} else {
					$msg = ! empty( $edd_options['edd_lfd_access_denied'] ) ? esc_attr( $edd_options['edd_lfd_access_denied'] ) : __( 'The license key isn\'t allowed to download this product for free. Sorry.', 'edd_lfd' );

					self::$edd_fdl_errors = new WP_Error( 'edd_lfd_access_denied', $msg );

					return;
				}
			} else {
				$msg = ! empty( $edd_options['edd_lfd_license_validation_failed'] ) ? esc_attr( $edd_options['edd_lfd_license_validation_failed'] ) : __( 'License key validation failed. Try again', 'edd_lfd' );

				self::$edd_fdl_errors = new WP_Error( 'edd_lfd_license_invalid', $msg );

				return;
			}
		}
	}


	/**
	 * Verify the license key hasn't expired
	 *
	 * Returns true if the license key is active or false otherwise
	 *
	 * @param string $license_key
	 *
	 * @return bool
	 */
	public static function validate_license( $license_key ) {
		global $wpdb;

		// The edd_license post ID of the license key
		$license_post_id = $wpdb->get_var(
		$wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_edd_sl_key' AND meta_value = %s", $license_key )
		 );

		$obj    = edd_software_licensing();
		$status = $obj->get_license_status( $license_post_id );

		if ( $status != 'expired' ||  $status != 'revoked') {
			return true;
		} else {
			return false;
		}
	}


	/**
	 * Get the products id associated with a license key
	 *
	 * @param string $license_key
	 *
	 * @return array
	 */
	public static function get_license_product_ids( $license_key ) {

		global $wpdb;

		// The edd_license post ID of the license key
		$license_post_id = $wpdb->get_var(
		$wpdb->prepare(
		"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_edd_sl_key' AND meta_value = %s", $license_key )
		);

		$payment_id = get_post_meta( $license_post_id, '_edd_sl_payment_id', true );

		$get_product_id_for_payment = edd_get_payment_meta_cart_details( $payment_id );

		$products_ids_for_payment = array();
		foreach ( $get_product_id_for_payment as $key => $products ) {
			$products_ids_for_payment[] = $products['id'];
		}

		return $products_ids_for_payment;
	}


	/**
	 * Check if any or all of the license_key products is in the list of product available for free download
	 *
	 * @return bool
	 */
	public static function comparison( $license_key, $download_id ) {

		// products ids (that was saved in 'chosen' select box) the license will be checked against
		$free_products = self::get_free_products_ids( $download_id );

		// ids of products the license is for
		$license_products = self::get_license_product_ids( $license_key );

		// store the status of the license products. i.e whether they are among the products available for free or not
		foreach ( $license_products as $product ) {
			if ( in_array( $product, $free_products ) ) {
				return true;
			}
		}

		// return false if the return above doesn't return true
		return false;
	}


	/**
	 * Return the IDs of products saved against the post/product with '$product_id' ID
	 *
	 * @param int $product_id ID of product that is mark as free to check
	 *
	 * @return array
	 */
	public static function get_free_products_ids( $product_id ) {
		global $wpdb;

		// return multi-dimensional array of all products set to free download
		$query = $wpdb->get_col(
		$wpdb->prepare( "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_edd_lfd_products' AND post_id = %d", $product_id )
		);

		return unserialize( $query[0] );

	}


	/**
	 * Add product to cart and subsequently checkout
	 *
	 * @param int $download_id
	 */
	public static function add_to_cart_and_checkout( $download_id ) {
		edd_add_to_cart( $download_id );

		$saved_download_ids = self::free_downloads_in_cart();

		if ( is_array( $saved_download_ids ) && ! empty( $saved_download_ids ) ) {
			$saved_download_ids[] = $download_id;
		} else {
			$saved_download_ids = array( $download_id );
		}

		EDD()->session->set( 'edd_fdl_download_ids', $saved_download_ids );

		wp_redirect( edd_get_checkout_uri() );
		exit;
	}


	/**
	 * Set price of free download to 0
	 *
	 * @param int $price
	 * @param int $download_id
	 * @param array $options
	 *
	 * @return int
	 */
	public static function set_price( $price, $download_id, $options ) {
		$free_product_ids = self::free_downloads_in_cart();

		if ( is_array( $free_product_ids ) && in_array( $download_id, $free_product_ids ) ) {
			$price = 0;
		}

		return $price;
	}


	/**
	 * Deleted free products saved to the free product session when cart is emptied.
	 */
	public static function delete_saved_free_download_in_cart() {
		EDD()->session->set( 'edd_fdl_download_ids', null );
	}


	/**
	 * Delete free product from session when removed from cart.
	 *
	 * @param int $cart_key
	 * @param int $item_id
	 */
	public static function delete_free_downloads_on_cart_removal( $cart_key, $item_id ) {
		// free products saved to session
		$free_products_in_cart = self::free_downloads_in_cart();

		if ( ! is_array( $free_products_in_cart ) ) {
			return;
		}

		// if the product being removed is among the saved/carted free product, delete.
		foreach ( $free_products_in_cart as $key => $value ) {
			if ( $item_id == $value ) {
				unset( $free_products_in_cart[ $key ] );
			}
		}

		// save the new free product array to seesion variable.
		if ( empty( $free_products_in_cart ) ) {
			EDD()->session->set( 'edd_fdl_download_ids', null );
		} else {
			EDD()->session->set( 'edd_fdl_download_ids', $free_products_in_cart );
		}
	}

	/**
	 * Ensure a distinct free download is available in cart.
	 *
	 * @param array $cart
	 *
	 * @return array
	 */
	public static function remove_duplicate_order( $cart ) {
		// products in cart
		$carts = EDD()->session->get( 'edd_cart' );
		$carts = ! empty( $carts ) ? array_values( $carts ) : false;

		// free downloads in saved to session and apparently also in cart
		$free_downloads_in_cart = self::free_downloads_in_cart();

		if ( ! $carts || ! is_array( $free_downloads_in_cart ) ) {
			return $cart;
		} else {

			// Am separating the free downloads from the cart item in order to make them distinct via self::array_unique

			/** @var array $free_downloads save the free download */
			$free_downloads = array();

			/** @var array $other_items_in_cart rest of other product in cart */
			$other_items_in_cart = array();

			foreach ( $carts as $key => $value ) {
				if ( in_array( $carts[ $key ]['id'], $free_downloads_in_cart ) ) {
					$free_downloads[] = $carts[ $key ];
				} else {
					$other_items_in_cart[] = $carts[ $key ];
				}
			}

			$cart = array_merge( $other_items_in_cart, self::array_unique( $free_downloads ) );

			return $cart;
		}
	}

	/**
	 * Remove duplicate from cart array
	 *
	 * @param $array
	 *
	 * @return array
	 */
	public static function array_unique( $array ) {
		$newArr = array();
		foreach ( $array as $val ) {
			$newArr[ $val['id'] ] = $val;
		}

		return array_values( $newArr );
	}


	/**
	 * Return free downloads in session/cart
	 *
	 * @return mixed
	 */
	public static function free_downloads_in_cart() {
		return EDD()->session->get( 'edd_fdl_download_ids' );
	}

	/**
	 * If dependency requirements are not satisfied, self-deactivate
	 */
	public static function maybe_self_deactivate() {
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			deactivate_plugins( plugin_basename( __FILE__ ) );
			add_action( 'admin_notices', array( __CLASS__, 'self_deactivate_notice' ) );
		}
	}

	public static function settings_page( $settings ) {

		$license_settings = array(
			array(
				'id'   => 'edd_lfd_header',
				'name' => '<strong>' . __( 'License Free Downloads', 'edd_lfd' ) . '</strong>',
				'desc' => '',
				'type' => 'header',
				'size' => 'regular'
			),
			array(
				'id'   => 'edd_lfd_license_missing',
				'name' => __( 'License Missing', 'edd_sl' ),
				'desc' => __( 'Error displayed when no license key is detected.', 'edd_lfd' ),
				'type' => 'text'
			),
			array(
				'id'   => 'edd_lfd_license_validation_failed',
				'name' => __( 'Failed License Validation', 'edd_sl' ),
				'desc' => __( 'Error displayed when a license key is deemed invalid.', 'edd_lfd' ),
				'type' => 'text'
			),
			array(
				'id'   => 'edd_lfd_product_not_free',
				'name' => __( 'Product not Free', 'edd_sl' ),
				'desc' => __( 'Error displayed when trying to download a product that is not free.', 'edd_lfd' ),
				'type' => 'text'
			),
			array(
				'id'   => 'edd_lfd_access_denied',
				'name' => __( 'Access Denied', 'edd_sl' ),
				'desc' => __( 'Error displayed when a license key is denied access to a free download.', 'edd_lfd' ),
				'type' => 'text'
			),
		);

		return array_merge( $settings, $license_settings );

	}

	/**
	 * Display an error message when the plugin deactivates itself.
	 */
	public static function self_deactivate_notice() {
		echo '<div class="error"><p><strong>' . __( 'EDD License free download', 'edd_lfd' ) . '</strong> ' . __( 'requires Easy Digital Download plugin activated to work', 'edd_lfd' ) . '.</p></div>';
	}

	/**
	 * Default options on activation
	 */
	public static function register_activation() {

		// if plugin has been activated initially, return.
		if( false !== get_option('edd_lfd_plugin_activated') ) {
			return;
		}

		edd_update_option( 'edd_lfd_license_missing', 'License key is missing.' );
		edd_update_option( 'edd_lfd_license_validation_failed', 'License key validation failed. Try again.' );
		edd_update_option( 'edd_lfd_product_not_free', 'Product is not available for free.' );
		edd_update_option( 'edd_lfd_access_denied', 'The license key isn\'t allowed to download this product for free. Sorry.' );

		// option is added to prevent overriding user entered settings if the plugin is deactivated and reactivated.
		// option is deleted when plugin is uninstalled.
		add_option('edd_lfd_plugin_activated', 'true');
	}

}

register_activation_hook( __FILE__, array( 'EDD_lfd', 'register_activation' ) );

add_action( 'plugins_loaded', array( 'EDD_lfd', 'maybe_self_deactivate' ) );

add_action( 'plugins_loaded', 'edd_lfd_load_class' );

function edd_lfd_load_class() {
	EDD_lfd::init();
}


$license = new EDD_License( __FILE__, 'License Free Download', '1.0', 'W3Guy LLC' );