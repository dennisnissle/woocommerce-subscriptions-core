<?php
/**
 * Post Types Admin
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( class_exists( 'WCS_Admin_Post_Types' ) ) {
	return new WCS_Admin_Post_Types();
}

/**
 * WC_Admin_Post_Types Class
 *
 * Handles the edit posts views and some functionality on the edit post screen for WC post types.
 */
class WCS_Admin_Post_Types {

	/**
	 * Constructor
	 */
	public function __construct() {

		// Subscription list table columns and their content
		add_filter( 'manage_edit-shop_subscription_columns', array( $this, 'shop_subscription_columns' ) );
		add_filter( 'manage_edit-shop_subscription_sortable_columns', array( $this, 'shop_subscription_sortable_columns' ) );
		add_action( 'manage_shop_subscription_posts_custom_column', array( $this, 'render_shop_subscription_columns' ), 2 );

		// Bulk actions
		add_filter( 'bulk_actions-edit-shop_subscription', array( $this, 'remove_bulk_actions' ) );
		add_action( 'admin_print_footer_scripts', array( $this, 'print_bulk_actions_script' ) );
		add_action( 'load-edit.php', array( $this, 'parse_bulk_actions' ) );
		add_action( 'admin_notices', array( $this, 'bulk_admin_notices' ) );

		// Subscription order/filter
		add_filter( 'request', array( $this, 'request_query' ) );

		// Subscription Search
		add_filter( 'get_search_query', array( $this, 'shop_subscription_search_label' ) );
		add_filter( 'query_vars', array( $this, 'add_custom_query_var' ) );
		add_action( 'parse_query', array( $this, 'shop_subscription_search_custom_fields' ) );

		add_filter( 'post_updated_messages', array( $this, 'post_updated_messages' ) );

		add_action( 'restrict_manage_posts', array( $this, 'restrict_by_product' ) );
	}


