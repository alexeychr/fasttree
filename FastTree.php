<?php
/* *********************************************************************************************** 
 * 
 * Initial project name: Optik 
 * Copyright (c) dev.osiacat.ru, 2007
 * 
 * **********************************************************************************************
 *
 * Created on 27.03.2007 1:04:54 by Phoebus
 * 
 ************************************************************************************************/

/**
 * DatabaseFastTree library
 * Version 3.0a
 * 
 * !TODO Think over mergin FastTree and AdjacencyMatrixTree into one class due it is a nonsense to
 * put the caching logic to FastTree class. Or not?..
 */
class FastTree extends AdjacencyMatrixTree {

	/**
	 * A name of a virtual column that will represent the levels of each node in the result sets.
	 */
	const VIRTUAL_COLUMN = "___currentNodeLevel";

	/**
	 * Name of a field used for storing a path chunk that describes the uniqness of a node on the
	 * level it is situated.
	 */	
	public $DbColumnPath;
	
	/**
	 * Name of a field used for storing an  absolte path
	 */		
	public $DbColumnPathCache;

	/**
	 * Takes the descendatns the node specified by path downto the mentioned depth value. Returns
	 * collection of descendants on success, or AdjacencyMatrixTree::BROKEN_NODE if the specified
	 * node does no exist. Each descendant represent a hash with the fields fetched from the
	 * databases according to the secong argument.
	 */
	public function GetNodeDescendantsByPath($path,
											 array $columns = array(),
											 $depth = AdjacencyMatrixTree::DEPTH_INFINITY,
											 $limit = AdjacencyMatrixTree::LIMIT_INFINITY){
		$path  = $this->preparePath($path);
		$nodes = explode('/', $path);
		$maxlevel = 0;
 
		$rows = $this->FetchDescendantsByPath($path, $columns, $maxlevel, $depth, $limit);

		$itemCount = count($rows);

		//if the node has no children, fetchNodes() return an array
		// with 1 elemnt -- the node itself.
		// if the resulting array is empty, smth gone wrong
		if( $itemCount == 0 ){
			$rows = AdjacencyMatrixTree::BROKEN_NODE;
		}
		//ok, the array has one element: it could be the node
		//itself or a single child-node. Let's compare the requested
		//path and max level we reached
		elseif( $itemCount == 1 && $maxlevel-1 != count($nodes) ){
			// As the node has no PopChildren, we could return empty
			// array -- it is what we expect to see
			$rows = array();
		}

		return $rows;
	}

	protected function StoreNodeToPathCache($id,$path){
		if( $this->DbColumnPathCache && $id ){
			$this->db->QueryAsync('
				UPDATE ?#
				SET
				 ?# = ?
				WHERE
				 ?# = ?',
				 	$this->getDbTable(),
				 	$this->DbColumnPathCache,
				 	strim($path),
				 	$this->getColumnId(),
				 	$id);
		}
	}
	
	protected function GetNodeByPathCache($path,array $fields){
		if( $this->DbColumnPathCache ){
			$node = $this->db->SelectRow('
				SELECT
					?#
				FROM
					?#
				WHERE
					?# = ?',
						$fields,
						$this->getDbTable(),
						$this->DbColumnPathCache,
						strim($path));
			if( intval($node) > 0 ){
				return $node;
			}
		}		
	}

