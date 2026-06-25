<?php
namespace MediaUsageTracker;

/**
 * Lightweight XLSX writer — no external library required.
 *
 * Usage:
 *   $xls = new Excel_Export( 'Sheet1' );
 *   $xls->add_header_row( ['Col A', 'Col B'] );
 *   $xls->add_row( ['value 1', 'value 2'] );
 *   $xls->send( 'filename.xlsx' );   // streams & exits
 */
class Excel_Export {

	/** @var string */
	private $sheet_title;

	/** @var array[] */
	private $rows = array();

	public function __construct( $sheet_title = 'Export' ) {
		$this->sheet_title = substr( preg_replace( '/[\/\\\\?\*\[\]:]/', '', $sheet_title ), 0, 31 );
	}

	/** Add a bold header row (must be called before add_row). */
	public function add_header_row( array $cols ) {
		$this->rows[] = array( 'data' => $cols, 'header' => true );
	}

	/** Add a regular data row. */
	public function add_row( array $cols ) {
		$this->rows[] = array( 'data' => $cols, 'header' => false );
	}

	/**
	 * Stream the file to the browser and exit.
	 *
	 * @param string $filename e.g. 'report-2025-01-01.xlsx'
	 */
	public function send( $filename ) {
		$xlsx = $this->build();

		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $xlsx ) );
		header( 'Cache-Control: max-age=0' );

		echo $xlsx; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function build() {
		$sheet_xml   = $this->build_sheet();
		$styles_xml  = $this->build_styles();
		$strings_xml = $this->build_shared_strings();

		$tmp = tempnam( sys_get_temp_dir(), 'mut_xlsx_' );
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::OVERWRITE );

		$zip->addFromString( '_rels/.rels',                         $this->rels_root() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels',          $this->rels_workbook() );
		$zip->addFromString( '[Content_Types].xml',                 $this->content_types() );
		$zip->addFromString( 'xl/workbook.xml',                     $this->workbook_xml() );
		$zip->addFromString( 'xl/styles.xml',                       $styles_xml );
		$zip->addFromString( 'xl/sharedStrings.xml',                $strings_xml );
		$zip->addFromString( 'xl/worksheets/sheet1.xml',            $sheet_xml );

		$zip->close();

		$data = file_get_contents( $tmp );
		unlink( $tmp );
		return $data;
	}

	/** Shared string table — every unique cell value is referenced by index. */
	private $strings     = array();
	private $string_map  = array();

	private function str_index( $value ) {
		$key = (string) $value;
		if ( ! isset( $this->string_map[ $key ] ) ) {
			$this->string_map[ $key ] = count( $this->strings );
			$this->strings[]          = $key;
		}
		return $this->string_map[ $key ];
	}

	private function build_sheet() {
		$col_count = 0;
		foreach ( $this->rows as $r ) {
			$col_count = max( $col_count, count( $r['data'] ) );
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
		$xml .= ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

		// Freeze header row
		$xml .= '<sheetViews><sheetView workbookViewId="0">';
		$xml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
		$xml .= '</sheetView></sheetViews>';

		// Auto-width hint (approximate)
		$col_widths = array_fill( 0, $col_count, 12 );
		foreach ( $this->rows as $r ) {
			foreach ( $r['data'] as $ci => $val ) {
				$len = mb_strlen( (string) $val );
				if ( $len + 2 > $col_widths[ $ci ] ) {
					$col_widths[ $ci ] = min( $len + 2, 60 );
				}
			}
		}
		$xml .= '<cols>';
		foreach ( $col_widths as $ci => $w ) {
			$n    = $ci + 1;
			$xml .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" bestFit="1" customWidth="1"/>';
		}
		$xml .= '</cols>';

		$xml .= '<sheetData>';

		foreach ( $this->rows as $ri => $row ) {
			$row_num  = $ri + 1;
			$is_hdr   = $row['header'];
			$xml     .= '<row r="' . $row_num . '">';

			foreach ( $row['data'] as $ci => $value ) {
				$col_letter = $this->col_letter( $ci );
				$cell_ref   = $col_letter . $row_num;
				$si         = $this->str_index( $value );
				$style      = $is_hdr ? '1' : '0';
				$xml       .= '<c r="' . $cell_ref . '" t="s" s="' . $style . '">';
				$xml       .= '<v>' . $si . '</v>';
				$xml       .= '</c>';
			}

			$xml .= '</row>';
		}

		$xml .= '</sheetData>';
		$xml .= '<autoFilter ref="A1:' . $this->col_letter( $col_count - 1 ) . '1"/>';
		$xml .= '</worksheet>';

		return $xml;
	}

	private function build_shared_strings() {
		$count = count( $this->strings );
		$xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$xml  .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
		$xml  .= ' count="' . $count . '" uniqueCount="' . $count . '">';
		foreach ( $this->strings as $s ) {
			$xml .= '<si><t xml:space="preserve">' . htmlspecialchars( $s, ENT_XML1, 'UTF-8' ) . '</t></si>';
		}
		$xml .= '</sst>';
		return $xml;
	}

	private function build_styles() {
		// Style index 0 = normal, 1 = bold (header)
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Arial"/></font>
    <font><b/><sz val="11"/><name val="Arial"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF2271B1"/></patternFill></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1">
      <alignment horizontal="left"/>
    </xf>
  </cellXfs>
</styleSheet>';
	}

	private function workbook_xml() {
		$title = htmlspecialchars( $this->sheet_title, ENT_XML1, 'UTF-8' );
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="' . $title . '" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';
	}

	private function content_types() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
	}

	private function rels_root() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="xl/workbook.xml"/>
</Relationships>';
	}

	private function rels_workbook() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
    Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"
    Target="sharedStrings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"
    Target="styles.xml"/>
</Relationships>';
	}

	/** Convert 0-based column index to Excel letter(s): 0→A, 25→Z, 26→AA … */
	private function col_letter( $index ) {
		$letter = '';
		$index++;
		while ( $index > 0 ) {
			$index--;
			$letter = chr( 65 + ( $index % 26 ) ) . $letter;
			$index  = (int) ( $index / 26 );
		}
		return $letter;
	}
}
