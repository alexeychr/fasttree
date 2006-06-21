<?php

/**
 * AdjacencyMatrixTree
 */
class AdjacencyMatrixTree {

	const LIMIT_INFINITY = -1;
	const DEPTH_INFINITY = -1;
	const BROKEN_NODE    = false;

	/**
	 * Database object
	 */
	protected $db;

	/**
	 * Name of the table that stores a tree structure to be traversed.
	 */
	private $dbTable;
	
	/**
	 * Getter for AdjacencyMatrixTree::$dbTable
	 */
	protected function getDbTable(){
		return $this->dbTable;
	}

	/**
	 * Hash of the conditions, that are to be passed when traversing the tree. It is very useful
	 * when you store different type of nodes within one table (for example, directories and
	 * files) and wish to get only directories.
	 */
	private $dbConditions = array();
	
	/**
	 * Getter for AdjacencyMatrixTree::$dbConditions
	 */
	protected function getDbConditions(){
		return $this->dbConditions;
	}

	/**
	 * Name of a field used for storing the unique identifier of a node.
	 */
	private $columnId;
	
	/**
	 * Getter for AdjacencyMatrixTree::$columnId
	 */
	protected function getColumnId(){
		return $this->columnId;
	}

	/**
	 * Name of a field used for storing the identifier of a parent node
	 */
	private $columnParentId;
	
	/**
	 * Getter for AdjacencyMatrixTree::$columnParentId
	 */
	protected function getColumnParentId(){
		return $this->columnParentId;
	}

	/**
	 * Name of a field that is used for sorting the results. Default is AdjacencyMatrixTree::
	 * columnId.
	 */
	public $DbColumnOrderBy;

	public function __construct(SQLWorkbench $db,$dbTable,$columnId,$columnParentId){
		$this->db = $db;
		$this->dbTable = $dbTable;
		$this->columnId = $columnId;
		$this->columnParentId = $columnParentId;
	}

	public function SetConditions(array $conditions){
		$this->dbConditions = $conditions;
	}
	
	public function GetConditions(){
		return $this->dbConditions;
	}

	/**
	 * Moves a node (specified by $id) and its' descendants to the parent node specified by
	 * $dest_pid. This method overrides a native method because caching fields introduced by the
	 * FastTree class should be updated manually after copying.
	 */
	public function MoveNode($id,$dest_pid){
		try{
			$this->db->Query(
				'UPDATE ? SET ?# = ? WHERE ?# = ?',
					$this->getDbTable(),
					$this->getColumnParentId(), $dest_pid,
					$this->getColumnId(), $id);
		}
		catch( SQLWorkbenchException $e ){
			//$id does not exist or $dest_pid is invalid (constraint fails)
			return false;
		}
		return true;
	}

	/** 
	 * Copies a node (specified by $id) and its' descendants to the parent node specified by
	 * $dest_pid. This method overrides a native method because caching fields introduced by the
	 * FastTree class should be updated manually after copying.
	 *
	 * @return bool
	 */
	public function CopyNode($id,$dest_pid){
		$subtree = $this->Tree2Stack($id);
		//fetching column names
		$cols = $this->db->SelectRow('SHOW COLUMNS FROM ?#',$this->getDbTable());
		unset($cols[ array_search($this->getColumnId(),$cols) ]);
		unset($cols[ array_search($this->getColumnParentId(),$cols) ]);
		$this->CopyHandler($subtree,$dest_pid,$cols);
	}

	private function CopyHandler($stack,$pid,array $column_names){
		foreach( $stack as $k=>$v ){
			$pairs = array();
			$pairs = $this->db->SelectRow('
				SELECT ?# FROM ?# WHERE ?# = ?',
					$column_names, $this->getDbTable(),
					$this->getColumnId(), $k);
			$pairs[ $this->getColumnParentId() ] = $pid;

			$this->db->Query('
				INSERT INTO ?# SET ?a',
					$this->getDbTable(), $pairs);
			if( sizeof($v) > 0 ){
				$this->CopyHandler($v,$this->db->SelectCell('SELECT LAST_INSERT_ID()'),$column_names);
			}
		}
	}

