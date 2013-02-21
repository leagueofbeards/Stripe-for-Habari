<?php
namespace Habari;
/**
 * @package Habari
 *
 */

class Product extends Post
{
	public static function get($paramarray = array()) {
		$defaults = array(
			'content_type' => 'product',
			'fetch_fn' => 'get_row',
			'limit' => 1,
			'fetch_class' => 'Product',
		);
		
		$paramarray = array_merge($defaults, Utils::get_params($paramarray));
		return Posts::get( $paramarray );
	}

	public function jsonSerialize() {
		$array = array_merge( $this->fields, $this->newfields );		
		return json_encode($array);
	}
	
	public function owned($user) {
		if( $this->plan == '' ) {
			$row = DB::get_row( "SELECT id FROM {transactions} WHERE user_id = " . $user . " AND product_id = " . $this->id . " AND paid = 1" );
		} else {
			$row = DB::get_row( "SELECT id FROM {sub_transactions} WHERE user_id = " . $user . " AND plan_id = " . $this->plan . " AND canceled != 1" );
		}
		
		return $row;
	}
}
?>