	/**
	 * Modifies the actual SQL that is needed to order by last payment date on subscriptions. Data is pulled from related
	 * but independent posts, so subqueries are needed. That's something we can't get by filtering the request. This is hooked
	 * in @see WCS_Admin_Post_Types::request_query function.
	 *
	 * @param  array 	$pieces 	all the pieces of the resulting SQL once WordPress has finished parsing it
	 * @param  WP_Query $query  	the query object that forms the basis of the SQL
	 * @return array 				modified pieces of the SQL query
	 */
	public function posts_clauses( $pieces, $query ) {
		global $wpdb;

		if ( ! is_admin() || ! isset( $query->query['post_type'] ) || 'shop_subscription' !== $query->query['post_type'] ) {
			return $pieces;
		}

		// we need to name ID again due to name conflict if we don't
		$pieces['fields'] .= ", {$wpdb->posts}.ID AS original_id, {$wpdb->posts}.post_parent AS original_parent, CASE (SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_subscription_renewal' AND meta_value = original_id)
			WHEN 0 THEN CASE (SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = original_parent)
				WHEN 0 THEN 0
				ELSE (SELECT post_date_gmt FROM {$wpdb->posts} WHERE ID = original_parent)
				END
			ELSE (SELECT p.post_date_gmt FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_subscription_renewal' AND meta_value = original_id ORDER BY p.post_date_gmt DESC LIMIT 1)
			END
			AS last_payment";

		$order = strtoupper( $query->query['order'] );

		$pieces['orderby'] = "CAST(last_payment AS DATETIME) {$order}";

		return $pieces;
	}


	/**
	 * Displays the dropdown for the product filter
	 * @return string the html dropdown element
	 */
	public function restrict_by_product() {
		global $typenow;

		if ( 'shop_subscription' !== $typenow ) {
			return;
		}

		$product_id = '';
		$product_string = '';

		if ( ! empty( $_GET['_wcs_product'] ) ) {
			$product_id     = absint( $_GET['_wcs_product'] );
			$product_string = wc_get_product( $product_id )->get_formatted_name();
		}

		?>
		<input type="hidden" class="wc-product-search" name="_wcs_product" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'woocommerce-subscriptions' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-selected="<?php echo esc_attr( strip_tags( $product_string ) ); ?>" value="<?php echo esc_attr( $product_id ); ?>" data-allow_clear="true" />
		<?php
	}

	/**
	 * Remove "edit" from the bulk actions.
	 *
	 * @param array $actions
	 * @return array
	 */
	public function remove_bulk_actions( $actions ) {

		if ( isset( $actions['edit'] ) ) {
			unset( $actions['edit'] );
		}

		return $actions;
	}

	/**
	 * Add extra options to the bulk actions dropdown
	 *
	 * It's only on the All Shop Subscriptions screen.
	 * Introducing new filter: woocommerce_subscription_bulk_actions. This has to be done through jQuery as the
	 * 'bulk_actions' filter that WordPress has can only be used to remove bulk actions, not to add them.
	 *
	 * This is a filterable array where the key is the action (will become query arg), and the value is a translatable
	 * string. The same array is used to
	 *
	 */
	public function print_bulk_actions_script() {

		$post_status = ( isset( $_GET['post_status'] ) ) ? $_GET['post_status'] : '';

		if ( 'shop_subscription' !== get_post_type() || in_array( $post_status, array( 'cancelled', 'trash', 'wc-expired' ) ) ) {
			return;
		}

		// Make it filterable in case extensions want to change this
		$bulk_actions = apply_filters( 'woocommerce_subscription_bulk_actions', array(
			'active'    => _x( 'Activate', 'action on bulk subscriptions', 'woocommerce-subscriptions' ),
			'on-hold'   => _x( 'Put on-hold', 'action on bulk subscriptions', 'woocommerce-subscriptions' ),
			'cancelled' => _x( 'Cancel', 'action on bulk subscriptions', 'woocommerce-subscriptions' ),
		) );

		// No need to display certain bulk actions if we know all the subscriptions on the page have that status already
		switch ( $post_status ) {
			case 'wc-active' :
				unset( $bulk_actions['active'] );
				break;
			case 'wc-on-hold' :
				unset( $bulk_actions['on-hold'] );
				break;
		}

		?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				<?php
				foreach ( $bulk_actions as $action => $title ) {
					?>
					$('<option>')
						.val('<?php echo esc_attr( $action ); ?>')
						.text('<?php echo esc_html( $title ); ?>')
						.appendTo("select[name='action'], select[name='action2']" );
					<?php
				}
				?>
			});
		</script>
		<?php
	}

	/**
	 * Deals with bulk actions. The style is similar to what WooCommerce is doing. Extensions will have to define their
	 * own logic by copying the concept behind this method.
	 */
	public function parse_bulk_actions() {

		// We only want to deal with shop_subscriptions. In case any other CPTs have an 'active' action
		if ( ! isset( $_REQUEST['post_type'] ) || 'shop_subscription' !== $_REQUEST['post_type'] || ! isset( $_REQUEST['post'] ) ) {
			return;
		}

		$action = '';

		if ( isset( $_REQUEST['action'] ) && -1 != $_REQUEST['action'] ) {
			$action = $_REQUEST['action'];
		} else if ( isset( $_REQUEST['action2'] ) && -1 != $_REQUEST['action2'] ) {
			$action = $_REQUEST['action2'];
		}

		switch ( $action ) {
			case 'active':
			case 'on-hold':
			case 'cancelled' :
				$new_status = $action;
				break;
			default:
				return;
		}

		$report_action = 'marked_' . $new_status;

		$changed = 0;

		$subscription_ids = array_map( 'absint', (array) $_REQUEST['post'] );

		$sendback_args = array(
			'post_type'    => 'shop_subscription',
			$report_action => true,
			'ids'          => join( ',', $subscription_ids ),
			'error_count'  => 0,
		);

		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			$order_note   = __( 'Subscription status changed by bulk edit:', 'woocommerce-subscriptions' );

			try {

				if ( 'cancelled' == $action ) {
					$subscription->cancel_order( $order_note );
				} else {
					$subscription->update_status( $new_status, $order_note, true );
				}

				// Fire the action hooks
				switch ( $action ) {
					case 'active' :
					case 'on-hold' :
					case 'cancelled' :
					case 'trash' :
						do_action( 'woocommerce_admin_changed_subscription_to_' . $action, $subscription_id );
						break;
				}

				$changed++;

			} catch ( Exception $e ) {
				$sendback_args['error'] = urlencode( $e->getMessage() );
				$sendback_args['error_count']++;
			}
		}

		$sendback_args['changed'] = $changed;
		$sendback = add_query_arg( $sendback_args, wp_get_referer() ? wp_get_referer() : '' );
		wp_redirect( $sendback );

		exit();
	}

	/**
	 * Show confirmation message that subscription status was changed
	 */
	public function bulk_admin_notices() {
		global $post_type, $pagenow;

		// Bail out if not on shop order list page
		if ( 'edit.php' !== $pagenow || 'shop_subscription' !== $post_type ) {
			return;
		}

		$subscription_statuses = wcs_get_subscription_statuses();

		// Check if any status changes happened
		foreach ( $subscription_statuses as $slug => $name ) {

			if ( isset( $_REQUEST[ 'marked_' . str_replace( 'wc-', '', $slug ) ] ) ) {

				$number = isset( $_REQUEST['changed'] ) ? absint( $_REQUEST['changed'] ) : 0;

				// translators: placeholder is the number of subscriptions updated
				$message = sprintf( _n( '%s subscription status changed.', '%s subscription statuses changed.', $number, 'woocommerce-subscriptions' ), number_format_i18n( $number ) );
				echo '<div class="updated"><p>' . esc_html( $message ) . '</p></div>';

				if ( ! empty( $_REQUEST['error_count'] ) ) {
					$error_msg = isset( $_REQUEST['error'] ) ? stripslashes( $_REQUEST['error'] ) : '';
					$error_count = isset( $_REQUEST['error_count'] ) ? absint( $_REQUEST['error_count'] ) : 0;
					// translators: 1$: is the number of subscriptions not updated, 2$: is the error message
					$message = sprintf( _n( '%1$s subscription could not be updated: %2$s', '%1$s subscriptions could not be updated: %2$s', $error_count, 'woocommerce-subscriptions' ), number_format_i18n( $error_count ), $error_msg );
					echo '<div class="error"><p>' . esc_html( $message ) . '</p></div>';
				}

				break;
			}
		}
	}

	/**
	 * Define custom columns for subscription
	 *
	 * Column names that have a corresponding `WC_Order` column use the `order_` prefix here
	 * to take advantage of core WooCommerce assets, like JS/CSS.
	 *
	 * @param  array $existing_columns
	 * @return array
	 */
	public function shop_subscription_columns( $existing_columns ) {

		$columns = array(
			'cb'                => '<input type="checkbox" />',
			'status'            => _x( 'Status', 'list column title', 'woocommerce-subscriptions' ),
			'order_title'       => _x( 'Subscription', 'list column title', 'woocommerce-subscriptions' ),
			'order_items'       => _x( 'Items', 'list column title', 'woocommerce-subscriptions' ),
			'recurring_total'   => _x( 'Total', 'list column title', 'woocommerce-subscriptions' ),
			'start_date'        => _x( 'Start Date', 'list column title', 'woocommerce-subscriptions' ),
			'trial_end_date'    => _x( 'Trial End', 'list column title', 'woocommerce-subscriptions' ),
			'next_payment_date' => _x( 'Next Payment', 'list column title', 'woocommerce-subscriptions' ),
			'last_payment_date' => _x( 'Last Payment', 'list column title', 'woocommerce-subscriptions' ),
			'end_date'          => _x( 'End Date', 'list column title', 'woocommerce-subscriptions' ),
			'orders'            => _x( 'Orders', 'list column title', 'woocommerce-subscriptions' ),
		);

		return $columns;
	}

	/**
	 * Output custom columns for subscriptions
	 * @param  string $column
	 */
	public function render_shop_subscription_columns( $column ) {
		global $post, $the_subscription;

		if ( empty( $the_subscription ) || $the_subscription->id != $post->ID ) {
			$the_subscription = wcs_get_subscription( $post->ID );
		}

		$column_content = '';

		switch ( $column ) {
			case 'status' :
				// The status label
				$column_content = sprintf( '<mark class="%s tips" data-tip="%s">%s</mark>', sanitize_title( $the_subscription->get_status() ), wcs_get_subscription_status_name( $the_subscription->get_status() ), wcs_get_subscription_status_name( $the_subscription->get_status() ) );

				// Inline actions
				$wp_list_table    = _get_list_table( 'WP_Posts_List_Table' );
				$post_type_object = get_post_type_object( $post->post_type );

				$actions = array();

				$action_url = add_query_arg(
					array(
						'post'     => $the_subscription->id,
						'_wpnonce' => wp_create_nonce( 'bulk-posts' ),
					)
				);

				if ( isset( $_REQUEST['status'] ) ) {
					$action_url = add_query_arg( array( 'status' => $_REQUEST['status'] ), $action_url );
				}

				$all_statuses = array(
					'active'    => __( 'Reactivate', 'woocommerce-subscriptions' ),
					'on-hold'   => __( 'Suspend', 'woocommerce-subscriptions' ),
					'cancelled' => __( 'Cancel', 'woocommerce-subscriptions' ),
					'trash'     => __( 'Trash', 'woocommerce-subscriptions' ),
					'deleted'   => __( 'Delete Permanently', 'woocommerce-subscriptions' ),
				);

				foreach ( $all_statuses as $status => $label ) {

					if ( $the_subscription->can_be_updated_to( $status ) ) {

						if ( in_array( $status, array( 'trash', 'deleted' ) ) ) {

							if ( current_user_can( $post_type_object->cap->delete_post, $post->ID ) ) {

								if ( 'trash' == $post->post_status ) {
									$actions['untrash'] = '<a title="' . esc_attr( __( 'Restore this item from the Trash', 'woocommerce-subscriptions' ) ) . '" href="' . wp_nonce_url( admin_url( sprintf( $post_type_object->_edit_link . '&amp;action=untrash', $post->ID ) ), 'untrash-post_' . $post->ID ) . '">' . __( 'Restore', 'woocommerce-subscriptions' ) . '</a>';
								} elseif ( EMPTY_TRASH_DAYS ) {
									$actions['trash'] = '<a class="submitdelete" title="' . esc_attr( __( 'Move this item to the Trash', 'woocommerce-subscriptions' ) ) . '" href="' . get_delete_post_link( $post->ID ) . '">' . __( 'Trash', 'woocommerce-subscriptions' ) . '</a>';
								}

								if ( 'trash' == $post->post_status || ! EMPTY_TRASH_DAYS ) {
									$actions['delete'] = '<a class="submitdelete" title="' . esc_attr( __( 'Delete this item permanently', 'woocommerce-subscriptions' ) ) . '" href="' . get_delete_post_link( $post->ID, '', true ) . '">' . __( 'Delete Permanently', 'woocommerce-subscriptions' ) . '</a>';
								}
							}
						} else {

							if ( 'pending-cancel' === $the_subscription->get_status() ) {
								$label = __( 'Cancel Now', 'woocommerce-subscriptions' );
							}

							$actions[ $status ] = sprintf( '<a href="%s">%s</a>', add_query_arg( 'action', $status, $action_url ), $label );

						}
					}
				}

				if ( 'pending' === $the_subscription->get_status() ) {
					unset( $actions['active'] );
					unset( $actions['trash'] );
				} elseif ( ! in_array( $the_subscription->get_status(), array( 'cancelled', 'pending-cancel', 'expired', 'switched', 'suspended' ) ) ) {
					unset( $actions['trash'] );
				}

				$actions = apply_filters( 'woocommerce_subscription_list_table_actions', $actions, $the_subscription );

				$column_content .= $wp_list_table->row_actions( $actions );

				$column_content = apply_filters( 'woocommerce_subscription_list_table_column_status_content', $column_content, $the_subscription, $actions );
				break;

			case 'order_title' :

				$customer_tip = '';

				if ( $address = $the_subscription->get_formatted_billing_address() ) {
					$customer_tip .= _x( 'Billing:', 'meaning billing address', 'woocommerce-subscriptions' ) . ' ' . esc_html( $address );
				}

				if ( $the_subscription->billing_email ) {
					$customer_tip .= '<br/><br/>' . __( 'Email:', 'woocommerce-subscriptions' ) . ' ' . esc_attr( $the_subscription->billing_email );
				}

				if ( $the_subscription->billing_phone ) {
					$customer_tip .= '<br/><br/>' . __( 'Tel:', 'woocommerce-subscriptions' ) . ' ' . esc_html( $the_subscription->billing_phone );
				}

				if ( ! empty( $customer_tip ) ) {
					echo '<div class="tips" data-tip="' . esc_attr( $customer_tip ) . '">';
				}

				// This is to stop PHP from complaining
				$username = '';

				if ( $the_subscription->get_user_id() && ( false !== ( $user_info = get_userdata( $the_subscription->get_user_id() ) ) ) ) {

					$username  = '<a href="user-edit.php?user_id=' . absint( $user_info->ID ) . '">';

					if ( $the_subscription->billing_first_name || $the_subscription->billing_last_name ) {
						$username .= esc_html( ucfirst( $the_subscription->billing_first_name ) . ' ' . ucfirst( $the_subscription->billing_last_name ) );
					} elseif ( $user_info->first_name || $user_info->last_name ) {
						$username .= esc_html( ucfirst( $user_info->first_name ) . ' ' . ucfirst( $user_info->last_name ) );
					} else {
						$username .= esc_html( ucfirst( $user_info->display_name ) );
					}

					$username .= '</a>';

				} elseif ( $the_subscription->billing_first_name || $the_subscription->billing_last_name ) {
					$username = trim( $the_subscription->billing_first_name . ' ' . $the_subscription->billing_last_name );
				}

				$column_content = sprintf( _x( '%s for %s', 'Subscription number for X', 'woocommerce-subscriptions' ), '<a href="' . esc_url( admin_url( 'post.php?post=' . absint( $post->ID ) . '&action=edit' ) ) . '"><strong>' . esc_attr( $the_subscription->get_order_number() ) . '</strong></a>', $username );

				$column_content .= '</div>';

				break;
			case 'order_items' :
				// Display either the item name or item count with a collapsed list of items
				$subscription_items = $the_subscription->get_items();
				switch ( count( $subscription_items ) ) {
					case 0 :
						$column_content .= '&ndash;';
						break;
					case 1 :
						foreach ( $the_subscription->get_items() as $item ) {
							$_product       = apply_filters( 'woocommerce_order_item_product', $the_subscription->get_product_from_item( $item ), $item );
							$item_meta      = wcs_get_order_item_meta( $item, $_product );
							$item_meta_html = $item_meta->display( true, true );
							$item_quantity  = absint( $item['qty'] );

							$item_name = '';
							if ( wc_product_sku_enabled() && $_product && $_product->get_sku() ) {
								$item_name .= $_product->get_sku() . ' - ';
							}
							$item_name .= $item['name'];

							$item_name = apply_filters( 'woocommerce_order_item_name', $item_name, $item );
							$item_name = esc_html( $item_name );
							if ( $item_quantity > 1 ) {
								$item_name = sprintf( '%s &times; %s', absint( $item_quantity ), $item_name );
							}
							if ( $_product ) {
								$item_name = sprintf( '<a href="%s">%s</a>', get_edit_post_link( $_product->id ), $item_name );
							}
							ob_start();
							?>
							<div class="order-item">
								<?php echo wp_kses( $item_name, array( 'a' => array( 'href' => array() ) ) ); ?>
								<?php if ( $item_meta_html ) : ?>
								<a class="tips" href="#" data-tip="<?php echo esc_attr( $item_meta_html ); ?>">[?]</a>
								<?php endif; ?>
							</div>
							<?php
							$column_content .= ob_get_clean();
						}
						break;
					default :
						$column_content .= '<a href="#" class="show_order_items">' . esc_html( apply_filters( 'woocommerce_admin_order_item_count', sprintf( _n( '%d item', '%d items', $the_subscription->get_item_count(), 'woocommerce-subscriptions' ), $the_subscription->get_item_count() ), $the_subscription ) ) . '</a>';
						$column_content .= '<table class="order_items" cellspacing="0">';

						foreach ( $the_subscription->get_items() as $item ) {
							$_product       = apply_filters( 'woocommerce_order_item_product', $the_subscription->get_product_from_item( $item ), $item );
							$item_meta      = wcs_get_order_item_meta( $item, $_product );
							$item_meta_html = $item_meta->display( true, true );
							ob_start();
							?>
							<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_admin_order_item_class', '', $item ) ); ?>">
								<td class="qty"><?php echo absint( $item['qty'] ); ?></td>
								<td class="name">
									<?php
									if ( wc_product_sku_enabled() && $_product && $_product->get_sku() ) {
										echo esc_html( $_product->get_sku() ) . ' - ';
									}
									echo esc_html( apply_filters( 'woocommerce_order_item_name', $item['name'], $item ) );
									if ( $item_meta_html ) { ?>
										<a class="tips" href="#" data-tip="<?php echo esc_attr( $item_meta_html ); ?>">[?]</a>
									<?php } ?>
								</td>
							</tr>
							<?php
							$column_content .= ob_get_clean();
						}

						$column_content .= '</table>';
						break;
				}
				break;

			case 'recurring_total' :
				$column_content .= esc_html( strip_tags( $the_subscription->get_formatted_order_total() ) );

				// translators: placeholder is payment method used
				$column_content .= '<small class="meta">' . esc_html( sprintf( _x( 'Via %s', 'used in admin list table on recurring total', 'woocommerce-subscriptions' ), $the_subscription->get_payment_method_to_display() ) ) . '</small>';
				break;

			case 'start_date':
			case 'trial_end_date':
			case 'next_payment_date':
			case 'last_payment_date':
			case 'end_date':
				if ( 0 == $the_subscription->get_time( $column, 'gmt' ) ) {
					$column_content .= '-';
				} else {
					$column_content .= sprintf( '<time class="%s" title="%s">%s</time>', esc_attr( $column ), esc_attr( $the_subscription->get_time( $column, 'site' ) ), esc_html( $the_subscription->get_date_to_display( $column ) ) );
				}

				$column_content = $column_content;
				break;
			case 'orders' :
				$column_content .= $this->get_related_orders_link( $the_subscription );
				break;
		}

		echo wp_kses( apply_filters( 'woocommerce_subscription_list_table_column_content', $column_content, $the_subscription, $column ), array( 'a' => array( 'class' => array(), 'href' => array(), 'data-tip' => array(), 'title' => array() ), 'time' => array( 'class' => array(), 'title' => array() ), 'mark' => array( 'class' => array(), 'data-tip' => array() ), 'small' => array( 'class' => array() ), 'table' => array( 'class' => array(), 'cellspacing' => array(), 'cellpadding' => array() ), 'tr' => array( 'class' => array() ), 'td' => array( 'class' => array() ), 'div' => array( 'class' => array(), 'data-tip' => array() ), 'br' => array(), 'strong' => array(), 'span' => array( 'class' => array() ), 'p' => array( 'class' => array() ) ) );
	}

	/**
	 * Make columns sortable
	 *
	 * @param array $columns
	 * @return array
	 */
	public function shop_subscription_sortable_columns( $columns ) {

		$sortable_columns = array(
			'status'            => 'post_status',
			'order_title'       => 'ID',
			'recurring_total'   => 'order_total',
			'start_date'        => 'date',
			'trial_end_date'    => 'trial_end_date',
			'next_payment_date' => 'next_payment_date',
			'last_payment_date' => 'last_payment_date',
			'end_date'          => 'end_date',
		);

		return wp_parse_args( $sortable_columns, $columns );
	}

	/**
	 * Search custom fields as well as content.
	 *
	 * @access public
	 * @param WP_Query $wp
	 * @return void
	 */
	public function shop_subscription_search_custom_fields( $wp ) {
		global $pagenow, $wpdb;

		if ( 'edit.php' !== $pagenow || empty( $wp->query_vars['s'] ) || 'shop_subscription' !== $wp->query_vars['post_type'] ) {
			return;
		}

		$search_fields = array_map( 'wc_clean', apply_filters( 'woocommerce_shop_subscription_search_fields', array(
			'_order_key',
			'_billing_company',
			'_billing_address_1',
			'_billing_address_2',
			'_billing_city',
			'_billing_postcode',
			'_billing_country',
			'_billing_state',
			'_billing_email',
			'_billing_phone',
			'_shipping_address_1',
			'_shipping_address_2',
			'_shipping_city',
			'_shipping_postcode',
			'_shipping_country',
			'_shipping_state',
		) ) );

		$search_order_id = str_replace( 'Order #', '', $_GET['s'] );
		if ( ! is_numeric( $search_order_id ) ) {
			$search_order_id = 0;
		}

		// Search orders
		$post_ids = array_unique( array_merge(
			$wpdb->get_col(
				$wpdb->prepare( "
					SELECT p1.post_id
					FROM {$wpdb->postmeta} p1
					INNER JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id
					WHERE
						( p1.meta_key = '_billing_first_name' AND p2.meta_key = '_billing_last_name' AND CONCAT(p1.meta_value, ' ', p2.meta_value) LIKE '%%%s%%' )
					OR
						( p1.meta_key = '_shipping_first_name' AND p2.meta_key = '_shipping_last_name' AND CONCAT(p1.meta_value, ' ', p2.meta_value) LIKE '%%%s%%' )
					OR
						( p1.meta_key IN ('" . implode( "','", esc_sql( $search_fields ) ) . "') AND p1.meta_value LIKE '%%%s%%' )
					",
					esc_attr( $_GET['s'] ), esc_attr( $_GET['s'] ), esc_attr( $_GET['s'] )
				)
			),
			$wpdb->get_col(
				$wpdb->prepare( "
					SELECT order_id
					FROM {$wpdb->prefix}woocommerce_order_items as order_items
					WHERE order_item_name LIKE '%%%s%%'
					",
					esc_attr( $_GET['s'] )
				)
			),
			array( $search_order_id )
		) );

		// Remove s - we don't want to search order name
		unset( $wp->query_vars['s'] );

		// so we know we're doing this
		$wp->query_vars['shop_subscription_search'] = true;

		// Search by found posts
		$wp->query_vars['post__in'] = $post_ids;
	}

	/**
	 * Change the label when searching orders.
	 *
	 * @access public
	 * @param mixed $query
	 * @return string
	 */
	public function shop_subscription_search_label( $query ) {
		global $pagenow, $typenow;

		if ( 'edit.php' !== $pagenow ) {
			return $query;
		}

		if ( 'shop_subscription' !== $typenow ) {
			return $query;
		}

		if ( ! get_query_var( 'shop_subscription_search' ) ) {
			return $query;
		}

		return wp_unslash( $_GET['s'] );
	}

	/**
	 * Query vars for custom searches.
	 *
	 * @access public
	 * @param mixed $public_query_vars
	 * @return array
	 */
	public function add_custom_query_var( $public_query_vars ) {
		$public_query_vars[] = 'sku';
		$public_query_vars[] = 'shop_subscription_search';

		return $public_query_vars;
	}

	/**
	 * Filters and sorting handler
	 *
	 * @param  array $vars
	 * @return array
	 */
	public function request_query( $vars ) {
		global $typenow;

		if ( 'shop_subscription' === $typenow ) {

			// Filter the orders by the posted customer.
			if ( isset( $_GET['_customer_user'] ) && $_GET['_customer_user'] > 0 ) {
				$vars['meta_key'] = '_customer_user';
				$vars['meta_value'] = (int) $_GET['_customer_user'];
			}

			if ( isset( $_GET['_wcs_product'] ) && $_GET['_wcs_product'] > 0 ) {

				$subscription_ids = wcs_get_subscriptions_for_product( $_GET['_wcs_product'] );

				if ( ! empty( $subscription_ids ) ) {
					$vars['post__in'] = $subscription_ids;
				} else {
					// no subscriptions contain this product, but we need to pass post__in an ID that no post will have because WP returns all posts when post__in is an empty array: https://core.trac.wordpress.org/ticket/28099
					$vars['post__in'] = array( 0 );
				}
			}

			// Sorting
			if ( isset( $vars['orderby'] ) ) {
				switch ( $vars['orderby'] ) {
					case 'order_total' :
						$vars = array_merge( $vars, array(
							'meta_key' 	=> '_order_total',
							'orderby' 	=> 'meta_value_num',
						) );
					break;
					case 'last_payment_date' :
						add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 10, 2 );
						break;
					case 'trial_end_date' :
					case 'next_payment_date' :
					case 'end_date' :
						$vars = array_merge( $vars, array(
							'meta_key'     => sprintf( '_schedule_%s', str_replace( '_date', '', $vars['orderby'] ) ),
							'meta_type'    => 'DATETIME',
							'orderby'      => 'meta_value',
						) );
					break;
				}
			}

			// Status
			if ( ! isset( $vars['post_status'] ) ) {
				$vars['post_status'] = array_keys( wcs_get_subscription_statuses() );
			}
		}

		return $vars;
	}

	/**
	 * Change messages when a post type is updated.
	 *
	 * @param  array $messages
	 * @return array
	 */
	public function post_updated_messages( $messages ) {
		global $post, $post_ID;

		$messages['shop_subscription'] = array(
			0 => '', // Unused. Messages start at index 1.
			1 => __( 'Subscription updated.', 'woocommerce-subscriptions' ),
			2 => __( 'Custom field updated.', 'woocommerce-subscriptions' ),
			3 => __( 'Custom field deleted.', 'woocommerce-subscriptions' ),
			4 => __( 'Subscription updated.', 'woocommerce-subscriptions' ),
			// translators: placeholder is previous post title
			5 => isset( $_GET['revision'] ) ? sprintf( _x( 'Subscription restored to revision from %s', 'used in post updated messages', 'woocommerce-subscriptions' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
			6 => __( 'Subscription updated.', 'woocommerce-subscriptions' ),
			7 => __( 'Subscription saved.', 'woocommerce-subscriptions' ),
			8 => __( 'Subscription submitted.', 'woocommerce-subscriptions' ),
			// translators: php date string
			9 => sprintf( __( 'Subscription scheduled for: %1$s.', 'woocommerce-subscriptions' ), '<strong>' . date_i18n( _x( 'M j, Y @ G:i', 'used in "Subscription scheduled for <date>"', 'woocommerce-subscriptions' ), strtotime( $post->post_date ) ) . '</strong>' ),
			10 => __( 'Subscription draft updated.', 'woocommerce-subscriptions' ),
		);

		return $messages;
	}

	/**
	 * Returns a clickable link that takes you to a collection of orders relating to the subscription.
	 *
	 * @uses  self::get_related_orders()
	 * @since  2.0
	 * @return string 						the link string
	 */
	public function get_related_orders_link( $the_subscription ) {
		$order_id = isset( $the_subscription->order->id ) ? $the_subscription->order->id : 0;

		return sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'edit.php?post_status=all&post_type=shop_order&_subscription_related_orders=' . absint( $the_subscription->id ) ),
			count( $the_subscription->get_related_orders() )
		);
	}

}

new WCS_Admin_Post_Types();
