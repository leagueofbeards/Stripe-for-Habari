<?php
namespace Habari;
/**
 * Subscriptions Class
 *
 */
class Subscriptions extends Posts
{
	public static function get($paramarray = array()) {
		$defaults = array(
			'content_type' => 'subscription',
			'fetch_class' => 'Subscription',
		);
		
		$paramarray = array_merge($defaults, Utils::get_params($paramarray));
		return Posts::get( $paramarray );
	}
	
	public static function find_my_subs($person) {
		$ids = array();
	    $p_ids = DB::get_results( "SELECT plan_id FROM {sub_transactions} WHERE user_id = " . $person->id );
	    	    
	    foreach( $p_ids as $id ) {
		    $ids[] = $id->plan_id;
		}
		
		if( $ids[0]->id != '' ) {  	    
			return Products::get( array('plan' => $ids, 'nolimit' => true) );
		} else {
			return null;
		}
    }
}
?>