<?php
namespace MediaUsageTracker\Admin;

/**
 * Natural-language query parser.
 *
 * Translates a plain-English sentence into the structured filter array that
 * MediaSearch already understands. Pure, deterministic, WP-independent — the
 * whole class is unit-testable in isolation. The public shape is deliberately
 * LLM-swappable: parse() could later defer to an API without changing callers.
 *
 * Example:
 *   "Show large unused images"      → usage_status=unused, media_type=image, size=large
 *   "Find unused PDFs"              → usage_status=unused, media_type=application/pdf
 *   "images not used in last year"  → usage_status=unused, media_type=image, older_than_days=365
 */
class QueryParser {

	/**
	 * Parse a natural-language query.
	 *
	 * @return array{
	 *   filters: array,        // keys: usage_status, media_type, size, date_range, older_than_days, min_size_bytes, s, source
	 *   interpreted: string,   // human echo, e.g. "Unused • Images • Large"
	 *   unmatched: string[]    // leftover words we couldn't map
	 * }
	 */
	public function parse( $query ) {
		$original = trim( (string) $query );
		$text     = strtolower( $original );

		$filters = array(
			'usage_status'    => '',
			'media_type'      => '',
			'size'            => '',
			'date_range'      => '',
			'older_than_days' => 0,
			'min_size_bytes'  => 0,
			's'               => '',
			'source'          => '',
		);
		$parts = array(); // human interpretation fragments

		// Track which spans of text we consumed so leftovers become "unmatched".
		$consumed = $text;

		// ---------------------------------------------------------------------
		// 1. "not used in the last <period>"  → unused + older_than
		//    This idiom must run BEFORE the plain date-range rule, because the
		//    date here is inverted (older THAN the window, not within it).
		// ---------------------------------------------------------------------
		if ( preg_match( '/\bnot?\s+(?:used|referenced)\s+(?:in|within|for|over)?\s*(?:the\s+)?(?:last|past)?\s*(\d+)?\s*(year|years|month|months|week|weeks|day|days)\b/', $text, $m ) ) {
			$filters['usage_status']    = 'unused';
			$num                        = isset( $m[1] ) && $m[1] !== '' ? (int) $m[1] : 1;
			$filters['older_than_days'] = $this->period_to_days( $num, $m[2] );
			$parts[]                    = 'Unused';
			$parts[]                    = 'Older than ' . $this->humanize_days( $filters['older_than_days'] );
			$consumed                   = str_replace( $m[0], ' ', $consumed );
		}

		// ---------------------------------------------------------------------
		// 2. Usage status (only if not already set by rule 1)
		// ---------------------------------------------------------------------
		if ( $filters['usage_status'] === '' ) {
			if ( preg_match( '/\b(unused|not\s+used|not\s+referenced|orphan(?:ed)?|unreferenced)\b/', $text, $m ) ) {
				$filters['usage_status'] = 'unused';
				$parts[]                 = 'Unused';
				$consumed                = str_replace( $m[0], ' ', $consumed );
			} elseif ( preg_match( '/\b(in\s+use|in-use|used|referenced|active)\b/', $text, $m ) ) {
				$filters['usage_status'] = 'used';
				$parts[]                 = 'Used';
				$consumed                = str_replace( $m[0], ' ', $consumed );
			}
		}

		// ---------------------------------------------------------------------
		// 3. Media type
		// ---------------------------------------------------------------------
		if ( preg_match( '/\b(images?|photos?|pictures?|pics?)\b/', $text, $m ) ) {
			$filters['media_type'] = 'image';
			$parts[]               = 'Images';
			$consumed              = str_replace( $m[0], ' ', $consumed );
		} elseif ( preg_match( '/\b(pdfs?|documents?|docs?)\b/', $text, $m ) ) {
			$filters['media_type'] = 'application/pdf';
			$parts[]               = 'PDFs';
			$consumed              = str_replace( $m[0], ' ', $consumed );
		} elseif ( preg_match( '/\b(videos?|movies?|clips?|mp4s?)\b/', $text, $m ) ) {
			$filters['media_type'] = 'video';
			$parts[]               = 'Videos';
			$consumed              = str_replace( $m[0], ' ', $consumed );
		} elseif ( preg_match( '/\b(audios?|sounds?|mp3s?|music)\b/', $text, $m ) ) {
			$filters['media_type'] = 'audio';
			$parts[]               = 'Audio';
			$consumed              = str_replace( $m[0], ' ', $consumed );
		}

		// ---------------------------------------------------------------------
		// 4. Explicit size threshold:  "over 5 MB", "bigger than 2gb", "> 500kb"
		// ---------------------------------------------------------------------
		if ( preg_match( '/\b(?:over|above|bigger\s+than|larger\s+than|greater\s+than|more\s+than|>)\s*(\d+(?:\.\d+)?)\s*(gb|mb|kb|g|m|k|bytes?|b)\b/', $text, $m ) ) {
			$bytes                      = $this->size_to_bytes( (float) $m[1], $m[2] );
			$filters['min_size_bytes']  = $bytes;
			$parts[]                    = 'Over ' . $this->humanize_bytes( $bytes );
			$consumed                   = str_replace( $m[0], ' ', $consumed );
		} elseif ( preg_match( '/\b(large|big|huge|heavy|oversized)\b/', $text, $m ) ) {
			$filters['size'] = 'large';
			$parts[]         = 'Large';
			$consumed        = str_replace( $m[0], ' ', $consumed );
		} elseif ( preg_match( '/\b(small|tiny|little)\b/', $text, $m ) ) {
			$filters['size'] = 'small';
			$parts[]         = 'Small';
			$consumed        = str_replace( $m[0], ' ', $consumed );
		}

		// ---------------------------------------------------------------------
		// 5. Plain "uploaded in the last <period>" date range (only if rule 1
		//    didn't already set an inverted date sense).
		// ---------------------------------------------------------------------
		if ( $filters['older_than_days'] === 0 ) {
			if ( preg_match( '/\b(?:in\s+the\s+)?(?:last|past)\s+(\d+)?\s*(year|years|month|months|week|weeks|day|days)\b/', $text, $m ) ) {
				$num   = isset( $m[1] ) && $m[1] !== '' ? (int) $m[1] : 1;
				$days  = $this->period_to_days( $num, $m[2] );
				$range = $this->days_to_range( $days );
				if ( $range ) {
					$filters['date_range'] = $range;
					$parts[]               = 'Uploaded ' . $this->date_range_label( $range );
					$consumed              = str_replace( $m[0], ' ', $consumed );
				}
			} elseif ( preg_match( '/\btoday\b/', $text, $m ) ) {
				$filters['date_range'] = 'today';
				$parts[]               = 'Uploaded today';
				$consumed              = str_replace( $m[0], ' ', $consumed );
			}
		}

		// ---------------------------------------------------------------------
		// 6. Plugin / source detection  → maps plugin mentions to source keys
		// ---------------------------------------------------------------------
		$source_map = array(
			'/\bacf\b|\badvanced\s+custom\s+fields?\b/'                           => array( 'acf',            'ACF' ),
			'/\belementor\b/'                                                      => array( 'elementor',      'Elementor' ),
			'/\bwoocommerce\b|\bwoo(?:\s+commerce)?\b|\bwoo\b/'                   => array( 'woocommerce',    'WooCommerce' ),
			'/\byoast(?:\s+seo)?\b/'                                               => array( 'yoast',          'Yoast SEO' ),
			'/\bdivi\b/'                                                           => array( 'divi',           'Divi' ),
			'/\bwpbakery\b|\bwp\s+bakery\b|\bvisual\s+composer\b|\b(?<!\/)vc\b/' => array( 'wpbakery',       'WPBakery' ),
			'/\bbeaver\s*builder\b|\bbeaver\b/'                                   => array( 'beaver_builder', 'Beaver Builder' ),
			'/\bavada\b|\bfusion(?:\s+builder)?\b/'                               => array( 'avada',          'Avada' ),
			'/\bjetengine\b|\bjet\s+engine\b/'                                    => array( 'jetengine',      'JetEngine' ),
			'/\bjetpopup\b|\bjet\s+popup\b/'                                      => array( 'jetpopup',       'JetPopup' ),
			'/\bgravity\s*forms?\b|\bgravityforms?\b|\bgf\b/'                     => array( 'gravityforms',   'Gravity Forms' ),
			'/\bastra\b/'                                                          => array( 'astra',          'Astra' ),
		);
		foreach ( $source_map as $pattern => $info ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				$filters['source'] = $info[0];
				$parts[]           = 'Referenced by: ' . $info[1];
				$consumed          = str_replace( $m[0], ' ', $consumed );
				break;
			}
		}

