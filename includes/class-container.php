<?php

/**
 * Class MC4WP_Service_Container
 *
 * @access private
 * @ignore
 */
class MC4WP_Container implements ArrayAccess {

	/**
	 * @var array
	 */
	protected $services = array();

	/**
	 * @var array
	 */
	protected $resolved_services = array();

	/**
	 * @param string $name
	 * @return boolean
	 */
	public function has(string $name) : bool {
		return isset( $this->services[ $name ] );
	}

	/**
	 * @param string $name
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function get(string $name) {
		if ( ! $this->has( $name ) ) {
			throw new Exception( sprintf( 'No service named %s was registered.', $name ) );
		}

		$service = $this->services[ $name ];

		// is this a resolvable service?
		if ( is_callable( $service ) ) {

			// resolve service if it's not resolved yet
			if ( ! isset( $this->resolved_services[ $name ] ) ) {
				$this->resolved_services[ $name ] = call_user_func( $service );
			}

			return $this->resolved_services[ $name ];
		}

		return $this->services[ $name ];
	}

	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return $this->has( $offset );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->get( $offset );
	}


	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		$this->services[ $offset ] = $value;
	}


	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		unset( $this->services[ $offset ] );
	}
}
