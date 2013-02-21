<?php 
namespace Habari;
class SubsPlugin extends Plugin
{
	const STRIPE_SEKRET = '';
		
	public function action_init() {
		DB::register_table('subscriptions');
		DB::register_table('sub_transactions');
	}
	
	public function action_plugin_activation() {
		Post::add_new_type( 'subscription' );
		$this->create_subscriptions_table();
		$this->create_sub_transactions_table();
	}
	
	private function create_subscriptions_table() {
		$sql = "CREATE TABLE {\$prefix}subscriptions (
			id int unsigned NOT NULL AUTO_INCREMENT,
			post_id int unsigned NOT NULL,
			price varchar(255) NOT NULL DEFAULT '0.00',
			total_purchased int unsigned NOT NULL DEFAULT 0,
			type int unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			UNIQUE KEY `post_id` (`post_id`)
			) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;";

		DB::dbdelta( $sql );
	}

	private function create_sub_transactions_table() {
		$sql = "CREATE TABLE {\$prefix}sub_transactions (
			id int unsigned NOT NULL AUTO_INCREMENT,
			user_id int unsigned NOT NULL,
			object_type varchar(255) NULL,
			created varchar(255) NULL,
			plan_id varchar(255) NULL,
			plan_id varchar(255) NULL,
			plan_interval varchar(255) NULL,
			current_period_start varchar(255) NULL,
			current_period_end varchar(255) NULL,
			canceled int unsigned NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `plan_id` (`plan_id`)
			) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;";

		DB::dbdelta( $sql );
	}
	
	public function filter_posts_get_paramarray($paramarray) {
		$queried_types = Posts::extract_param($paramarray, 'content_type');
		if($queried_types && in_array('subscription', $queried_types)) {
			$paramarray['post_join'][] = '{subscriptions}';
			$default_fields = isset($paramarray['default_fields']) ? $paramarray['default_fields'] : array();
			$default_fields['{subscriptions}.created'] = '';
			$default_fields['{subscriptions}.plan_id'] = '';
			$default_fields['{subscriptions}.status'] = '';
			$default_fields['{subscriptions}.interval'] = '';
			$paramarray['default_fields'] = $default_fields;
		}
					
		return $paramarray;
	}

	public function filter_post_schema_map_subscription($schema, $post) {
		$schema['subscriptions'] = $schema['*'];
		$schema['subscriptions']['post_id'] = '*id';
		return $schema;
	}

	public function filter_post_get($out, $name, $sub) {
		if('subscription' == Post::type_name($sub->get_raw_field('content_type'))) {
			switch($name) {
				case 'get_cancel_link':
					$out = URL::get( 'auth_ajax', array('context' => 'sub_cancel_link', 'id' => $sub->id) );
				break;
			}
		}
		
		return $out;
	}

	private function setup_stripe() {
		include_once( 'user/classes/Stripe.php' );
		Stripe::setApiKey(self::STRIPE_SEKRET);
	}

	private function next_id($prefix = '') {
		$user = User::identify();
		$ids = $user->info->ids;
			
		if(!is_array($ids)) {
			$ids = array();
		}
		
		if(!isset($ids['p' . $prefix])) {
			$ids['p' . $prefix] = 0;
		}
		
		$ids['p' . $prefix] = $ids['p' . $prefix] + 1;
		$user->info->ids = $ids;
		$user->info->commit();

		$id = $prefix . sprintf('%0' . (5 - strlen($prefix)) . 'd', $ids['p' . $prefix]);
		
		return $id;
	}

	private function save_subscription($args) {
		DB::insert( DB::table('subscriptions'), $args );
	}
	
	public function action_auth_ajax_purchase_plan($data) {
		$vars = $data->handler_vars;
		$user = User::identify();
		$this->setup_stripe();
		$error = '';
		$success = '';
		$sub = Subscription::get( array('id' => $vars['sub_id']) );
		$total = $sub->total_purchased;
		
		try {
			if( !isset($vars['stripeToken']) ) {
				$status = 401;
				$message = "The Stripe Token was not generated correctly";
			} else {
				$customer = Stripe_Customer::retrieve( $user->info->stripe_id );
				$r = $customer->updateSubscription( array('prorate' => true, 'plan' => $vars['plan'], 'coupon' => $vars['coupon']) );
				$charge = json_decode( $r );
				$chrg['plan_id'] = $product->id;
				$chrg['user_id'] = $user->id;
				$chrg['status'] = $charge->status;
				$chrg['current_period_start'] = $charge->current_period_start;
				$chrg['started_at'] = $charge->start;
				$chrg['ended_at'] = $charge->ended_at;
				$chrg['object_type'] = $charge->object;
				$chrg['plan_id'] = $charge->plan->id;
				
				$this->save_subscription( $chrg );
				$sub->total_purchased = intval($total) + 1;
				$sub->update();
				
				$status = 200;
			    $message = 'W00t! Your payment went through. <a href="' . $product->get_download_link . '">Here is a shiny new download</a>, crafted just for you.<br><br>You will also receive a receipt in your email that contains the download link. Remember to check your spam folder!';
			    Email::send_receipt( $product, $user, $chrg );				
			}
		} catch( Exception $e ) {
			$status = 401;
			$error = $e->getMessage();			
		}
		
		$response = new AjaxResponse( $status, $message, null );
		$response->out();
	}
	
	public function action_auth_ajax_sub_cancel_link($data) {
		$vars = $data->handler_vars;
		$user = User::identify();
		$this->setup_stripe();
		$sub = Product::get( array('id' => $vars['product_id']) );
		$c = Stripe_Customer::retrieve( $user->info->stripe_id );
		$r = $c->cancelSubscription();
		$burninate = json_decode( $r );
		
		if( $burninate->status == 'canceled' ) {
			DB::query( "DELETE FROM {sub_transactions} WHERE user_id = " . $user->id );
			$user->info->insider = 0;
			$user->info->commit();
		}
		
		$ar = new AjaxResponse( 200, 'Your subscription has been cancelled.', null );
		$ar->html( '#your_subs', '#' );
		$ar->out();
	}
}
?>