		// ---------------------------------------------------------------------
		// 7. Leftover words → free-text search + "unmatched" report
		//    Strip filler/stop words so a query like "show me large images"
		//    doesn't leave "show me" behind as a literal filename search.
		// ---------------------------------------------------------------------
		$leftover = $this->strip_stopwords( $consumed );
		if ( $leftover !== '' ) {
			$filters['s'] = $leftover;
			$parts[]      = 'Matching "' . $leftover . '"';
		}

		return array(
			'filters'     => $filters,
			'interpreted' => implode( ' • ', $parts ),
			'unmatched'   => $leftover !== '' ? array_values( array_filter( explode( ' ', $leftover ) ) ) : array(),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function period_to_days( $num, $unit ) {
		$unit = rtrim( $unit, 's' );
		switch ( $unit ) {
			case 'year':  return $num * 365;
			case 'month': return $num * 30;
			case 'week':  return $num * 7;
			default:      return $num; // day
		}
	}

	/** Map a "within the last N days" window to the nearest existing date_range option. */
	private function days_to_range( $days ) {
		if ( $days <= 1 )   return 'today';
		if ( $days <= 7 )   return '7days';
		if ( $days <= 30 )  return '30days';
		if ( $days <= 90 )  return '90days';
		return '1year';
	}

	private function size_to_bytes( $value, $unit ) {
		switch ( strtolower( rtrim( $unit, 's' ) ) ) {
			case 'gb':
			case 'g':     return (int) round( $value * 1073741824 );
			case 'mb':
			case 'm':     return (int) round( $value * 1048576 );
			case 'kb':
			case 'k':     return (int) round( $value * 1024 );
			default:      return (int) round( $value ); // bytes
		}
	}

	private function humanize_bytes( $bytes ) {
		if ( $bytes >= 1073741824 ) return rtrim( rtrim( number_format( $bytes / 1073741824, 1 ), '0' ), '.' ) . ' GB';
		if ( $bytes >= 1048576 )    return rtrim( rtrim( number_format( $bytes / 1048576, 1 ), '0' ), '.' ) . ' MB';
		if ( $bytes >= 1024 )       return rtrim( rtrim( number_format( $bytes / 1024, 1 ), '0' ), '.' ) . ' KB';
		return $bytes . ' B';
	}

	private function humanize_days( $days ) {
		if ( $days % 365 === 0 ) { $n = $days / 365; return $n . ' year' . ( $n === 1 ? '' : 's' ); }
		if ( $days % 30 === 0 )  { $n = $days / 30;  return $n . ' month' . ( $n === 1 ? '' : 's' ); }
		if ( $days % 7 === 0 )   { $n = $days / 7;   return $n . ' week' . ( $n === 1 ? '' : 's' ); }
		return $days . ' day' . ( $days === 1 ? '' : 's' );
	}

	private function date_range_label( $range ) {
		$map = array(
			'today'  => 'today',
			'7days'  => 'in the last 7 days',
			'30days' => 'in the last 30 days',
			'90days' => 'in the last 90 days',
			'1year'  => 'in the last year',
		);
		return $map[ $range ] ?? '';
	}

	private function strip_stopwords( $text ) {
		$stop = array(
			'show', 'me', 'find', 'list', 'get', 'display', 'all', 'the', 'a', 'an',
			'my', 'any', 'with', 'that', 'are', 'is', 'of', 'in', 'on', 'and', 'files',
			'file', 'media', 'which', 'please', 'give', 'search', 'for', 'over',
		);
		$words = preg_split( '/\s+/', trim( $text ) );
		$kept  = array();
		foreach ( $words as $w ) {
			$w = preg_replace( '/[^a-z0-9\-\.]/', '', $w );
			if ( $w === '' || in_array( $w, $stop, true ) ) {
				continue;
			}
			$kept[] = $w;
		}
		return implode( ' ', $kept );
	}
}
