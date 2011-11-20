<?php

class phpSesame_SparqlRes
{
	private $xml;

	function __construct($responseBody)
	{
		$this->xml = simplexml_load_string($responseBody);
	}

	public function getHeaders()
	{
		$headers = array();
		foreach($this->xml->head->variable as $header)
		{
			$headers[] = (string) $header['name'];
		}

		return $headers;
	}

	/**
	 * Returns the rows in an array format, false if there are no rows.
	 *
	 * @return	mixed
	 */
	public function getRows()
	{
		$rows = array();
		foreach($this->xml->results->result as $result)
		{
			//print_r($result);
			$row = array();
			foreach($result->binding as $binding)
			{
				$name = (string) $binding['name'];
				if($binding->literal)
				{
					$row[$name] = (string) $binding->literal;
					$row[$name . '_type'] = 'literal';
				}
				else if($binding->uri)
				{
					$row[$name] = (string) $binding->uri;
					$row[$name . '_type'] = 'uri';
				}
				else if($binding->bnode)
				{
					$row[$name] = (string) $binding->bnode;
					$row[$name . '_type'] = 'bnode';
				}
			}
			$rows[] = $row;
		}

		if(sizeof($rows) <= 0)
		{
			return false;
		}

		return $rows;
	}


	/**
	 * Returns whether the result contains any rows
	 *
	 * @return	bool
	 */
	public function hasRows()
	{
		//return true;
		//print_r($this->xml->results);
		foreach($this->xml->results->result as $result)
		{
			foreach($result->binding as $binding) {
			//print_r($result->binding);
			if ((string) $binding->literal != "") return true;
			//if ((string) $result->binding->uri != "") return true;
			//if ((string) $result->binding->bnode != "") return true;
			}
		}
		
		return false;
	}
}
?>