	public function GetNodeByPath($path,array $fields){		
		$path  = $this->preparePath($path);
		//cache-control
		if( $node = $this->GetNodeByPathCache($path,$fields) ){
			return $node;
		}
		$nodes = explode('/', $path);
		// &$maxlevel -- level of the node we have found
		// $depth -- there is no need to fetch all child-nodes =)
		// $limit -- at least single node is needed (child -- if
		//   there are more than 0 PopChildren or the requested node
		//   itself -- if the requested node has no PopChildren)
		$maxlevel=0;
		$rows = $this->FetchDescendantsByPath($path,$fields,$maxlevel,1,1);

		$itemCount = count($rows);

		if( $itemCount == 0 ){
			$id = AdjacencyMatrixTree::BROKEN_NODE;
		}
		elseif( $itemCount == 1 && $maxlevel-1 != count($nodes) ){
			// This node has no subnodes, so we take its ID and return
			$node = $rows[0];
		}
		else{
			$node = $this->DB->SelectRow('
				SELECT
				  ?#
				FROM
				  ?#
				WHERE
				  ?#  = ?',
				  	$fields,
				  	$this->getDbTable(),
				  	$this->getColumnId(),
				  	$rows[0][$this->getColumnParentId()]);
		}
		
		if( $id != AdjacencyMatrixTree::BROKEN_NODE ){
			//save it to cache
			$this->StoreNodeToPathCache($node[$this->getColumnId()],$path);
		}

		return $node;
	}

	/**
	 * Returns the ID of the node specified by $path. If the path is invalid (i.e. speciefied node
	 * does not exists), AdjacencyMatrixTree::BROKEN_NODE is returned.
	 */
	public function GetNodeIdByPath($path){
		$path  = $this->preparePath($path);
		//cache-control
		if( $node = $this->GetNodeByPathCache($path,array($this->getColumnId())) ){
			return $node[$this->getColumnId()];
		}
		$nodes = explode('/', $path);

		// &$maxlevel -- level of the node we have found
		// $depth -- there is no need to fetch all child-nodes =)
		// $limit -- at least single node is needed (child -- if
		//   there are more than 0 PopChildren or the requested node
		//   itself -- if the requested node has no PopChildren)
		$maxlevel=0;
		$rows = $this->FetchDescendantsByPath($path,array(),$maxlevel,1,1);

		$itemCount = count($rows);

		if( $itemCount == 0 ){
			$id = AdjacencyMatrixTree::BROKEN_NODE;
		}
		elseif( $itemCount == 1 && $maxlevel-1 != count($nodes) ){
			// This node has no subnodes, so we take its ID and return
			$id = $rows[0][$this->getColumnId()];
		}
		else{
			// Oh, this node has subnodes... take any subnode (for example,
			// the first) and return it's PARENTID!
			$id = $rows[0][$this->getColumnParentId()];
		}
		
		if( $id != AdjacencyMatrixTree::BROKEN_NODE ){
			//save it to cache
			$this->StoreNodeToPathCache($id,$path);
		}	

		return $id;
	}

