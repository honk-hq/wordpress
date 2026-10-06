<?php
/**
 * Buttons (contracts/API.md §13): off by default, each added only with the detail it needs and
 * only when its data is valid, at most three, links the API accepts, and a preview that shows
 * them like the message.
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey\Functions;

class ActionsTest extends Honk_Test_Case {

	use Honk_Scenarios;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_wordpress();
		Honk_Preview::flush();
		Honk_Module_Forms::flush_forms();
	}

	/**
	 * The buttons of each message of a scenario (null: none).
	 */
	private function buttons( $scenario, $pii, array $details ) {
		return array_map(
			function ( $job ) {
				return isset( $job['payload']['actions'] ) ? $job['payload']['actions'] : null;
			},
			$this->run_scenario( $scenario, $pii, array( 'details' => $details ) )
		);
	}

	private function button( $title, $url ) {
		return array(
			'title' => $title,
			'url'   => $url,
		);
	}

	public function test_orders_email_and_call_the_customer() {
		$on = array( 'action_email' => true, 'action_call' => true );
		$b  = $this->buttons( 'woo_new_order', true, array( 'woo_new_order' => $on + array( 'phone' => true ) ) );
		$this->assertSame( array( $this->button( 'Email customer', 'mailto:ana@example.com?subject=Order%20%231234' ), $this->button( 'Call customer', 'tel:+40721000000' ) ), $b[0] );

		$b = $this->buttons( 'woo_new_order', true, array( 'woo_new_order' => $on ) );
		$this->assertSame( array( $this->button( 'Email customer', 'mailto:ana@example.com?subject=Order%20%231234' ) ), $b[0], 'no call without the phone number' );

		$b = $this->buttons( 'woo_new_order', true, array( 'woo_new_order' => $on + array( 'email' => false, 'phone' => true ) ) );
		$this->assertSame( array( $this->button( 'Call customer', 'tel:+40721000000' ) ), $b[0], 'no email without the email address' );

		$b = $this->buttons( 'woo_new_order_sparse', true, array( 'woo_new_order' => $on + array( 'phone' => true ) ) );
		$this->assertNull( $b[0], 'no email address or phone number, no buttons' );

		$jobs = $this->run_scenario( 'woo_new_order', true, array( 'details' => array( 'woo_new_order' => $on + array( 'name' => false, 'email' => true ) ) ) );
		$this->assertTrue( $jobs[0]['pii'] );

		foreach ( array( 'woo_order_status', 'woo_payment_failed' ) as $event ) {
			$b = $this->buttons( $event, true, array( $event => $on + array( 'phone' => true ) ) );
			$this->assertSame( array( 'Email customer', 'Call customer' ), array_column( $b[0], 'title' ), $event );
		}
		$b = $this->buttons( 'woo_refund', true, array( 'woo_refund' => array( 'email' => true, 'action_email' => true ) ) );
		$this->assertSame( array( $this->button( 'Email customer', 'mailto:ana@example.com?subject=Order%20%231234' ) ), $b[0] );
		$this->assertSame( array( $this->button( 'Email customer', 'mailto:ana@example.com?subject=Order%20%231236' ) ), $b[1] );
	}

	public function test_the_message_link_stays_the_message_link() {
		$jobs = $this->run_scenario( 'woo_new_order', true, array( 'details' => array( 'woo_new_order' => array( 'action_email' => true ) ) ) );
		$this->assertSame( 'https://shop.example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=77', $jobs[0]['payload']['url'] );
	}

	public function test_customers_users_and_subscriptions() {
		$b = $this->buttons( 'woo_new_customer', true, array( 'woo_new_customer' => array( 'action_email' => true ) ) );
		$this->assertSame( array( $this->button( 'Email customer', 'mailto:ana@example.com' ) ), $b[0] );

		$b = $this->buttons( 'user_registered', true, array( 'user_registered' => array( 'action_email' => true ) ) );
		$this->assertSame( array( $this->button( 'Email user', 'mailto:mara@example.com' ) ), $b[0] );
		$this->assertSame( array( $this->button( 'Email user', 'mailto:sam@example.com' ) ), $b[1] );

		$b = $this->buttons( 'woo_subscription_failed', true, array( 'woo_subscription_failed' => array( 'action_email' => true ) ) );
		$this->assertSame( array( $this->button( 'Email customer', 'mailto:ana@example.com?subject=Subscription%20%23300' ) ), $b[0] );
	}

	public function test_comments_and_reviews_can_be_approved_and_answered() {
		$on = array( 'action_approve' => true, 'action_reply' => true );
		$b  = $this->buttons( 'comment_pending', true, array( 'comment_pending' => $on ) );
		$this->assertSame(
			array(
				$this->button( 'Approve', 'https://shop.example.com/wp-admin/comment.php?action=approve&c=601' ),
				$this->button( 'Reply by email', 'mailto:dan@example.com?subject=Re%3A%20Hello%20world' ),
			),
			$b[0]
		);
		$this->assertSame( array( $this->button( 'Approve', 'https://shop.example.com/wp-admin/comment.php?action=approve&c=602' ) ), $b[1], 'an anonymous comment can only be approved' );

		// Approving needs no personal data, so it works with the privacy switch off.
		$jobs = $this->run_scenario( 'comment_pending', false, array( 'details' => array( 'comment_pending' => $on ) ) );
		$this->assertSame( array( $this->button( 'Approve', 'https://shop.example.com/wp-admin/comment.php?action=approve&c=601' ) ), $jobs[0]['payload']['actions'] );
		$this->assertFalse( $jobs[0]['pii'] );

		$b = $this->buttons( 'woo_new_review', true, array( 'woo_new_review' => $on ) );
		$this->assertSame(
			array(
				$this->button( 'Approve', 'https://shop.example.com/wp-admin/comment.php?action=approve&c=501' ),
				$this->button( 'Reply by email', 'mailto:ana@example.com?subject=Re%3A%20Linen%20Apron' ),
			),
			$b[0]
		);
		$this->assertNull( $b[1], 'an approved review without an email address has no buttons' );
	}

	public function test_approving_needs_an_https_dashboard() {
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'http://shop.example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		$this->assertSame( '', Honk_Notifier::approve_link( 601 ) );
		$b = $this->buttons( 'comment_pending', false, array( 'comment_pending' => array( 'action_approve' => true ) ) );
		$this->assertNull( $b[0] );
	}

	public function test_forms_reply_to_and_call_what_was_entered() {
		$on = array(
			'values'       => true,
			'action_reply' => true,
			'action_call'  => true,
		);
		$b  = $this->buttons( 'forms', true, array( 'form_wpforms' => $on, 'form_cf7' => $on ) );
		$this->assertSame( array( $this->button( 'Reply by email', 'mailto:ana@example.com?subject=Re%3A%20Contact' ) ), $b[0] );
		$this->assertNull( $b[1], 'nothing entered, no buttons' );

		$b = $this->buttons( 'forms', true, array( 'form_wpforms' => $on + array( 'skip' => array( '3' => array( '2' ) ) ) ) );
		$this->assertNull( $b[0], 'the email field is left out, so is the reply' );
		$b = $this->buttons( 'forms', true, array( 'form_wpforms' => array( 'values' => false ) + $on ) );
		$this->assertNull( $b[0], 'the answers are left out, so are the buttons' );
		$b = $this->buttons( 'forms', false, array( 'form_wpforms' => $on ) );
		$this->assertNull( $b[0], 'personal data is off' );

		$this->options = array();
		$this->configure( array( 'include_pii' => true, 'details' => array( 'form_wpforms' => $on ) ) );
		Honk_Module_Forms::report(
			'form_wpforms',
			'wpforms',
			'5',
			'Quote request',
			array(
				array( 'key' => 'name', 'label' => 'Name', 'value' => 'Ana Pop', 'type' => 'text' ),
				array( 'key' => 'email', 'label' => 'Email', 'value' => 'not an address', 'type' => 'email' ),
				array( 'key' => 'work_email', 'label' => 'Work email', 'value' => 'ana@firm.example', 'type' => 'text' ),
				array( 'key' => 'tel', 'label' => 'Telefon', 'value' => '0721 000 000', 'type' => 'tel' ),
			),
			'entry-77'
		);
		$payloads = $this->queued_payloads();
		$this->assertCount( 1, $payloads );
		$this->assertSame(
			array(
				$this->button( 'Reply by email', 'mailto:ana@firm.example?subject=Re%3A%20Quote%20request' ),
				$this->button( 'Call back', 'tel:0721000000' ),
			),
			$payloads[0]['actions']
		);
	}

	public function test_contact_fields_are_found_by_type_name_or_label() {
		$contact = Honk_Module_Forms::contact(
			array(
				array( 'key' => 'address', 'label' => 'Mailing address', 'value' => '1 Main St', 'type' => 'text' ),
				array( 'key' => 'pw', 'label' => 'Email password', 'value' => 'a@b.example', 'type' => 'password' ),
				array( 'key' => 'your-email', 'label' => 'Email', 'value' => array( 'x' ), 'type' => '' ),
				array( 'key' => 'your-phone', 'label' => 'Phone', 'value' => 'ext. 12', 'type' => 'text' ),
				array( 'key' => 'your-mail', 'label' => 'Email', 'value' => ' jane@example.com ', 'type' => 'text' ),
				array( 'key' => 'mobile', 'label' => 'Mobile phone', 'value' => '+1 (503) 555-0142', 'type' => 'text' ),
				array( 'key' => 'other', 'label' => 'Other email', 'value' => 'other@example.com', 'type' => 'email' ),
			)
		);
		$this->assertSame( 'your-mail', $contact['email']['key'] );
		$this->assertSame( 'mobile', $contact['phone']['key'] );
		$this->assertSame( array( 'email' => null, 'phone' => null ), Honk_Module_Forms::contact( array() ) );
	}

	public function test_links_the_api_accepts() {
		foreach ( array(
			'https://shop.example.com/wp-admin/post.php?post=1&action=edit',
			'HTTPS://Shop.example.com',
			'mailto:jane@example.com',
			'mailto:jane+shop@example.co.uk?subject=Order%20%231234&body=Hi',
			'mailto:jane%40example.com?body=a+b&subject=',
			'mailto:jürgen@exämple.de',
			'mailto:o.brien@[192.0.2.1]',
			'MAILTO:jane@example.com',
			'tel:+15035550142',
			'tel:+1-503-(555).0142',
			'tel://+15035550142',
			'sms:+15035550142',
			'sms:+15035550142?body=On%20my%20way',
		) as $url ) {
			$this->assertSame( $url, Honk_Payload::action_url( $url ), $url );
		}
		foreach ( array(
			'',
			'http://shop.example.com',
			'https://user:pw@shop.example.com',
			'https://shop.example.com/a b',
			"https://shop.example.com/\u{0085}",
			'javascript:alert(1)',
			'data:text/html,hi',
			'file:///etc/passwd',
			'whatsapp://send?phone=1',
			'mailto:',
			'mailto:jane',
			'mailto:jane@localhost',
			'mailto:@example.com',
			'mailto:.jane@example.com',
			'mailto:jane..doe@example.com',
			'https:shop.example.com',
			'https:///shop.example.com',
			'https://shop.example.com/%zz',
			'https://shop.example.com:0',
			'mailto:jane@example.com,ana@example.com',
			'mailto:Jane%20<jane@example.com>',
			'mailto:%22jane%22@example.com',
			'mailto:jane@example.com?cc=ana@example.com',
			'mailto:jane@example.com?bcc=ana@example.com',
			'mailto:jane@example.com?attach=/etc/passwd',
			'mailto:jane@example.com?to=a@example.com&subject=Hi',
			'mailto:jane@example.com?subject=100%',
			'mailto:jane@example.com?subject=a;b',
			"mailto:jane@example.com?subject=a\u{2003}b",
			'tel:',
			'tel:call me',
			'tel:abc',
			'tel:1+555',
			'tel:+1555;ext=12',
			'tel:%2B1555',
			'sms:+1555?subject=x',
			'sms:+1555?Body=x',
			'sms://+1555',
			'https://' . str_repeat( 'a', 2048 ) . '.example',
		) as $url ) {
			$this->assertSame( '', Honk_Payload::action_url( $url ), $url );
		}
		$this->assertSame( '', Honk_Payload::action_url( array( 'https://x.example' ) ) );
		$this->assertSame( 'tel:+1555', Honk_Payload::action_url( " tel:+1555\n" ), 'trimmed, as the server does' );
	}

	public function test_email_addresses_and_phone_numbers_become_links_only_when_valid() {
		$this->assertSame( 'mailto:jane@example.com', Honk_Payload::mailto_url( ' jane@example.com ' ) );
		$this->assertSame( 'mailto:jane@example.com?subject=R%C3%A9%3A%20%E2%80%9CDevis%E2%80%9D%20%26%20more', Honk_Payload::mailto_url( 'jane@example.com', 'Ré: “Devis” &amp; more' ) );
		foreach ( array( '', 'jane', 'jane@', 'jane@localhost', 'jane doe@example.com', 'jane?x@example.com', 'jane%40@example.com', 'jäne@example.com', 'jane@exämple.com', 'a@b@example.com' ) as $email ) {
			$this->assertSame( '', Honk_Payload::mailto_url( $email ), $email );
		}
		$this->assertSame( '', Honk_Payload::mailto_url( array( 'jane@example.com' ) ) );
		$subject = Honk_Payload::mailto_url( 'jane@example.com', str_repeat( 'x', 500 ) );
		$this->assertLessThan( 200, strlen( $subject ), 'long subjects are cut' );

		$this->assertSame( 'tel:+40721000000', Honk_Payload::tel_url( '+40 721 000 000' ) );
		$this->assertSame( 'tel:5035550142', Honk_Payload::tel_url( '(503) 555-0142' ) );
		$this->assertSame( 'tel:0721000000', Honk_Payload::tel_url( "0721\u{00a0}000\u{00a0}000" ) );
		$this->assertSame( 'tel:112', Honk_Payload::tel_url( '112' ) );
		$this->assertSame( 'tel:+442079460000', Honk_Payload::tel_url( '+44 (0)20 7946 0000' ), 'the national 0 after the country code' );
		$this->assertSame( 'tel:0207946000', Honk_Payload::tel_url( '(020) 7946–000' ) );
		$this->assertSame( 'tel:0721000000', Honk_Payload::tel_url( '0721/000 000' ) );
		foreach ( array( '', '+', '12', '555-0142 ext. 12', 'call me', '+40 721 000 000 (evenings)', '0721+000', str_repeat( '1', 21 ) ) as $phone ) {
			$this->assertSame( '', Honk_Payload::tel_url( $phone ), $phone );
		}
	}

	public function test_buttons_are_normalized_to_the_contract() {
		$out = Honk_Payload::normalize(
			array(
				'message' => 'm',
				'url'     => 'https://shop.example.com/order/1',
				'actions' => array(
					array( 'title' => 'Open', 'url' => 'https://shop.example.com/order/1' ),
					array( 'title' => '<b>Reply</b> to ' . str_repeat( 'é', 60 ), 'url' => 'mailto:jane@example.com' ),
					'nope',
					array( 'title' => 'Run', 'url' => 'javascript:alert(1)' ),
					array( 'title' => "Bell\u{0007}", 'url' => 'tel:+1555' ),
					array( 'title' => '   ', 'url' => 'tel:+1555' ),
					array( 'url' => 'tel:+1555' ),
					array( 'title' => 'Call', 'url' => 'tel:+1555' ),
					array( 'title' => 'Text', 'url' => 'sms:+1555' ),
					array( 'title' => 'Fourth', 'url' => 'tel:+1666' ),
				),
			)
		);
		$this->assertSame( array( 'Reply to ' . str_repeat( 'é', 30 ) . '…', 'Call', 'Text' ), array_column( $out['actions'], 'title' ) );
		$this->assertSame( 40, mb_strlen( $out['actions'][0]['title'], 'UTF-8' ) );
		$this->assertSame( array( 'mailto:jane@example.com', 'tel:+1555', 'sms:+1555' ), array_column( $out['actions'], 'url' ) );

		$this->assertArrayNotHasKey( 'actions', Honk_Payload::normalize( array( 'message' => 'm', 'actions' => array() ) ) );
		$this->assertArrayNotHasKey( 'actions', Honk_Payload::normalize( array( 'message' => 'm', 'actions' => 'tel:+1555' ) ) );
		$this->assertArrayNotHasKey( 'actions', Honk_Payload::normalize( array( 'message' => 'm' ) ) );
	}

	public function test_buttons_never_push_the_body_over_16_kib() {
		$actions = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$actions[] = array( 'title' => 'Open ' . $i, 'url' => 'https://shop.example.com/' . str_repeat( 'a', 2000 ) );
		}
		$out = Honk_Payload::normalize(
			array(
				'title'    => str_repeat( 'T', 300 ),
				'message'  => str_repeat( 'x', 9000 ),
				'metadata' => array( 'k' => str_repeat( 'v', 500 ) ),
				'actions'  => $actions,
			)
		);
		$this->assertLessThanOrEqual( 16384, strlen( Honk_Payload::encode( $out ) ) );
		$this->assertCount( 3, $out['actions'], 'the text is shortened first' );
	}

	public function test_every_button_is_labelled_and_needs_a_detail_of_its_event() {
		foreach ( Honk_Details::ACTIONS as $event => $actions ) {
			$this->assertArrayHasKey( $event, Honk_Details::FACTS, $event );
			$this->assertLessThanOrEqual( Honk_Payload::MAX_ACTIONS, count( $actions ), $event );
			foreach ( $actions as $action => $need ) {
				$label = Honk_Details::label( $event, $action );
				$this->assertNotSame( $action, $label, $event . ' ' . $action );
				$this->assertLessThanOrEqual( Honk_Payload::MAX_ACTION_TITLE_CHARS, mb_strlen( $label, 'UTF-8' ) );
				$this->assertFalse( Honk_Details::chosen( $event, $action ), $event . ' ' . $action . ' is off by default' );
				if ( '' !== $need ) {
					$this->assertArrayHasKey( $need, Honk_Details::facts( $event ), $event . ' ' . $action );
					$this->assertTrue( Honk_Details::is_personal( $event, $need ), $event . ' ' . $action . ' carries personal data' );
				}
			}
		}
	}

	public function test_a_button_is_on_only_with_the_detail_it_needs() {
		$this->configure( array( 'include_pii' => true, 'details' => array( 'woo_new_order' => array( 'action_email' => true, 'action_call' => true ) ) ) );
		$this->assertTrue( Honk_Details::on( 'woo_new_order', 'action_email' ) );
		$this->assertFalse( Honk_Details::on( 'woo_new_order', 'action_call' ), 'the phone number is off by default' );
		$this->assertSame( array( 'action_email' => true, 'action_call' => false ), array_slice( Honk_Details::states( 'woo_new_order' ), -2, 2, true ) );
		$this->configure( array( 'include_pii' => false, 'details' => array( 'woo_new_order' => array( 'action_email' => true ) ) ) );
		$this->assertTrue( Honk_Details::chosen( 'woo_new_order', 'action_email' ) );
		$this->assertFalse( Honk_Details::on( 'woo_new_order', 'action_email' ), 'personal data is off' );
		$this->assertFalse( Honk_Details::chosen( 'woo_low_stock', 'action_email' ), 'not a button of this event' );
	}

	public function test_the_preview_shows_the_buttons() {
		$specs = Honk_Preview::specs();
		$all   = array( 'email' => true, 'phone' => true, 'action_email' => true, 'action_call' => true );
		$this->assertSame(
			array( $this->button( 'Email customer', 'mailto:jane@example.com?subject=Order%20%231234' ), $this->button( 'Call customer', 'tel:+15035550142' ) ),
			Honk_Details::compose( 'woo_new_order', $specs['woo_new_order'], $all )['actions']
		);
		$this->assertSame( array(), Honk_Details::compose( 'woo_new_order', $specs['woo_new_order'], Honk_Details::states( 'woo_new_order' ) )['actions'], 'off by default' );

		$comment = Honk_Details::compose( 'comment_pending', $specs['comment_pending'], array( 'action_approve' => true ) );
		$this->assertSame( array( 'Approve' ), array_column( $comment['actions'], 'title' ) );
		$this->assertFalse( $comment['pii'] );

		$form = Honk_Details::compose( 'form_wpforms', $specs['form_wpforms'], array( 'values' => true, 'action_reply' => true, 'action_call' => true ) );
		$this->assertSame( array( 'Reply by email', 'Call back' ), array_column( $form['actions'], 'title' ) );
		$this->assertTrue( $form['pii'] );
	}

	public function test_a_listed_forms_buttons_follow_its_fields() {
		Functions\when( 'wpforms' )->justReturn(
			new class() {
				public function obj( $name ) {
					return new class() {
						public function get( $id, $args ) {
							return array( new WP_Post( array( 'ID' => 3, 'post_title' => 'Contact', 'post_content' => json_encode( array( 'fields' => array( '1' => array( 'id' => '1', 'type' => 'name', 'label' => 'Name' ), '2' => array( 'id' => '2', 'type' => 'email', 'label' => 'Email' ), '4' => array( 'id' => '4', 'type' => 'phone', 'label' => 'Phone' ) ) ) ) ) ) );
						}
					};
				}
			}
		);
		$spec = Honk_Preview::spec( 'form_wpforms' );
		$on   = array( 'values' => true, 'action_reply' => true, 'action_call' => true, 'field:3:1' => true, 'field:3:2' => true, 'field:3:4' => true );
		$this->assertSame( array( 'Reply by email', 'Call back' ), array_column( Honk_Details::compose( 'form_wpforms', $spec, $on )['actions'], 'title' ) );
		$this->assertSame( array( 'Call back' ), array_column( Honk_Details::compose( 'form_wpforms', $spec, array_merge( $on, array( 'field:3:2' => false ) ) )['actions'], 'title' ), 'the email field is left out' );
		$this->assertSame( 'mailto:jane@example.com?subject=Re%3A%20Contact', Honk_Details::compose( 'form_wpforms', $spec, $on )['actions'][0]['url'] );
	}
}
