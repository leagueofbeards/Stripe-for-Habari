<?php
namespace Habari;
/**
 * Products Class
 *
 */
class Products extends Posts
{
	public static function get($paramarray = array()) {
		$defaults = array(
			'content_type' => 'product',
			'fetch_class' => 'Product',
		);
		
		$paramarray = array_merge($defaults, Utils::get_params($paramarray));
		return Posts::get( $paramarray );
	}
	
	public static function find_my_products($person) {
		$ids = array();
	    $p_ids = DB::get_results( "SELECT product_id FROM {transactions} WHERE user_id = " . $person->id );
	    
	    foreach( $p_ids as $id ) {
		    $ids[] = $id->product_id;
	    }
	    	    	    
	    return self::get( array('id' => $ids, 'nolimit' => true) );
    }
}
?>