	/**
	 * Fetches the descendants of the node specified by path.
	 */
	private function FetchDescendantsByPath($path, $columns, &$maxlevel, $depth, $limit){

		// Check the cols to select
		$columns[] = $this->GetColumnId();
		$columns[] = $this->getColumnParentId();
		$columns[] = $this->DbColumnOrderBy;
		$fConds  = $this->EscapeColNames(array_keys($this->getDbConditions()));
		$columns = array_merge($columns,$fConds);
		$columns = array_unique($columns);

		// Search beginning from the root node
		$searchFromParent = false;

		// Prepare path
		$nodes = explode('/', $path);

		if( substr($path, 0, 1) === '/' ){
			$searchFromParent = true;
			array_shift($nodes);
			if( $nodes[count($nodes)-1] === '' || is_null($nodes[count($nodes)-1]) ){
				array_pop($nodes);
			}
		}

		// Set the depth according to the request
		if( $depth < 1 )
			$depth = 1;
		$depth += count($nodes)-1;

		$joins = $wheres =
			$levels = // Level increments
				$fields = array(); // if()`s

		for( $i = 0; $i < $depth; $i++ ){
			// db_table aliases for JOIN-conditions
			$alias     = "tbl" . sprintf("%04d", $i);
			$prevAlias = $i>0 ? "tbl" . sprintf("%04d", $i-1) : null;

			// List of cols for current alias
			foreach( $columns as $column ){
				$fields[] =
					$this->db->EscapeName($alias).".".$this->db->EscapeName($column)
					." AS ".$this->db->EscapeName($i.".".$column);
			}

			// Fasten to the root node
			if( !$prevAlias && $searchFromParent ){
				$wheres[] =
					$this->db->EscapeName($alias).".".$this->db->EscapeName($this->GetColumnParentId())
					." IS NULL";
			}

			// Selection conditions
			if( isset($nodes[$i]) && @$nodes[$i] != "" ){
				$wheres[] =
					$this->db->EscapeName($alias).".".$this->db->EscapeName($this->DbColumnPath)
					." LIKE " . $this->db->EscapeValue($nodes[$i]);
			}

			$levels[] = "
				CASE
					WHEN 
						".$this->db->EscapeName($alias).".".$this->db->EscapeName($this->getColumnId())."
						IS NOT NULL
					THEN 1
					ELSE 0
				END";

			// Begin joining only after second db_table
			if($prevAlias){
				$joins[] = "
					LEFT JOIN 
						".$this->db->EscapeName($this->getDbTable())." ".$this->db->EscapeName($alias)."
					ON (
						".$this->db->EscapeName($alias).".".$this->db->EscapeName($this->GetColumnParentId())."
						= ".$this->db->EscapeName($prevAlias).".".$this->db->EscapeName($this->getColumnId())
					.")";
			}
			else{
				$joins[] = $this->db->EscapeName($this->getDbTable()) . " " . $this->db->EscapeName($alias);
			}
		}

		$havings = array();
		foreach( $this->getDbConditions() as $k=>$v ){
			$havings[ ($depth-1).'.'.$k ] = $v;
		}

		$j_levels = implode(" + ", $levels);
		$j_fields = implode(" , ", $fields);
		$j_joins  = implode(" ", $joins);
		$j_wheres = implode(" AND ", $wheres);
		$j_having = sizeof($havings) > 0 ? ' HAVING '.join(" AND ",$this->EscapeSet($havings)) : "";
		$j_limit  = $limit>0 ? "LIMIT $limit" : "";
		if( $this->DbColumnOrderBy != self::VIRTUAL_COLUMN ){
			$j_order = ' ORDER BY '.$this->db->EscapeName(($depth-1).".".$this->DbColumnOrderBy). ' ASC ';
		}

		$sql= "
			SELECT
				$j_levels AS ".$this->db->EscapeName(self::VIRTUAL_COLUMN).",
				$j_fields
			FROM
				$j_joins
			WHERE
				$j_wheres

			$j_having

			$j_order

			$j_limit";


		$rows = array();
		foreach( (array)$this->db->Select($sql) as $row ){
			$tmparr = array();
			foreach( (array)$row as $k=>$v ){
				// cleaning..
				// cut out empty rows
				if( ( false === strpos($k, ".".self::VIRTUAL_COLUMN) || $k == '0.'.$this->getColumnId())
						&& $v != '' ){
					// Syntax of each key is: <num of level>.<column>
					// so, crop nums
					$k = preg_replace('/^\d+\.(.+)$/', '\\1', $k);
					$tmparr[$k] = $v;
				}
			}
			$rows[] = $tmparr;
			$maxlevel = max($maxlevel, $row[self::VIRTUAL_COLUMN]);
		}
		return $rows;
	}

	/**
	 * Moves a node (specified by $id) and its' descendants to the parent node specified by
	 * $dest_pid. This method overrides a native method because caching fields introduced by the
	 * FastTree class should be updated manually after copying.
	 */
	public function MoveNode($id,$dest_pid){
		//!TODO Implement method
	}

	/**
	 * Copies a node (specified by $id) and its' descendants to the parent node specified by
	 * $dest_pid. This method overrides a native method because caching fields introduced by the
	 * FastTree class should be updated manually after copying.
	 */
	public function CopyNode($id,$dest_pid){
		//!TODO Implement method
	}


	private function PreparePath($string){
		$string = strim($string);
		if( $string == '' ){
			$string = '/';
		}

		return $string;
	}

} // EOC { FastTree }


?>