	/**
	 * Builds a recursive stack that represents a tree structure. Keys of the resulting array
	 * represent node ID's and the values represent the arrays of descendant nodes (certanly,
	 * could be empty if there is no subnodes -- i.e. has a sizeof == 0).
	 *
	 * Example:
	 * The tree has the following structure:
	 * <code>
	 * 	 Array(
	 *	  Array(
	 *		  "id"=>1,
	 *		  "parentId"=>0,
	 *		  "name"=>"rootnode",
	 *	  ),
	 *	  Array(
	 *		  "id"=>2,
	 *		  "parentId"=>0,
	 *		  "name"=>"rootnode2",
	 *	  ),
	 *	  Array(
	 *		  "id"=>3,
	 *		  "parentId"=>1,
	 *		  "name"=>"node_3",
	 *	  ),
	 *	  Array(
	 *		  "id"=>4,
	 *		  "parentId"=>1,
	 *		  "name"=>"node_4",
	 *	  ),
	 *	  Array(
	 *		  "id"=>5,
	 *		  "parentId"=>2,
	 *		  "name"=>"node_5",
	 *	  ),
	 *	 );
	 * </code>
	 * Let's print the stack:
	 * <code>
	 *  $result = $tree->Tree2Stack();
	 *  print_r($result);
	 *  //Result:
	 *  Array(
	 *	 1 => Array(
	 *	   3 => Array(
	 *	   ),
	 *	   4 => Array(
	 *	   ),
	 *	 ),
	 *	 2 => Array(
	 *	   5 => Array(
	 *	   ),
	 *	 ),
	 *  );
	 * </code>
	 * 
	 * It means that we get an easy-to-dump tree. If we specify an $id than we'll get a tree
	 * beginning from a node with that $id. For example:
	 * 
	 * <code>
	 *   $result   = $tree -> Tree2Stack($nodes, 1);
	 *   print_r  ($result);
	 *   //Result:   
	 *   Array   (
	 *  	 1 => Array(
	 *  	   3 => Array( ),
	 *  	   4 => Array( ), 
	 *       ),
	 *   )
	 * </code>
	 */
	public function Tree2Stack($id = null){
		$struct = $this->Tree2Struct($this->fetchAll(),false);
		return (array)$this->Tree2StackHandler($struct,$id);
	}

	/**
	 * Tree2Array([ $id = null, [, array $fetch_columns = array() [, $depth_limit = 0 ]]]) 
	 * 
	 * Similiar to Tree2Stack() method excepting that it returns a 1D-collection of nodes
	 * reordered  for plain tree dump using the additionally added parameters:  - 'level'  --
	 * additional specificator for the level of current node starting from the top node  -
	 * 'number' -- additional specificator that shows the number of descendants of the current
	 * node E. g.:
	 * 
	 * <code>
	 * 	print_r( $TREE->Tree2Array(null) );
	 *  //echoes:
	 *		Array(
	 *			[0] => Array(
	 *				'id'=>1,
	 *				'pid'=>null,
	 *				'atom'=>'root',
	 *				'level'=>0,
	 *				'number'=>3,
	 *			),
	 *			[1] => Array(
	 *				'id'=>2,
	 *				'pid'=>1,
	 *				'atom'=>'t1',
	 *				'level'=>1,
	 *				'number'=>0,
	 *			),
	 *			[2] => Array(
	 *				'id'=>3,
	 *				'pid'=>1,
	 *				'atom'=>'t1',
	 *				'level'=>1,
	 *				'number'=>1,
	 *			),
	 *			[3] => Array(
	 *				'id'=>4,
	 *				'pid'=>3,
	 *				'atom'=>'t2',
	 *				'level'=>2,
	 *				'number'=>0,
	 *			),
	 *		);
	 * </code>
	 */
	public function Tree2Array(
								$id = null,array $fetch_columns = array(),
								$depth_limit = AdjacencyMatrixTree::DEPTH_INFINITY){
		//!TODO implement method
	}

	/**
	 * Fetches the descendants of the node specified by $id (including the nested nodes).
	 * Returns an array of all possible _children of a node specified by node id.
	 */
	public function GetNodeDescendantsById($id, array $fetch_columns = Array()){
		$struct = $this->Tree2Struct($this->fetchAll($fetch_columns),false);
		return $this->PopChildren($struct,$id);
	}

