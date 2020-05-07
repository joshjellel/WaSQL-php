<?php
//http://php.net/manual/en/oci8.configuration.php
//connection class to enable better connection pooling
ini_set('oci8.connection_class','WaSQL');
//event (FAN)
ini_set('oci8.events','ON');
//max number of persistent connections to the database
ini_set('oci8.max_persistent',50);
//seconds a persistent connection will stay alive
ini_set('oci8.persistent_timeout',-1);
//number of rows in each DB round trip to cache
ini_set('oci8.default_prefetch',100);
//number of statements to cache
ini_set('oci8.statement_cache_size',20);
//---------- begin function oracleAddDBRecords ----------
/**
* @describe add multiple records at the same time using json_table.
*  if cdate, and cuser exists as fields then they are populated with the create date and create username
* @param $params array - These can also be set in the CONFIG file with dbname_oracle,dbuser_oracle, and dbpass_oracle
*   -table - name of the table to add to
*   -list - list of records to add. Recommended list size in 500~1000 so that you keep the memory footprint small
*	[-dateformat] - string- format of date field values. defaults to 'YYYY-MM-DD HH24:MI:SS'
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	other field=>value pairs to add to the record
* @return boolean
* @usage $ok=oracleAddDBRecords(array('-table'=>'abc','-list'=>$list));
*/
function oracleAddDBRecords($params=array()){
	global $USER;
	if(!isset($params['-table'])){
		$out=array(
    		'function'=>"oracleAddDBRecords",
    		'error'=>'No table specified'
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
    }
    if(!isset($params['-list']) || !is_array($params['-list'])){
    	$out=array(
    		'function'=>"oracleAddDBRecords",
    		'error'=>'No records (list) specified'
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
    }
    //defaults
    if(!isset($params['-dateformat'])){
    	$params['-dateformat']='YYYY-MM-DD HH24:MI:SS';
    }
    $recs=$params['-list'];
    $info=oracleGetDBFieldInfo($params['-table']);
    //check for cdate and cuser
    foreach($recs as $i=>$rec){
    	if(isset($info['cdate']) && !isset($rec['cdate'])){
			$recs[$i]['cdate']=strtoupper(date('Y-m-d  H:i:s'));
		}
		elseif(isset($info['_cdate']) && !isset($rec['_cdate'])){
			$recs[$i]['_cdate']=strtoupper(date('Y-m-d  H:i:s'));
		}
		if(isset($info['cuser']) && !isset($rec['cuser'])){
			$recs[$i]['cuser']=$USER['username'];
		}
		elseif(isset($info['_cuser']) && !isset($rec['_cuser'])){
			$recs[$i]['_cuser']=$USER['username'];
		}
    }
	$j=array("items"=>$recs);
    $json=json_encode($j);
    
    $fields=array();
    $jfields=array();
    $defines=array();

    foreach($recs[0] as $field=>$value){
    	if(!isset($info[$field])){continue;}
    	$fields[]=$field;
    	switch(strtolower($info[$field]['_dbtype'])){
    		case 'timestamp':
    		case 'date':
    			//date types have to be converted into a format that Oracle understands
    			$jfields[]="to_date(substr({$field},1,19),'{$params['-dateformat']}' ) as {$field}";
    		break;
    		default:
    			$jfields[]=$field;
    		break;
    	}
    	$defines[]="{$field} varchar2(4000) PATH '\$.{$field}'";
    }
    if(!count($fields)){return 'No matching Fields';}
    $fieldstr=implode(',',$fields);
    $jfieldstr=implode(',',$jfields);
    $definestr=implode(','.PHP_EOL,$defines);
    $query = <<<ENDOFQ
    INSERT INTO {$params['-table']}
    	({$fieldstr})
    SELECT 
    	{$jfieldstr}
	FROM JSON_TABLE(
		:b_json
		, '\$'
		COLUMNS (
			nested path '\$.items[*]'
			COLUMNS(
				{$definestr}
			)
		)
	)
ENDOFQ;
	if(isset($params['-debug'])){
		return $query.PHP_EOL.PHP_EOL.$json.PHP_EOL;
	}
	$dbh_oracle=oracleDBConnect($params);
	$stid = oci_parse($dbh_oracle, $query);
	if (!is_resource($stid)){
    	$out=array(
    		'function'=>"oracleAddDBRecords",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_parse',
    		'error'=>oci_error($dbh_oracle),
    		'query'=>$query
    	);
    	oci_close($dbh_oracle);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	
    	return false;
    }
	$descriptor = oci_new_descriptor($dbh_oracle, OCI_DTYPE_LOB);
	if(!oci_bind_by_name($stid, ':b_json', $descriptor, -1, SQLT_CLOB)){
		$out=array(
    		'function'=>"oracleAddDBRecords",
    		'connection'=>$dbh_oracle,
    		'stid'=>$stid,
    		'action'=>'oci_bind_by_name',
    		'error'=>oci_error($stid),
    		'query'=>$query,
    		'field'=>$k,
    		'_dbtype'=>$fields[$k]['_dbtype'],
    		'bind'=>$bind,
    		'value'=>$values[$k]
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
	}
	$descriptor->writeTemporary($json);
	$r = oci_execute($stid);
	$e=oci_error($stid);
	if (!$r) {
		$out=array(
    		'function'=>"oracleAddDBRecords",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_execute',
    		'stid'=>$stid,
    		'error'=>$e,
    		'query'=>$query,
    		'json'=>$json
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
	}
	return true;
}

//---------- begin function oracleAddDBRecord ----------
/**
* @describe adds a records from params passed in.
*  if cdate, and cuser exists as fields then they are populated with the create date and create username
* @param $params array - These can also be set in the CONFIG file with dbname_oracle,dbuser_oracle, and dbpass_oracle
*   -table - name of the table to add to
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	other field=>value pairs to add to the record
* @return integer returns the autoincriment key
* @usage $id=oracleAddDBRecord(array('-table'=>'abc','name'=>'bob','age'=>25));
*/
function oracleAddDBRecord($params){
	global $USER;
	if(!isset($params['-table'])){
		$out=array(
    		'function'=>"oracleAddDBRecord",
    		'error'=>'No table specified'
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
    }
	//connect
	$dbh_oracle=oracleDBConnect($params);
	$fields=oracleGetDBFieldInfo($params['-table'],$params);
	//populate cdate and cuser fields
	if(isset($fields['cdate']) && !isset($params['cdate'])){
		$params['cdate']=strtoupper(date('d-M-Y  H:i:s'));
	}
	elseif(isset($fields['_cdate']) && !isset($params['_cdate'])){
		$params['_cdate']=strtoupper(date('d-M-Y  H:i:s'));
	}
	if(isset($fields['cuser']) && !isset($params['cuser'])){
		$params['cuser']=$USER['username'];
	}
	elseif(isset($fields['_cuser']) && !isset($params['_cuser'])){
		$params['_cuser']=$USER['username'];
	}
	$values=array();
	$bindvars=array();
	foreach($params as $k=>$v){
		$k=strtolower($k);
		if(!strlen(trim($v))){continue;}
		if(!isset($fields[$k])){continue;}
		if($k=='euser' || $k=='edate'){continue;}
		if($k=='_euser' || $k=='_edate'){continue;}
		if(is_array($params[$k])){
            	$params[$k]=implode(':',$params[$k]);
		}
		$bindvars[$k]=':b_'.preg_replace('/[^a-z]/i','',$k);
		switch(strtolower($fields[$k]['_dbtype'])){
        	case 'date':
				if($k=='cdate' || $k=='_cdate'){
					$params[$k]=date('d-M-Y',strtotime($v));
				}
				//set the template for the to_date
				if(preg_match('/^([0-9]{2,2}?)\-([a-z]{3,3}?)\-([0-9]{2,4})/i',$params[$k],$m)){
					//already in the right format: 02-MAR-2019
					$values[$k]="{$m[1]}-{$m[2]}-{$m[3]}";
				}
				elseif(preg_match('/^([0-9]{4,4}?)\-([0-9]{2,2}?)\-([0-9]{2,2})/i',$params[$k],$m)){
					//2018-11-07
					$values[$k]=date('d-M-Y',strtotime("{$m[1]}-{$m[2]}-{$m[3]}"));
				}
				else{
					$values[$k]=date('d-M-Y',strtotime($v));
				}
        	break;
        	default:
        		$values[$k]=$v;
        	break;
		}
	}
	//build the query with bind variables
	$fieldstr=implode(', ',array_keys($values));
	$bindstr=implode(', ',array_values($bindvars));
    $query="INSERT INTO {$params['-table']} ({$fieldstr}) values ({$bindstr})";
    $stid = oci_parse($dbh_oracle, $query);
    if (!is_resource($stid)){
    	$out=array(
    		'function'=>"oracleAddDBRecord",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_parse',
    		'error'=>oci_error($dbh_oracle),
    		'query'=>$query
    	);
    	oci_close($dbh_oracle);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	
    	return false;
    }
    //bind the variables
    foreach($values as $k=>$v){
    	$bind=$bindvars[$k];
    	switch(strtolower($fields[$k]['_dbtype'])){
    		case 'clob':
    			// treat clobs differently so we can insert large amounts of data
    			$descriptor[$k] = oci_new_descriptor($dbh_oracle, OCI_DTYPE_LOB);
				if(!oci_bind_by_name($stid, $bind, $descriptor[$k], -1, SQLT_CLOB)){
					$out=array(
			    		'function'=>"oracleAddDBRecord",
			    		'connection'=>$dbh_oracle,
			    		'stid'=>$stid,
			    		'action'=>'oci_bind_by_name',
			    		'error'=>oci_error($stid),
			    		'query'=>$query,
			    		'field'=>$k,
			    		'_dbtype'=>$fields[$k]['_dbtype'],
			    		'bind'=>$bind,
			    		'value'=>$values[$k]
			    	);
			    	if(isset($params['-return_errors'])){
			    		return $out;
			    	}
					debugValue($out);
			    	return false;
				}
				$descriptor[$k]->writeTemporary($values[$k]);
    		break;
    		case 'blob':
    			if(!oci_bind_by_name($stid, $bind, $values[$k], strlen($values[$k]), OCI_B_BLOB )){
			    	$out=array(
			    		'function'=>"oracleAddDBRecord",
			    		'connection'=>$dbh_oracle,
			    		'stid'=>$stid,
			    		'action'=>'oci_bind_by_name',
			    		'error'=>oci_error($stid),
			    		'query'=>$query,
			    		'field'=>$k,
			    		'_dbtype'=>$fields[$k]['_dbtype'],
			    		'bind'=>$bind,
			    		'value'=>$values[$k]
			    	);
			    	if(isset($params['-return_errors'])){
			    		return $out;
			    	}
					debugValue($out);
			    	return false;
				}
    		break;
    		default:
    			if(!oci_bind_by_name($stid, $bind, $values[$k], strlen($values[$k]))){
			    	$out=array(
			    		'function'=>"oracleAddDBRecord",
			    		'connection'=>$dbh_oracle,
			    		'stid'=>$stid,
			    		'action'=>'oci_bind_by_name',
			    		'error'=>oci_error($stid),
			    		'query'=>$query,
			    		'field'=>$k,
			    		'_dbtype'=>$fields[$k]['_dbtype'],
			    		'bind'=>$bind,
			    		'value'=>$values[$k]
			    	);
			    	if(isset($params['-return_errors'])){
			    		return $out;
			    	}
					debugValue($out);
			    	return false;
				}
    		break;
    	}
    }
	$r = oci_execute($stid);
	$e=oci_error($stid);
	if (!$r) {
		$out=array(
    		'function'=>"oracleAddDBRecord",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_execute',
    		'stid'=>$stid,
    		'error'=>$e,
    		'query'=>$query
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
	}
	return true;
}
//---------- begin function oracleAutoCommit ----------
/**
* @describe turn autocommit on or off
* @param $stid resource - statement id
* @param $onoff boolean - set to 0 or 'off' to turn autocommit off
* @return connection resource and sets the global $dbh_oracle variable
* @usage $ok=oracleAutoCommit($stid,'off');
*/
function oracleAutoCommit($stid,$onoff=0){
	switch(strtolower($onoff)){
		case 0:
		case 'off':
			//turn OFF autocommit
			$r = oci_execute($stid, OCI_NO_AUTO_COMMIT);
		break;
		default:
			//turn ON autocommit
			$r = oci_execute($stid, OCI_COMMIT_ON_SUCCESS );
		break;
	}
	if (!$r) {
		$out=array(
    		'function'=>"oracleAutoCommit",
    		'stid'=>$stid,
    		'action'=>'oci_execute',
    		'error'=>oci_error($stid),
    		'onoff'=>$onoff
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
		return false;
	}
	return true;
}
//---------- begin function oracleCommit ----------
/**
* @describe commit any transactions that have not been committed
* @param [$conn] resource - connection. defaults to $dbh_oracle global
* @return boolean
* @usage $ok=oracleCommit();
*/
function oracleCommit($conn=''){
	if(is_resource($conn)){
		global $dbh_oracle;
		$conn=$dbh_oracle;
	}
	return oci_commit($conn);
}
//---------- begin function oracleDBConnect ----------
/**
* @describe returns connection resource
* @param $params array - These can also be set in the CONFIG file with dbname_oracle,dbuser_oracle, and dbpass_oracle
*	[-host] - oracle server to connect to
* 	[-dbname] - name of database.
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return connection resource and sets the global $dbh_oracle variable
* @usage $dbh_oracle=oracleDBConnect($params);
* @usage singe query usage
* 	$conn=oracleDBConnect(array('-single'=>1));
* 		$stid = oci_parse($conn, 'select 1,2,3 from dual');
* 		oci_execute($stid);
* 		while ($row = oci_fetch_array($stid, OCI_ASSOC+OCI_RETURN_NULLS)) {
* 			echo printValue($row);
* 		}
* 	oci_close($conn);
*/
function oracleDBConnect($params=array()){
	if(!isset($params['-port'])){$params['-port']=1521;}
	if(!isset($params['-charset'])){$params['-charset']='AL32UTF8';}
	$params=oracleParseConnectParams($params);
	if(!isset($params['-connect'])){
		$params['-dbpass']=preg_replace('/./','*',$params['-dbpass']);
		$params['-dbuser']=preg_replace('/./','*',$params['-dbuser']);
		echo "oracleDBConnect error: no connect params".printValue($params);
		exit;
	}
	if(isset($params['-single'])){
		$dbh_single = oci_connect($params['-dbuser'],$params['-dbpass'],$params['-connect'],$params['-charset']);
		if(!is_resource($dbh_single)){
			$err=json_encode(oci_error());
			$params['-dbpass']=preg_replace('/./','*',$params['-dbpass']);
			$params['-dbuser']=preg_replace('/./','*',$params['-dbuser']);
			echo "oracleDBConnect single connect error:{$err}".printValue($params);
			exit;
		}
		return $dbh_single;
	}
	global $dbh_oracle;
	if(is_resource($dbh_oracle)){return $dbh_oracle;}
	try{
		$dbh_oracle = oci_pconnect($params['-dbuser'],$params['-dbpass'],$params['-connect'],$params['-charset']);
		if(!is_resource($dbh_oracle)){
			$err=oci_error();
			$params['-dbpass']=preg_replace('/./','*',$params['-dbpass']);
			$params['-dbuser']=preg_replace('/./','*',$params['-dbuser']);
			echo "oracleDBConnect resource error. ".printValue($err).printValue($params);
			exit;

		}
		return $dbh_oracle;
	}
	catch (Exception $e) {
		echo "oracleDBConnect exception" . printValue($e);
		exit;
	}
}
//---------- begin function oracleEditDBRecord ----------
/**
* @describe edits a records from params passed in. must have a -table and a -where
*  if cdate, and cuser exists as fields then they are populated with the create date and create username
* @param $params array - These can also be set in the CONFIG file with dbname_oracle,dbuser_oracle, and dbpass_oracle
*   -table - name of the table to add to
*   -where - filter to limit edit by.  i.e "id=4"
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	other field=>value pairs to specify what fields and values to edit
* @return integer return 1 on success
* @usage $id=oracleEditDBRecord(array('-table'=>'abc','-where'=>'id=4',name'=>'bob','age'=>25));
*/
function oracleEditDBRecord($params,$id=0,$opts=array()){
	//check for function overload: editDBRecord(table,id,opts());
	if(!is_array($params) && strlen($params) && isNum($id) && $id > 0 && is_array($opts) && count($opts)){
		$opts['-table']=$params;
		$opts['-where']="_id={$id}";
		$params=$opts;
	}
	if(!isset($params['-table'])){
		$out=array(
    		'function'=>"oracleEditDBRecord",
    		'error'=>'No table specified'
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
    }
	if(!isset($params['-where'])){
		$out=array(
    		'function'=>"oracleEditDBRecord",
    		'error'=>'No where specified'
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
    }
	global $USER;
	//get the database handle
	$dbh_oracle=oracleDBConnect($params);
	$fields=oracleGetDBFieldInfo($params['-table'],$params);
	$values=array();
	$bindars=array();
	if(isset($fields['edate']) && !isset($params['edate'])){
		$params['edate']=strtoupper(date('d-M-Y  H:i:s'));
	}
	elseif(isset($fields['_edate']) && !isset($params['_edate'])){
		$params['_edate']=strtoupper(date('d-M-Y  H:i:s'));
	}
	if(isset($fields['euser']) && !isset($params['euser'])){
		$params['euser']=$USER['username'];
	}
	elseif(isset($fields['_euser']) && !isset($params['_euser'])){
		$params['_euser']=$USER['username'];
	}
	$values=array();
	$bindvars=array();
	foreach($params as $k=>$v){
		$k=strtolower($k);
		if(!strlen(trim($v))){continue;}
		if(!isset($fields[$k])){continue;}
		if($k=='cuser' || $k=='cdate'){continue;}
		if($k=='_cuser' || $k=='_cdate'){continue;}
		if(is_array($params[$k])){
            $params[$k]=implode(':',$params[$k]);
		}
		$bindvars[$k]=':b'.preg_replace('/[^a-z]/i','',$k);
		switch(strtolower($fields[$k]['_dbtype'])){
        	case 'date':
				if($k=='cdate' || $k=='_cdate'){
					$params[$k]=date('d-M-Y',strtotime($v));
				}
				//set the template for the to_date
				if(preg_match('/^([0-9]{2,2}?)\-([a-z]{3,3}?)\-([0-9]{2,4})/i',$params[$k],$m)){
					//already in the right format: 02-MAR-2019
					$values[$k]="{$m[1]}-{$m[2]}-{$m[3]}";
				}
				elseif(preg_match('/^([0-9]{4,4}?)\-([0-9]{2,2}?)\-([0-9]{2,2})/i',$params[$k],$m)){
					//2018-11-07
					$values[$k]=date('d-M-Y',strtotime("{$m[1]}-{$m[2]}-{$m[3]}"));
				}
				else{
					$values[$k]=date('d-M-Y',strtotime($v));
				}
        	break;
        	default:
        		$values[$k]=$v;
        	break;
		}
	}
	//build the query with bind variables
	$sets=array();
	foreach($values as $k=>$v){
		$sets[]="{$k}={$bindvars[$k]}";
	}
	$setstr=implode(',',$sets);
    $query="update {$params['-table']} set {$setstr} where {$params['-where']}";
    $stid = oci_parse($dbh_oracle, $query);
    //check for parse errors
    if(!is_resource($stid)){
    	$out=array(
    		'function'=>"oracleEditDBRecord",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_parse',
    		'error'=>oci_error($dbh_oracle),
    		'query'=>$query
    	);

    	oci_close($dbh_oracle);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
    }
    //bind the variables
    foreach($bindvars as $k=>$bind){
    	switch(strtolower($fields[$k]['_dbtype'])){
    		case 'clob':
    			// treat clobs differently so we can insert large amounts of data
    			$descriptor[$k] = oci_new_descriptor($dbh_oracle, OCI_DTYPE_LOB);
				if(!oci_bind_by_name($stid, $bind, $descriptor[$k], -1, SQLT_CLOB)){
					$out=array(
			    		'function'=>"oracleEditDBRecord",
			    		'connection'=>$dbh_oracle,
			    		'stid'=>$stid,
			    		'action'=>'oci_bind_by_name',
			    		'error'=>oci_error($stid),
			    		'query'=>$query,
			    		'field'=>$k,
			    		'_dbtype'=>$fields[$k]['_dbtype'],
			    		'bind'=>$bind,
			    		'value'=>$values[$k]
			    	);
			    	if(isset($params['-return_errors'])){
			    		return $out;
			    	}
					debugValue($out);
			    	return false;
				}
				$descriptor[$k]->writeTemporary($values[$k]);
    		break;
    		case 'blob':
    			if(!oci_bind_by_name($stid, $bind, $values[$k], strlen($values[$k]), OCI_B_BLOB )){
			    	$out=array(
			    		'function'=>"oracleEditDBRecord",
			    		'connection'=>$dbh_oracle,
			    		'stid'=>$stid,
			    		'action'=>'oci_bind_by_name',
			    		'error'=>oci_error($stid),
			    		'query'=>$query,
			    		'field'=>$k,
			    		'_dbtype'=>$fields[$k]['_dbtype'],
			    		'bind'=>$bind,
			    		'value'=>$values[$k]
			    	);
			    	if(isset($params['-return_errors'])){
			    		return $out;
			    	}
					debugValue($out);
			    	return false;
				}
    		break;
    		default:
    			if(!oci_bind_by_name($stid, $bind, $values[$k], strlen($values[$k]))){
			    	$out=array(
			    		'function'=>"oracleEditDBRecord",
			    		'connection'=>$dbh_oracle,
			    		'stid'=>$stid,
			    		'action'=>'oci_bind_by_name',
			    		'error'=>oci_error($stid),
			    		'query'=>$query,
			    		'field'=>$k,
			    		'_dbtype'=>$fields[$k]['_dbtype'],
			    		'bind'=>$bind,
			    		'value'=>$values[$k]
			    	);
			    	if(isset($params['-return_errors'])){
			    		return $out;
			    	}
					debugValue($out);
			    	return false;
				}
    		break;
    	}
    }
	$r = oci_execute($stid);
	$e=oci_error($stid);
	if (!$r) {
		$out=array(
    		'function'=>"oracleEditDBRecord",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_execute',
    		'stid'=>$stid,
    		'error'=>$e,
    		'query'=>$query
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
	}
	return true;
}
//---------- begin function oracleExecuteSQL ----------
/**
* @describe executes query and returns succes or error
* @param $query string - SQL query to execute
* @param [$params] array - These can also be set in the CONFIG file with dbname_oracle,dbuser_oracle, and dbpass_oracle
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	[module] - module to set query against. Defaults to 'waSQL'
* 	[action] - action to set query against. Defaults to 'oracleExecuteSQL'
* 	[id] - identifier to set query against. Defaults to current user
* 	[setmodule] boolean - set to false to not set module, action, and id. Defaults to true
* 	[-idcolumn] boolean - set to true to include row number as _id column
* @return array - returns boolean or error
* @usage $ok=oracleExecuteSQL($query);
*/
function oracleExecuteSQL($query='',$params=array()){
	global $USER;
	//connect
	$dbh_oracle=oracleDBConnect($params);
	if(!isset($params['setmodule'])){$params['setmodule']=true;}
	$stid = oci_parse($dbh_oracle, $query);
	if (!$stid) {
		$out=array(
    		'function'=>"oracleExecuteSQL",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_parse',
    		'error'=>oci_error($dbh_oracle),
    		'query'=>$query
    	);
    	oci_close($dbh_oracle);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return;
	}
	if($params['setmodule']){
		if(!isset($params['module'])){$params['module']='waSQL';}
		if(!isset($params['action'])){
			if(isset($_REQUEST['AjaxRequestUniqueId'])){$params['action']='oracleExecuteSQL (AJAX): '.$_REQUEST['AjaxRequestUniqueId'];}
			else{$params['action']='oracleExecuteSQL';}
		}
		if(!isset($params['id'])){$params['id']=$USER['username'];}
		oci_set_module_name($dbh_oracle, $params['module']);
		oci_set_action($dbh_oracle, $params['action']);
		oci_set_client_identifier($dbh_oracle, $params['id']);
	}
	//log this query
	// check for non-select query
	$start=microtime(true);
	if(preg_match('/^(truncate|create|drop|update|insert|alter)/is',trim($query))){
		$r = oci_execute($stid,OCI_COMMIT_ON_SUCCESS);
		$e=oci_error($stid);
		if(function_exists('logDBQuery')){
			logDBQuery($query,$start,'oracleExecuteSQL','oracle');
		}
    	if($params['setmodule']){
			oci_set_module_name($dbh_oracle, 'idle');
			oci_set_action($dbh_oracle, 'idle');
			oci_set_client_identifier($dbh_oracle, 'idle');
		}
		if (!$r){
			$out=array(
	    		'function'=>"oracleExecuteSQL",
	    		'connection'=>$dbh_oracle,
	    		'action'=>'oci_execute',
	    		'error'=>$e,
	    		'query'=>$query
	    	);
	    	oci_free_statement($stid);
	    	oci_close($dbh_oracle);
	    	if(isset($params['-return_errors'])){
	    		return $out;
	    	}
			debugValue($out);
	    	return false;
		}
		oci_free_statement($stid);
	    oci_close($dbh_oracle);
		return true;
	}
	$r = oci_execute($stid);
	$e=oci_error($stid);
	if(function_exists('logDBQuery')){
		logDBQuery($query,$start,'oracleExecuteSQL','oracle');
	}
    if($params['setmodule']){
		oci_set_module_name($dbh_oracle, 'idle');
		oci_set_action($dbh_oracle, 'idle');
		oci_set_client_identifier($dbh_oracle, 'idle');
	}
	if (!$r){
		$out=array(
    		'function'=>"oracleExecuteSQL",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_execute',
    		'error'=>$e,
    		'query'=>$query
    	);
    	oci_free_statement($stid);
		oci_close($dbh_oracle);
		if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
	}
	oci_free_statement($stid);
	oci_close($dbh_oracle);
	return true;
}
//---------- begin function oracleGetActiveSessionCount
/**
* @describe returns and array of records
* @param params array - requires either -list or -table or a raw query instead of params
*	[-seconds] integer - seconds since  - LAST_CALL_ET
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return integer - number of sessions
* @usage
*	$cnt=oracleGetActiveSessionCount(array('-seconds'=>60));
*/
function oracleGetActiveSessionCount($params=array()){
	$query="
		SELECT
			count(*) cnt
		FROM v\$session sess
		WHERE
			sess.type='USER'
			and sess.status='ACTIVE'
	";
	if(isset($params['-seconds']) && isNum($params['-seconds'])){
		$query .= " AND sess.LAST_CALL_ET >= {$params['-seconds']}";
	}
	$recs=oracleQueryResults($query,$params);
	if(isset($recs[0]['cnt'])){return $recs[0]['cnt'];}
	return $query;
}
//---------- begin function oracleGetDBCount--------------------
/**
* @describe returns a record count based on params
* @param params array - requires either -list or -table or a raw query instead of params
*	-table string - table name.  Use this with other field/value params to filter the results
*	[-host] -  server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array
* @usage $cnt=oracleGetDBCount(array('-table'=>'states'));
*/
function oracleGetDBCount($params=array()){
	$params['-fields']="count(*) as cnt";
	unset($params['-order']);
	unset($params['-limit']);
	unset($params['-offset']);
	$recs=oracleGetDBRecords($params);
	//if($params['-table']=='states'){echo $query.printValue($recs);exit;}
	if(!isset($recs[0]['cnt'])){
		$out=array(
    		'function'=>"oracleGetDBCount",
    		'error'=>$recs,
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
		return 0;
	}
	return $recs[0]['cnt'];
}
//---------- begin function oracleTruncateDBTable--------------------
/**
* @describe truncates the specified table
* @param params array - requires either -list or -table or a raw query instead of params
*	-table string - table name.  Use this with other field/value params to filter the results
*	[-host] -  server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array
* @usage $cnt=oracleTruncateDBTable('myschema.mytable');
*/
function oracleTruncateDBTable($table,$params=array()){
	return oracleExecuteSQL("truncate table {$table}",$params);
}
//---------- begin function oracleGetDBFields--------------------
/**
* @describe returns an array of fields for said table
* @param table string - table name
* @param params array - requires either -list or -table or a raw query instead of params
*	[-getmeta] string - table name.  Use this with other field/value params to filter the results
*	[-field] mixed - query record limit
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array
* @usage $fields=oracleGetDBFields('notes');
*/
function oracleGetDBFields($table,$params=array()){
	$info=oracleGetDBFieldInfo($table,$params);
	return array_keys($info);
}
//---------- begin function oracleGetDBFieldInfo--------------------
/**
* @describe returns an array containing type,length, and flags for each field in said table
* @param table string - table name
* @param params array - requires either -list or -table or a raw query instead of params
*	[-getmeta] string - table name.  Use this with other field/value params to filter the results
*	[-field] mixed - query record limit
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array
* @usage $fields=oracleGetDBFieldInfo('notes');
*/
function oracleGetDBFieldInfo($table,$params=array()){
	//connect
	$dbh_oracle=oracleDBConnect($params);
	//primary keys
	$pkeys=oracleGetDBTablePrimaryKeys($table,$params);
	//echo $table.printValue($pkeys);exit;
	$query="select * from {$table} where 0=".rand(1,1000);
	$stid = oci_parse($dbh_oracle, $query);
	if(!$stid){
		$out=array(
    		'function'=>"oracleGetDBFieldInfo",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_parse',
    		'error'=>oci_error($dbh_oracle),
    		'query'=>$query
    	);
    	oci_close($dbh_oracle);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
	}
	oci_execute($stid, OCI_DESCRIBE_ONLY);
	$ncols = oci_num_fields($stid);
	//echo "here".$ncols;exit;
	$fields=array();
	for ($i = 1; $i <= $ncols; $i++) {
		$name=oci_field_name($stid, $i);
		$field=array(
			'table'	=> $table,
			'_dbtable'	=> $table,
			'name'	=> $name,
			'_dbfield'	=> strtolower($name),
			'type'	=> oci_field_type($stid, $i),
			'precision'	=> oci_field_precision($stid, $i),
			'scale'	=> oci_field_scale($stid, $i),
			'length'	=> oci_field_size($stid, $i),
			//'type_raw'	=> oci_field_type_raw($stid, $i),
		);
		$field['_dbtype']=$field['_dbtype_ex']=strtolower($field['type']);
		$field['_dblength']=$field['length'];
		if($field['precision'] > 0){
			$field['_dbtype_ex']=strtolower("{$field['type']}({$field['precision']})");
		}
		elseif($field['length'] > 0 && preg_match('/(char|text|blob)/i',$field['_dbtype'])){
			$field['_dbtype_ex']=strtolower("{$field['type']}({$field['length']})");
		}
		$field['_dblength']=$field['length'];
		if(in_array($name,$pkeys)){
			$field['primary_key']=true;
		}
		else{
			$field['primary_key']=false;
		}
		$name=strtolower($name);
	    $fields[$name]=$field;
	}
	oci_free_statement($stid);
	oci_close($dbh_oracle);
	return $fields;
}
//---------- begin function oracleGetDBRecord--------------------
/**
* @describe returns a record based on params
* @param params array 
*	-table string - table name.  Use this with other field/value params to filter the results
*	[-host] -  server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array
* @usage $cnt=oracleGetDBRecord(array('-table'=>'states','code'=>'UT'));
*/
function oracleGetDBRecord($params=array()){
	$params['-limit']=1;
	$recs=oracleGetDBRecords($params);
	if(!isset($recs[0])){
		if(isset($params['-return_errors'])){
    		return json_encode($recs);
    	}
		debugValue($recs);
		return array();
	}
	return $recs[0];
}
//---------- begin function oracleGetDBRecordById--------------------
/**
* @describe returns a single multi-dimensional record with said id in said table
* @param table string - tablename
* @param id integer - record ID of record
* @param relate boolean - defaults to true
* @param fields string - defaults to blank
* @return array
* @usage $rec=oracleGetDBRecordById('comments',7);
*/
function oracleGetDBRecordById($table='',$id=0,$relate=1,$fields=""){
	if(!strlen($table)){return "oracleGetDBRecordById Error: No Table";}
	if($id == 0){return "oracleGetDBRecordById Error: No ID";}
	$recopts=array('-table'=>$table,'_id'=>$id);
	if($relate){$recopts['-relate']=1;}
	if(strlen($fields)){$recopts['-fields']=$fields;}
	$rec=oracleGetDBRecord($recopts);
	return $rec;
}
//---------- begin function oracleEditDBRecordById--------------------
/**
* @describe edits a record with said id in said table
* @param table string - tablename
* @param id mixed - record ID of record or a comma separated list of ids
* @param params array - field=>value pairs to edit in this record
* @return boolean
* @usage $ok=oracleEditDBRecordById('comments',7,array('name'=>'bob'));
*/
function oracleEditDBRecordById($table='',$id=0,$params=array()){
	if(!strlen($table)){
		return debugValue("oracleEditDBRecordById Error: No Table");
	}
	//allow id to be a number or a set of numbers
	$ids=array();
	if(is_array($id)){
		foreach($id as $i){
			if(isNum($i) && !in_array($i,$ids)){$ids[]=$i;}
		}
	}
	else{
		$id=preg_split('/[\,\:]+/',$id);
		foreach($id as $i){
			if(isNum($i) && !in_array($i,$ids)){$ids[]=$i;}
		}
	}
	if(!count($ids)){return debugValue("oracleEditDBRecordById Error: invalid ID(s)");}
	if(!is_array($params) || !count($params)){return debugValue("oracleEditDBRecordById Error: No params");}
	if(isset($params[0])){return debugValue("oracleEditDBRecordById Error: invalid params");}
	$idstr=implode(',',$ids);
	$params['-table']=$table;
	$params['-where']="_id in ({$idstr})";
	$recopts=array('-table'=>$table,'_id'=>$id);
	return oracleEditDBRecord($params);
}
//---------- begin function oracleDelDBRecordById--------------------
/**
* @describe deletes a record with said id in said table
* @param table string - tablename
* @param id mixed - record ID of record or a comma separated list of ids
* @return boolean
* @usage $ok=oracleDelDBRecordById('comments',7,array('name'=>'bob'));
*/
function oracleDelDBRecordById($table='',$id=0){
	if(!strlen($table)){
		return debugValue("oracleDelDBRecordById Error: No Table");
	}
	//allow id to be a number or a set of numbers
	$ids=array();
	if(is_array($id)){
		foreach($id as $i){
			if(isNum($i) && !in_array($i,$ids)){$ids[]=$i;}
		}
	}
	else{
		$id=preg_split('/[\,\:]+/',$id);
		foreach($id as $i){
			if(isNum($i) && !in_array($i,$ids)){$ids[]=$i;}
		}
	}
	if(!count($ids)){return debugValue("oracleDelDBRecordById Error: invalid ID(s)");}
	$idstr=implode(',',$ids);
	$params=array();
	$params['-table']=$table;
	$params['-where']="_id in ({$idstr})";
	$recopts=array('-table'=>$table,'_id'=>$id);
	return oracleDelDBRecord($params);
}


//---------- begin function oracleGetDBRecords
/**
* @describe returns and array of records
* @param params array - requires either -table or a raw query instead of params
*	[-table] string - table name.  Use this with other field/value params to filter the results
*	[-limit] mixed - query record limit.  Defaults to CONFIG['paging'] if set in config.xml otherwise 25
*	[-offset] mixed - query offset limit
*	[-fields] mixed - fields to return
*	[-where] string - string to add to where clause
*	[-filter] string - string to add to where clause
*	[-host] - server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array - set of records
* @usage
*	<?=oracleGetDBRecords(array('-table'=>'notes'));?>
*	<?=oracleGetDBRecords("select * from myschema.mytable where ...");?>
*/
function oracleGetDBRecords($params){
	global $USER;
	global $CONFIG;
	if(empty($params['-table']) && !is_array($params)){
		$params=trim($params);
		if(preg_match('/^(select|exec|with|explain|returning|show|call)[\t\s\ \r\n]/i',$params)){
			//they just entered a query
			$query=$params;
			$params=array('-lobs'=>1);
		}
		else{
			$ok=oraclelExecuteSQL($params);
			return $ok;
		}
	}
	else{
		//determine fields to return
		if(!empty($params['-fields'])){
			if(!is_array($params['-fields'])){;
				$params['-fields']=preg_split('/\,/',$params['-fields']);
				foreach($params['-fields'] as $i=>$field){
					$params['-fields'][$i]=trim($field);
				}
			}
			$params['-fields']=implode(',',$params['-fields']);
		}
		if(empty($params['-fields'])){$params['-fields']='*';}
		$fields=oracleGetDBFieldInfo($params['-table'],$params);
		$ands=array();
		foreach($params as $k=>$v){
			$k=strtolower($k);
			if(!strlen(trim($v))){continue;}
			if(!isset($fields[$k])){continue;}
			//check for lobs
			if($fields[$k]['_dbtype']=='clob' && !isset($params['-lobs'])){$params['-lobs']=1;}
			if(is_array($params[$k])){
	            $params[$k]=implode(':',$params[$k]);
			}
	        $params[$k]=str_replace("'","''",$params[$k]);
	        $v=strtoupper($params[$k]);
	        if(isNum($v)){
	        	$ands[]="{$k} = {$v}";
	        }
	        else{
	        	$ands[]="upper({$k})='{$v}'";
	        }
	        
		}
		//check for -where
		if(!empty($params['-where'])){
			$ands[]= "({$params['-where']})";
		}
		if(isset($params['-filter'])){
			$ands[]= "({$params['-filter']})";
		}
		$wherestr='';
		if(count($ands)){
			$wherestr='WHERE '.implode(' and ',$ands);
		}
	    $query="SELECT {$params['-fields']} FROM {$params['-table']} {$wherestr}";
	    if(isset($params['-order'])){
    		$query .= " ORDER BY {$params['-order']}";
    	}
    	//offset and limit
    	if(!isset($params['-nolimit'])){
	    	$offset=isset($params['-offset'])?$params['-offset']:0;
	    	$limit=25;
	    	if(!empty($params['-limit'])){$limit=$params['-limit'];}
	    	elseif(!empty($CONFIG['paging'])){$limit=$CONFIG['paging'];}
	    	$query .= " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
	    }
	}
	if(isset($params['-debug'])){return $query;}
	return oracleQueryResults($query,$params);
}
//---------- begin function oracleGetDBTables ----------
/**
* @describe returns all valid table names
* @param params array
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array - valid table names 
*/
function oracleGetDBTables($params=array()){
	global $CONFIG;
	$query=<<<ENDOFQUERY
		SELECT 
			owner,table_name,last_analyzed,num_rows,pct_free 
		FROM 
			all_tables 
		WHERE 
			owner not in ('SYS','SYSTEM') 
			and tablespace_name not in ('SYS','SYSAUX','SYSTEM') 
			and status='VALID'
		ORDER BY 
			owner,table_name
ENDOFQUERY;
	$recs = oracleQueryResults($query,$params);
	$tables=array();
	foreach($recs as $rec){
		$tables[]="{$rec['owner']}.{$rec['table_name']}";
	}
	return $tables;
}
//---------- begin function oracleGetDBTablePrimaryKeys
/**
* @describe returns a list of primary key fields for specified table
* @param table string - table name
* @param params array
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array - primary key fields
* @usage
*	$pkeys=oracleGetDBTablePrimaryKeys('people');?>
*/
function oracleGetDBTablePrimaryKeys($table,$params=array()){
	$parts=preg_split('/\./',$table);
	$table=array_pop($parts);
	$table=str_replace("'","''",$table);
	$table=strtoupper($table);
	if(count($parts)){
		$owner=array_pop($parts);
		$owner=str_replace("'","''",$owner);
		$owner=strtoupper($owner);
		$owner_filter="AND upper(cols.owner) = '{$owner}'";
	}
	else{$owner_filter='';}
	$query=<<<ENDOFQUERY
	SELECT
  		cols.column_name
		,cols.position
		,cons.status
		,cons.owner
	FROM all_constraints cons, all_cons_columns cols
	WHERE
		upper(cols.table_name) = '{$table}'
		{$owner_filter}
		AND cons.constraint_type = 'P'
		AND cons.constraint_name = cols.constraint_name
		AND cons.owner = cols.owner
	ORDER BY cols.position
ENDOFQUERY;
	$tmp = oracleQueryResults($query);
	$keys=array();
	foreach($tmp as $rec){
		$keys[]=$rec['column_name'];
    }
	return $keys;
}
//---------- begin function oracleEnumQueryResults ----------
/**
* @describe returns the results from a query resource
* @param $resource resource - resource handle from query call
* @param $params array - These can also be set in the CONFIG file with dbname_oracle,dbuser_oracle, and dbpass_oracle
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return $recs array
* @usage $recs=oracleEnumQueryResults($resource);
*/
function oracleEnumQueryResults($res,$params=array()){
	$header=0;
	unset($fh);
	//write to file or return a recordset?
	if(isset($params['-filename'])){
		$starttime=microtime(true);
		if(isset($params['-append'])){
			//append
    		$fh = fopen($params['-filename'],"ab");
		}
		else{
			if(file_exists($params['-filename'])){unlink($params['-filename']);}
    		$fh = fopen($params['-filename'],"wb");
		}
    	if(!isset($fh) || !is_resource($fh)){
			odbc_free_result($result);
			return 'hanaQueryResults error: Failed to open '.$params['-filename'];
			exit;
		}
		if(isset($params['-logfile'])){
			setFileContents($params['-logfile'],$query.PHP_EOL.PHP_EOL);
		}
		
	}
	else{$recs=array();}
	$i=0;
	$fetchopts=OCI_ASSOC+OCI_RETURN_NULLS;
	if(isset($params['-lobs'])){$fetchopts=OCI_ASSOC+OCI_RETURN_NULLS+OCI_RETURN_LOBS;}
	while ($row = oci_fetch_array($res, $fetchopts)) {
		$rec=array();
		foreach ($row as $field=>$val){
			$field=strtolower($field);
			if(is_resource($val)){
				oci_execute($val);
				//get the fields
				$xfields=array();
				$icount=oci_num_fields($val);
				for($i=1;$i<=$icount;$i++){
					$xfield=strtolower(oci_field_name($val,$i));
					$xfields[]=$xfield;
				}
				$rec[$field]=oracleEnumQueryResults($val,$params);
				if(!count($rec[$field]) && isset($params['-forceheader'])){
					$xrec=array();
					foreach($xfields as $xfield){
						$xrec[$xfield]='';
					}
					$rec[$field]=array($xrec);
				}
				oci_free_statement($val);
			}
			else{
				$rec[$field]=$val;
			}
		}
		if(isset($fh) && is_resource($fh)){
        	if($header==0){
            	$csv=arrays2CSV(array($rec));
            	$header=1;
            	//add UTF-8 byte order mark to the beginning of the csv
				$csv="\xEF\xBB\xBF".$csv;
			}
			else{
            	$csv=arrays2CSV(array($rec),array('-noheader'=>1));
			}
			$csv=preg_replace('/[\r\n]+$/','',$csv);
			fwrite($fh,$csv."\r\n");
			$i+=1;
			if(isset($params['-logfile']) && file_exists($params['-logfile']) && $i % 5000 == 0){
				appendFileContents($params['-logfile'],$i.PHP_EOL);
			}
			continue;
		}
		elseif(isset($params['-process'])){
			$ok=call_user_func($params['-process'],$rec);
			$x++;
			continue;
		}
		else{$recs[]=$rec;}
	}
	if(isset($fh) && is_resource($fh)){
		@fclose($fh);
		if(isset($params['-logfile']) && file_exists($params['-logfile'])){
			$elapsed=microtime(true)-$starttime;
			appendFileContents($params['-logfile'],"Line count:{$i}, Execute Time: ".verboseTime($elapsed).PHP_EOL);
		}
		return $i;
	}
	return $recs;
}
//---------- begin function oracleListRecords
/**
* @describe returns an html table of records from a oracle database. refer to databaseListRecords
*/
function oracleListRecords($params=array()){
	$params['-database']='oracle';
	//check for clobs
	if(isset($params['-table']) && !isset($params['-lobs'])){
		$fields=oracleGetDBFieldInfo($params['-table'],$params);
		foreach($fields as $k=>$info){
			if($fields[$k]['_dbtype']=='clob'){
				$params['-lobs']=1;
				break;
			}
		}
	}
	return databaseListRecords($params);
}

//---------- begin function oracleParseConnectParams ----------
/**
* @describe parses the params array and checks in the CONFIG if missing
* @param $params array - These can also be set in the CONFIG file with dbname_oracle,dbuser_oracle, and dbpass_oracle
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return $params array
* @usage $params=oracleParseConnectParams($params);
*/
function oracleParseConnectParams($params=array()){
	global $CONFIG;
	global $DATABASE;
	global $USER;
	if(isset($CONFIG['db']) && isset($DATABASE[$CONFIG['db']])){
		foreach($CONFIG as $k=>$v){
			if(preg_match('/^oracle/i',$k)){unset($CONFIG[$k]);}
		}
		foreach($DATABASE[$CONFIG['db']] as $k=>$v){
			$params["-{$k}"]=$v;
		}
	}
	//check for user specific
	if(isUser() && strlen($USER['username'])){
		foreach($params as $k=>$v){
			if(stringEndsWith($k,"_{$USER['username']}")){
				$nk=str_replace("_{$USER['username']}",'',$k);
				unset($params[$k]);
				$params[$nk]=$v;
			}
		}
	}
	if(!isset($params['-dbuser'])){
		if(isset($CONFIG['dbuser_oracle'])){
			$params['-dbuser']=$CONFIG['dbuser_oracle'];
			$params['-dbuser_source']="CONFIG dbuser_oracle";
		}
		elseif(isset($CONFIG['oracle_dbuser'])){
			$params['-dbuser']=$CONFIG['oracle_dbuser'];
			$params['-dbuser_source']="CONFIG oracle_dbuser";
		}
		else{return 'oracleParseConnectParams Error: No dbuser set';}
	}
	else{
		$params['-dbuser_source']="passed in";
	}
	if(!isset($params['-dbpass'])){
		if(isset($CONFIG['dbpass_oracle'])){
			$params['-dbpass']=$CONFIG['dbpass_oracle'];
			$params['-dbpass_source']="CONFIG dbpass_oracle";
		}
		elseif(isset($CONFIG['oracle_dbpass'])){
			$params['-dbpass']=$CONFIG['oracle_dbpass'];
			$params['-dbpass_source']="CONFIG oracle_dbpass";
		}
		else{return 'oracleParseConnectParams Error: No dbpass set';}
	}
	else{
		$params['-dbpass_source']="passed in";
	}
	//
	if(isset($CONFIG['oracle_single'])){$params['-single']=$CONFIG['oracle_single'];}
	//connect
	if(!isset($params['-connect'])){
		if(isset($CONFIG['oracle_connect'])){
			$params['-connect']=$CONFIG['oracle_connect'];
			$params['-connect_source']="CONFIG oracle_connect";
		}
		elseif(isset($CONFIG['connect_oracle'])){
			$params['-connect']=$CONFIG['connect_oracle'];
			$params['-connect_source']="CONFIG connect_oracle";
		}
		else{
			//build connect
			$dbhost='';
			if(isset($params['-dbhost'])){$dbhost=$params['-dbhost'];}
			elseif(isset($CONFIG['oracle_dbhost'])){$dbhost=$CONFIG['oracle_dbhost'];}
			elseif(isset($CONFIG['dbhost_oracle'])){$dbhost=$CONFIG['dbhost_oracle'];}
			if(!strlen($dbhost)){return $params;}
			$protocol='TCP';
			if(isset($params['-protocol'])){$tcp=$params['-protocol'];}
			elseif(isset($CONFIG['oracle_protocol'])){$tcp=$CONFIG['oracle_protocol'];}
			elseif(isset($CONFIG['protocol_oracle'])){$tcp=$CONFIG['protocol_oracle'];}
			$port='1521';
			if(isset($params['-port'])){$port=$params['-port'];}
			elseif(isset($CONFIG['oracle_port'])){$port=$CONFIG['oracle_port'];}
			elseif(isset($CONFIG['port_oracle'])){$port=$CONFIG['port_oracle'];}
			$connect_data='';
			//sid - identify the Oracle8 database instance by its Oracle System Identifier (SID)
			if(isset($params['-sid'])){$connect_data.="(SID={$params['-sid']})";}
			elseif(isset($CONFIG['oracle_sid'])){$connect_data.="(SID={$CONFIG['oracle_sid']})";}
			elseif(isset($CONFIG['sid_oracle'])){$connect_data.="(SID={$CONFIG['sid_oracle']})";}
			//service_name - identify the Oracle9i or Oracle8 database service to access
			if(isset($params['-service_name'])){$connect_data.="(SERVICE_NAME={$params['-service_name']})";}
			elseif(isset($CONFIG['oracle_service_name'])){$connect_data.="(SERVICE_NAME={$CONFIG['oracle_service_name']})";}
			elseif(isset($CONFIG['service_name_oracle'])){$connect_data.="(SERVICE_NAME={$CONFIG['service_name_oracle']})";}
			//instance_name - identify the database instance to access
			if(isset($params['-instance_name'])){$connect_data.="(INSTANCE_NAME={$params['-instance_name']})";}
			elseif(isset($CONFIG['oracle_instance_name'])){$connect_data.="(INSTANCE_NAME={$CONFIG['oracle_instance_name']})";}
			elseif(isset($CONFIG['instance_name_oracle'])){$connect_data.="(INSTANCE_NAME={$CONFIG['instance_name_oracle']})";}
			//server_name
			if(isset($params['-server_name'])){$connect_data.="(SERVER_NAME={$params['-server_name']})";}
			elseif(isset($CONFIG['oracle_server_name'])){$connect_data.="(SERVER_NAME={$CONFIG['oracle_server_name']})";}
			elseif(isset($CONFIG['server_name_oracle'])){$connect_data.="(SERVER_NAME={$CONFIG['server_name_oracle']})";}
			//global_name - identify the Oracle Rdb database.
			if(isset($params['-global_name'])){$connect_data.="(GLOBAL_NAME={$params['-global_name']})";}
			elseif(isset($CONFIG['oracle_global_name'])){$connect_data.="(GLOBAL_NAME={$CONFIG['oracle_global_name']})";}
			elseif(isset($CONFIG['global_name_oracle'])){$connect_data.="(GLOBAL_NAME={$CONFIG['global_name_oracle']})";}
			//server - instruct the listener to connect the client to a specific type of service handler, dedicated or shared
			if(isset($params['-server'])){$connect_data.="(SERVER={$params['-server']})";}
			elseif(isset($CONFIG['oracle_server'])){$connect_data.="(SERVER={$CONFIG['oracle_server']})";}
			elseif(isset($CONFIG['server_oracle'])){$connect_data.="(SERVER={$CONFIG['server_oracle']})";}
			//rdb_database - specify the file name of an Oracle Rdb database
			if(isset($params['-rdb_database'])){$connect_data.="(RDB_DATABASE={$params['-rdb_database']})";}
			elseif(isset($CONFIG['oracle_rdb_database'])){$connect_data.="(RDB_DATABASE={$CONFIG['oracle_rdb_database']})";}
			elseif(isset($CONFIG['rdb_database_oracle'])){$connect_data.="(RDB_DATABASE={$CONFIG['rdb_database_oracle']})";}
			if(!strlen($connect_data)){return $params;}
			$params['-connect']="(DESCRIPTION=(ADDRESS=(PROTOCOL = {$protocol})(HOST = {$dbhost})(PORT={$port}))(CONNECT_DATA={$connect_data}))";
			$params['-connect_source']="tcp,host,port";
		}
	}
	else{
		$params['-connect_source']="passed in";
	}
	return $params;
}

//---------- begin function oracleQueryResults ----------
/**
* @describe returns the oracle records from query
* @param $query string - SQL query to execute
* @param [$params] array - 
*	[-host] - oracle server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	[module] - module to set query against. Defaults to 'waSQL'
* 	[action] - action to set query against. Defaults to 'oracleQueryResults'
* 	[id] - identifier to set query against. Defaults to current user
* 	[setmodule] boolean - set to false to not set module, action, and id. Defaults to true
* 	[-idcolumn] boolean - set to true to include row number as _id column
*	[-lobs] boolean - add OCI_RETURN_LOBS to the oci_fetch to return lobs in the data
* 	[-filename] - if you pass in a filename then it will write the results to the csv filename you passed in
* @return array - returns records
*/
function oracleQueryResults($query='',$params=array()){
	global $USER;
	//connect
	$dbh_oracle=oracleDBConnect($params);
	//check for -process
	if(isset($params['-process']) && !function_exists($params['-process'])){
		$out=array(
    		'function'=>"oracleQueryResults",
    		'connection'=>$dbh_oracle,
    		'error'=>'Invalid process function',
    		'function'=>$params['-process'],
    		'query'=>$query
    	);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
		return false;
	}
	oci_rollback($dbh_oracle);
	if(!isset($params['setmodule'])){$params['setmodule']=true;}
	$stid = oci_parse($dbh_oracle, $query);
	if(!is_resource($stid)){
		$out=array(
    		'function'=>"oracleQueryResults",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_parse',
    		'error'=>oci_error($dbh_oracle),
    		'query'=>$query
    	);
    	oci_close($dbh_oracle);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
	}
	if($params['setmodule']){
		if(!isset($params['module'])){$params['module']='waSQL';}
		if(!isset($params['action'])){
			if(isset($_REQUEST['AjaxRequestUniqueId'])){$params['action']='oracleQueryResults (AJAX): '.$_REQUEST['AjaxRequestUniqueId'];}
			else{$params['action']='oracleQueryResults';}
		}
		if(!isset($params['id'])){$params['id']=$USER['username'];}
		oci_set_module_name($dbh_oracle, $params['module']);
		oci_set_action($dbh_oracle, $params['action']);
		oci_set_client_identifier($dbh_oracle, $params['id']);
	}
	// check for non-select query
	$start=microtime(true);
	if(preg_match('/^(create|drop|grant|truncate|update|insert|alter)/is',trim($query))){
		$r = oci_execute($stid,OCI_COMMIT_ON_SUCCESS);
		$e=oci_error($stid);
		if(function_exists('logDBQuery')){
			logDBQuery($query,$start,'oracleQueryResults','oracle');
		}
    	oci_free_statement($stid);
    	if($params['setmodule']){
			oci_set_module_name($dbh_oracle, 'idle');
			oci_set_action($dbh_oracle, 'idle');
			oci_set_client_identifier($dbh_oracle, 'idle');
		}
		oci_close($dbh_oracle);
		if (!$r){
			$out=array(
	    		'function'=>"oracleQueryResults",
	    		'connection'=>$dbh_oracle,
	    		'action'=>'oci_execute',
	    		'error'=>$e,
	    		'query'=>$query
	    	);
	    	if(isset($params['-return_errors'])){
	    		return $out;
	    	}
			debugValue($out);
	    	return false;
		}
		return true;
	}
	$r = oci_execute($stid);
	$e=oci_error($stid);
	if(function_exists('logDBQuery')){
		logDBQuery($query,$start,'oracleQueryResults','oracle');
	}
	if($params['setmodule']){
		oci_set_module_name($dbh_oracle, 'idle');
		oci_set_action($dbh_oracle, 'idle');
		oci_set_client_identifier($dbh_oracle, 'idle');
	}
	if (!$r) {
		$out=array(
    		'function'=>"oracleQueryResults",
    		'connection'=>$dbh_oracle,
    		'action'=>'oci_execute',
    		'error'=>$e,
    		'query'=>$query
    	);
    	oci_free_statement($stid);
		oci_close($dbh_oracle);
    	if(isset($params['-return_errors'])){
    		return $out;
    	}
		debugValue($out);
    	return false;
	}
	//read results into a recordset array	
	$recs=oracleEnumQueryResults($stid,$params);
	if(!count($recs) && isset($params['-forceheader'])){
		$fields=array();
		for($i=1;$i<=oci_num_fields($stid);$i++){
			$field=strtolower(oci_field_name($stid,$i));
			$fields[]=$field;
		}
		oci_free_statement($stid);
		$rec=array();
		foreach($fields as $field){
			$rec[$field]='';
		}
		$recs=array($rec);
	}
	oci_free_statement($stid);
	oci_close($dbh_oracle);
	return $recs;
}
//---------- begin function oracleNamedQuery ----------
/**
* @describe returns pre-build queries based on name
* @param name string
*	[running_queries]
*	[table_locks]
* @return query string
*/
function oracleNamedQuery($name){
	global $CONFIG;
	global $DATABASE;
	switch(strtolower($name)){
		case 'running_queries':
			return <<<ENDOFQUERY
SELECT
	a.sid, 
	a.username,
	b.sql_id, 
	b.sql_fulltext 
FROM v\$session a, v\$sql b
WHERE 
	a.sql_id = b.sql_id 
	and a.status = 'ACTIVE' 
	and a.username != 'SYS'
ENDOFQUERY;
		break;
		case 'sessions':
			return <<<ENDOFQUERY
SELECT 
	sid,
    serial#,
    osuser,
    machine,
    program,
    module
from v\$session
ENDOFQUERY;
		break;
		case 'table_locks':
			return <<<ENDOFQUERY
SELECT 
	b.owner, 
	b.object_name, 
	a.oracle_username, 
	a.os_user_name  
FROM v\$locked_object a, all_objects b
WHERE 
	a.object_id = b.object_id
ENDOFQUERY;
		break;
		case 'functions':
			$owner=strtoupper($DATABASE[$CONFIG['db']]['dbschema']);
			return <<<ENDOFQUERY
SELECT 
	owner, object_name, object_id, data_object_id, subobject_name status,created, last_ddl_time, timestamp
FROM ALL_OBJECTS 
WHERE OBJECT_TYPE = 'FUNCTION' 
and owner = '{$owner}'
ENDOFQUERY;
		break;
		case 'procedures':
			$owner=strtoupper($DATABASE[$CONFIG['db']]['dbschema']);
			return <<<ENDOFQUERY
SELECT 
	owner, object_name, object_id, data_object_id, subobject_name status,created, last_ddl_time, timestamp
FROM ALL_OBJECTS 
WHERE OBJECT_TYPE = 'PROCEDURE' 
and owner = '{$owner}'
ENDOFQUERY;
		break;
		case 'packages':
			$owner=strtoupper($DATABASE[$CONFIG['db']]['dbschema']);
			return <<<ENDOFQUERY
SELECT 
	owner, object_name, object_id, data_object_id, subobject_name status,created, last_ddl_time, timestamp
FROM ALL_OBJECTS 
WHERE OBJECT_TYPE = 'PACKAGE' 
and owner = '{$owner}'
ENDOFQUERY;
		break;
	}
}