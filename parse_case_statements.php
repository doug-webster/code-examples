<?php /* Written by Doug Webster in 2017. This system allowed for "rules" to be parsed which potentially included field names matched to data, mathmatical operators, and psuedo SQL case statements with logical and mathematical operators. Will return a single value. */

// returns a value based on a rule such as "field1 + field2 - field3"
function get_calc_value( $rule, $data ) {
	// if the rule is a psuedo case statement
	if ( preg_match( '/^CASE\s+/i', trim( $rule ) ) )
		return parse_case_statement( $rule, $data );

	/* return text values encased in quotes (no text operators are available)
	regex: a string beginning with a double or single quote and ending with a matching quote but not containing a matching quote
		^("|\') first character is a " or '
		(((?!\1).)*) zero or more characters which do not match the first character
		\1$ last character matches the first
	*/
	if ( preg_match( '/^("|\')(((?!\1).)*)\1$/', trim( $rule ), $matches ) ) {
		if ( isset( $matches[2] ) ) // should contain text w/o the quotes
			return $matches[2];
	}

	// precedence *, / left to right, +, - left to right
	$operator_precedence = array(
		array( ' * ', ' / ' ),
		array( ' + ', ' - ' ),
	);

	// build regex of operator
	$regex_operator_precedence = $operator_precedence;
	// escape operators for regex
	array_walk_recursive( $regex_operator_precedence, function(&$a){
		$a = preg_quote($a);
		$a = str_replace('/','\/', $a);
	});
	$regex = array();
	foreach ( $regex_operator_precedence as $operators )
		$regex[] = implode( '|', $operators );
	$regex = '/(' . implode( '|', $regex ) . ')/';

	// get value for each field
	$pieces = preg_split( $regex, $rule, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( empty( $pieces ) || ! is_array( $pieces ) )
		return false;

	// replace keys with their corresponding values
	foreach ( $pieces as $i => $field ) {
		// odd indexes should be operators
		// we could probably skip this because they shouldn't be found in the data below anyway
		if ( $i % 2 != 0 ) continue;
		// allows for actual values instead of fields
		if ( is_numeric( $field ) ) continue;
		if ( isset( $data[$field] ) )
			$pieces[$i] = $data[$field];
	}
	// simple field rule which was not found
	// removed check in order to allow string values
	//if ( count( $pieces ) == 1 && ! is_numeric( $pieces[0] ) )
	//	return false;

	// perform calculations
	foreach ( $operator_precedence as $operators ) {
		// we're using the internal array pointer for looping because we are reducing the array as we run each calculation
		reset( $pieces );
		for ( $i = 0; $i < 1000; $i++ ) {
			// if we're down to just one value, we're done
			if ( count( $pieces ) == 1 )
				return $pieces[0];

			$field = each( $pieces );
			if ( ! $field ) break; // each will return false when outside of array
			$key = $field['key'];
			$field = $field['value'];

			// odd indexes should be operators
			if ( $key % 2 == 0 ) continue;
			// only handle the operators at this level of precedence
			if ( ! in_array( $field, $operators ) ) continue;
			// make sure the necessary values are present
			if ( ! isset( $pieces[$key-1] ) || ! isset( $pieces[$key+1] ) )
				continue;
			$v1 = $pieces[$key-1];
			$v2 = $pieces[$key+1];
			// normally if the value isn't numeric, we can't do math and must therefore skip this rule
			//if ( ! is_numeric( $v1 ) || ! is_numeric( $v2 ) ) continue;
			// however we have decided to set non-numeric strings to 0
			if ( ! is_numeric( $v1 ) )
				$v1 = 0;
			if ( ! is_numeric( $v2 ) )
				$v2 = 0;

			// perform math to get calculated value
			$value = null;
			switch ( $field ) {
				case ' * ': $value = $v1 * $v2; break;
				case ' / ':
					if ( $v2 == 0 )
						$value = 0;
					else
						$value = $v1 / $v2; break;
				case ' + ': $value = $v1 + $v2; break;
				case ' - ': $value = $v1 - $v2; break;
			} // end switch
			if ( ! isset( $value ) ) return false;

			// replace the three pieces just used with $value in the $pieces array
			// the internal array pointer is apparently reset by array_splice
			array_splice( $pieces, $key-1, 3, $value );
		} // end loop for each piece
	} // end loop for each operator precedent level

	if ( isset( $pieces ) && count( $pieces ) == 1 )
		return $pieces[0];
	else
		return false;
}

// parses a psuedo case statement and returns a value or false on failure
function parse_case_statement( $rule, $data ) {
	$case = array( 'rules' => array() );

	// remove "CASE " from beginning of string
	$rule = preg_replace( '/^CASE\s+/i', '', trim( $rule ) );
	// remove " END" from end of string
	$rule = preg_replace( '/\s+END$/i', '', trim( $rule ) );

	$options = preg_split( '/\bWHEN\s+/i', trim( $rule ), -1, PREG_SPLIT_NO_EMPTY );
	if ( empty( $options ) ) return false;

	$last_index = count( $options ) - 1;
	$last = preg_split( '/\bELSE\s+/i', trim( $options[$last_index] ) );
	if ( isset( $last[1] ) ) {
		$case['else'] = $last[1];
		$options[$last_index] = $last[0];
	}

	foreach ( $options as $option ) {
		$exps = preg_split( '/\s+THEN\s+/i', trim( $option ) );
		if ( ! isset( $exps[1] ) ) continue;
		// separate logical and value portions of option
		$case['rules'][] = array(
			'logical_expression' => $exps[0],
			'value_expression' => $exps[1],
		);
	}

	/* At this point we should have some resembling the following:
	$case = array(
		'rules' => array(
			0 => array(
				'logical_expression' => '[logical expression]',
				'value_expression' => '[value expression]',
			),
			1 => array(
				'logical_expression' => '[logical expression]',
				'value_expression' => '[value expression]',
			),
		),
		'else' => '[value expression]',
	);*/

	foreach ( $case['rules'] as $c_rule ) {
		if ( isset( $c_rule['logical_expression'] ) ) {
			if ( evaluate_logical_expression( $c_rule['logical_expression'], $data ) ) {
				if ( isset( $c_rule['value_expression'] ) ) {
					return get_calc_value( $c_rule['value_expression'], $data );
				}
			}
		}
	}

	if ( ! isset( $c_value ) && isset( $case['else'] ) )
		return get_calc_value( $case['else'], $data );

	return false;
}

// evaluates a logical expression and returns either true or false
// returns false on failure
function evaluate_logical_expression( $exp, $data ) {
// (based on get_calc_value)
	$operator_precedence = array(
		array( ' < ', ' <= ', ' > ', ' >= ', ' = ', ' != ', ' <> ' ),
		array( ' && ', ' AND ' ),
		array( ' || ', ' OR ' ),
	);

	// build regex of operator
	$regex_operator_precedence = $operator_precedence;
	// escape operators for regex
	array_walk_recursive( $regex_operator_precedence, function(&$a){
		$a = preg_quote($a);
		$a = str_replace('/','\/', $a);
	});
	$regex = array();
	foreach ( $regex_operator_precedence as $operators )
		$regex[] = implode( '|', $operators );
	$regex = '/(' . implode( '|', $regex ) . ')/';

	// get value for each field
	$pieces = preg_split( $regex, $exp, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( empty( $pieces ) || ! is_array( $pieces ) )
		return false;

	// replace keys with their corresponding values
	foreach ( $pieces as $i => $field ) {
		// odd indexes should be operators
		// we could probably skip this because they shouldn't be found in the data below anyway
		if ( $i % 2 != 0 ) continue;
		//if ( isset( $data[$field] ) )
		//	$pieces[$i] = $data[$field];
		$val = get_calc_value( $field, $data );
		if ( $val !== false )
			$pieces[$i] = $val;
	}
	// simple field rule which was not found
	if ( count( $pieces ) == 1 )
		return (bool)$pieces[0];

	// perform calculations
	foreach ( $operator_precedence as $operators ) {
		// we're using the internal array pointer for looping because we are reducing the array as we run each calculation
		reset( $pieces );
		for ( $i = 0; $i < 1000; $i++ ) {
			// if we're down to just one value, we're done
			if ( count( $pieces ) == 1 )
				return (bool)$pieces[0];

			$field = each( $pieces );
			if ( ! $field ) break; // each will return false when outside of array
			$key = $field['key'];
			$field = $field['value'];

			// odd indexes should be operators
			if ( $key % 2 == 0 ) continue;
			// only handle the operators at this level of precedence
			if ( ! in_array( $field, $operators ) ) continue;
			// make sure the necessary values are present
			if ( ! isset( $pieces[$key-1] ) || ! isset( $pieces[$key+1] ) )
				continue;
			$v1 = $pieces[$key-1];
			$v2 = $pieces[$key+1];
			// do case insensitive string comparison
			if ( ! is_numeric( $v1 ) && ! is_bool( $v1 ) )
				$v1 = strtoupper( $v1 );
			if ( ! is_numeric( $v2 ) && ! is_bool( $v2 ) )
				$v2 = strtoupper( $v2 );

			// evaluate logical operation
			$value = null;
			switch ( $field ) {
				case ' < '  : $value = $v1 <  $v2; break;
				case ' <= ' : $value = $v1 <= $v2; break;
				case ' > '  : $value = $v1 >  $v2; break;
				case ' >= ' : $value = $v1 >= $v2; break;
				case ' = '  : $value = $v1 == $v2; break;
				case ' <> ' :
				case ' != ' : $value = $v1 != $v2; break;
				case ' AND ':
				case ' && ' : $value = $v1 && $v2; break;
				case ' OR ' :
				case ' || ' : $value = $v1 || $v2; break;
			} // end switch
			if ( ! isset( $value ) ) return false;

			// replace the three pieces just used with $value in the $pieces array
			// the internal array pointer is apparently reset by array_splice
			array_splice( $pieces, $key-1, 3, $value );
		} // end loop for each piece
	} // end loop for each operator precedent level

	if ( isset( $pieces ) && count( $pieces ) == 1 )
		return (bool)$pieces[0];
	else
		return false;
}
