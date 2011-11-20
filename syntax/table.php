<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class syntax_plugin_semanticdata_table extends DokuWiki_Syntax_Plugin {

	/**
	 * will hold the data helper plugin
	 */
	var $dthlp = null;

	/**
	 * Constructor. Load helper plugin
	 */
	function syntax_plugin_semanticdata_table(){
		$this->dthlp =& plugin_load('helper', 'semanticdata');
	}

	/**
	 * What kind of syntax are we?
	 */
	function getType(){
		return 'substition';
	}

	/**
	 * What about paragraphs?
	 */
	function getPType(){
		return 'block';
	}

	/**
	 * Where to sort in?
	 */
	function getSort(){
		return 155;
	}

	/**
	 * Connect pattern to lexer
	 */
	function connectTo($mode) {
		$this->Lexer->addSpecialPattern('----+ *datatable(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',$mode,'plugin_semanticdata_table');
	}


	/**
	 * Handle the match - parse the data
	 *
	 * This parsing is shared between the multiple different output/control
	 * syntaxes
	 */
	function handle($match, $state, $pos, &$handler){
		// get lines and additional class
		$lines = explode("\n",$match);
		array_pop($lines);
		$class = array_shift($lines);
		$class = preg_replace('/^----+ *data[a-z]+/','',$class);
		$class = trim($class,'- ');

		$data = array('classes' => $class,
                      'limit'   => 0,
                      'headers' => array());

		// parse info
		foreach ( $lines as $line ) {
			// ignore comments
			$line = preg_replace('/(?<![&\\\\])#.*$/','',$line);
			$line = str_replace('\\#','#',$line);
			$line = trim($line);
			if(empty($line)) continue;
			$line = preg_split('/\s*:\s*/',$line,2);
			$line[0] = strtolower($line[0]);

			$logic = 'OR';
			// handle line commands (we allow various aliases here)
			switch($line[0]){
				case 'select':
				case 'cols':
				case 'field':
				case 'col':
					$cols = explode(',',$line[1]);
					foreach($cols as $col){
						$col = trim($col);
						if(!$col) continue;
						$column = $this->dthlp->_column($col);
						$data['cols'][$column['key']] = $column;
					}
					break;
				case 'title':
					$data['title'] = $line[1];
					break;
				case 'head':
				case 'header':
				case 'headers':
					$cols = explode(',',$line[1]);
					foreach($cols as $col){
						$col = trim($col);
						$data['headers'][] = $col;
					}
					break;
				case 'min':
					$data['min']   = abs((int) $line[1]);
					break;
				case 'limit':
				case 'max':
					$data['limit'] = abs((int) $line[1]);
					break;
				case 'order':
				case 'sort':
					$column = $this->dthlp->_column($line[1]);
					$sort = $column['key'];
					if(substr($sort,0,1) == '^'){
						$data['sort'] = array(substr($sort,1),'DESC');
					}else{
						$data['sort'] = array($sort,'ASC');
					}
					break;
				case 'where':
				case 'filter':
				case 'filterand':
				case 'and':
					$logic = 'AND';
				case 'filteror':
				case 'or':
					if(!$logic) $logic = 'OR';
					$flt = $this->dthlp->_parse_filter($line[1]);
					if(is_array($flt)){
						$flt['logic'] = $logic;
						$data['filter'][] = $flt;
					}
					break;
				case 'page':
				case 'target':
					$data['page'] = cleanID($line[1]);
					break;
				default:
					msg("data plugin: unknown option '".hsc($line[0])."'",-1);
			}
		}

		// we need at least one column to display
		if(!is_array($data['cols']) || !count($data['cols'])){
			msg('data plugin: no columns selected',-1);
			return null;
		}

		// fill up headers with field names if necessary
		$data['headers'] = (array) $data['headers'];
		$cnth = count($data['headers']);
		$cntf = count($data['cols']);
		for($i=$cnth; $i<$cntf; $i++){
			$item = array_pop(array_slice($data['cols'],$i,1));
			$data['headers'][] = $item['title'];
		}

		$data['sql'] = $this->_buildSQL($data);
		return $data;
	}

	protected $before_item = '<tr>';
	protected $after_item  = '</tr>';
	protected $before_val  = '<td>';
	protected $after_val   = '</td>';

	/**
	 * Create output
	 */
	function render($format, &$R, $data) {
		if($format != 'xhtml') return false;
		if(is_null($data)) return false;
		$R->info['cache'] = false;

		$store = $this->dthlp->_getTripleStore();
		$resultFormat = phpSesame::SPARQL_XML;
		$lang = "sparql";
		$infer = true;
		if(!$store) return false;

		$this->updateSQLwithQuery($data); // handles request params

		// run query
		$clist = array_keys($data['cols']);

		$res = $store->query($data['sql'],$resultFormat, $lang, $infer);

		if($res->hasRows())
		{
			$headers = $res->getHeaders();
			$R->doc .= $this->preList($clist, $data);

			foreach($res->getRows() as $row)
			{
				$R->doc .= $this->before_item;
				foreach(array_values($headers) as $num => $cval) {
					$R->doc .= $this->before_val;
					$values = explode(",",$row[$cval]);
					sort($values);
					$R->doc .= $this->dthlp->_formatData(
					$data['cols'][$clist[$num]],implode(",",$values),$R);
					$R->doc .= $this->after_val;
				}
				$R->doc .= $this->after_item;
			}
			$R->doc .= $this->postList($data, $cnt);
		}
		else {
			$this->nullList($data, $clist, $R);
			return true;
		}


		if ($data['limit'] && $cnt > $data['limit']) {
			$rows = array_slice($rows, 0, $data['limit']);
		}


		return true;
	}

	function preList($clist, $data) {
		global $ID;
		// build table
		$text = '<div class="table dataaggregation">'
		. '<table class="inline dataplugin_table '.$data['classes'].'">';
		// build column headers
		$text .= '<tr>';
		foreach($data['headers'] as $num => $head){
			$ckey = $clist[$num];

			$text .= '<th>';

			// add sort arrow
			if(isset($data['sort']) && $ckey == $data['sort'][0]){
				if($data['sort'][1] == 'ASC'){
					$text .= '<span>&darr;</span> ';
					$ckey = '^'.$ckey;
				}else{
					$text .= '<span>&uarr;</span> ';
				}
			}

			// keep url params
			$params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
			$params['datasrt'] = $ckey;
			$params['dataofs'] = $_REQUEST['dataofs'];

			// clickable header
			$text .= '<a href="'.wl($ID,$params).
                       '" title="'.$this->getLang('sort').'">'.hsc($head).'</a>';

			$text .= '</th>';
		}
		$text .= '</tr>';
		return $text;
	}

	function nullList($data, $clist, &$R) {
		$R->doc .= $this->preList($clist, $data);
		$R->tablerow_open();
		$R->tablecell_open(count($clist), 'center');
		$R->cdata($this->getLang('none'));
		$R->tablecell_close();
		$R->tablerow_close();
		$R->doc .= '</table></div>';
	}

	function postList($data, $rowcnt) {
		global $ID;
		$text = '';
		// if limit was set, add control
		if($data['limit']){
			$text .= '<tr><th colspan="'.count($data['cols']).'">';
			$offset = (int) $_REQUEST['dataofs'];
			if($offset){
				$prev = $offset - $data['limit'];
				if($prev < 0) $prev = 0;

				// keep url params
				$params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
				$params['datasrt'] = $_REQUEST['datasrt'];
				$params['dataofs'] = $prev;

				$text .= '<a href="'.wl($ID,$params).
                              '" title="'.$this->getLang('prev').
                              '" class="prev">'.$this->getLang('prev').'</a>';
			}

			$text .= '&nbsp;';

			if($rowcnt > $data['limit']){
				$next = $offset + $data['limit'];

				// keep url params
				$params = $this->dthlp->_a2ua('dataflt',$_REQUEST['dataflt']);
				$params['datasrt'] = $_REQUEST['datasrt'];
				$params['dataofs'] = $next;

				$text .= '<a href="'.wl($ID,$params).
                              '" title="'.$this->getLang('next').
                              '" class="next">'.$this->getLang('next').'</a>';
			}
			$text .= '</th></tr>';
		}

		$text .= '</table></div>';
		return $text;
	}

	/**
	 * Builds the SQL query from the given data
	 */
	function _buildSQL(&$data){
		$cnt    = 0;
		$tables = array();
		$select = array();
		$selectview = array();
		$wherefirst = '';
		$where = '';
		$order  = '';

		// prepare the columns to show
		foreach ($data['cols'] as &$col){
			$key = $col['key'];
			if($key == '%pageid%'){
				$select[] = '?page';
				$selectview[] = '?page';
				$wherefirst .= "{ ?pageurl rdfs:label ?page . }";

			}elseif($key == '%class%'){
				$select[] = '?class';
				$selectview[] = '?class';
				$wherefirst .= "{ ?pageurl spd:class ?class . }";
			}elseif($key == '%title%'){
				$select[] = '?title';
				$selectview[] = '?title';
				$wherefirst .= "{ ?pageurl spd:title ?title . }";
			}else{
				if(!isset($tables[$key])){
					$tables[$key] = 'T'.(++$cnt);
					if ($where != '') $where .= " UNION ";
					$where .= sprintf('{ ?pageurl <%s%s> ?%s . }',$this->getConf('base_url'),urlencode($key),$tables[$key]);
				}
				$type = $col['type'];
				if (is_array($type)) $type = $type['type'];
				
				
				$select[] = sprintf('(GROUP_CONCAT(DISTINCT ?%s ; SEPARATOR=",") AS ?%ss)',$tables[$key],$tables[$key]);
				$selectview[] = "?".$tables[$key]."s";
								
				if ($type=='pageid') $col['type'] = 'title';
			}
		}
		unset($col);

		// prepare sorting
		if(isset($data['sort'])){
			$col = $data['sort'][0];

			if($col == '%pageid%'){
				$order = 'ORDER BY '.$data['sort'][1].'(?page)';
			}elseif($col == '%class%'){
				$order = 'ORDER BY '.$data['sort'][1].'(?class)';
			}elseif($col == '%title%'){
				$order = 'ORDER BY '.$data['sort'][1].'(?title)';
			}else{
				// sort by hidden column?
				if(!$tables[$col]){
					$tables[$col] = 'T'.(++$cnt);
					$select[] = "(GROUP_CONCAT(DISTINCT ?".$tables[$col]." ; SEPARATOR=\",\") AS ?".$tables[$col]."s)";
					if ($where != '') $where .= " UNION ";
					$where .= sprintf('{ ?pageurl <%s%s> ?%s . }',$this->getConf('base_url'),urlencode($col),$tables[$col]); 
				}
				$order = sprintf('ORDER BY %s(?%ss)',$data['sort'][1],$tables[$col]);

			}
		}else{
			$order = 'ORDER BY ASC(?page)';
		}

		// add request filters
		if (!isset($data['filter'])) $data['filter'] = array();
		$data['filter'] = array_merge($data['filter'], $this->dthlp->_get_filters());

		// prepare filters
		if(is_array($data['filter']) && count($data['filter'])){
			$wherefilter  = '{';
			foreach($data['filter'] as $filter){
				$col = $filter['key'];

				if ($filter['logic'] == 'OR') $wherefilter .= '} UNION {';


				$predicate = '';
				if ($col == '%pageid%') $predicate = 'rdfs:label';
				else {
					if ($col == '%class%') $predicate = 'spd:class';
					else {
						if ($col == '%title%') $predicate = 'spd:title';
						else {
							if(!$tables[$col]) $tables[$col] = 'T'.(++$cnt);
							$predicate = sprintf('<%s%s>',$this->getConf('base_url'),urlencode($col));
						}
					}
				};
					
				//value is already escaped

				switch ($filter['compare']) {
					case '=':
					// seems not necessary, performance impact
					//	if ($filter['value']=="") 
					//		$wherefilter .= sprintf(' { OPTIONAL { ?pageurl %s ?%s_value . } FILTER (!bound(?%s_value) || str(?%s_value)="") }',$predicate, $col, $col, $col);
					//	else
							$wherefilter .= sprintf('{ ?pageurl %s "%s" . }',$predicate, $filter['value']);
						break;
					case 'LIKE':
						$wherefilter .= sprintf('{ ?pageurl %s ?%s_value . FILTER regex(?%s_value,"^%s$") }',$predicate,$col,$col,addslashes(str_replace('%','.*',$filter['value'])));
						break;
					case 'NOT LIKE':
						$wherefilter .= sprintf('{ ?pageurl %s ?%s_value . FILTER (! regex(?%s_value,"^%s$")) }',$predicate,$col,$col,addslashes(str_replace('%','.*',$filter['value'])));						
						break;
					default:						
						$wherefilter .= sprintf('{ ?pageurl %s ?%s_value . FILTER (?%s_value %s "%s") }',$predicate,$col,$col,$filter['compare'],addslashes($filter['value']));
				}
			}
		}
		$wherefilter  .= '}';

		// build the query

		$sql =
        	"PREFIX rdfs:<http://www.w3.org/2000/01/rdf-schema#> ".
        	sprintf("PREFIX spd:<%s> ",$this->getConf('base_url')).
			"SELECT ".join(' ',$selectview)." WHERE {".		//keys to display
        	"SELECT DISTINCT ".join(' ',$select).
        	"WHERE {".$wherefirst."{".$where."} ".$wherefilter."} GROUP BY ?page ".$order.
			"}";
			

		// offset and limit
		if($data['limit']){
			$sql .= ' LIMIT '.($data['limit'] + 1);
			// offset is added from REQUEST params in updateSQLwithQuery
		}
		return $sql;
	}

	function updateSQLwithQuery(&$data) {
		// take overrides from HTTP request params into account
		if(isset($_REQUEST['datasrt'])){
			if($_REQUEST['datasrt']{0} == '^'){
				$data['sort'] = array(substr($_REQUEST['datasrt'],1),'DESC');
			}else{
				$data['sort'] = array($_REQUEST['datasrt'],'ASC');
			}
			// Rebuild SQL FIXME do this smarter & faster
			$data['sql'] = $this->_buildSQL($data);
		}

		if($data['limit'] && (int) $_REQUEST['dataofs']){
			$data['sql'] .= ' OFFSET '.((int) $_REQUEST['dataofs']);
        }
    }
}

