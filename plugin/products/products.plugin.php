<?php 
namespace Habari;
class ProductsPlugin extends Plugin
{
	const STRIPE_SEKRET = '';
		
	public function action_init() {
		DB::register_table('products');
		DB::register_table('product_versions');
		DB::register_table('transactions');
		
		$this->add_template( 'product.edit', __DIR__ . '/screens/product.edit.php' );
		$this->add_template( 'product.multiple', __DIR__ . '/screens/product.multiple.php' );
		$this->add_template( 'product.single', __DIR__ . '/screens/product.single.php' );
		$this->add_template( 'product.header', __DIR__ . '/screens/product.header.php' );
		$this->add_template( 'product.footer', __DIR__ . '/screens/product.footer.php' );
		$this->add_template( 'p.dropzone', __DIR__ . '/screens/p.dropzone.php' );
	}
	
	public function action_plugin_activation() {
		Post::add_new_type( 'product' );
		$this->create_products_table();
		$this->create_products_versions_table();
		$this->create_transactions_table();
	}
	
	private function create_products_table() {
		$sql = "CREATE TABLE {\$prefix}products (
			id int unsigned NOT NULL AUTO_INCREMENT,
			post_id int unsigned NOT NULL,
			price varchar(255) NOT NULL DEFAULT '0.00',
			plan varchar(255) NULL,			
			preview_url varchar(255) NULL,
			total_purchased int unsigned NOT NULL DEFAULT 0,
			hero varchar(255) NULL,
			s3_path varchar(255) NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `post_id` (`post_id`)
			) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;";