	/**
	 * Fetches the parents of a node specified by $id and returns the array of fetched nodes with
	 * the database fields' values marked by $fetch_columns
	 *
	 * @param $id -- a valid ID of the node
	 * @param $columns -- a collection of columns to be fetched and returned with each node.
	 * 
	 * E.g., if the tree has the following structure:
	 * 
	 * <code>
	 *		Array(
	 *			Array(
	 *				"id"=>1,
	 *				"parentId"=>0,
	 *				"name"=>"rootnode",
	 *			),
	 *			Array(
	 *				"id"=>2,
	 *				"parentId"=>0,
	 *				"name"=>"rootnode2",
	 *			),
	 *			Array(
	 *				"id"=>3,
	 *				"parentId"=>1,
	 *				"name"=>"node_3",
	 *			),
	 *			Array(
	 *				"id"=>4,
	 *				"parentId"=>1,
	 *				"name"=>"node_4",
	 *			),
	 *			Array(
	 *				"id"=>5,
	 *				"parentId"=>2,
	 *				"name"=>"node_5",
	 *			),
	 *		);
	 * </code>
	 * 
	 * then the result would be the following:
	 * 
	 * <code>
	 *		$result = $tree -> getNodeParentsById(3, Array("name"));
	 *		print_r($result);
	 *		//Prints:
	 *		Array(
	 *			 Array(
	 *				"id"=>1,
	 *				"parentId"=>0,
	 *				"name"=>"rootnode",
	 *			),
	 *			Array(
	 *				"id"=>3,
	 *				"parentId"=>1,
	 *				"name"=>"node_3",
	 *			),
	 *		)
	 * </code>
	 */
	public function GetNodeParentsById($id,array $fetch_columns = array()){
		$struct = $this->Tree2Struct($this->fetchAll($fetch_columns),true);
		return $this->PopParents($struct,$id);
	}

	//
	// Private
	//

	/**
	 * Tree recursive handler, that is used for representing a tree structure.
	 */
	private function Tree2StackHandler($struct,$id){
		$eax_stack = array();

		foreach( $struct[$id] as $key => $val ){
			// Here we do our recursion
			$eax_stack[$key] = ( isset($struct[$key] )? $this->Tree2StackHandler($struct,$key) 
													  : array());
		}

		return (array)$eax_stack;
	}

	protected function EscapeColNames(array $columns){
		$columns_ready = array();
		foreach( $columns as $c ){
			$columns_ready[] = $this->db->EscapeName($c);
		}
		return $columns_ready;
	}

	protected function EscapeValues(array $values){
		$values_ready = array();
		foreach( $values as $c ){
			$values_ready[] = $this->db->EscapeName($c);
		}
		return $values_ready;
	}

	protected function EscapeSet(array $columns_values){
		$set = array();
		foreach( $columns_values as $column=>$value ){
			$set[]= $this->db->EscapeName($column).' = '.$this->db->EscapeValue($value);
		}
		return $set;
	}

	/**
	 * Pops the parent nodes of a node specified by $id from the stack
	 */
	private function PopParents($struct,$id){
		$result = array();
		if( $id > 0 ){
			reset($struct[$id]);
			$pid = key($struct[$id]);
			$result[] = current($struct[$id]);

			// Recursion
			foreach( array_reverse($this->PopParents($struct,$pid)) as $k ){
				$result[] = $k;
			}
			
		}
		return array_reverse($result);
	}

	/**
	 * Pops the descendants of a node specified by $id from the stack
	 */
	private function PopChildren($struct,$id){
		$eax = array();
		foreach( $struct[$id] as $key => $val ){
			$eax[] = $val;		
		
			if( isset($struct[$key]) ){
				$eax = array_merge($eax,$this->PopChildren($struct,$key));
			}
		}

		return (array)$eax;
	}

	private function Tree2Struct(array $rows,$direct = false){
		$data = array();
		foreach( $rows as $row ){
			$id  = $row[$this->columnId];
			$pid = $row[$this->columnParentId];
			if( $direct ){
				$data[$id][$pid] = $row;
			}
			else{
				$data[$pid][$id] = $row;
			}
		}
		return $data;	
	}

	/**
	 * Fetches node structure from the database for the heap. The resuls a cached according to the
	 * self::$dbConditions and $fetch_columns array (that holds the names of the columns that should be
	 * fetched too). To avoid that use AdjacencyMatrixTree::ClearCache()
	 */
	private function fetchAll(array $fetch_columns = Array()){
		$fetch_columns[] = $this->columnId;
		$fetch_columns[] = $this->columnParentId;
		$fetch_columns = array_unique($fetch_columns);
		sort($fetch_columns);
		$md = md5(join(":",$fetch_columns));
		if( !isset($this->queryCache[$md]) ){
			$sql = '
				SELECT
					' . implode(', ', $this->EscapeColNames($fetch_columns)) . '
				FROM 
					' . $this->db->EscapeName($this->dbTable).'
					' . (
						sizeof($this->dbConditions) > 0
							? 'WHERE '.join(' AND ',$this->EscapeSet($this->dbConditions)) 
							: ''
					) . '
				ORDER BY 
					' . $this->db->EscapeName($this->columnId);

			$this->queryCache[$md] = $this->db->Select($sql);
		}
		return (array)$this->queryCache[$md];
	}

	public function ClearCache(){
		$this->queryCache = array();
	}


} // EOC { AdjacencyMatrixTree }

?>