<?php
/*
	postgresql.php - a collection of postgresqls functions for use by WaSQL.
	
	References:
		https://en.wikibooks.org/wiki/Converting_MySQL_to_PostgreSQL
		https://www.convert-in.com/mysql-to-postgresqls-types-mapping.htm
		https://medium.com/coding-blocks/creating-user-database-and-adding-access-on-postgresqlsql-8bfcd2f4a91e
		https://stackoverflow.com/questions/15520361/permission-denied-for-relation

	NOTE: make sure pgsql.auto_reset_persistent in php.ini is set to On.  this will get rid of "server closed the connection unexpectedly" errors

	Json_table equivilent in PostgreSQL
		drop TYPE json_test;
		create  TYPE json_test AS (id_item int, id_menu varchar(100));
		select * from json_populate_recordset(null::json_test,'[{"id_item":1,"id_menu":"34"},{"id_item":2,"id_menu":"35"}]')

*/
//---------- begin function postgresqlAddDBRecords--------------------
/**
* @describe add multiple records into a table
* @param table string - tablename
* @param params array - 
*	[-recs] array - array of records to insert into specified table
*	[-csv] array - csv file of records to insert into specified table
* @return count int
* @usage $ok=postgresqlAddDBRecords('comments',array('-csv'=>$afile);
* @usage $ok=postgresqlAddDBRecords('comments',array('-recs'=>$recs);
*/
function postgresqlAddDBRecords($table='',$params=array()){
	if(!strlen($table)){
		$err="postgresqlAddDBRecords Error: No Table";
		debugValue($err);
		return $err;
	}
	if(!isset($params['-chunk'])){$params['-chunk']=1000;}
	$params['-table']=$table;
	//require either -recs or -csv
	if(!isset($params['-recs']) && !isset($params['-csv'])){
		$err="postgresqlAddDBRecords Error: either -csv or -recs is required";
		debugValue($err);
		return $err;
	}
	if(isset($params['-csv'])){
		if(!is_file($params['-csv'])){
			$err="postgresqlAddDBRecords Error: no such file: {$params['-csv']}";
			debugValue($err);
		return $err;
		}
		return processCSVLines($params['-csv'],'postgresqlAddDBRecordsProcess',$params);
	}
	elseif(isset($params['-recs'])){
		if(!is_array($params['-recs'])){
			$err="postgresqlAddDBRecords Error: no recs";
			debugValue($err);
			return $err;
		}
		elseif(!count($params['-recs'])){
			$err="postgresqlAddDBRecords Error: no recs";
			debugValue($err);
			return $err;
		}
		return postgresqlAddDBRecordsProcess($params['-recs'],$params);
	}
}
function postgresqlAddDBRecordsProcess($recs,$params=array()){
	global $CONFIG;
	if(!isset($params['-table'])){
		$err="postgresqlAddDBRecordsProcess Error: no table"; 
		debugValue($err);
		return $err;
	}
	$table=$params['-table'];
	$fieldinfo=postgresqlGetDBFieldInfo($table);
	if(!is_array($fieldinfo) || !count($fieldinfo)){
		$err="postgresqlAddDBRecordsProcess Error: no fields for {$table} in {$CONFIG['db']}"; 
		debugValue($err);
		return $err;
	}
	//if -map then remap specified fields
	if(isset($params['-map'])){
		foreach($recs as $i=>$rec){
			foreach($rec as $k=>$v){
				if(isset($params['-map'][$k])){
					unset($recs[$i][$k]);
					$k=$params['-map'][$k];
					$recs[$i][$k]=$v;
				}
			}
		}
	}
	//fields
	$fields=array();
	foreach($recs as $i=>$rec){
		foreach($rec as $k=>$v){
			if(!isset($fieldinfo[$k])){continue;}
			if(!in_array($k,$fields)){$fields[]=$k;}
		}
	}
	$fieldstr=implode(',',$fields);
	$query="INSERT INTO {$table} ({$fieldstr}) VALUES ".PHP_EOL;
	$values=array();
	foreach($recs as $i=>$rec){
		$vals=array();
		foreach($fields as $field){
			$val='NULL';
			if(isset($rec[$field]) && strlen($rec[$field])){
				$val=postgresqlEscapeString($rec[$field]);
				switch($fieldinfo[$field]['_dbtype']){
					case 'date':
					case 'time':
					case 'datetime':
					case 'timestamp':
						if(preg_match('/^([a-z\_0-9]+)\(\)$/is',$val)){
							//val is a function - do not put quotes around it
						}
						elseif(preg_match('/^(current_timestamp)$/is',$val)){
							//val is a function - do not put quotes around it
						}
						else{
							$val="'{$val}'";
						}
					break;
					default:
						$val="'{$val}'";
					break;
				}
			}
			$vals[]=$val;
		}
		$values[]='('.implode(',',$vals).')';
	}
	$query.=implode(','.PHP_EOL,$values);
	if(isset($params['-upsert']) && isset($params['-upserton'])){
		if(!is_array($params['-upsert'])){
			$params['-upsert']=preg_split('/\,/',$params['-upsert']);
		}
		/*
			ON CONFLICT (id) DO UPDATE SET 
			  id=EXCLUDED.id, username=EXCLUDED.username,
			  password=EXCLUDED.password, level=EXCLUDED.level,email=EXCLUDED.email
		*/
		if(strtolower($params['-upsert'][0])=='ignore'){
			$query.=PHP_EOL."ON CONFLICT ({$params['-upserton']}) DO NOTHING";
		}
		else{
			$query.=PHP_EOL."ON CONFLICT ({$params['-upserton']}) DO UPDATE SET";
			$flds=array();
			foreach($params['-upsert'] as $fld){
				$flds[]="{$fld}=EXCLUDED.{$fld}";
			}
			$query.=PHP_EOL.implode(', ',$flds);
		}
	}
	if(isset($params['-return'])){
		if(is_array($params['-return'])){
			$params['-return']=implode(',',$params['-return']);
		}
		$query.=PHP_EOL."RETURNING {$params['-return']}";
	}
	$ok=postgresqlExecuteSQL($query);
	if(isset($ok['error'])){
		$ok['query']=$query;
		debugValue($ok);
		return printValue($ok);
	}
	if(isset($params['-debug'])){
		$ok['query']=$query;
		debugValue($ok);
		return printValue($ok);
	}
	return count($values);
}
function postgresqlEscapeString($str){
	return pg_escape_string($str);
}
//---------- begin function postgresqlGetTableDDL ----------
/**
* @describe returns create script for specified table
* @param table string - tablename
* @param [schema] string - schema. defaults to dbschema specified in config
* @return string
* @usage $createsql=postgresqlGetTableDDL('sample');
* @link https://stackoverflow.com/questions/2593803/how-to-generate-the-create-table-sql-statement-for-an-existing-table-in-postgr
*/
function postgresqlGetTableDDL($table,$schema=''){
	$table=strtoupper($table);
	if(!strlen($schema)){
		$schema=postgresqlGetDBSchema();
	}
	if(!strlen($schema)){
		debugValue('postgresqlGetTableDDL error: schema is not defined in config.xml');
		return null;
	}
	$schema=strtolower($schema);
	$table=strtolower($table);

	$fieldinfo=postgresqlGetDBFieldInfo($table);

	$pkeys=postgresqlGetDBTablePrimaryKeys($table);
	//return printValue($fieldinfo).printValue($pkeys);
	$fields=array();
	foreach($fieldinfo as $field=>$info){
		$fld=" {$info['_dbfield']} {$info['_dbtype_ex']}";
		if(in_array($info['primary_key'],array('true','yes',1))){
			$fld.=' PRIMARY KEY';
		}
		elseif(in_array($field,$pkeys)){
			$fld.=' PRIMARY KEY';
		}
		if($info['identity']==1){
			$fld.=' IDENTITY(1,1)';
		}
		if(in_array($info['nullable'],array('NO','no',0))){
			$fld.=' NOT NULL';
		}
		else{
			$fld.=' NULL';
		}
		if(strlen($info['default'])){
			if(stringBeginsWith($info['default'],'nextval(')){
				if($info['_dbtype']=='bigint'){
					$fld=str_replace(' bigint',' bigserial',$fld);
				}
				elseif($info['_dbtype']=='int'){
					$fld=str_replace(' int',' serial',$fld);
				}
			}
			else{
				$fld.=" DEFAULT {$info['default']}";
			}
		}
		$fields[]=$fld;
	}
	$ddl="CREATE TABLE {$schema}.{$table} (".PHP_EOL;
	$ddl.=implode(','.PHP_EOL,$fields);
	$ddl.=PHP_EOL.')'.PHP_EOL;
	return $ddl;
}
//---------- begin function postgresqlAddDBIndex--------------------
/**
* @describe add an index to a table
* @param params array
*	-table
*	-fields
*	[-fulltext]
*	[-unique]
*	[-name] name of the index
* @return boolean
* @usage
*	$ok=postgresqlAddDBIndex(array('-table'=>$table,'-fields'=>"name",'-unique'=>true));
* 	$ok=postgresqlAddDBIndex(array('-table'=>$table,'-fields'=>"name,number",'-unique'=>true));
*/
function postgresqlAddDBIndex($params=array()){
	if(!isset($params['-table'])){return 'postgresqlAddDBIndex Error: No table';}
	if(!isset($params['-fields'])){return 'postgresqlAddDBIndex Error: No fields';}
	if(!is_array($params['-fields'])){$params['-fields']=preg_split('/\,+/',$params['-fields']);}
	//check for schema name
	if(!stringContains($params['-table'],'.')){
		$schema=postgresqlGetDBSchema();
		if(strlen($schema) && !stringBeginsWith($params['-table'],"{$schema}.")){
			$params['-table']="{$schema}.{$params['-table']}";
		}
	}
	
	//fulltext or unique
	$fulltext=$params['-fulltext']?' FULLTEXT':'';
	$unique=$params['-unique']?' UNIQUE':'';
	//prefix
	$prefix='';
	if(strlen($unique)){$prefix .= 'U';}
	if(strlen($fulltext)){$prefix .= 'F';}
	$prefix.='IDX';
	//name
	$fieldstr=implode('_',$params['-fields']);
	//index names cannot be longer than 64 chars long
	if(strlen($fieldstr) > 60){
    	$fieldstr=substr($fieldstr,0,60);
	}
	if(!isset($params['-name'])){$params['-name']=str_replace('.','_',"{$prefix}_{$params['-table']}_{$fieldstr}");}
	//build and execute
	$fieldstr=implode(", ",$params['-fields']);
	$query="CREATE {$unique} INDEX IF NOT EXISTS {$params['-name']} on {$params['-table']} ({$fieldstr})";
	return postgresqlExecuteSQL($query);
}


