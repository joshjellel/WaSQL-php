<?php

/*
	sqlite3 Database functions
		http://php.net/manual/en/sqlite3.query.php
		*
*/

//---------- begin function sqliteParseConnectParams ----------
/**
* @describe parses the params array and checks in the CONFIG if missing
* @param $params array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return $params array
* @usage $params=sqliteParseConnectParams($params);
*/
function sqliteParseConnectParams($params=array()){
	global $CONFIG;
	//dbname
	if(!isset($params['-dbname'])){
		if(isset($CONFIG['dbname_sqlite'])){
			$params['-dbname']=$CONFIG['dbname_sqlite'];
			$params['-dbname_source']="CONFIG dbname_sqlite";
		}
		elseif(isset($CONFIG['sqlite_dbname'])){
			$params['-dbname']=$CONFIG['sqlite_dbname'];
			$params['-dbname_source']="CONFIG sqlite_dbname";
		}
		else{return 'sqliteParseConnectParams Error: No dbname set';}
	}
	else{
		$params['-dbname_source']="passed in";
	}
	//dbmode
	if(!isset($params['-dbmode'])){
		if(isset($CONFIG['dbmode_sqlite'])){
			$params['-dbmode']=$CONFIG['dbmode_sqlite'];
			$params['-dbmode_source']="CONFIG dbname_sqlite";
		}
		elseif(isset($CONFIG['sqlite_dbmode'])){
			$params['-dbmode']=$CONFIG['sqlite_dbmode'];
			$params['-dbmode_source']="CONFIG sqlite_dbname";
		}
	}
	else{
		$params['-dbmode_source']="passed in";
	}
	return $params;
}
//---------- begin function sqliteDBConnect ----------
/**
* @describe connects to a SQLITE database and returns the handle resource
* @param $param array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
* 	[-dbname] - name of ODBC connection
*   [-single] - if you pass in -single it will connect using sqlite_connect instead of sqlite_pconnect and return the connection
* @return $dbh_sqlite resource - returns the sqlite connection resource
* @usage $dbh_sqlite=sqliteDBConnect($params);
* @usage  example of using -single
*
	$conn=sqliteDBConnect(array('-single'=>1));
	sqlite_autocommit($conn, FALSE);

	sqlite_exec($conn, $query1);
	sqlite_exec($conn, $query2);

	if (!sqlite_error()){
		sqlite_commit($conn);
	}
	else{
		sqlite_rollback($conn);
	}
	sqlite_close($conn);
*
*/
function sqliteDBConnect($params=array()){
	if(!is_array($params) && $params=='single'){$params=array('-single'=>1);}
	$params=sqliteParseConnectParams($params);
	if(!isset($params['-dbname'])){
		echo "sqliteDBConnect error: no dbname set";
		exit;
	}
	if(!isset($params['-mode'])){$params['-mode']=0666;}

	global $dbh_sqlite;
	if($dbh_sqlite){return $dbh_sqlite;}
	try{
		$dbh_sqlite = new SQLite3($params['-dbname'],SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		$dbh_sqlite->busyTimeout(5000);
		// WAL mode has better control over concurrency.
		// Source: https://www.sqlite.org/wal.html
		$dbh_sqlite->exec('PRAGMA journal_mode = wal;');
		return $dbh_sqlite;
	}
	catch (Exception $e) {
		echo "sqliteDBConnect exception" . $e->getMessage();
		exit;

	}
}
//---------- begin function sqliteIsDBTable ----------
/**
* @describe returns true if table exists
* @param $tablename string - table name
* @param $schema string - schema name
* @param $params array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return boolean returns true if table exists
* @usage if(sqliteIsDBTable('abc')){...}
*/
function sqliteIsDBTable($table,$params=array()){
	if(!strlen($table)){
		echo "sqliteIsDBTable error: No table";
		exit;
	}
	$table=strtolower($table);
	$dbh_sqlite=sqliteDBConnect($params);
	if(!is_resource($dbh_sqlite)){
		echo "sqliteDBConnect error".printValue($params);
		exit;
	}
	try{
		$query="SELECT name FROM sqlite_master WHERE type='table' and name = ?";
		$vals=array($table);
		$stmt=$dbh_sqlite->prepare($query);
		if(!$stmt){
			$err=array(
				'msg'=>"sqliteIsDBTable error",
				'error'	=> $dbh_sqlite->lastErrorMsg(),
				'query'	=> $query,
				'vals'	=> $vals
				);
			echo printValue($err);
			exit;
		}
		$stmt->bindParam(1,$vals[0],SQLITE3_TEXT);
		$resuts=$stmt->execute();
		while ($rec = $results->fetchArray(SQLITE3_ASSOC)) {
			if(strtolower($rec['name']) == $table){
				$results->finalize();
				return true;
			}
		}
		$results->finalize();
		return false;
	}
	catch (Exception $e) {
		$err=$e->getMessage();
		echo "sqliteIsDBTable error: exception".printValue($err);
		exit;
	}
    return false;
}
//---------- begin function sqliteClearConnection ----------
/**
* @describe clears the sqlite database connection
* @return boolean returns true if query succeeded
* @usage $ok=sqliteClearConnection();
*/
function sqliteClearConnection(){
	global $dbh_sqlite;
	$dbh_sqlite=null;
	return true;
}
//---------- begin function sqliteExecuteSQL ----------
/**
* @describe executes a query and returns without parsing the results
* @param $query string - query to execute
* @param $params array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return boolean returns true if query succeeded
* @usage $ok=sqliteExecuteSQL("truncate table abc");
*/
function sqliteExecuteSQL($query,$params=array()){
	$dbh_sqlite=sqliteDBConnect($params);
	try{
		$result=$dbh_sqlite->exec($query);
		if(!$result){
			debugValue($err);
			return false;
		}

		return true;
	}
	catch (Exception $e) {
		$err=$e->getMessage();
		debugValue("sqliteExecuteSQL error: {$err}");
		return false;
	}
	return true;
}
//---------- begin function sqliteAddDBRecord ----------
/**
* @describe adds a records from params passed in.
*  if cdate, and cuser exists as fields then they are populated with the create date and create username
* @param $params array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
*   -table - name of the table to add to
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	other field=>value pairs to add to the record
* @return integer returns the autoincriment key
* @usage $id=sqliteAddDBRecord(array('-table'=>'abc','name'=>'bob','age'=>25));
*/
function sqliteAddDBRecord($params){
	global $USER;
	if(!isset($params['-table'])){return 'sqliteAddRecord error: No table specified.';}
	$fields=sqliteGetDBFieldInfo($params['-table'],$params);
	$opts=array();
	if(isset($fields['cdate'])){
		$params['cdate']=strtoupper(date('d-M-Y  H:i:s'));
	}
	elseif(isset($fields['_cdate'])){
		$params['_cdate']=strtoupper(date('d-M-Y  H:i:s'));
	}
	if(isset($fields['cuser'])){
		$params['cuser']=$USER['username'];
	}
	elseif(isset($fields['_cuser'])){
		$params['_cuser']=$USER['username'];
	}
	$binds=array();
	$vals=array();
	$flds=array();
	foreach($params as $k=>$v){
		$k=strtolower($k);
		if(!isset($fields[$k])){continue;}
		$vals[]=$v;
		$flds[]=$k;
        $binds[]='?';
	}
	$fldstr=implode(', ',$flds);
	$bindstr=implode(',',$binds);

    $query=<<<ENDOFQUERY
		INSERT INTO {$params['-table']}
			({$fldstr})
		VALUES
			({$bindstr})
ENDOFQUERY;
	$dbh_sqlite=sqliteDBConnect($params);
	if(!$dbh_sqlite){
    	$e=sqlite_error_string(sqlite_last_error());
    	debugValue(array("sqliteAddDBRecord Connect Error",$e));
    	return;
	}
	try{
		//echo $query.printValue($vals);exit;
		$stmt=$dbh_sqlite->prepare($query);
		if(!$stmt){
			$err=array(
				'msg'=>"sqliteAddDBRecord error",
				'error'	=> $dbh_sqlite->lastErrorMsg(),
				'query'	=> $query,
				'vals'	=> $vals
				);
			echo printValue($err);
			exit;
		}
		foreach($vals as $i=>$v){
			$fld=$flds[$i];
			$x=$i+1;
			//echo "{$x}::{$v}::{$fields[$fld]['type']}<br>".PHP_EOL;
			switch(strtolower($fields[$fld]['type'])){
				case 'integer':
					$stmt->bindParam($x,$vals[$i],SQLITE3_INTEGER);
				break;
				case 'float':
					$stmt->bindParam($x,$vals[$i],SQLITE3_FLOAT);
				break;
				case 'blob':
					$stmt->bindParam($x,$vals[$i],SQLITE3_BLOB);
				break;
				case 'null':
					$stmt->bindParam($x,$vals[$i],SQLITE3_NULL);
				break;
				default:
					$stmt->bindParam($x,$vals[$i],SQLITE3_TEXT);
				break;
			}
		}
		$results=$stmt->execute();
		return $dbh_sqlite->lastInsertRowID();;
	}
	catch (Exception $e) {
		$err=$e->getMessage();
		debugValue("sqliteAddDBRecord error: {$err}");
		return null;
	}
	return 0;
}
//---------- begin function sqliteEditDBRecord ----------
/**
* @describe edits a record from params passed in based on where.
*  if edate, and euser exists as fields then they are populated with the edit date and edit username
* @param $params array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
*   -table - name of the table to add to
*   -where - filter criteria
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	other field=>value pairs to edit
* @return boolean returns true on success
* @usage $id=sqliteEditDBRecord(array('-table'=>'abc','-where'=>"id=3",'name'=>'bob','age'=>25));
*/
function sqliteEditDBRecord($params){
	if(!isset($params['-table'])){return 'sqliteEditDBRecord error: No table specified.';}
	if(!isset($params['-where'])){return 'sqliteEditDBRecord error: No where specified.';}
	global $USER;
	$fields=sqliteGetDBFieldInfo($params['-table'],$params);
	$opts=array();
	if(isset($fields['edate'])){
		$params['edate']=strtoupper(date('Y-M-d H:i:s'));
	}
	elseif(isset($fields['_edate'])){
		$params['_edate']=strtoupper(date('Y-M-d H:i:s'));
	}
	if(isset($fields['euser'])){
		$params['euser']=$USER['username'];
	}
	elseif(isset($fields['_euser'])){
		$params['_euser']=$USER['username'];
	}
	$updates=array();
	$vals=array();
	$flds=array();
	foreach($params as $k=>$v){
		$k=strtolower($k);
		if(!isset($fields[$k])){continue;}
		$vals[]=$v;
		$flds[]=$k;
        $updates[]="{$k}=?";
	}
	$updatestr=implode(', ',$updates);
    $query=<<<ENDOFQUERY
		UPDATE {$params['-table']}
		SET {$updatestr}
		WHERE {$params['-where']}
ENDOFQUERY;
	echo $query.printValue($params);
	$dbh_sqlite=sqliteDBConnect($params);
	if(!$dbh_sqlite){
    	$e=sqlite_error_string(sqlite_last_error());
    	debugValue(array("sqliteAddDBRecord Connect Error",$e));
    	return;
	}
	try{
		$stmt=$dbh_sqlite->prepare($query);
		if(!$stmt){
			$err=array(
				'msg'=>"sqliteEditDBRecord error",
				'error'	=> $dbh_sqlite->lastErrorMsg(),
				'query'	=> $query,
				'vals'	=> $vals
				);
			echo printValue($err);
			exit;
		}
		foreach($vals as $i=>$v){
			$fld=$flds[$i];
			$x=$i+1;
			echo "{$x}::{$v}::{$fields[$fld]['type']}<br>".PHP_EOL;
			switch(strtolower($fields[$fld]['type'])){
				case 'integer':
					$stmt->bindParam($x,$vals[$i],SQLITE3_INTEGER);
				break;
				case 'float':
					$stmt->bindParam($x,$vals[$i],SQLITE3_FLOAT);
				break;
				case 'blob':
					$stmt->bindParam($x,$vals[$i],SQLITE3_BLOB);
				break;
				case 'null':
					$stmt->bindParam($x,$vals[$i],SQLITE3_NULL);
				break;
				default:
					$stmt->bindParam($x,$vals[$i],SQLITE3_TEXT);
				break;
			}
		}
		$results=$stmt->execute();
		return 1;
	}
	catch (Exception $e) {
		$err=$e->getMessage();
		debugValue("sqliteAddDBRecord error: {$err}");
		return null;
	}
	return 0;
}
//---------- begin function sqliteGetDBTables ----------
/**
* @describe returns an array of tables
* @param $params array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return boolean returns true on success
* @usage $tables=sqliteGetDBTables();
*/
function sqliteGetDBTables($params=array()){
	$dbh_sqlite=sqliteDBConnect($params);
	if(!$dbh_sqlite){
    	$e=sqlite_error_string(sqlite_last_error());
    	debugValue(array("sqliteGetDBTables Error",$e));
    	return;
	}
	try{
		$query="SELECT name FROM sqlite_master WHERE type='table';";
		$results=$dbh_sqlite->query($query);
		while ($rec = $results->fetchArray(SQLITE3_ASSOC)) {
			$tables[]=strtolower($rec['name']);
		}
		$results->finalize();
		return $tables;
	}
	catch (Exception $e) {
		$err=$e->errorInfo;
		echo "sqliteIsDBTable error: exception".printValue($err);
		exit;
	}
	return array();
}
//---------- begin function sqliteGetDBFieldInfo ----------
/**
* @describe returns an array of field info. fieldname is the key, Each field returns name,type,scale, precision, length, num are
* @param $params array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return boolean returns true on success
* @usage $fieldinfo=sqliteGetDBFieldInfo('abcschema.abc');
*/
function sqliteGetDBFieldInfo($table,$params=array()){
	$dbh_sqlite=sqliteDBConnect($params);
	if(!$dbh_sqlite){
    	$e=sqlite_error_string(sqlite_last_error());
    	debugValue(array("sqliteGetDBSchemas Connect Error",$e));
    	return;
	}
	$query="PRAGMA table_info({$table})";
	try{
		$results=$dbh_sqlite->query($query);
		$recs=array();
		while ($xrec = $results->fetchArray(SQLITE3_ASSOC)) {
			$recs[$xrec['name']]=$xrec;
		}
		$results->finalize();
		return $recs;
	}
	catch (Exception $e) {
		$err=$e->errorInfo;
		echo "sqliteGetDBFieldInfo error: exception".printValue($err);
		exit;
	}
	return array();
}
//---------- begin function sqliteGetDBCount ----------
/**
* @describe returns the count of any query without actually getting the data
* @param $query string - the query to run
* @param $params array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* @return $count integer
* @usage $cnt=sqliteGetDBCount('select * from abcschema.abc');
*/
function sqliteGetDBCount($query,$params){
	$params['-count']=1;
	return sqliteQueryResults($query,$params);
}
//---------- begin function sqliteQueryResults ----------
/**
* @describe returns the records of a query
* @param $params array - These can also be set in the CONFIG file with dbname_sqlite,dbuser_sqlite, and dbpass_sqlite
* 	[-dbname] - name of ODBC connection
* 	[-dbuser] - username
* 	[-dbpass] - password
* 	[-filename] - if you pass in a filename then it will write the results to the csv filename you passed in
* @return $recs array
* @usage $recs=sqliteQueryResults('select top 50 * from abcschema.abc');
*/
function sqliteQueryResults($query,$params=array()){
	global $dbh_sqlite;
	if(!$dbh_sqlite){
		$dbh_sqlite=sqliteDBConnect($params);
	}
	if(!$dbh_sqlite){
    	$e=sqlite_error_string(sqlite_last_error());
    	debugValue(array("sqliteQueryResults Connect Error",$e));
    	return;
	}
	try{
		$results=$dbh_sqlite->query($query);
		if(isset($params['-count'])){
			return $results->rowCount();
		}
		$recs=array();
		while ($xrec = $results->fetchArray(SQLITE3_ASSOC)) {
			$rec=array();
			foreach($xrec as $k=>$v){
				$k=strtolower($k);
				$rec[$k]=$v;
			}
			$recs[]=$rec;
		}
		$results->finalize();
		return $recs;
	}
	catch (Exception $e) {
		$err=$e->errorInfo;
		echo "sqliteQueryResults error: exception".printValue($err);
		exit;
	}
}
