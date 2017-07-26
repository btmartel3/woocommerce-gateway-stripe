<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract class that will be inherited by all payment methods.
 *
 * @extends WC_Payment_Gateway_CC
 *
 * @since 4.0.0
 */
abstract class WC_Stripe_Payment_Gateway extends WC_Payment_Gateway_CC {
	/**
	 * Check if this gateway is enabled
	 */
	public function is_available() {
		if ( 'yes' === $this->enabled ) {
			if ( ! $this->testmode && is_checkout() && ! is_ssl() ) {
				return false;
			}
			if ( ! $this->secret_key || ! $this->publishable_key ) {
				return false;
			}
			return true;
		}
		return false;
	}

	/**
	 * Allow this class and other classes to add slug keyed notices (to avoid duplication).
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public function add_admin_notice( $slug, $class, $message ) {
		$this->notices[ $slug ] = array(
			'class'   => $class,
			'message' => $message,
		);
	}

	/**
	 * Gets the transaction URL linked to Stripe dashboard.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public function get_transaction_url( $order ) {
		if ( $this->testmode ) {
			$this->view_transaction_url = 'https://dashboard.stripe.com/test/payments/%s';
		} else {
			$this->view_transaction_url = 'https://dashboard.stripe.com/payments/%s';
		}

		return parent::get_transaction_url( $order );
	}

	/**
	 * Builds the return URL from redirects.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param object $order
	 * @param int $id Stripe session id.
	 */
	public function get_stripe_return_url( $order = null, $id = null ) {
		if ( is_object( $order ) ) {
			if ( empty( $id ) ) {
				$id = uniqid();
			}

			$order_id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();

			return esc_url_raw( add_query_arg( array( 'utm_nooverride' => '1', 'stripe_session_id' => $id, 'order_id' => $order_id ), $this->get_return_url( $order ) ) );
		}

		return esc_url_raw( add_query_arg( array( 'utm_nooverride' => '1' ), $this->get_return_url() ) );
	}

	/**
	 * Generate the request for the payment.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 * @param  WC_Order $order
	 * @param  object $source
	 * @return array()
	 */
	public function generate_payment_request( $order, $source ) {
		$settings                          = get_option( 'woocommerce_stripe_settings', array() );
		$statement_descriptor              = ! empty( $settings['statement_descriptor'] ) ? $settings['statement_descriptor'] : '';
		$capture                           = ! empty( $settings['capture'] ) && 'yes' === $settings['capture'] ? true : false; 
		$post_data                         = array();
		$post_data['currency']             = strtolower( version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->get_order_currency() : $order->get_currency() );
		$post_data['amount']               = WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $post_data['currency'] );
		$post_data['description']          = sprintf( __( '%1$s - Order %2$s', 'woocommerce-gateway-stripe' ), $statement_descriptor, $order->get_order_number() );
		$post_data['capture']              = $capture ? 'true' : 'false';

		$billing_email      = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_email : $order->get_billing_email();
		$billing_first_name = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_first_name : $order->get_billing_first_name();
		$billing_last_name  = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_last_name : $order->get_billing_last_name();

		if ( ! empty( $billing_email ) && apply_filters( 'wc_stripe_send_stripe_receipt', false ) ) {
			$post_data['receipt_email'] = $billing_email;
		}

		switch ( $order->get_payment_method() ) {
			case 'stripe':
				$post_data['statement_descriptor'] = substr( str_replace( "'", '', $statement_descriptor ), 0, 22 );
				break;
		}

		$post_data['expand[]'] = 'balance_transaction';

		$metadata = array(
			__( 'Customer Name', 'woocommerce-gateway-stripe' ) => sanitize_text_field( $billing_first_name ) . ' ' . sanitize_text_field( $billing_last_name ),
			__( 'Customer Email', 'woocommerce-gateway-stripe' ) => sanitize_email( $billing_email ),
		);

		$post_data['metadata'] = apply_filters( 'wc_stripe_payment_metadata', $metadata, $order, $source );

		if ( $source->customer ) {
			$post_data['customer'] = $source->customer;
		}

		if ( $source->source ) {
			$post_data['source'] = $source->source;
		}