//---------- begin function postgresqlAddDBRecord ----------
/**
* @describe adds a records from params passed in.
*  if cdate, and cuser exists as fields then they are populated with the create date and create username
* @param $params array - These can also be set in the CONFIG file with dbname_postgresql,dbuser_postgresql, and dbpass_postgresql
*   -table - name of the table to add to
*	[-host] - postgresql server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	other field=>value pairs to add to the record
* @return integer returns the autoincriment key
* @usage $id=postgresqlAddDBRecord(array('-table'=>'abc','name'=>'bob','age'=>25));
*/
function postgresqlAddDBRecord($params=array()){
	global $USER;
	global $CONFIG;
	if(!isset($params['-table'])){return 'postgresqlAddRecord error: No table specified.';}
	//check for schema name
	if(!stringContains($params['-table'],'.')){
		$schema=postgresqlGetDBSchema();
		if(strlen($schema) && !stringBeginsWith($params['-table'],"{$schema}.")){
			$params['-table']="{$schema}.{$params['-table']}";
		}
	}
	$finfo=postgresqlGetDBFieldInfo($params['-table'],$params);

	$sequence='';
	$opts=array();
	if(isset($finfo['cdate'])){
		$params['cdate']=strtoupper(date('d-M-Y  H:i:s'));
	}
	elseif(isset($finfo['_cdate'])){
		$params['_cdate']=strtoupper(date('d-M-Y  H:i:s'));
	}
	if(isset($finfo['cuser'])){
		$params['cuser']=(function_exists('isUser') && isUser())?$USER['_id']:0;
	}
	elseif(isset($finfo['_cuser'])){
		$params['_cuser']=(function_exists('isUser') && isUser())?$USER['_id']:0;
	}
	$fields=array();
	$values=array();
	$prepares=array();
	foreach($params as $k=>$v){
		$k=strtolower($k);
		if(!strlen(trim($v))){continue;}
		if(!isset($finfo[$k])){continue;}
		if(is_array($params[$k])){
            	$params[$k]=implode(':',$params[$k]);
		}
		$fields[]=$k;
		$values[]=$params[$k];
        $prepares[]='$'.count($values);
	}
	$fieldstr=implode(',',$fields);
	$preparestr=implode(',',$prepares);
	//determine output field - identity column to return
	/*
		PostgreSQL will automatically generate and populate values into the SERIAL column. 
		This is similar to AUTO_INCREMENT column in MySQL or AUTOINCREMENT column in SQLite.
		You can create inserts that return a value... so you can return the identity field
	*/
	$output='';
	if(isset($params['-return'])){
		$output=" RETURNING {$params['-return']}";
		$output_field=$params['-return'];
	}
	else{
		foreach($finfo as $field=>$info){
			if($info['identity']==1){
				$output=" RETURNING {$field}";
				$output_field=$field;
				break;
			}
			elseif($info['sequence']==1){
				$output=" RETURNING {$field}";
				$output_field=$field;
				break;
			}
		}
	}
	$more='';
	if(isset($params['-ignore'])){
		$precs=postgresqlGetDBTableIndexes($params['-table']);
		if(is_array($precs)){
			$pflds=array();
			foreach($precs as $prec){
				if($prec['is_unique']=='t'){
					//echo printValue($prec);
					$rflds=json_decode($prec['index_keys']);
					foreach($rflds as $rfld){
						if($rfld != '_id' && !in_array($rfld,$pflds)){
							$pflds[]=$rfld;
						}
					}
				}
			}
			$pfieldstr=implode(',',$pflds);
			$more="ON CONFLICT ({$pfieldstr}) DO NOTHING";
		}
	}
	elseif(isset($params['-upsert']) && isset($params['-upserton'])){
		if(!is_array($params['-upsert'])){
			$params['-upsert']=preg_split('/\,/',$params['-upsert']);
		}
		/*
			ON CONFLICT (id) DO UPDATE SET 
			  id=EXCLUDED.id, username=EXCLUDED.username,
			  password=EXCLUDED.password, level=EXCLUDED.level,email=EXCLUDED.email
		*/
		if(strtolower($params['-upsert'][0])=='ignore'){
			$more=" ON CONFLICT ({$params['-upserton']}) DO NOTHING";
		}
		else{
			$more=" ON CONFLICT ({$params['-upserton']}) DO UPDATE SET";
			$flds=array();
			foreach($params['-upsert'] as $fld){
				$flds[]="{$fld}=EXCLUDED.{$fld}";
			}
			$more.=PHP_EOL.implode(', ',$flds);
		}
	}
    $query=<<<ENDOFQUERY
		INSERT INTO {$params['-table']}
			({$fieldstr})
		VALUES(
			{$preparestr}
		)
		{$more}
		{$output}

ENDOFQUERY;
	if(isset($params['-debug']) && $params['-debug']==1){
		return array(
			'params'=>$params,
			'fieldInfo'=>$finfo,
			'query'=>$query
		);
	}

	global $dbh_postgresql;
	if(!is_resource($dbh_postgresql)){
		$dbh_postgresql=postgresqlDBConnect();
	}
	if(!$dbh_postgresql){
		debugValue(array(
			'function'=>'postgresqlAddDBRecord',
			'message'=>'connect failed',
			'error'=>pg_last_error(),
			'query'=>$query,
			'params'=>$params
		));
    	return null;
	}
	$result=pg_query_params($dbh_postgresql,$query,$values);
	if(!is_resource($result)){
		$err=pg_last_error($dbh_postgresql);
		debugValue(array(
			'function'=>'postgresqlAddDBRecord',
			'message'=>'pg_query_params failed',
			'error'=>$err,
			'query'=>$query,
			'values'=>$values,
			'params'=>$params
		));
		pg_close($dbh_postgresql);
		return null;
	}
	$recs = postgresqlEnumQueryResults($result,$params);
	// debugValue(array(
	// 	'function'=>'postgresqlAddDBRecord',
	// 	'query'=>$query,
	// 	'values'=>$values,
	// 	'params'=>$params
	// ));
	pg_close($dbh_postgresql);
	if(isset($recs[0][$output_field])){return $recs[0][$output_field];}
	return $recs;
}
//---------- begin function postgresqlGetDBRecordById--------------------
/**
* @describe returns a single multi-dimensional record with said id in said table
* @param table string - tablename
* @param id integer - record ID of record
* @param relate boolean - defaults to true
* @param fields string - defaults to blank
* @return array
* @usage $rec=postgresqlGetDBRecordById('comments',7);
*/
function postgresqlGetDBRecordById($table='',$id=0,$relate=1,$fields=""){
	if(!strlen($table)){return "postgresqlGetDBRecordById Error: No Table";}
	if($id == 0){return "postgresqlGetDBRecordById Error: No ID";}
	$recopts=array('-table'=>$table,'_id'=>$id);
	if($relate){$recopts['-relate']=1;}
	if(strlen($fields)){$recopts['-fields']=$fields;}
	$rec=postgresqlGetDBRecord($recopts);
	return $rec;
}
//---------- begin function postgresqlEditDBRecordById--------------------
/**
* @describe edits a record with said id in said table
* @param table string - tablename
* @param id mixed - record ID of record or a comma separated list of ids
* @param params array - field=>value pairs to edit in this record
* @return boolean
* @usage $ok=postgresqlEditDBRecordById('comments',7,array('name'=>'bob'));
*/
function postgresqlEditDBRecordById($table='',$id=0,$params=array()){
	if(!strlen($table)){
		return debugValue("postgresqlEditDBRecordById Error: No Table");
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
	if(!count($ids)){return debugValue("postgresqlEditDBRecordById Error: invalid ID(s)");}
	if(!is_array($params) || !count($params)){return debugValue("postgresqlEditDBRecordById Error: No params");}
	if(isset($params[0])){return debugValue("postgresqlEditDBRecordById Error: invalid params");}
	$idstr=implode(',',$ids);
	$params['-table']=$table;
	$params['-where']="_id in ({$idstr})";
	$recopts=array('-table'=>$table,'_id'=>$id);
	return postgresqlEditDBRecord($params);
}
//---------- begin function postgresqlDelDBRecordById--------------------
/**
* @describe deletes a record with said id in said table
* @param table string - tablename
* @param id mixed - record ID of record or a comma separated list of ids
* @return boolean
* @usage $ok=postgresqlEditDBRecordById('comments',7,array('name'=>'bob'));
*/
function postgresqlDelDBRecordById($table='',$id=0){
	if(!strlen($table)){
		return debugValue("postgresqlDelDBRecordById Error: No Table");
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
	if(!count($ids)){return debugValue("postgresqlDelDBRecordById Error: invalid ID(s)");}
	$idstr=implode(',',$ids);
	$params=array();
	$params['-table']=$table;
	$params['-where']="_id in ({$idstr})";
	$recopts=array('-table'=>$table,'_id'=>$id);
	return postgresqlDelDBRecord($params);
}

//---------- begin function postgresqlCreateDBTable--------------------
/**
* @describe creates postgresql table with specified fields
* @param table string - name of table to alter
* @param params array - list of field/attributes to add
* @return mixed - 1 on success, error string on failure
* @usage
*	$ok=postgresqlCreateDBTable($table,array($field=>"varchar(255) NULL",$field2=>"int NOT NULL"));
*/
function postgresqlCreateDBTable($table='',$fields=array()){
	$function='createDBTable';
	if(strlen($table)==0){return "postgresqlCreateDBTable error: No table";}
	if(count($fields)==0){return "postgresqlCreateDBTable error: No fields";}
	//check for schema name
	$schema=postgresqlGetDBSchema();
	if(!stringContains($table,'.')){
		if(strlen($schema) && !stringBeginsWith($table,"{$schema}.")){
			$table="{$schema}.{$table}";
		}
	}
	if(postgresqlIsDBTable($table)){return 0;}
	global $CONFIG;	
	//lowercase the tablename and replace spaces with underscores
	$table=strtolower(trim($table));
	$table=str_replace(' ','_',$table);
	$ori_table=$table;
	if(strlen($schema) && !stringContains($table,$schema)){
		$table="{$schema}.{$table}";
	}
	$query="create table {$table} (".PHP_EOL;
	$lines=array();
	foreach($fields as $field=>$attributes){
		//datatype conversions
		$attributes=str_replace('tinyint','smallint',$attributes);
		$attributes=str_replace('mediumint','integer',$attributes);
		$attributes=str_replace('datetime','timestamp',$attributes);
		$attributes=str_replace('float','real',$attributes);
		//lowercase the fieldname and replace spaces with underscores
		$field=strtolower(trim($field));
		$field=str_replace(' ','_',$field);
		$lines[]= "	{$field} {$attributes}";
   	}
    $query .= implode(','.PHP_EOL,$lines).PHP_EOL;
    $query .= ")".PHP_EOL;
    return postgresqlExecuteSQL($query);
}
//---------- begin function postgresqlDropDBIndex--------------------
/**
* @describe drop an index previously created
* @param params array
*	-table
*	-name
* @return boolean
* @usage $ok=addDBIndex(array('-table'=>$table,'-name'=>"myindex"));
*/
function postgresqlDropDBIndex($params=array()){
	if(!isset($params['-table'])){return 'postgresqlDropDBIndex Error: No table';}
	if(!isset($params['-name'])){return 'postgresqlDropDBIndex Error: No name';}
	//check for schema name
	if(!stringContains($params['-table'],'.')){
		$schema=postgresqlGetDBSchema();
		if(strlen($schema) && !stringBeginsWith($params['-table'],"{$schema}.")){
			$params['-table']="{$schema}.{$params['-table']}";
		}
	}
	$params['-table']=strtolower($params['-table']);
	//build and execute
	$query="alter table {$params['-table']} drop index {$params['-name']}";
	return postgresqlExecuteSQL($query);
}
//---------- begin function postgresqlDropDBTable--------------------
/**
* @describe drops the specified table
* @param table string - name of table to drop
* @param [meta] boolean - also remove metadata in _fielddata and _tabledata tables associated with this table. defaults to true
* @return 1
* @usage $ok=dropDBTable('comments',1);
*/
function postgresqlDropDBTable($table='',$meta=1){
	if(!strlen($table)){return 0;}
	//check for schema name
	if(!stringContains($table,'.')){
		$schema=postgresqlGetDBSchema();
		if(strlen($schema) && !stringBeginsWith($table,"{$schema}.")){
			$table="{$schema}.{$table}";
		}
	}
	//drop indexes first
	$recs=postgresqlGetDBTableIndexes($table);
	if(is_array($recs)){
		foreach($recs as $rec){
	    	$key=$rec['key_name'];
	    	$ok=postgresqlDropDBIndex($table,$key);
		}
	}
	$result=postgresqlExecuteSQL("drop table {$table}");
	$ok=postgresqlDelDBRecord(array('-table'=>'_tabledata','-where'=>"tablename = '{$table}'"));
	$ok=postgresqlDelDBRecord(array('-table'=>"_fielddata",'-where'=>"tablename = '{$table}'"));
    return 1;
}
//---------- begin function postgresExceptionErrorHandler ----------
/**
* @describe returns connection resource
*/
function postgresExceptionErrorHandler($errno, $errstr, $errfile, $errline ) {
	    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
	}
//---------- begin function postgresqlDBConnect ----------
/**
* @describe returns connection resource
* @param $params array - These can also be set in the CONFIG file with dbname_postgresql,dbuser_postgresql, and dbpass_postgresql
*	[-host] - postgresql server to connect to
* 	[-dbname] - name of database.
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return connection resource and sets the global $dbh_postgresql variable.
* @usage $dbh_postgresql=postgresqlDBConnect($params);
*/
function postgresqlDBConnect(){
	$params=postgresqlParseConnectParams();
	if(!isset($params['-connect'])){
		debugValue("postgresqlDBConnect error: No connect params".printValue($params));
		return '';
	}
	global $dbh_postgresql;
	//if(is_resource($dbh_postgresql)){return $dbh_postgresql;}
	set_error_handler('postgresExceptionErrorHandler');
	try{
		$dbh_postgresql = pg_connect($params['-connect']);
	}
	catch (Exception $e) {
		$err=$e->getMessage();
		restore_error_handler();
		$params['-dbpass']=preg_replace('/./','*',$params['-dbpass']);
		debugValue("postgresqlDBConnect exception: {$err}. Will retry in 2 seconds" . printValue($params));
	}
	restore_error_handler();
	if(!is_resource($dbh_postgresql)){
		//try one more time after a couple of seconds
		sleep(2);
		set_error_handler('postgresExceptionErrorHandler');
		try{
			$dbh_postgresql = pg_connect($params['-connect']);
		}
		catch (Exception $e) {
			$err=$e->getMessage();
			restore_error_handler();
			$params['-dbpass']=preg_replace('/./','*',$params['-dbpass']);
			debugValue("postgresqlDBConnect retry exception : {$err}" . printValue($params));
		}
		restore_error_handler();
	}

	if(!is_resource($dbh_postgresql)){
		return '';
	}
	return $dbh_postgresql;
	
}
//---------- begin function postgresqlDelDBRecord ----------
/**
* @describe deletes records in table that match -where clause
* @param params array
*	-table string - name of table
*	-where string - where clause to filter what records are deleted
* @return boolean
* @usage $id=postgresqlDelDBRecord(array('-table'=> '_tabledata','-where'=> "_id=4"));
*/
function postgresqlDelDBRecord($params=array()){
	global $USER;
	if(!isset($params['-table'])){return 'postgresqlDelDBRecord error: No table specified.';}
	if(!isset($params['-where'])){return 'postgresqlDelDBRecord Error: No where';}
	//check for schema name
	if(!stringContains($params['-table'],'.')){
		$schema=postgresqlGetDBSchema();
		if(strlen($schema) && !stringBeginsWith($params['-table'],"{$schema}.")){
			$params['-table']="{$schema}.{$params['-table']}";
		}
	}
	$query="delete from {$params['-table']} where " . $params['-where'];
	return postgresqlExecuteSQL($query,$params);
}
//---------- begin function postgresqlEditDBRecord ----------
/**
* @describe edits a record from params passed in based on where.
*  if edate, and euser exists as fields then they are populated with the edit date and edit username
* @param $params array - These can also be set in the CONFIG file with dbname_postgresql,dbuser_postgresql, and dbpass_postgresql
*   -table - name of the table to add to
*   -where - filter criteria
*	[-host] - postgresql server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	other field=>value pairs to edit
* @return boolean returns true on success
* @usage $id=postgresqlEditDBRecord(array('-table'=>'abc','-where'=>"id=3",'name'=>'bob','age'=>25));
*/
function postgresqlEditDBRecord($params=array(),$id=0,$opts=array()){
	//check for function overload: editDBRecord(table,id,opts());
	if(!is_array($params) && strlen($params) && isNum($id) && $id > 0 && is_array($opts) && count($opts)){
		$opts['-table']=$params;
		$opts['-where']="_id={$id}";
		$params=$opts;
	}
	if(!isset($params['-table'])){return 'postgresqlEditRecord error: No table specified.';}
	if(!isset($params['-where'])){return 'postgresqlEditRecord error: No where specified.';}
	//check for schema name
	if(!stringContains($params['-table'],'.')){
		$schema=postgresqlGetDBSchema();
		if(strlen($schema) && !stringBeginsWith($params['-table'],"{$schema}.")){
			$params['-table']="{$schema}.{$params['-table']}";
		}
	}
	global $USER;
	$finfo=postgresqlGetDBFieldInfo($params['-table']);
	$opts=array();
	if(isset($finfo['edate'])){
		$params['edate']=strtoupper(date('Y-M-d H:i:s'));
	}
	elseif(isset($finfo['_edate'])){
		$params['_edate']=strtoupper(date('Y-M-d H:i:s'));
	}
	if(isset($finfo['euser'])){
		$params['euser']=(function_exists('isUser') && isUser())?$USER['_id']:0;
	}
	elseif(isset($finfo['_euser'])){
		$params['_euser']=(function_exists('isUser') && isUser())?$USER['_id']:0;
	}
	$sets=array();
	$values=array();
	foreach($params as $k=>$v){
		$k=strtolower($k);
		if(!strlen(trim($v))){continue;}
		if(!isset($finfo[$k])){continue;}
		if(is_array($params[$k])){
            $params[$k]=implode(':',$params[$k]);
		}
		$p=count($sets)+1;
		$sets[]="{$k}=\${$p}";
		$values[]=$params[$k];
	}
	$setstr=implode(', ',$sets);
	$output='';
	$output_field='_id';
	foreach($finfo as $field=>$info){
		if($info['identity']==1){
			$output=" RETURNING {$field}";
			$output_field=$field;
			break;
		}
		elseif($info['sequence']==1){
			$output=" RETURNING {$field}";
			$output_field=$field;
			break;
		}
		elseif($field=='_id'){
			$output=" RETURNING {$field}";
			break;
		}
	}
    $query=<<<ENDOFQUERY
		UPDATE {$params['-table']}
		SET {$setstr}
		WHERE {$params['-where']}
		{$output}
ENDOFQUERY;
	global $dbh_postgresql;
	if(!is_resource($dbh_postgresql)){
		$dbh_postgresql=postgresqlDBConnect();
	}
	if(!$dbh_postgresql){
		debugValue(array(
			'function'=>'postgresqlEditDBRecord',
			'message'=>'connect failed',
			'error'=>pg_last_error(),
			'query'=>$query
		));
    	return;
	}
	if(!pg_prepare($dbh_postgresql,'',$query)){
		debugValue(array(
			'function'=>'postgresqlEditDBRecord',
			'message'=>'pg_prepare failed',
			'error'=>pg_last_error(),
			'query'=>$query
		));
		pg_close($dbh_postgresql);
		return;
	}
	$data=pg_execute($dbh_postgresql,'',$values);
	$err=pg_result_error($data);
	if(strlen($err)){
		debugValue(array(
			'function'=>'postgresqlEditDBRecord',
			'message'=>'pg_execute failed',
			'error'=>$err,
			'query'=>$query,
			'values'=>$values,
		));
		pg_close($dbh_postgresql);
		return null;
	}
	$recs = postgresqlEnumQueryResults($data,$params);
	pg_close($dbh_postgresql);
	return $recs;
}
//---------- begin function postgresqlExecuteSQL ----------
/**
* @describe executes a query and returns without parsing the results
* @param $query string - query to execute
* @return int returns 1 if query succeeded, else 0
* @usage $ok=postgresqlExecuteSQL("truncate table abc");
*/
function postgresqlExecuteSQL($query,$return_error=1){
	global $dbh_postgresql;
	global $DATABASE;
	$DATABASE['_lastquery']=array(
		'start'=>microtime(true),
		'stop'=>0,
		'time'=>0,
		'error'=>'',
		'query'=>$query,
		'function'=>'postgresqlExecuteSQL'
	);

	$dbh_postgresql=postgresqlDBConnect();
	if(!$dbh_postgresql){
		$DATABASE['_lastquery']['error']='connect failed: '.pg_last_error();
		debugValue($DATABASE['_lastquery']);
		if($return_error==1){return $DATABASE['_lastquery'];}
		return 0;
	}
	try{
		$result=pg_query($dbh_postgresql,$query);
		if(!$result && stringContains(pg_last_error($dbh_postgresql),'server closed the connection unexpectedly')){
			pg_close($dbh_postgresql);
			usleep(200);
			$dbh_postgresql='';
			$dbh_postgresql=postgresqlDBConnect();
			$result=pg_query($dbh_postgresql,$query);
		}
		if(!$result){
			$DATABASE['_lastquery']['error']=pg_last_error($dbh_postgresql);
			debugValue($DATABASE['_lastquery']);
			pg_close($dbh_postgresql);
			if($return_error==1){return $DATABASE['_lastquery'];}
			return 0;
		}
		pg_close($dbh_postgresql);
		$DATABASE['_lastquery']['stop']=microtime(true);
		$DATABASE['_lastquery']['time']=$DATABASE['_lastquery']['stop']-$DATABASE['_lastquery']['start'];
		return 1;
	}
	catch (Exception $e) {
		$DATABASE['_lastquery']['error']=$e->errorInfo;
		debugValue($DATABASE['_lastquery']);
		if($return_error==1){return $DATABASE['_lastquery'];}
		return 0;
	}
	return 1;
}
//---------- begin function postgresqlGetDBCount--------------------
/**
* @describe returns a record count based on params
* @param params array - requires either -list or -table or a raw query instead of params
*	-table string - table name.  Use this with other field/value params to filter the results
* @return array
* @usage $cnt=postgresqlGetDBCount(array('-table'=>'states'));
*/
function postgresqlGetDBCount($params=array()){
	global $CONFIG;
	global $DATABASE;
	if(!isset($params['-table'])){return null;}
	$parts=preg_split('/\./',$params['-table']);
	if(count($parts)==2){
		$dbschema=strtolower($parts[0]);
		$table=strtolower($parts[1]);
	}
	else{
		$dbschema=strtolower($DATABASE[$CONFIG['db']]['dbschema']);
		$table=strtolower($params['-table']);
	}
	if(!stringContains($params['-table'],'.')){
		$schema=postgresqlGetDBSchema();
		if(strlen($schema) && !stringBeginsWith($params['-table'],"{$schema}.")){
			$params['-table']="{$schema}.{$params['-table']}";
		}
	}
	$params['-fields']="count(*) as cnt";
	unset($params['-order']);
	unset($params['-limit']);
	unset($params['-offset']);
	//$params['-debug']=1;
	$params['-queryonly']=1;
	$query=postgresqlGetDBRecords($params);
	if(!stringContains($query,'where') && strlen($dbschema)){
	 	$query="SELECT schemaname,relname,n_live_tup as cnt FROM pg_stat_user_tables where lower(schemaname)='{$dbschema}' and lower(relname)='{$table}'";
	 	$recs=postgresqlQueryResults($query);
	 	//echo $query.printValue($recs);exit;
	 	if(isset($recs[0]['cnt']) && isNum($recs[0]['cnt'])){
	 		return (integer)$recs[0]['cnt'];
	 	}
	}
	$recs=postgresqlQueryResults($query);
	if(!isset($recs[0]['cnt'])){
		debugValue(array(
			'function'=>'postgresqlGetDBCount',
			'message'=>'get count failed',
			'error'=>$recs,
			'params'=>$params
		));
		return 0;
	}
	return $recs[0]['cnt'];
}
//---------- begin function postgresqlGetDBDatabases ----------
/**
* @describe returns an array of databases
* @param [$params] array - These can also be set in the CONFIG file with dbname_postgresql,dbuser_postgresql, and dbpass_postgresql
*	[-host] - postgresql server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array returns array of databases
* @usage $tables=postgresqlGetDBDatabases();
*/
function postgresqlGetDBDatabases($params=array()){
	$query=<<<ENDOFQUERY
		SELECT datname as name 
		FROM pg_database
		WHERE datistemplate = false
ENDOFQUERY;
	$recs = postgresqlQueryResults($query,$params);
	return $recs;
}
//---------- begin function postgresqlGetDBFields ----------
/**
* @describe returns an array of field info. fieldname is the key, Each field returns name, type, length, num, default
* @param $params array - These can also be set in the CONFIG file with dbname_postgresql,dbuser_postgresql, and dbpass_postgresql
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return boolean returns true on success
* @usage $fieldinfo=postgresqlGetDBFieldInfo('test');
*/
function postgresqlGetDBFields($table,$allfields=0){
	$finfo=postgresqlGetDBFieldInfo($table);
	return array_keys($finfo);
}
//---------- begin function postgresqlGetAllTableFields ----------
/**
* @describe returns fields of all tables with the table name as the index
* @param [$schema] string - schema. defaults to dbschema specified in config
* @return array
* @usage $allfields=postgresqlGetAllTableFields();
*/
function postgresqlGetAllTableFields($schema=''){
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlGetAllFields');
	if(isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	if(!strlen($schema)){
		$schema=postgresqlGetDBSchema();
	}
	if(!strlen($schema)){
		debugValue('postgresqlGetAllFields error: schema is not defined in config.xml');
		return null;
	}
	$query=<<<ENDOFQUERY
		SELECT
			table_name as table_name,
			column_name as field_name,
			udt_name as type_name
		FROM information_schema.columns
		WHERE
			table_schema='{$schema}'
		ORDER BY table_name,column_name
ENDOFQUERY;
	$recs=postgresqlQueryResults($query);
	$databaseCache[$cachekey]=array();
	foreach($recs as $rec){
		$table=strtolower($rec['table_name']);
		//$field=strtolower($rec['field_name']);
		//$type=strtolower($rec['type_name']);
		$databaseCache[$cachekey][$table][]=$rec;
	}
	return $databaseCache[$cachekey];
}
//---------- begin function postgresqlAlterDBTable--------------------
/**
* @describe alters fields in given table
* @param table string - name of table to alter
* @param params array - list of field/attributes to edit
* @return mixed - 1 on success, error string on failure
* @usage
*	$ok=postgresqlAlterDBTable('comments',array('comment'=>"varchar(1000) NULL"));
*/
function postgresqlAlterDBTable($table,$fields=array()){
	$info=postgresqlGetDBFieldInfo($table);
	if(!is_array($info) || !count($info)){
		debugValue("postgresqlAlterDBTable - {$table} is missing or has no fields".printValue($table));
		return false;
	}
	$schema=postgresqlGetDBSchema();
	if(!strlen($schema)){
		debugValue('postgresqlAlterDBTable error: schema is not defined in config.xml');
		return null;
	}
	$rtn=array();
	//$rtn[]=$info;
	$addfields=array();
	foreach($fields as $name=>$type){
		$lname=strtolower($name);
		$uname=strtoupper($name);
		if(isset($info[$name]) || isset($info[$lname]) || isset($info[$uname])){continue;}
		$addfields[]="ADD COLUMN {$name} {$type}";
	}
	$dropfields=array();
	foreach($info as $name=>$finfo){
		$lname=strtolower($name);
		$uname=strtoupper($name);
		if(!isset($fields[$name]) && !isset($fields[$lname]) && !isset($fields[$uname])){
			$dropfields[]="DROP COLUMN {$name}";
		}
	}
	if(count($dropfields)){
		$fieldstr=implode(', ',$dropfields);
		$query="ALTER TABLE {$schema}.{$table} {$fieldstr}";
		$ok=postgresqlExecuteSQL($query);
		$rtn[]=$query;
		$rtn[]=$ok;
	}
	if(count($addfields)){
		$fieldstr=implode(', ',$addfields);
		$query="ALTER TABLE {$schema}.{$table} {$fieldstr}";
		$ok=postgresqlExecuteSQL($query);
		$rtn[]=$query;
		$rtn[]=$ok;
	}
	return $rtn;
}
//---------- begin function postgresqlGetAllProcedures ----------
/**
* @describe returns all procedures in said schema
* @param [$schema] string - schema. defaults to dbschema specified in config
* @return array
* @usage $allprocedures=postgresqlGetAllProcedures();
*/
function postgresqlGetAllProcedures($schema=''){
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlGetAllProcedures');
	if(isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	if(!strlen($schema)){
		$schema=postgresqlGetDBSchema();
	}
	if(!strlen($schema)){
		debugValue('postgresqlGetAllProcedures error: schema is not defined in config.xml');
		return null;
	}
	//key_name,column_name,seq_in_index,non_unique
	$schema=strtoupper($schema);
	//get source
	$query=<<<ENDOFQUERY
	SELECT 
		p.proname as object_name,
		case 
			when p.prokind = 'p' then 'PROCEDURE'
			when p.prokind = 'f' then 'FUNCTION'
			when p.prokind = 'a' then 'AGGREGATE FUNCTION'
			when p.prokind = 'w' then 'WINDOW FUNCTION'
			else 'UNKNOWN'
		end as object_type,
		md5(case 
			when l.lanname = 'internal' then p.prosrc
			else pg_get_functiondef(p.oid)
			end
			) as hash,
		pg_get_function_arguments(p.oid) as args
	FROM pg_proc p
		LEFT JOIN pg_namespace n on p.pronamespace = n.oid
		LEFT JOIN pg_language l on p.prolang = l.oid
		LEFT JOIN pg_type t on t.oid = p.prorettype 
	WHERE upper(n.nspname)='{$schema}'
	ORDER BY 1
ENDOFQUERY;
	$recs=postgresqlQueryResults($query);
	$databaseCache[$cachekey]=array();
	foreach($recs as $rec){
		$key=$rec['object_name'].$rec['object_type'];
		$databaseCache[$cachekey][$key][]=$rec;
	}
	return $databaseCache[$cachekey];
}
//---------- begin function postgresqlGetProcedureText ----------
/**
* @describe returns all procedures in said schema
* @param [$schema] string - schema. defaults to dbschema specified in config
* @return array
* @usage $allprocedures=postgresqlGetProcedureText();
*/
function postgresqlGetProcedureText($name='',$type='',$schema=''){
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlGetAllProcedures');
	if(isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	if(!strlen($schema)){
		$schema=postgresqlGetDBSchema();
	}
	if(!strlen($schema)){
		debugValue('postgresqlGetProcedureText error: schema is not defined in config.xml');
		return null;
	}
	//key_name,column_name,seq_in_index,non_unique
	$name=strtoupper($name);
	$type=strtolower(substr($type,0,1));
	$schema=strtoupper($schema);
	//get source
	$query=<<<ENDOFQUERY
	SELECT 
		p.proname as object_name,
		case 
			when l.lanname = 'internal' then p.prosrc
			else pg_get_functiondef(p.oid)
		end as text
	FROM pg_proc p
		LEFT JOIN pg_namespace n on p.pronamespace = n.oid
		LEFT JOIN pg_language l on p.prolang = l.oid
		LEFT JOIN pg_type t on t.oid = p.prorettype 
	WHERE 
		upper(n.nspname)='{$schema}'
		and upper(p.proname)='{$name}'
		and p.prokind='{$type}'
	ORDER BY 1
ENDOFQUERY;
	$recs=postgresqlQueryResults($query);
	$databaseCache[$cachekey]=preg_split('/[\r\n]+/',$recs[0]['text']);
	return $databaseCache[$cachekey];
}
//---------- begin function postgresqlGetAllTableConstraints ----------
/**
* @describe returns constraints (foreign keys) of all tables with the table name as the index
* @param [$schema] string - schema. defaults to dbschema specified in config
* @return array
* @usage $allconstraints=postgresqlGetAllTableConstraints();
*/
function postgresqlGetAllTableConstraints($schema=''){
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlGetAllTableConstraints');
	if(isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	if(!strlen($schema)){
		$schema=postgresqlGetDBSchema();
	}
	if(!strlen($schema)){
		debugValue('postgresqlGetAllTableConstraints error: schema is not defined in config.xml');
		return null;
	}
	//key_name,column_name,seq_in_index,non_unique
	$schema=strtoupper($schema);
	$query=<<<ENDOFQUERY
	SELECT
		tc.table_name, 
		kcu.column_name, 
		tc.constraint_name,
		ccu.table_name AS foreign_table_name,
		ccu.column_name AS foreign_column_name 
	FROM 
		information_schema.table_constraints AS tc 
		JOIN information_schema.key_column_usage AS kcu
			ON tc.constraint_name = kcu.constraint_name
			AND tc.table_schema = kcu.table_schema
		JOIN information_schema.constraint_column_usage AS ccu
			ON ccu.constraint_name = tc.constraint_name
			AND ccu.table_schema = tc.table_schema
	WHERE tc.table_schema = '{$schema}'
	ORDER BY 1,2,3
ENDOFQUERY;
	$recs=postgresqlQueryResults($query);
	//echo "{$CONFIG['db']}--{$schema}".$query.'<hr>';
	$databaseCache[$cachekey]=array();
	foreach($recs as $rec){
		$table=strtolower($rec['table_name']);
		$table=str_replace("{$schema}.",'',$table);
		$databaseCache[$cachekey][$table][]=$rec;
	}
	return $databaseCache[$cachekey];
}
//---------- begin function postgresqlGetAllTableIndexes ----------
/**
* @describe returns indexes of all tables with the table name as the index
* @param [$schema] string - schema. defaults to dbschema specified in config
* @return array
* @usage $allindexes=postgresqlGetAllTableIndexes();
*/
function postgresqlGetAllTableIndexes($schema=''){
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlGetAllIndexes');
	if(isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	if(!strlen($schema)){
		$schema=postgresqlGetDBSchema();
	}
	if(!strlen($schema)){
		debugValue('postgresqlGetAllIndexes error: schema is not defined in config.xml');
		return null;
	}
	//key_name,column_name,seq_in_index,non_unique
	$query=<<<ENDOFQUERY
	SELECT
	  	idx.indrelid :: REGCLASS AS table_name,
	  	i.relname                AS index_name,
	  	idx.indisunique          AS is_unique,
	  	idx.indisprimary         AS is_primary,
       	to_json(array(
           SELECT pg_get_indexdef(idx.indexrelid, k + 1, TRUE)
           FROM
             generate_subscripts(idx.indkey, 1) AS k
           ORDER BY k
       	)) AS index_keys
	FROM pg_index AS idx
  		JOIN pg_class AS i ON i.oid = idx.indexrelid
  		JOIN pg_namespace AS ns ON i.relnamespace = NS.OID
	WHERE ns.nspname = '{$schema}'
	ORDER BY 1,2
ENDOFQUERY;
	$recs=postgresqlQueryResults($query);
	$databaseCache[$cachekey]=array();
	foreach($recs as $rec){
		$table=strtolower($rec['table_name']);
		$table=str_replace("{$schema}.",'',$table);
		$databaseCache[$cachekey][$table][]=$rec;
	}
	return $databaseCache[$cachekey];
}
//---------- begin function postgresqlGetDBFieldInfo ----------
/**
* @describe returns an array of field info. fieldname is the key, Each field returns name, type, length, num, default
* @param $params array - These can also be set in the CONFIG file with dbname_postgresql,dbuser_postgresql, and dbpass_postgresql
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return boolean returns true on success
* @usage $fieldinfo=postgresqlGetDBFieldInfo('test');
*/
function postgresqlGetDBFieldInfo($table){
	$table=strtolower($table);
	global $databaseCache;
	global $CONFIG;
	//check for schema name
	if(stringContains($table,'.')){
		list($schema,$table)=preg_split('/\./',$table,2);
	}
	else{
		$schema=postgresqlGetDBSchema();
	}
	/*

	https://postgrespro.com/list/thread-id/1493099
	*/
	$query_old=<<<ENDOFQUERY
		SELECT
			table_schema,
			table_name,
			column_name,
			ordinal_position,
			column_default,
			is_nullable,
			data_type,
			character_maximum_length,
			numeric_precision,
			numeric_precision_radix,
			udt_name,
			is_identity	
		FROM information_schema.columns
		WHERE
			table_schema='{$schema}'
			and table_name='{$table}'
		ORDER BY ordinal_position
ENDOFQUERY;
	$query=<<<ENDOFQUERYNEW
	SELECT
		s.nspname as table_schema, 
		c.relname as table_name,
		a.attname as column_name,
		a.attnum as ordinal_position,
		pg_get_expr(d.adbin, d.adrelid) AS column_default,
		a.attnotnull as not_null,
		pg_catalog.format_type(a.atttypid, a.atttypmod) as data_type,
		a.attlen as character_maximum_length,
		a.attnum as numeric_precision,
		'' as numeric_precision_radix,
		'' as udt_name,
		CASE WHEN p.contype = 'p' THEN true ELSE false END AS primarykey,
    	CASE WHEN p.contype = 'u' THEN true ELSE false END AS uniquekey,
		a.attidentity as is_identity
	FROM pg_attribute a
		LEFT JOIN pg_catalog.pg_attrdef d ON (a.attrelid, a.attnum) = (d.adrelid,  d.adnum)
		JOIN pg_class c on a.attrelid = c.oid
		LEFT JOIN pg_constraint p ON p.conrelid = c.oid AND a.attnum = ANY (p.conkey)
		JOIN pg_namespace s on c.relnamespace = s.oid
	WHERE a.attnum > 0 
		AND NOT a.attisdropped			--<< no dropped (dead) columns
		AND c.relname = '{$table}' 	--<< table name 
		AND s.nspname = '{$schema}' 	--<< schema name 
	ORDER BY a.attnum
ENDOFQUERYNEW;
	$recs=postgresqlQueryResults($query);
	$fields=array();
	foreach($recs as $rec){
		$field=array(
			'_dbtable'	=> $rec['table_name'],
		 	'_dbfield'	=> strtolower($rec['column_name']),
		 	'_dbtype'	=> $rec['data_type'],
		 	'_dblength' => $rec['character_maximum_length'],
		 	'table'		=> $rec['table_name'],
		 	'name'		=> $rec['column_name'],
		 	'type'		=> $rec['data_type'],
			'length'	=> $rec['character_maximum_length'],
			'num'		=> $rec['numeric_precision'],
			'size'		=> $rec['numeric_precision_radix'],
			'identity'	=> strtolower($rec['is_identity'])=='yes'?1:0,
		);
		//nullable
		switch(strtolower($rec['not_null'])){
			case 't':
				$field['nullable']=0;
			break;
			default:
				$field['nullable']=1;
			break;

		}
		//dbtype
		switch(strtolower($field['_dbtype'])){
			case 'timestamp without time zone':
				$rec['data_type']=$field['_dbtype']='timestamp';
			break;
		}
		//echo printValue($field);
		//_dbtype_ex
		switch(strtolower($field['_dbtype'])){
			case 'bigint':
			case 'integer':
			case 'timestamp':
				$field['_dbtype_ex']=$field['_dbtype'];
			break;
			default:
				if(strlen($rec['character_maximum_length']) && $rec['character_maximum_length'] != '-1'){
					$field['_dbtype_ex']="{$rec['data_type']}({$rec['character_maximum_length']})";
				}
				else{
					$field['_dbtype_ex']=$field['_dbtype'];
				}
			break;
		}
		
		//default
		if(strlen($rec['column_default'])){
			$field['_dbdef']=$field['default']=$rec['column_default'];
		}
		$fields[$field['_dbfield']]=$field;
	}
	//echo $table.printValue($fields).'<hr>'.PHP_EOL;
	return $fields;
}
function postgresqlGetDBIndexes($tablename=''){
	return postgresqlGetDBTableIndexes($tablename);
}
function postgresqlGetDBTableIndexes($tablename=''){
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlGetDBTableIndexes'.$tablename);
	if(isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	//key_name,column_name,seq_in_index,non_unique
	$query=<<<ENDOFQUERY
	SELECT
  		U.usename AS user_name,
	  	ns.nspname               AS schema_name,
	  	idx.indrelid :: REGCLASS AS table_name,
	  	i.relname                AS index_name,
	  	idx.indisunique          AS is_unique,
	  	idx.indisprimary         AS is_primary,
	  	am.amname                AS index_type,
	  	idx.indkey,
       	to_json(array(
           SELECT pg_get_indexdef(idx.indexrelid, k + 1, TRUE)
           FROM
             generate_subscripts(idx.indkey, 1) AS k
           ORDER BY k
       	)) AS index_keys,
  		(idx.indexprs IS NOT NULL) OR (idx.indkey::int[] @> array[0]) AS is_functional,
  		idx.indpred IS NOT NULL AS is_partial
	FROM pg_index AS idx
  		JOIN pg_class AS i ON i.oid = idx.indexrelid
  		JOIN pg_am AS am ON i.relam = am.oid
  		JOIN pg_namespace AS NS ON i.relnamespace = NS.OID
  		JOIN pg_user AS U ON i.relowner = U.usesysid
	WHERE NOT nspname LIKE 'pg%'
ENDOFQUERY;
	if(strlen($tablename)){
		$schema=postgresqlGetDBSchema();
		if(strlen($schema) && !stringBeginsWith($tablename,"{$schema}.")){
			$tablename="{$schema}.{$tablename}";
		}
		$query .= " and idx.indrelid ='{$tablename}' :: REGCLASS ";
	}
	$recs=postgresqlQueryResults($query);
	$xrecs=array();
	foreach($recs as $rec){
		$cols=json_decode($rec['index_keys'],true);
		foreach($cols as $i=>$col){
			//key_name,column_name,is_primary,is_unique,seq_in_index
			$xrec=$rec;
			$xrec['key_name']=$rec['index_name'];
			$xrec['column_name']=$col;
			if(strtolower($rec['is_primary'])=='t'){
				$xrec['is_primary']=1;
			}
			if(strtolower($rec['is_unique'])=='t'){
				$xrec['is_unique']=1;
			}
			$xrec['seq_in_index']=$i;
			$xrecs[]=$xrec;
		}
	}
	$databaseCache[$cachekey]=$xrecs;
	return $databaseCache[$cachekey];
}
//---------- begin function postgresqlGetDBRecord ----------
/**
* @describe retrieves a single record from DB based on params
* @param $params array
* 	-table 	  - table to query
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return array recordset
* @usage $rec=postgresqlGetDBRecord(array('-table'=>'tesl));
*/
function postgresqlGetDBRecord($params=array()){
	$recs=postgresqlGetDBRecords($params);
	if(isset($recs[0])){return $recs[0];}
	return null;
}
//---------- begin function postgresqlGetDBRecords
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
*	<?=postgresqlGetDBRecords(array('-table'=>'notes'));?>
*	<?=postgresqlGetDBRecords("select * from myschema.mytable where ...");?>
*/
function postgresqlGetDBRecords($params){
	global $USER;
	global $CONFIG;
	if(empty($params['-table']) && !is_array($params)){
		$params=trim($params);
		if(preg_match('/^(select|analyze|exec|with|explain|returning|show|call)[\t\s\ \r\n]/i',$params)){
			//they just entered a query
			$query=$params;
			$params=array();
		}
		else{
			$ok=postgresqlExecuteSQL($params);
			return $ok;
		}
	}
	elseif(isset($params['-query'])){
		$query=$params['-query'];
		unset($params['-query']);
	}
	else{
		if(empty($params['-table'])){
			debugValue(array(
				'function'=>'postgresqlGetDBRecords',
				'message'=>'no table',
				'params'=>$params
			));
	    	return null;
		}
		//check for schema name
		if(!stringContains($params['-table'],'.')){
			$schema=postgresqlGetDBSchema();
			if(strlen($schema) && !stringBeginsWith($params['-table'],"{$schema}.")){
				$params['-table']="{$schema}.{$params['-table']}";
			}
		}
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
		$fields=postgresqlGetDBFieldInfo($params['-table']);
		//echo printValue($fields);
		$ands=array();
		foreach($params as $k=>$v){
			$k=strtolower($k);
			if(!isset($fields[$k])){continue;}
			if(is_array($params[$k])){
	            $params[$k]=implode(':',$params[$k]);
			}
			elseif(!strlen(trim($params[$k]))){continue;}	
	        $params[$k]=str_replace("'","''",$params[$k]);
	        switch(strtolower($fields[$k])){
	        	case 'char':
	        	case 'varchar':
	        		$v=strtoupper($params[$k]);
	        		$ands[]="upper({$k})='{$v}'";
	        	break;
	        	case 'int':
	        	case 'int4':
	        	case 'numeric':
	        		$ands[]="{$k}={$v}";
	        	break;
	        	default:
	        		$ands[]="{$k}='{$v}'";
	        	break;

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
	if(isset($params['-queryonly'])){return $query;}
	return postgresqlQueryResults($query,$params);
}
//---------- begin function
/**
* @exclude  - this function is for internal use only and thus excluded from the manual
*/
function postgresqlGetDBVersion(){
	global $dbh_postgresql;
	if(!is_resource($dbh_postgresql)){
		$dbh_postgresql=postgresqlDBConnect();
	}
	return pg_version($dbh_postgresql);	
}
//---------- begin function postgresqlGrepDBTables ----------
/**
* grepDBTables - searches across tables for a specified value
* @param search string
* @param tables array - optional. defaults to all tables except for _changelog,_cronlog, and _errors
* @return  array of arrays - tablename,_id,fieldname,search_count
* @usage $results=postgresqlGrepDBTables('searchstring');
*/
function postgresqlGrepDBTables($search,$tables=array(),$dbname=''){
	if(!is_array($tables)){
		if(strlen($tables)){$tables=array($tables);}
		else{$tables=array();}
	}
	if(!count($tables)){
		$tables=postgresqlGetDBTables($dbname);
		//ignore _changelog
		foreach($tables as $i=>$table){
			if(in_array($table,array('_changelog','_cronlog','_errors'))){unset($tables[$i]);}
		}
	}
	//return $tables;
	$search=trim($search);
	if(!strlen($search)){return "grepDBTables Error: no search value";}
	$results=array();
	$search=str_replace("'","''",$search);
	$search=strtolower($search);
	foreach($tables as $table){
		if(strlen($dbname)){$table=$dbname.'.'.$table;}
		if(!postgresqlIsDBTable($table)){return "grepDBTables Error: {$table} is not a table";}
		$info=postgresqlGetDBFieldInfo($table);
		$wheres=array();
		$fields=array();
		foreach($info as $field=>$finfo){
			switch($info[$field]['_dbtype']){
				case 'int':
				case 'integer':
				case 'number':
				case 'float':
					if(isNum($search)){
						$wheres[]="{$field}={$search}";
						$fields[]=$field;
					}
				break;
				case 'varchar':
				case 'char':
				case 'string':
				case 'blob':
				case 'text':
				case 'mediumtext':
					$wheres[]="{$field} like '%{$search}%'";
					$fields[]=$field;
				break;
			}
		}
		if(!count($wheres)){continue;}
		if(!in_array('_id',$fields)){array_unshift($fields,'_id');}
		$where=implode(' or ',$wheres);
		$fields=implode(',',$fields);
		$recopts=array('-table'=>$table,'-where'=>$where,'-fields'=>$fields);
		$recs=postgresqlGetDBRecords($recopts);
		if(is_array($recs)){
			$cnt=count($recs);
			foreach($recs as $rec){
				$vals=array();
				foreach($rec as $key=>$val){
					if(stringContains($val,$search)){
						$results[]=array(
							'tablename'=>$table,
							'_id'		=> $rec['_id'],
							'fieldname' => $key,
							'search_count'=> substr_count(strtolower($val),$search)
						);
					}
				}
			}
		}
	}
	return $results;
}
//---------- begin function
/**
* @exclude  - this function is for internal use only and thus excluded from the manual
*/
function postgresqlTranslateDataType($str){
	$parts=preg_split('/[,()]/',$str);
	$name=strtolower($parts[0]);
	//echo "postgresqlTranslateDataType({$str}):{$name}".printValue($parts).'<hr>'.PHP_EOL;
	switch(strtolower($name)){
		case 'tinyint':return 'int2';break;
		case 'smallint':return 'int4';break;
    	case 'bigint':return 'bigint';break;
    	case 'real':
    		if(count($parts)==3){return "numeric({$parts[1]},{$parts[2]})";}
    		elseif(count($parts)==2){return "numeric({$parts[1]})";}
    		else{return 'numeric';}
    	break;
    	case 'integer':
    	case 'int':
    		return 'integer';
    	break;
    	case 'json':return 'json';break;
    	case 'date':
    	case 'seconddate':
    		return 'date';
    	break;
    	case 'time':return 'time';break;
    	case 'datetime':
    	case 'timestamp':
    		return 'timestamp';
    	break;
    	case 'numeric':
    	case 'decimal':
    	case 'number':
    		if(count($parts)==3){return "decimal({$parts[1]},{$parts[2]})";}
    		elseif(count($parts)==2){return "decimal({$parts[1]})";}
    		else{return 'decimal';}
    	break;
    	case 'money':return 'money';break;
    	case 'tinytext':
    	case 'mediumtext':
    	case 'longtext':
			return 'text';
		break;
		case 'varchar':
		case 'nvarchar':
		case 'varchar2':
			if(count($parts)==2){return "varchar({$parts[1]})";}
    		else{return 'varchar(255)';}
    	break;
    	case 'char':
    	case 'nchar':
			if(count($parts)==2){
				//use char ONLY if the len is 1
				if($parts[1]==1){return "char({$parts[1]})";}
				else{return "varchar({$parts[1]})";}
			}
    		else{return 'varchar(255)';}
    	break;
	}
	return $str;
}
//---------- begin function postgresqlIsDBTable ----------
/**
* @describe returns true if table already exists
* @param table string
* @return boolean
* @usage if(postgresqlIsDBTable('_users')){...}
*/
function postgresqlIsDBTable($table='',$force=0){
	$table=strtolower($table);
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlIsDBTable'.$table);
	if($force==0 && isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	$schema=postgresqlGetDBSchema();
	if(strlen($schema)){
		$query="SELECT tablename FROM pg_catalog.pg_tables where schemaname='{$schema}' and tablename='{$table}'";
	}
	else{
		$query="SELECT tablename FROM pg_catalog.pg_tables where tablename='{$table}'";
	}
	$recs = postgresqlQueryResults($query);
	if(isset($recs[0]['tablename'])){
		$databaseCache[$cachekey]=true;
	}
	else{
		$databaseCache[$cachekey]=false;
	}
	return $databaseCache[$cachekey];
}

//---------- begin function postgresqlGetDBTables ----------
/**
* @describe returns an array of tables
* @param [$params] array - These can also be set in the CONFIG file with dbname_postgresql,dbuser_postgresql, and dbpass_postgresql
* @return array returns array of tables
* @usage $tables=postgresqlGetDBTables();
*/
function postgresqlGetDBTables($params=array()){
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlGetDBTables');
	if(isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	$databaseCache[$cachekey]=array();
	global $CONFIG;
	$include_schema=1;
	$schema=postgresqlGetDBSchema();
	if(strlen($schema)){
		$query="SELECT schemaname,tablename FROM pg_catalog.pg_tables where schemaname='{$schema}' order by tablename";
	}
	else{
		$query="SELECT schemaname,tablename FROM pg_catalog.pg_tables order by tablename";
	}
	$recs = postgresqlQueryResults($query);
	foreach($recs as $rec){
		$databaseCache[$cachekey][]=strtolower($rec['tablename']);
	}
	return $databaseCache[$cachekey];
}
//---------- begin function postgresqlGetDBViews ----------
/**
* @describe returns an array of views
* @return array returns array of views
* @usage $views=postgresqlGetDBViews();
*/
function postgresqlGetDBViews($params=array()){
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlGetDBViews');
	if(isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	$databaseCache[$cachekey]=array();
	global $CONFIG;
	$include_schema=1;
	$schema=postgresqlGetDBSchema();
	if(strlen($schema)){
		$query="SELECT schemaname,tablename FROM pg_catalog.pg_views where schemaname='{$schema}' order by tablename";
	}
	else{
		$query="SELECT schemaname,tablename FROM pg_catalog.pg_views order by tablename";
	}
	$recs = postgresqlQueryResults($query);
	foreach($recs as $rec){
		$databaseCache[$cachekey][]=strtolower($rec['tablename']);
	}
	return $databaseCache[$cachekey];
}
//---------- begin function postgresqlGetDBTablePrimaryKeys ----------
/**
* @describe returns an array of primary key fields for the specified table
* @param table string - specified table
* @return array returns array of primary key fields
* @usage $fields=postgresqlGetDBTablePrimaryKeys($table);
*/
function postgresqlGetDBTablePrimaryKeys($table){
	$table=strtolower($table);
	global $databaseCache;
	global $CONFIG;
	$cachekey=sha1(json_encode($CONFIG).'postgresqlGetDBTablePrimaryKeys'.$table);
	if(isset($databaseCache[$cachekey])){
		return $databaseCache[$cachekey];
	}
	//check for schema name
	if(!stringContains($table,'.')){
		$schema=postgresqlGetDBSchema();
		if(strlen($schema) && !stringBeginsWith($table,"{$schema}.")){
			$table="{$schema}.{$table}";
		}
	}

	$databaseCache[$cachekey]=array();
	$parts=preg_split('/\./',$table,2);
	$where='';
	if(count($parts)==2){
		$where = " and kc.table_schema='{$parts[0]}' and kc.table_name='{$parts[1]}'";
	}
	else{
		$where = " and kc.table_name='{$parts[0]}'";
	}
	$dbname=postgresqlGetConfigValue('dbname');
	$query=<<<ENDOFQUERY
		SELECT 	
			kc.table_schema,
			kc.table_name,
			kc.column_name,
			kc.constraint_name,
			kc.ordinal_position
		FROM  
		    information_schema.table_constraints tc,  
		    information_schema.key_column_usage kc  
		where 
		    tc.constraint_type = 'PRIMARY KEY' 
		    and kc.table_name = tc.table_name 
		    and kc.table_schema = tc.table_schema
		    and tc.table_catalog = '{$dbname}'
		    and kc.constraint_name = tc.constraint_name
		    {$where}
		order by kc.ordinal_position
ENDOFQUERY;
	$tmp = postgresqlQueryResults($query);
	foreach($tmp as $rec){
		$databaseCache[$cachekey][]=$rec['column_name'];
    }
	return $databaseCache[$cachekey];
}
function postgresqlGetDBSchema(){
	global $CONFIG;
	global $DATABASE;
	$params=postgresqlParseConnectParams();
	if(isset($CONFIG['db']) && isset($DATABASE[$CONFIG['db']]['dbschema'])){
		return $DATABASE[$CONFIG['db']]['dbschema'];
	}
	elseif(isset($CONFIG['dbschema'])){return $CONFIG['dbschema'];}
	elseif(isset($CONFIG['-dbschema'])){return $CONFIG['-dbschema'];}
	elseif(isset($CONFIG['schema'])){return $CONFIG['schema'];}
	elseif(isset($CONFIG['-schema'])){return $CONFIG['-schema'];}
	elseif(isset($CONFIG['postgresql_dbschema'])){return $CONFIG['postgresql_dbschema'];}
	elseif(isset($CONFIG['postgresql_schema'])){return $CONFIG['postgresql_schema'];}
	return '';
}

function postgresqlGetConfigValue($field){
	//dbschema, dbname
	global $CONFIG;
	switch(strtolower($CONFIG['dbtype'])){
		case 'postgres':
		case 'postgresql':
			if(isset($CONFIG[$field])){return $CONFIG[$field];}
			elseif(isset($CONFIG["postgresql_{$field}"])){return $CONFIG["postgresql_{$field}"];}
		break;
		default:
			if(isset($CONFIG["postgresql_{$field}"])){return $CONFIG["postgresql_{$field}"];}
		break;
	}
	return null;
}
//---------- begin function postgresqlListRecords
/**
* @describe returns an html table of records from a mmsql database. refer to databaseListRecords
*/
function postgresqlListRecords($params=array()){
	$params['-database']='postgresql';
	return databaseListRecords($params);
}
//---------- begin function postgresqlParseConnectParams ----------
/**
* @describe parses the params array and checks in the CONFIG if missing
* @param [$params] array - These can also be set in the CONFIG file with dbname_postgresql,dbuser_postgresql, and dbpass_postgresql
*	[-host] - postgresql server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return $params array
* @usage $params=postgresqlParseConnectParams($params);
*/
function postgresqlParseConnectParams($params=array()){
	global $CONFIG;
	global $DATABASE;
	global $USER;
	if(isset($CONFIG['db']) && isset($DATABASE[$CONFIG['db']])){
		foreach($CONFIG as $k=>$v){
			if(preg_match('/^postgres/i',$k)){unset($CONFIG[$k]);}
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
	if(isPostgreSQL()){
		$params['-dbhost']=$CONFIG['dbhost'];
		if(isset($CONFIG['dbname'])){
			$params['-dbname']=$CONFIG['dbname'];
		}
		if(isset($CONFIG['dbuser'])){
			$params['-dbuser']=$CONFIG['dbuser'];
		}
		if(isset($CONFIG['dbpass'])){
			$params['-dbpass']=$CONFIG['dbpass'];
		}
		if(isset($CONFIG['dbport'])){
			$params['-dbport']=$CONFIG['dbport'];
		}
		if(isset($CONFIG['dbconnect'])){
			$params['-connect']=$CONFIG['dbconnect'];
		}
	}
	//dbhost
	if(!isset($params['-dbhost'])){
		if(isset($CONFIG['dbhost_postgresql'])){
			$params['-dbhost']=$CONFIG['dbhost_postgresql'];
			//$params['-dbhost_source']="CONFIG dbhost_postgresql";
		}
		elseif(isset($CONFIG['postgresql_dbhost'])){
			$params['-dbhost']=$CONFIG['postgresql_dbhost'];
			//$params['-dbhost_source']="CONFIG postgresql_dbhost";
		}
		else{
			$params['-dbhost']=$params['-dbhost_source']='localhost';
		}
	}
	else{
		//$params['-dbhost_source']="passed in";
	}
	$CONFIG['postgresql_dbhost']=$params['-dbhost'];
	
	//dbuser
	if(!isset($params['-dbuser'])){
		if(isset($CONFIG['dbuser_postgresql'])){
			$params['-dbuser']=$CONFIG['dbuser_postgresql'];
			//$params['-dbuser_source']="CONFIG dbuser_postgresql";
		}
		elseif(isset($CONFIG['postgresql_dbuser'])){
			$params['-dbuser']=$CONFIG['postgresql_dbuser'];
			//$params['-dbuser_source']="CONFIG postgresql_dbuser";
		}
	}
	else{
		//$params['-dbuser_source']="passed in";
	}
	$CONFIG['postgresql_dbuser']=$params['-dbuser'];
	//dbpass
	if(!isset($params['-dbpass'])){
		if(isset($CONFIG['dbpass_postgresql'])){
			$params['-dbpass']=$CONFIG['dbpass_postgresql'];
			//$params['-dbpass_source']="CONFIG dbpass_postgresql";
		}
		elseif(isset($CONFIG['postgresql_dbpass'])){
			$params['-dbpass']=$CONFIG['postgresql_dbpass'];
			//$params['-dbpass_source']="CONFIG postgresql_dbpass";
		}
	}
	else{
		//$params['-dbpass_source']="passed in";
	}
	$CONFIG['postgresql_dbpass']=$params['-dbpass'];
	//dbname
	if(!isset($params['-dbname'])){
		if(isset($CONFIG['dbname_postgresql'])){
			$params['-dbname']=$CONFIG['dbname_postgresql'];
			//$params['-dbname_source']="CONFIG dbname_postgresql";
		}
		elseif(isset($CONFIG['postgresql_dbname'])){
			$params['-dbname']=$CONFIG['postgresql_dbname'];
			//$params['-dbname_source']="CONFIG postgresql_dbname";
		}
		else{
			$params['-dbname']=$CONFIG['postgresql_dbname'];
			//$params['-dbname_source']="set to username";
		}
	}
	else{
		//$params['-dbname_source']="passed in";
	}
	$CONFIG['postgresql_dbname']=$params['-dbname'];
	//dbport
	if(!isset($params['-dbport'])){
		if(isset($CONFIG['dbport_postgresql'])){
			$params['-dbport']=$CONFIG['dbport_postgresql'];
			//$params['-dbport_source']="CONFIG dbport_postgresql";
		}
		elseif(isset($CONFIG['postgresql_dbport'])){
			$params['-dbport']=$CONFIG['postgresql_dbport'];
			//$params['-dbport_source']="CONFIG postgresql_dbport";
		}
		else{
			$params['-dbport']=5432;
			//$params['-dbport_source']="default port";
		}
	}
	else{
		//$params['-dbport_source']="passed in";
	}
	$CONFIG['postgresql_dbport']=$params['-dbport'];
	//dbschema
	if(!isset($params['-dbschema'])){
		if(isset($CONFIG['dbschema_postgresql'])){
			$params['-dbschema']=$CONFIG['dbschema_postgresql'];
			//$params['-dbuser_source']="CONFIG dbuser_postgresql";
		}
		elseif(isset($CONFIG['postgresql_dbschema'])){
			$params['-dbschema']=$CONFIG['postgresql_dbschema'];
			//$params['-dbuser_source']="CONFIG postgresql_dbuser";
		}
	}
	else{
		//$params['-dbuser_source']="passed in";
	}
	$CONFIG['postgresql_dbschema']=$params['-dbschema'];
	//connect
	if(!isset($params['-connect'])){
		if(isset($CONFIG['postgresql_connect'])){
			$params['-connect']=$CONFIG['postgresql_connect'];
			//$params['-connect_source']="CONFIG postgresql_connect";
		}
		elseif(isset($CONFIG['connect_postgresql'])){
			$params['-connect']=$CONFIG['connect_postgresql'];
			//$params['-connect_source']="CONFIG connect_postgresql";
		}
		else{
			//build connect - http://php.net/manual/en/function.pg-connect.php
			//$conn_string = "host=sheep port=5432 dbname=test user=lamb password=bar";
			$params['-connect']="host={$CONFIG['postgresql_dbhost']} port={$CONFIG['postgresql_dbport']} dbname={$CONFIG['postgresql_dbname']} user={$CONFIG['postgresql_dbuser']} password={$CONFIG['postgresql_dbpass']}";
			//$params['-connect_source']="manual";
		}
		//add application_name
		if(!stringContains($params['-connect'],'options')){
			if(isset($params['-application_name'])){
				$appname=$params['-application_name'];
			}
			elseif(isset($CONFIG['postgres_application_name'])){
				$appname=$CONFIG['postgres_application_name'];
			}
			else{
				$appname='WaSQL_on_'.$_SERVER['HTTP_HOST'];
			}
			$appname=str_replace(' ','_',$appname);
			$params['-connect'].=" options='--application_name={$appname}'";
		}
		//add connect_timeout
		if(!stringContains($params['-connect'],'connect_timeout')){
			$params['-connect'].=" connect_timeout=10";
		}
		//add sslmode=disable
		if(!stringContains($params['-connect'],'sslmode')){
			$params['-connect'].=" sslmode=disable";
		}
	}
	else{
		//$params['-connect_source']="passed in";
	}
	return $params;
}
//---------- begin function postgresqlQueryResults ----------
/**
* @describe returns the postgresql record set
* @param query string - SQL query to execute
* @param [$params] array - These can also be set in the CONFIG file with dbname_postgresql,dbuser_postgresql, and dbpass_postgresql
*	[-host] - postgresql server to connect to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	[-filename] - if you pass in a filename then it will write the results to the csv filename you passed in
* @return array - returns records
*/
function postgresqlQueryResults($query='',$params=array()){
	global $DATABASE;
	$DATABASE['_lastquery']=array(
		'start'=>microtime(true),
		'stop'=>0,
		'time'=>0,
		'error'=>'',
		'query'=>$query,
		'function'=>'postgresqlQueryResults',
		'params'=>$params
	);
	$query=trim($query);
	global $USER;
	global $dbh_postgresql;
	$dbh_postgresql='';
	$dbh_postgresql=postgresqlDBConnect();
	if(!$dbh_postgresql){
		$DATABASE['_lastquery']['error']='connect failed: '.pg_last_error();
		debugValue($DATABASE['_lastquery']);
    	return array();
	}
	$data=pg_query($dbh_postgresql,$query);
	if(!$data && stringContains(pg_last_error($dbh_postgresql),'server closed the connection unexpectedly')){
		//lets try one more time
		usleep(200);
		$dbh_postgresql='';
		$dbh_postgresql=postgresqlDBConnect();
		$data=pg_query($dbh_postgresql,$query);
	}
	if(!$data){
		$DATABASE['_lastquery']['error']=pg_last_error($dbh_postgresql);
		debugValue($DATABASE['_lastquery']);
		return array();
	}
	if(preg_match('/^insert /i',$query) && !stringContains($query,' returning ')){
    	//return the id inserted on insert statements
    	$id=databaseAffectedRows($data);
    	pg_close($dbh_postgresql);
    	$DATABASE['_lastquery']['stop']=microtime(true);
		$DATABASE['_lastquery']['time']=$DATABASE['_lastquery']['stop']-$DATABASE['_lastquery']['start'];
    	return $id;
	}
	$results = postgresqlEnumQueryResults($data,$params);
	pg_close($dbh_postgresql);

	if(!is_array($results) && stringContains($results,'server closed the connection unexpectedly')){
		$DATABASE['_lastquery']['error']=$results." **NOTICE** make sure pgsql.auto_reset_persistent in php.ini is set to On. This usually resolved this issue.";
		debugValue($DATABASE['_lastquery']);
		return array();
		
	}
	$DATABASE['_lastquery']['stop']=microtime(true);
	$DATABASE['_lastquery']['time']=$DATABASE['_lastquery']['stop']-$DATABASE['_lastquery']['start'];
	return $results;
}
//---------- begin function postgresqlEnumQueryResults ----------
/**
* @describe enumerates through the data from a pg_query call
* @exclude - used for internal user only
* @param data resource
* @return array
*	returns records
*/
function postgresqlEnumQueryResults($data,$params=array()){
	global $postgresqlStopProcess;
	if(!is_resource($data)){return null;}
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
			pg_free_result($result);
			debugValue('postgresqlEnumQueryResults error: Failed to open '.$params['-filename']);
			return array();
		}
		if(isset($params['-logfile'])){
			setFileContents($params['-logfile'],$query.PHP_EOL.PHP_EOL);
		}
		
	}
	else{$recs=array();}
	$i=0;
	$writefile=0;
	if(isset($fh) && is_resource($fh)){
		$writefile=1;
	}
	while ($row = @pg_fetch_assoc($data)){
		//check for postgresqlStopProcess request
		if(isset($postgresqlStopProcess) && $postgresqlStopProcess==1){
			break;
		}
		$rec=array();
		foreach($row as $key=>$val){
			$key=strtolower($key);
			$rec[$key]=$val;
    	}
    	if($writefile==1){
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
			if(isset($params['-process'])){
				$ok=call_user_func($params['-process'],$rec);
			}
			continue;
		}
		elseif(isset($params['-process'])){
			$ok=call_user_func($params['-process'],$rec);
			$i++;
			continue;
		}
		elseif(isset($params['-index']) && isset($rec[$params['-index']])){
			$recs[$rec[$params['-index']]]=$rec;
		}
		else{
			$recs[]=$rec;
		}
	}
	if($writefile==1){
		@fclose($fh);
		if(isset($params['-logfile']) && file_exists($params['-logfile'])){
			$elapsed=microtime(true)-$starttime;
			appendFileContents($params['-logfile'],"Line count:{$i}, Execute Time: ".verboseTime($elapsed).PHP_EOL);
		}
		return $i;
	}
	elseif(isset($params['-process'])){
		return $i;
	}
	return $recs;
}
//---------- begin function postgresqlListDBDatatypes ----
/**
* @describe returns the data types for postgres
* @return string
* @author slloyd
* @exclude  - this function is for internal use only and thus excluded from the manual
*/
function postgresqlListDBDatatypes(){
	//default to mysql
	return <<<ENDOFDATATYPES
<div class="w_bold w_blue w_padtop">Text Types</div>
<div class="w_padleft">CHAR( ) A fixed length string with a maximum size of 1 GB.</div>
<div class="w_padleft">VARCHAR( ) A variable length string with a maximum size of 1 GB.</div>
<div class="w_padleft">TEXT A string with a maximum size of 1 GB.</div>
<div class="w_padleft">JSON textual JSON data with a maximum size of 1 GB</div>
<div class="w_padleft">XML textual XML data with a maximum size of 1 GB</div>

<div class="w_bold w_blue w_padtop">Number Types</div>
<div class="w_padleft">INT2 - signed two-byte integer</div>
<div class="w_padleft">INT4 or INT - signed four-byte integer</div>
<div class="w_padleft">INT8 or BIGINT - signed eight-byte integer</div>
<div class="w_padleft">FLOAT8 - double precision floating-point number (8 bytes)</div>
<div class="w_padleft">Float4 or REAL - single precision floating-point number (4 bytes)</div>
<div class="w_padleft">NUMERIC( , ) OR DECIMAL( , ) - exact numeric of selectable precision</div>

<div class="w_bold w_blue w_padtop">Date Types</div>
<div class="w_padleft">DATE YYYY-MM-DD.</div>
<div class="w_padleft">TIMESTAMP YYYYMMDDHHMMSS.</div>
<div class="w_padleft">TIME HH:MM:SS.</div>
ENDOFDATATYPES;
}
//---------- begin function postgresqlCancelQuery ----------
/**
* @describe cancels specified query pid(s)
* @param pids mixed - pid(s) to process - comma separated or an array of pids
* @return boolean
*/
function postgresqlCancelQuery($pids){
	if(is_array($pids)){
		$pids=preg_split('/[\,\:]+/',$pids);
	}
	$recs=array();
	foreach($pids as $pid){
		$pid=trim($pid);
		if(!isNum($pid)){continue;}
		$recs[]=array('pid'=>$pid);
	}
	$json=json_encode($recs);
	$query=<<<ENDOFQUERY
	with x as (select * from json_array_elements('{$json}'))
	select pg_cancel_backend(x.pid) from x
ENDOFQUERY;
	return postgresqlExecuteSQL($query);
}
//---------- begin function postgresqlNamedQuery ----------
/**
* @describe returns pre-build queries based on name
* @param name string
*	[running_queries]
*	[table_locks]
* @return query string
*/
function postgresqlNamedQuery($name){
	$schema=postgresqlGetDBSchema();
	switch(strtolower($name)){
		case 'running_queries':
			return <<<ENDOFQUERY
SELECT
	s.pid,
	s.client_addr,
	s.application_name,
	s.state,
	s.usename,
	s.query,
	l.mode,
	l.locktype,
	l.granted
FROM pg_stat_activity s
inner join pg_locks l on s.pid = l.pid
where query not like '%l.granted%'
and query not like 'autovacuum%'
GROUP BY
	s.pid,
	s.client_addr,
	s.application_name,
	s.state,
	s.usename,
	s.query,
	l.mode,
	l.locktype,
	l.granted
ENDOFQUERY;
		break;
		case 'sessions':
			return <<<ENDOFQUERY
select pid as process_id, 
       usename as username, 
       datname as database_name, 
       client_addr as client_address, 
       application_name,
       backend_start,
       state,
       state_change
from pg_stat_activity
order by state,application_name,database_name,username
ENDOFQUERY;
		break;
		case 'tables':
			if(strlen($schema)){
				$query="SELECT schemaname,tablename FROM pg_catalog.pg_tables where schemaname='{$schema}' order by tablename";
			}
			else{
				$query="SELECT schemaname,tablename FROM pg_catalog.pg_tables order by tablename";
			}
			return $query;
		break;
		case 'table_locks':
			return <<<ENDOFQUERY
select pid, 
       usename, 
       pg_blocking_pids(pid) as blocked_by, 
       query as blocked_query
from pg_stat_activity
where cardinality(pg_blocking_pids(pid)) > 0
ENDOFQUERY;
		break;
		case 'functions':
			return <<<ENDOFQUERY
SELECT 
	routines.specific_schema,
	routines.routine_catalog,
	routines.routine_name, 
	parameters.data_type, 
	parameters.ordinal_position,
	routines.routine_definition
FROM information_schema.routines
    LEFT JOIN information_schema.parameters ON routines.specific_name=parameters.specific_name
WHERE routines.routine_type='FUNCTION' and routines.specific_schema not in ('information_schema','pg_catalog')
ORDER BY routines.routine_name, parameters.ordinal_position;
ENDOFQUERY;
		break;
		case 'procedures':
			return <<<ENDOFQUERY
SELECT 
	routines.specific_schema,
	routines.routine_catalog,
	routines.routine_name, 
	parameters.data_type, 
	parameters.ordinal_position,
	routines.routine_definition
FROM information_schema.routines
    LEFT JOIN information_schema.parameters ON routines.specific_name=parameters.specific_name
WHERE routines.routine_type='PROCEDURE' and routines.specific_schema not in ('information_schema','pg_catalog')
ORDER BY routines.routine_name, parameters.ordinal_position;
ENDOFQUERY;
		break;
	}
}
function postgresqlOptimizations($params=array()){
	global $DATABASE;
	global $CONFIG;
	$db=$CONFIG['db'];
	$db_user=$DATABASE[$db]['dbuser'];
	$postgres=array();
	//get pg_settings
	$recs=postgresqlQueryResults("select name,setting from pg_settings");
	foreach($recs as $rec){
		$key=strtolower($rec['name']);
		$postgres[$key]=$rec['setting'];
	}
	//all_databases_size
	$recs=postgresqlQueryResults('select sum(pg_database_size(datname)) as val from pg_database');
	$postgres['all_databases_size']=$recs[0]['val'];
	//uptime
	$recs=postgresqlQueryResults('select extract(epoch from now()-pg_postmaster_start_time()) as val');
	$postgres['uptime']=$recs[0]['val'];
	//current_connections
	$recs=postgresqlQueryResults('select count(1) as val from pg_stat_activity');
	$postgres['current_connections']=$recs[0]['val'];
	//pg_backend_pid
	$recs=postgresqlQueryResults('select pg_backend_pid() as val');
	$postgres['pg_backend_pid']=$recs[0]['val'];
	//prepared_xact_count
	$recs=postgresqlQueryResults('select count(1) as val from pg_prepared_xacts');
	$postgres['prepared_xact_count']=$recs[0]['val'];
	//prepared_xact_lock_count
	$recs=postgresqlQueryResults('select count(1) as val from pg_locks where transactionid in (select transaction from pg_prepared_xacts)');
	$postgres['prepared_xact_lock_count']=$recs[0]['val'];
	//connection_age_average
	$recs=postgresqlQueryResults('select extract(epoch from avg(now()-backend_start)) as val from pg_stat_activity');
	$postgres['connection_age_average']=$recs[0]['val'];
	//sum_total_relation_size
	$recs=postgresqlQueryResults("select sum(pg_total_relation_size(schemaname||'.'||quote_ident(tablename))) as val from pg_tables");
	$postgres['sum_total_relation_size']=$recs[0]['val'];
	//sum_table_size
	$recs=postgresqlQueryResults("select sum(pg_table_size(schemaname||'.'||quote_ident(tablename))) as val from pg_tables");
	$postgres['sum_table_size']=$recs[0]['val'];
	//shared_buffer_heap_hit_rate
	$recs=postgresqlQueryResults("select sum(heap_blks_hit)*100/(sum(heap_blks_read)+sum(heap_blks_hit)+1) as val from pg_statio_all_tables");
	$postgres['shared_buffer_heap_hit_rate']=$recs[0]['val'];
	//shared_buffer_toast_hit_rate
	$recs=postgresqlQueryResults("select sum(toast_blks_hit)*100/(sum(toast_blks_read)+sum(toast_blks_hit)+1) as val from pg_statio_all_tables");
	$postgres['shared_buffer_toast_hit_rate']=$recs[0]['val'];
	//shared_buffer_idx_hit_rate
	$recs=postgresqlQueryResults("select sum(idx_blks_hit)*100/(sum(idx_blks_read)+sum(idx_blks_hit)+1) as val from pg_statio_all_tables");
	$postgres['shared_buffer_idx_hit_rate']=$recs[0]['val'];
	//expiring_soon_users
	$recs=postgresqlQueryResults("select usename from pg_user where valuntil='invalid' or valuntil < now()+interval'7 days'");
	foreach($recs as $rec){
		$postgres['expiring_soon_users'][]=$rec['usename'];
	}
	//bad_password_users
	$recs=postgresqlQueryResults("select usename from pg_shadow where passwd='md5'||md5(usename||usename)");
	foreach($recs as $rec){
		$postgres['bad_password_users'][]=$rec['usename'];
	}
	//databases
	$recs=postgresqlQueryResults('SELECT datname FROM pg_database WHERE NOT datistemplate AND datallowconn');
	foreach($recs as $rec){
		$postgres['databases'][]=$rec['datname'];
	}
	//users
	$recs=postgresqlQueryResults('select * from pg_user');
	foreach($recs as $rec){
		$postgres['users'][$rec['usename']]=$rec;
	}
	//extensions
	$recs=postgresqlQueryResults('select extname from pg_extension');
	foreach($recs as $rec){
		$postgres['extensions'][]=$rec['extname'];
	}
	//modified_costs
	$recs=postgresqlQueryResults("select name from pg_settings where name like '%cost%' and setting<>boot_val");
	foreach($recs as $rec){
		$postgres['modified_costs'][]=$rec['name'];
	}
	//tablespaces_in_pgdata
	$postgres['tablespaces_in_pgdata']=postgresqlQueryResults("select spcname,pg_tablespace_location(oid) from pg_tablespace where pg_tablespace_location(oid) like (select setting from pg_settings where name='data_directory')||'/%'");
	//Invalid_indexes
	$q=<<<ENDOFSQL
	SELECT
		concat(n.nspname, '.', c.relname) as index
	FROM
		pg_catalog.pg_class c,
		pg_catalog.pg_namespace n,
		pg_catalog.pg_index i
	WHERE
		i.indisvalid = false AND
		i.indexrelid = c.oid AND
		c.relnamespace = n.oid
ENDOFSQL;
	$postgres['invalid_indexes']=postgresqlQueryResults($q);
	//unused_indexes
	$q=<<<ENDOFSQL
	SELECT 
		relname||'.'||indexrelname as index_name 
	FROM pg_stat_user_indexes 
	WHERE 
		idx_scan=0 and not exists (select 1 from pg_constraint where conindid=indexrelid) 
	ORDER BY relname, indexrelname
ENDOFSQL;
	$postgres['unused_indexes']=postgresqlQueryResults($q);
	//default_cost_procs
	$q=<<<ENDOFSQL
	SELECT 
		n.nspname||'.'||p.proname as proc_name 
	FROM pg_catalog.pg_proc p 
		left join pg_catalog.pg_namespace n on n.oid = p.pronamespace 
	WHERE pg_catalog.pg_function_is_visible(p.oid) and n.nspname not in ('pg_catalog','information_schema','sys') and p.prorows<>1000 and p.procost<>10 and p.proname not like 'uuid_%' and p.proname != 'pg_stat_statements_reset'
ENDOFSQL;
	$postgres['default_cost_procs']=postgresqlQueryResults($q);
	//calculate other values based on above info
	//sum_index_size
	$postgres['sum_index_size']=$postgres['sum_total_relation_size']-$postgres['sum_table_size'];
	//index_percent
	$postgres['index_percent']=$postgres['sum_index_size']*100/$postgres['sum_total_relation_size'];
	//current_connections_percent
	$postgres['current_connections_percent']=$postgres['current_connections']*100/$postgres['max_connections'];
	//superuser_reserved_connections_ratio
	$postgres['superuser_reserved_connections_ratio']=$postgres['superuser_reserved_connections']*100/$postgres['max_connections'];
	//work_mem_total
	$postgres['work_mem_total']=$postgres['work_mem']*$postgres['work_mem_per_connection_percent']/100*$postgres['max_connections'];
	//maintenance_work_mem_total
	$postgres['maintenance_work_mem_total']=$postgres['maintenance_work_mem']*$postgres['autovacuum_max_workers'];
	//max_memory
	$postgres['max_memory']=$postgres['shared_buffers']+$postgres['work_mem_total']+$postgres['maintenance_work_mem_total']+$postgres['track_activity_size'];
	//shared_buffers_usage
	$postgres['shared_buffers_usage']=$postgres['all_databases_size']/$postgres['shared_buffers'];
	//buffercache_declared_size
	$postgres['buffercache_declared_size'] = $postgres['effective_cache_size'] - $postgres['shared_buffers'];
	//checkpoint_dirty_writing_time_window
	$postgres['checkpoint_dirty_writing_time_window']=$postgres['checkpoint_timeout'] * $postgres['checkpoint_completion_target'];
	//average_w
	$postgres['average_w']=$postgres['max_wal_size']/$postgres['checkpoint_dirty_writing_time_window'];

	//postgres + server calculations
	//percent_postgresql_max_memory

	//create a report
	$recs=array();
	//super user
	if(strtolower($postgres['users'][$db_user]['usesuper'])!='t'){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'Error: You need superuser rights.',
			'details'=>"{$db_user} does not have super user rights."
		);
	}
	//server_version
	if(preg_match('/(devel|rc|beta)/i',$postgres['server_version'])){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'running a Non-production database version',
			'details'=>"{$postgres['server_version']} is not a production stable version."
		);
	}
	if($postgres['server_version'] < 13){
		$report['advice'][]="Postgres version {$postgres['server_version']} is not the latest stable version. Upgrade to the latest stable PostgreSQL version";
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'Not the latest stable version.',
			'details'=>"Postgres version {$postgres['server_version']} is not the latest stable version. Upgrade to the latest stable PostgreSQL version"
		);
	}
	//uptime
	$oneday=24*60*60;
	if($postgres['uptime'] < $oneday){
		$recs[]=array(
			'priority'=>'3-low',
			'advice'=>'Uptime less than 1 day',
			'details'=>"Uptime less than 1 day.  This report may be inaccurate"
		);
	}
	//pg_stat_statements
	$pg_stat_statements=0;
	foreach($postgres['extensions'] as $ext){
		if(stringContains($ext,'pg_stat_statements')){$pg_stat_statements=1;break;}
	}
	if($pg_stat_statements==0){
		$recs[]=array(
			'priority'=>'3-low',
			'advice'=>'Enable pg_stat_statements',
			'details'=>"Enable pg_stat_statements in database to collect statistics on all queries (not only those longer than log_min_duration_statement)"
		);
	}
	//expiring_soon_users
	if(isset($postgres['expiring_soon_users'][0])){
		$recs[]=array(
			'priority'=>'3-low',
			'advice'=>'Expiring user accounts found',
			'details'=>"These user accounts will expire in less than 7 days: ".json_encode($postgres['expiring_soon_users'])
		);
	}
	//bad_password_users
	if(isset($postgres['bad_password_users'][0])){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'Users with bad passwords Found',
			'details'=>"These user passwords are the same as the username: ".json_encode($postgres['bad_password_users'])
		);
	}
	//password_encryption
	if(strtolower($postgres['password_encryption'])=='off'){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'Password encryption is disabled',
			'details'=>"password_encryption is set to off so passwords are not being encrypted.",
		);
	}
	//current_connections_percent
	if($postgres['current_connections_percent'] > 70){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'Over 70% of max connections',
			'details'=>"You are using more than 70% ({$postgres['current_connections_percent']}) of the connections slots.  Increase max_connections to avoid saturation of connection slots"
		);
	}
	//superuser_reserved_connections
	if($postgres['superuser_reserved_connections'] == 0){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'No connection slot is reserved for the superuser',
			'details'=>"No connection slot is reserved for the superuser.  In case of connection saturation you will not be able to connect to investigate or kill connections"
		);
	}
	//superuser_reserved_connections_ratio
	if($postgres['superuser_reserved_connections_ratio'] > 20 ){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'To many connection slots reserved for the superuser',
			'details'=>"{$postgres['superuser_reserved_connections_ratio']}% of connections are reserved for super user.  This is too much and may limit other users connections"
		);
	}
	//connection_age_average
	if($postgres['connection_age_average'] < 60 ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'Use a connection pooler to limit new connections/second',
			'details'=>"The average connection age is less than 1 minute.  Use a connection pooler to limit new connections/second"
		);
	}
	elseif($postgres['connection_age_average'] < 600 ){
		$recs[]=array(
			'priority'=>'3-low',
			'advice'=>'Use a connection pooler to limit new connections/second',
			'details'=>"The average connection age is less than 10 minutes.  Use a connection pooler to limit new connections/second"
		);
	}
	//pre_auth_delay
	if($postgres['pre_auth_delay'] > 0 ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'Set pre_auth_delay to 0',
			'details'=>"pre_auth_delay={$postgres['pre_auth_delay']}: this is a developer feature for debugging and increases the connection delay by {$postgres['pre_auth_delay']} seconds"
		);
	}
	//post_auth_delay
	if($postgres['post_auth_delay'] > 0 ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'Set post_auth_delay to 0',
			'details'=>"post_auth_delay={$postgres['post_auth_delay']}: this is a developer feature for debugging and increases the connection delay by {$postgres['post_auth_delay']} seconds"
		);
	}
	//maintenance_work_mem
	$default=65536;
	if($postgres['maintenance_work_mem'] <= $default ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'increase maintenance_work_mem',
			'details'=>"maintenance_work_mem specifies the maximum amount of memory to be used by maintenance operations, such as VACUUM, CREATE INDEX, and ALTER TABLE ADD FOREIGN KEY. The current value ({$postgres['maintenance_work_mem']}) is less or equal to its default value of {$default}. Max is 2147483647.  Increase it to reduce maintenance tasks duration"
		);
	}
	//shared_buffers_usage
	if($postgres['shared_buffers_usage'] < 0.7 ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'shared_buffers is too big',
			'details'=>"shared_buffers size ({$postgres['shared_buffers']})  is too big for the total databases size, uselessly using memory"
		);
	}
	//effective_cache_size
	if($postgres['effective_cache_size'] < $postgres['shared_buffers'] ){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'increase effective_cache_size',
			'details'=>"effective_cache_size sets the planner's assumption about the effective size of the disk cache that is available to a single query. This is factored into estimates of the cost of using an index; a higher value makes it more likely index scans will be used, a lower value makes it more likely sequential scans will be used. When setting this parameter you should consider both PostgreSQL's shared buffers and the portion of the kernel's disk cache that will be used for PostgreSQL data files, though some data might exist in both places. Also, take into account the expected number of concurrent queries on different tables, since they will have to share the available space. effective_cache_size({$postgres['effective_cache_size']}) < shared_buffers ({$postgres['shared_buffers']}).  This is inadequate, as effective_cache_size value must be (shared buffers) + (size in bytes of the kernel's storage buffercache that will be used for PostgreSQL data files)"
		);
	}
	//buffercache_declared_size
	if($postgres['buffercache_declared_size'] < 4000000000 ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'increase effective_cache_size',
			'details'=>"The declared buffercache size ( effective_cache_size - shared_buffers ) is less than 4GB.  effective_cache_size value is probably inadequate.  It must be (shared buffers) + (size in bytes of the kernel's storage buffercache that will be used for PostgreSQL data files). effective_cache_size sets the planner's assumption about the effective size of the disk cache that is available to a single query. This is factored into estimates of the cost of using an index; a higher value makes it more likely index scans will be used, a lower value makes it more likely sequential scans will be used. When setting this parameter you should consider both PostgreSQL's shared buffers and the portion of the kernel's disk cache that will be used for PostgreSQL data files, though some data might exist in both places. Also, take into account the expected number of concurrent queries on different tables, since they will have to share the available space. "
		);
	}
	//huge_pages
	if($postgres['huge_pages'] == 'on' ){
		$recs[]=array(
			'priority'=>'4-info',
			'advice'=>'check for hugepage support in the OS',
			'details'=>"huge_pages=on, therefore PostgreSQL needs Huge Pages and will not start if the kernel doesn't provide them. You can check for hugepage support  on the OS by running 'cat /proc/sys/vm/nr_hugepages'"
		);
	}
	elseif($postgres['huge_pages'] != 'try' ){
		$recs[]=array(
			'priority'=>'4-info',
			'advice'=>'check for hugepage support in the OS',
			'details'=>"Enable huge_pages to enhance memory allocation performance, and if necessary also enable them at OS level. You can check for hugepage support  on the OS by running 'cat /proc/sys/vm/nr_hugepages'"
		);
	}
	//log_hostname
	if($postgres['log_hostname'] == 'on'  ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'turn off log_hostname',
			'details'=>"log_hostname is on: this will decrease connection performance (because PostgreSQL has to do DNS lookups)"
		);
	}
	//log_min_duration_statement
	if($postgres['log_min_duration_statement'] == -1  ){
		$recs[]=array(
			'priority'=>'3-low',
			'advice'=>'set log_min_duration_statement to something > 1000 (ms)',
			'details'=>"Log of long queries is deactivated.  It will be more difficult to optimize query performance. Setting log_min_duration_statement causes the duration of each completed statement to be logged if the statement ran for at least the specified amount of time. If this value is specified without units, it is taken as milliseconds. Setting this to zero prints all statement durations. Minus-one (the default) disables logging statement durations. For example, if you set it to 250ms then all SQL statements that run 250ms or longer will be logged. Enabling this parameter can be helpful in tracking down unoptimized queries in your applications."
		);
	}
	elseif((integer)$postgres['log_min_duration_statement'] < 1000  ){
		$recs[]=array(
			'priority'=>'3-low',
			'advice'=>'increase log_min_duration_statement to something > 1000 (ms)',
			'details'=>"log_min_duration_statement={$postgres['log_min_duration_statement']}: any request during less than 1 sec will be written in log.  It may be storage-intensive (I/O and space). Setting log_min_duration_statement causes the duration of each completed statement to be logged if the statement ran for at least the specified amount of time. If this value is specified without units, it is taken as milliseconds. Setting this to zero prints all statement durations. Minus-one (the default) disables logging statement durations. For example, if you set it to 250ms then all SQL statements that run 250ms or longer will be logged. Enabling this parameter can be helpful in tracking down unoptimized queries in your applications. "
		);
	}
	//log_statement
	if($postgres['log_statement'] == 'all'  ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set log_statement to ddl',
			'details'=>"log_statement=all is very storage-intensive and only usefull for debuging"
		);
	}
	elseif($postgres['log_statement'] == 'mod'  ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set log_statement to ddl',
			'details'=>"log_statement=mod is storage-intensive"
		);
	}
	//autovacuum
	if($postgres['autovacuum'] != 'on'  ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set autovacuum to on',
			'details'=>"autovacuum is not activated.  This is normally bad."
		);
	}
	//checkpoint_completion_target
	$msg_CCT="checkpoint_completion_target is low.  Some checkpoints may abruptly overload the storage with write commands for a long time, slowing running queries down.  To avoid such temporary overload you may balance checkpoint writes using a higher value";
	if($postgres['checkpoint_completion_target'] == 0 ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set checkpoint_completion_target to somewhere between 0.7 and 0.9',
			'details'=>"checkpoint_completion_target value is 0.  This is absurd. {$msg_CCT}"
		);
	}
	elseif($postgres['checkpoint_completion_target'] < 0.5 ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set checkpoint_completion_target to somewhere between 0.5 and 0.9',
			'details'=>"Checkpoint_completion_target ({$postgres['checkpoint_completion_target']}) is lower than its default value (0.5). {$msg_CCT}"
		);
	}
	elseif($postgres['checkpoint_completion_target'] > 0.9){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set checkpoint_completion_target to somewhere between 0.7 and 0.9',
			'details'=>"Checkpoint_completion_target ({$postgres['checkpoint_completion_target']}) is too high. Reduce checkpoint_completion_target value."
		);
	}
	//checkpoint_dirty_writing_time_window
	if($postgres['checkpoint_dirty_writing_time_window'] < 10){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set checkpoint_timeout / checkpoint_completion_target a number > 10',
			'details'=>"(checkpoint_timeout / checkpoint_completion_target) is probably too low"
		);
	}
	//fsync
	if($postgres['fsync'] != 'on'  ){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'turn of fsync',
			'details'=>"fsync is off.  You may lose data after a crash, DANGER!"
		);
	}
	//synchronize_seqscans
	if($postgres['synchronize_seqscans'] != 'on'  ){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'set synchronize_seqscans to on',
			'details'=>"set synchronize_seqscans to 'on' to reduce I/O load"
		);
	}
	//wal_level
	if($postgres['wal_level'] == 'minimal'  ){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set wal_level to replica or logical',
			'details'=>"minimal WAL does not contain enough information to reconstruct the data from a base backup and the WAL logs, so replica or logical must be used to enable WAL archiving (archive_mode) and streaming replication."
		);
	}
	//modified_costs
	if(isset($postgres['modified_costs'][0])){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set modified costs settings to default value',
			'details'=>"Some I/O cost settings are not set to their default value: ".json_encode($postgres['modified_costs'])
		);
	}
	//disabled_plan_functions
	$disabled=array();
	foreach($postgres as $k=>$v){
		if(stringBeginsWith($k,'enable_') && strtolower($v)=='off'){
			$disabled[]=$k;
		}
	}
	if(count($disabled)){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'set plan features to on',
			'details'=>"Some plan features are disabled: ".json_encode($disabled)
		);
	}
	//tablespaces_in_pgdata
	if(isset($postgres['tablespaces_in_pgdata'][0])){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'move tablespaces outside of PGDATA folder',
			'details'=>"Some tablespaces defined in PGDATA. Move them outside of this folder "
		);
	}
	//shared_buffer_idx_hit_rate
	if($postgres['shared_buffer_idx_hit_rate'] > 99.99 ){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'decrease shared_buffer memory',
			'details'=>"shared_buffer_idx_hit_rate is too high. Decrease shared_buffer memory to lower hit rate."
		);
	}
	elseif($postgres['shared_buffer_idx_hit_rate'] < 90 ){
		$recs[]=array(
			'priority'=>'1-high',
			'advice'=>'increase shared_buffer memory',
			'details'=>"shared_buffer_idx_hit_rate is too low. Increase shared_buffer memory to increase hit rate."
		);
	}
	//invalid_indexes
	if(isset($postgres['invalid_indexes'][0])){
		$recs[]=array(
			'priority'=>'2-medium',
			'advice'=>'check/reindex any invalid index',
			'details'=>"List of invalid index in the database: ".json_encode($postgres['invalid_indexes'])
		);
	}
	//unused_indexes
	if(isset($postgres['unused_indexes'][0])){
		$recs[]=array(
			'priority'=>'3-low',
			'advice'=>'remove unused indexes',
			'details'=>"You have unused indexes in the database since the last statistics run.  Please remove them if they are rarely or not used. List of unused indexes not used since the last statistics run: ".json_encode($postgres['unused_indexes'])
		);
	}
	//default_cost_procs
	if(isset($postgres['default_cost_procs'][0])){
		$recs[]=array(
			'priority'=>'3-low',
			'advice'=>'reconfigure custom procedures',
			'details'=>"You have custom procedures with default cost and rows setting.  Reconfigure them with specific values to help the planner. List of user procedures do not have custom cost and rows settings: ".json_encode($postgres['default_cost_procs'])
		);
	}
	//order by priority
	$recs=sortArrayByKeys($recs,array('priority'=>SORT_ASC));
	return $recs;
}