		DB::dbdelta( $sql );
	}

	private function create_transactions_table() {
		$sql = "CREATE TABLE {\$prefix}transactions (
			id int unsigned NOT NULL AUTO_INCREMENT,
			product_id int unsigned NOT NULL,
			user_id int unsigned NOT NULL,
			object_type varchar(255) NULL,
			created varchar(255) NULL,
			charge_id varchar(255) NULL,
			paid int unsigned NOT NULL,
			amount varchar(255) NULL,
			card_exp_year varchar(255) NULL,
			card_exp_month varchar(255) NULL,
			card_type varchar(255) NULL,
			card_last4 varchar(255) NULL,
			card_fingerprint varchar(255) NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `charge_id` (`charge_id`)
			) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;";

		DB::dbdelta( $sql );
	}
	
	private function create_products_versions_table() {
		$sql = "CREATE TABLE {\$prefix}product_versions (
			id int unsigned NOT NULL AUTO_INCREMENT,
			product_id int unsigned NOT NULL,
			version_number varchar(10) NULL,
			version_notes varchar(255),
			PRIMARY KEY (`id`),
			UNIQUE KEY `version_number` (`version_number`)
			) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;";

		DB::dbdelta( $sql );
	}

	private function create($product) {
		$s3 = new S3( KEY, SEKRET );
		$bucket = $s3->putBucket( $product->slug, S3::ACL_AUTHENTICATED_READ );
    }

    private function get_s3_download($product) {
	    $s3 = new S3( KEY, SEKRET );
		return $s3->getAuthenticatedURL( 'lob-' . $product->slug, $product->s3_path, '3600' );
    }

	public function filter_posts_get_paramarray($paramarray) {
		$queried_types = Posts::extract_param($paramarray, 'content_type');
		if($queried_types && in_array('product', $queried_types)) {
			$paramarray['post_join'][] = '{products}';
			$default_fields = isset($paramarray['default_fields']) ? $paramarray['default_fields'] : array();
			$default_fields['{products}.price'] = '0.00';
			$default_fields['{products}.plan'] = '';
			$default_fields['{products}.total_purchased'] = 0;
			$default_fields['{products}.hero'] = '';
			$default_fields['{products}.s3_path'] = '';
			$paramarray['default_fields'] = $default_fields;
		}
					
		return $paramarray;
	}

	public function filter_post_schema_map_product($schema, $post) {
		$schema['products'] = $schema['*'];
		$schema['products']['post_id'] = '*id';
		return $schema;
	}

	public function filter_post_get($out, $name, $product) {
		if('product' == Post::type_name($product->get_raw_field('content_type'))) {
			switch($name) {
				case 'version':
					$out = $this->get_current_version( $product->id );
				break;
				case 'get_download_link':
					$out = $this->get_s3_download( $product );
				break;
				case 'get_cancel_link':
					$out = URL::get( 'auth_ajax', array('context' => 'sub_cancel_link', 'product_id' => $product->id) );
				break;
			}
		}
		
		return $out;
	}

	public function filter_default_rewrite_rules( $rules ) {
		$this->add_rule('"product"/slug', 'display_product');
		$this->add_rule('"products"/"bookmarked"', 'display_bookmarked_product');
		$this->add_rule('"delete"/"product"/slug', 'display_delete_product');
		$this->add_rule('"edit"/"product"/slug', 'display_edit_product');

		return $rules;
	}

	public function filter_post_type_display($type, $g_number)	{
		switch($type) {
			case 'product':
				switch($g_number) {
					case 'singular':
						return _t('Product');
					case 'plural':
						return _t('Products');
				}
				break;
		}
		return $type;
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

	private function save_transaction($args) {
		DB::insert( DB::table('transactions'), $args );
	}

	private function save_subscription($args) {
		DB::insert( DB::table('sub_transactions'), $args );
	}

	private function create_version($product, $notes, $version) {
		$args = array(
			'product_id'		=> $product->id,
			'version_number'	=> $version ? $version : '1.0',
			'version_notes'		=> $notes ? $notes : 'Initial Release',
		);
	
		$check = $this->version( $product->id, $version );
		
		if( $check == false ) {
			DB::insert( DB::table('product_versions'), $args );	
		} else {
			DB::update( DB::table('product_versions'), $args, array('id' => $check->id) );
		}
		
	}

	private function get_current_version($id) {
		$version = DB::get_row( "SELECT id, product_id, version_number, version_notes FROM {product_versions} WHERE product_id = " . $id . ' ORDER BY id DESC LIMIT 1' );
		return $version;
	}

	public function version($id, $version) {
		$version = DB::get_row( "SELECT id, product_id, version_number, version_notes FROM {product_versions} WHERE product_id = " . $id . " AND  version_number = " . $version );
		return $version;
	}

	private function get_versions($id) {
		$versions = DB::get_results( "SELECT id, product_id, version_number, version_notes FROM {product_versions} WHERE product_id = " . $id . " ORDER BY id DESC" );
		return $versions;
	}
	
	public function theme_route_display_edit_product($theme) {
		$slug = $theme->matched_rule->named_arg_values['slug'];
		$product = Product::get( array('slug' => $slug) );
		$user = User::identify();

		$theme->versions = $this->get_versions( $product->id );
		$theme->product = $product;

		$theme->display( 'product.edit' );
	}

	public function theme_route_display_product($theme) {
		$slug = $theme->matched_rule->named_arg_values['slug'];
		$product = Product::get( array('slug' => $slug) );
		$user = User::identify();

		if( !$product->id ) {
			Utils::redirect( Site::get_url('habari') );
			exit();
		}

		$theme->versions = $this->get_versions( $product->id );
		$theme->product = $product;

		$theme->display( 'product.single' );
	}

	public function action_auth_ajax_new_product($data) {
		$s3 = new S3( KEY, SEKRET );
		$vars = $data->handler_vars;
		$id = $this->next_id();
		$user = User::identify();
			
		$postdata= array(
			'title'=> $id,
			'slug' => Utils::slugify( $id ),
			'content' => '',
			'user_id' => $user->id,
			'pubdate' => DateTime::date_create( date(DATE_RFC822) ),
			'status' => Post::status('unpublished'),
			'content_type' => Post::type('product'),
		);
		
		$product = Product::create( $postdata );
		$this->create( $product );
							
		Utils::redirect( URL::get('display_edit_product', array('slug' => $product->slug)) );
		exit();
	}
	
	public function action_auth_ajax_update_product($data) {
		$s3 = new S3( KEY, SEKRET );
		$vars = $data->handler_vars;
						
		$user = User::identify();
		$product = Product::get( array('id' => $vars['product_id']) );
	
		if( $_FILES['uploaded']['name'] != '' ) {
			$upload_dir = Site::get_path('user') . '/files/uploads/heros/';
			$image = Common::upload_image( $_FILES, $upload_dir );
		} else {
			$image = null;
		}

		if( $_FILES['product']['name'] != '' ) {
			$upload_dir = Site::get_path('user') . '/files/uploads/products/';
			$file = Common::upload_product( $_FILES, $upload_dir );
		
			$filename = explode( '/', $file->document );
			$filename = array_pop( $filename );
		
			$s3->putObjectFile( $file->document, 'lob-' . $product->slug, $filename, S3::ACL_AUTHENTICATED_READ );
			$s3_path = $filename;
		} else {
			$file = null;
		}
		
		$postdata= array(
			'title'		=>	$vars['title'] ? $vars['title'] : $product->title,
			'content'	=>	$vars['content'] ? $vars['content'] : '',
			'updated'	=>	DateTime::date_create( date(DATE_RFC822) ),
			'price'		=>	$vars['price'] ? $vars['price'] : '',
			'plan'		=>	$vars['plan'] ? $vars['plan'] : '',
			'hero'		=>	$image ? $image->document : $product->hero,
			's3_path'	=>	$file ? $s3_path : $product->s3_path,
		);
	
		foreach( $postdata as $key => $value ) {
			$product->$key = $value;
		}
		
		if( isset($vars['publish']) ) {
			$product->status = Post::status('published');
		} else {
			if( isset($vars['unpublish']) ) {
				$product->status = Post::status('draft');
			}
		}
		
		$product->update();
				
		$this->create_version( $product, $vars['version_notes'], $vars['version_number'] );
	}
	
	public function action_auth_ajax_publish_product($data) {
		$vars = $data->handler_vars;
		$user = User::identify();
		$product = Product::get( array('id' => $vars['product_id']) );
		
		$product->status = Post::status('published');
		$product->commit();
		
		Utils::redirect( URL::get('display_product', array('slug' => $product->slug)) );
		exit();
	}
	
	public function action_auth_ajax_purchase_product($data) {
		$vars = $data->handler_vars;
		$user = User::identify();
		$this->setup_stripe();
		$disc = '';
		$chrg = array();
		$error = '';
		$success = '';
		$product = Product::get( array('id' => $vars['product_id']) );
		$total = $product->total_purchased;
		
		try {
			if( !isset($vars['stripeToken']) ) {
				$status = 401;
				$message = "The Stripe Token was not generated correctly";
			} else {
				if( $user->info->stripe_id != '' ) {
					$customer = Stripe_Customer::retrieve( $user->info->stripe_id );				
				} else {
					$u_args = array("card" => $vars['stripeToken'], "description" => $user->displayname, 'email' => $user->email );
					$customer = Stripe_Customer::create( $u_args );
					$user->info->stripe_id = $customer->id;
					$user->info->commit();
				}
				
				if( $vars['plan'] != '' ) {
					if( $vars['coupon'] != '' ) {
						$disc = 1;
						$r = $customer->updateSubscription( array('prorate' => true, 'plan' => $vars['plan'], 'coupon' => $vars['coupon']) );
					} else {
						$disc = 0;
						$r = $customer->updateSubscription( array('prorate' => true, 'plan' => $vars['plan']) );
					}
					
					$charge = json_decode( $r );
					$chrg['discounted'] = $disc;
					$chrg['plan_id'] = $product->id;
					$chrg['user_id'] = $user->id;
					$chrg['created'] = DateTime::date_create( date(DATE_RFC822) );
					$chrg['plan_status'] = $charge->status;
					$chrg['current_period_start'] = $charge->current_period_start;
					$chrg['current_period_end'] = $charge->current_period_end;
					$chrg['plan_interval'] = $charge->plan->interval;
					$chrg['object_type'] = $charge->object;
					$chrg['plan_id'] = $charge->plan->id;
					$this->save_subscription( $chrg );
									
					$status = 200;
					$message = 'W00t! Your payment went through. <a href="' . URL::get('display_dashboard') . '">You\'re now an Insider!</a>.<br><br>You will also receive a receipt in your email that contains the link to the Insiders area. Remember to check your spam folder!';
					
					$user->info->insider = 1;
					$user->info->commit();
				} else {
					$args = array( 'amount' => ($product->price) * 100, 'currency' => 'usd', 'customer' =>  $user->info->stripe_id );
					$charge = Stripe_Charge::create( $args );
					$charge = json_decode( $charge );
					
					$chrg['product_id'] = $product->id;
					$chrg['user_id'] = $user->id;
					$chrg['charge_id'] = $charge->id;
					$chrg['amount'] = $charge->amount;
					$chrg['object_type'] = $charge->object;
					$chrg['created'] = $charge->created;
					$chrg['charge_id'] = $charge->id;
					$chrg['paid'] = $charge->paid;
					$chrg['card_exp_month'] = $charge->card->exp_month;
					$chrg['card_exp_year'] = $charge->card->exp_year;
					$chrg['card_type'] = $charge->card->type;
					$chrg['card_last4'] = $charge->card->last4;
					$chrg['card_fingerprint'] = $charge->card->fingerprint;
					$this->save_transaction( $chrg );
					$status = 200;
					$message = 'W00t! Your payment went through. <a href="' . $product->get_download_link . '">Here is a shiny new download</a>, crafted just for you.<br><br>You will also receive a receipt in your email that contains the download link. Remember to check your spam folder!';					
				}
				
				$product->total_purchased = intval($total) + 1;
				$product->update();
				
			    Email::send_receipt( $product, $user, $chrg );
		    }
		} catch( Exception $e ) {
			$status = 401;
			$error = $e->getMessage();
		}
		
		$response = new AjaxResponse( $status, $message, null );
		$response->out();
	}
}
?>