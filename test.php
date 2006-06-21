<?php

$this->tree = new FastTree($this->srh->db,"catalog","ctatlog_node_id","catalog_node_parentid");
$this->tree->DbColumnPath = "catalog_node_url";
$this->tree->DbColumnOrderBy = "catalog_node_title";
$node = $this->tree->GetNodeByPath('/'.$this->uri,array('c_id','c_title','c_description','c_pid','c_url'));
//more details at http://forum.dklab.ru/sql/php/IerarhicheskieStrukturiVBd.html
?>