		/**
		 * Filter the return value of the WC_Payment_Gateway_CC::generate_payment_request.
		 *
		 * @since 3.1.0
		 * @param array $post_data
		 * @param WC_Order $order
		 * @param object $source
		 */
		return apply_filters( 'wc_stripe_generate_payment_request', $post_data, $order, $source );
	}

	/**
	 * Store extra meta data for an order from a Stripe Response.
	 */
	public function process_response( $response, $order ) {
		WC_Stripe_Logger::log( 'Processing response: ' . print_r( $response, true ) );

		$order_id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();

		// Store charge data
		update_post_meta( $order_id, '_stripe_charge_id', $response->id );
		update_post_meta( $order_id, '_stripe_charge_captured', $response->captured ? 'yes' : 'no' );

		// Store other data such as fees
		if ( isset( $response->balance_transaction ) && isset( $response->balance_transaction->fee ) ) {
			// Fees and Net needs to both come from Stripe to be accurate as the returned
			// values are in the local currency of the Stripe account, not from WC.
			$fee = ! empty( $response->balance_transaction->fee ) ? WC_Stripe_Helper::format_number( $response->balance_transaction, 'fee' ) : 0;
			$net = ! empty( $response->balance_transaction->net ) ? WC_Stripe_Helper::format_number( $response->balance_transaction, 'net' ) : 0;
			update_post_meta( $order_id, 'Stripe Fee', $fee );
			update_post_meta( $order_id, 'Net Revenue From Stripe', $net );
		}

		if ( $response->captured ) {
			$order->payment_complete( $response->id );

			$message = sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'woocommerce-gateway-stripe' ), $response->id );
			$order->add_order_note( $message );
			WC_Stripe_Logger::log( 'Success: ' . $message );

		} else {
			update_post_meta( $order_id, '_transaction_id', $response->id, true );

			if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
				version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->reduce_order_stock() : wc_reduce_stock_levels( $order_id );
			}

			$order->update_status( 'on-hold', sprintf( __( 'Stripe charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-gateway-stripe' ), $response->id ) );
			WC_Stripe_Logger::log( "Successful auth: $response->id" );
		}

		do_action( 'wc_gateway_stripe_process_response', $response, $order );

		return $response;
	}

	/**
	 * Sends the failed order email to admin.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 * @param int $order_id
	 * @return null
	 */
	public function send_failed_order_email( $order_id ) {
		$emails = WC()->mailer()->get_emails();
		if ( ! empty( $emails ) && ! empty( $order_id ) ) {
			$emails['WC_Email_Failed_Order']->trigger( $order_id );
		}
	}

	/**
	 * Get owner details.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param object $order
	 * @return object $details
	 */
	public function get_owner_details( $order ) {
		$billing_first_name = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_first_name : $order->get_billing_first_name();
		$billing_last_name  = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_last_name : $order->get_billing_last_name();

		$details = array();

		$details['name']                   = $billing_first_name . ' ' . $billing_last_name;
		$details['email']                  = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_email : $order->get_billing_email();
		$details['phone']                  = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_phone : $order->get_billing_phone();
		$details['address']['line1']       = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_address_1 : $order->get_billing_address_1();
		$details['address']['line2']       = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_address_2 : $order->get_billing_address_2();
		$details['address']['state']       = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_state : $order->get_billing_state();
		$details['address']['city']        = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_city : $order->get_billing_city();
		$details['address']['postal_code'] = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_postcode : $order->get_billing_postcode();
		$details['address']['country']     = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_country : $order->get_billing_country();

		return (object) $details;
	}

	/**
	 * Get payment source. This can be a new token or existing card.
	 *
	 * @param string $user_id
	 * @param bool  $force_customer Should we force customer creation.
	 *
	 * @throws Exception When card was not added or for and invalid card.
	 * @return object
	 */
	public function get_source( $user_id, $force_customer = false ) {
		$stripe_customer = new WC_Stripe_Customer( $user_id );
		$force_customer  = apply_filters( 'wc_stripe_force_customer_creation', $force_customer, $stripe_customer );
		$stripe_source   = false;
		$token_id        = false;

		// New CC info was entered and we have a new token to process
		if ( isset( $_POST['stripe_source'] ) ) {
			$stripe_source     = wc_clean( $_POST['stripe_source'] );
			$maybe_saved_card = isset( $_POST['wc-stripe-new-payment-method'] ) && ! empty( $_POST['wc-stripe-new-payment-method'] );

			// This is true if the user wants to store the card to their account.
			if ( ( $user_id && $this->saved_cards && $maybe_saved_card ) || $force_customer ) {
				$stripe_source = $stripe_customer->add_source( $stripe_source );

				if ( is_wp_error( $stripe_source ) ) {
					throw new Exception( $stripe_source->get_error_message() );
				}
			} else {
				// Not saving token, so don't define customer either.
				$stripe_source   = $stripe_source;
				$stripe_customer = false;
			}
		} elseif ( isset( $_POST['wc-stripe-payment-token'] ) && 'new' !== $_POST['wc-stripe-payment-token'] ) {
			// Use an existing token, and then process the payment

			$token_id = wc_clean( $_POST['wc-stripe-payment-token'] );
			$token    = WC_Payment_Tokens::get( $token_id );

			if ( ! $token || $token->get_user_id() !== get_current_user_id() ) {
				WC()->session->set( 'refresh_totals', true );
				throw new Exception( __( 'Invalid payment method. Please input a new card number.', 'woocommerce-gateway-stripe' ) );
			}

			$stripe_source = $token->get_token();
		}

		return (object) array(
			'token_id' => $token_id,
			'customer' => $stripe_customer ? $stripe_customer->get_id() : false,
			'source'   => $stripe_source,
		);
	}

	/**
	 * Save source to order.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 * @param WC_Order $order For to which the source applies.
	 * @param stdClass $source Source information.
	 */
	public function save_source( $order, $source ) {
		$order_id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();

		// Store source in the order.
		if ( $source->customer ) {
			version_compare( WC_VERSION, '3.0.0', '<' ) ? update_post_meta( $order_id, '_stripe_customer_id', $source->customer ) : $order->update_meta_data( '_stripe_customer_id', $source->customer );
		}
		if ( $source->source ) {
			version_compare( WC_VERSION, '3.0.0', '<' ) ? update_post_meta( $order_id, '_stripe_card_id', $source->source ) : $order->update_meta_data( '_stripe_card_id', $source->source );
		}

		if ( is_callable( array( $order, 'save' ) ) ) {
			$order->save();
		}
	}

	/**
	 * Get payment source from an order. This could be used in the future for
	 * a subscription as an example, therefore using the current user ID would
	 * not work - the customer won't be logged in :)
	 *
	 * Not using 2.6 tokens for this part since we need a customer AND a card
	 * token, and not just one.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 * @param object $order
	 * @return object
	 */
	public function get_order_source( $order = null ) {
		$stripe_customer = new WC_Stripe_Customer();
		$stripe_source   = false;
		$token_id        = false;

		if ( $order ) {
			$order_id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();

			if ( $meta_value = get_post_meta( $order_id, '_stripe_customer_id', true ) ) {
				$stripe_customer->set_id( $meta_value );
			}

			if ( $meta_value = get_post_meta( $order_id, '_stripe_card_id', true ) ) {
				$stripe_source = $meta_value;
			}
		}

		return (object) array(
			'token_id' => $token_id,
			'customer' => $stripe_customer ? $stripe_customer->get_id() : false,
			'source'   => $stripe_source,
		);
	}

	/**
	 * Refund a charge.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 * @param  int $order_id
	 * @param  float $amount
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->get_transaction_id() ) {
			return false;
		}

		$body = array();

		if ( ! is_null( $amount ) ) {
			$body['amount']	= WC_Stripe_Helper::get_stripe_amount( $amount );
		}

		if ( $reason ) {
			$body['metadata'] = array(
				'reason'	=> $reason,
			);
		}

		WC_Stripe_Logger::log( "Info: Beginning refund for order $order_id for the amount of {$amount}" );

		$response = WC_Stripe_API::request( $body, 'charges/' . $order->get_transaction_id() . '/refunds' );

		if ( is_wp_error( $response ) ) {
			WC_Stripe_Logger::log( 'Error: ' . $response->get_error_message() );
			return $response;
		} elseif ( ! empty( $response->id ) ) {
			$refund_message = sprintf( __( 'Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'woocommerce-gateway-stripe' ), wc_price( $response->amount / 100 ), $response->id, $reason );
			$order->add_order_note( $refund_message );
			WC_Stripe_Logger::log( 'Success: ' . html_entity_decode( strip_tags( $refund_message ) ) );
			return true;
		}
	}

	/**
	 * Add payment method via account screen.
	 * We don't store the token locally, but to the Stripe API.
	 *
	 * @since 3.0.0
	 * @version 4.0.0
	 */
	public function add_payment_method() {
		if ( empty( $_POST['stripe_token'] ) || ! is_user_logged_in() ) {
			wc_add_notice( __( 'There was a problem adding the card.', 'woocommerce-gateway-stripe' ), 'error' );
			return;
		}

		$stripe_customer = new WC_Stripe_Customer( get_current_user_id() );
		$card            = $stripe_customer->add_source( wc_clean( $_POST['stripe_token'] ) );

		if ( is_wp_error( $card ) ) {
			$localized_messages = WC_Stripe_Helper::get_localized_messages();
			$error_msg = __( 'There was a problem adding the card.', 'woocommerce-gateway-stripe' );

			// loop through the errors to find matching localized message
			foreach ( $card->errors as $error => $msg ) {
				if ( isset( $localized_messages[ $error ] ) ) {
					$error_msg = $localized_messages[ $error ];
				}
			}

			wc_add_notice( $error_msg, 'error' );
			return;
		}

		return array(
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		);
	}
}
