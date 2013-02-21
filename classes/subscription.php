<?php
namespace Habari;
/**
 * @package Habari
 *
 */

class Subscription extends Post
{
	public static function get($paramarray = array()) {
		$defaults = array(
			'content_type' => 'subscription',
			'fetch_fn' => 'get_row',
			'limit' => 1,
			'fetch_class' => 'Subscription',
		);
		
		$paramarray = array_merge($defaults, Utils::get_params($paramarray));
		return Posts::get( $paramarray );
	}

	public function jsonSerialize() {
		$array = array_merge( $this->fields, $this->newfields );		
		return json_encode($array);
	}
}
?>
