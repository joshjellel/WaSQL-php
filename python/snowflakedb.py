#! python
"""
Installation
    python -m pip install --upgrade snowflake-connector-python
       If it fails then go to https://visualstudio.microsoft.com/visual-cpp-build-tools/
           download build tools
           install c++ build tools
           reboot and try again
    python -m pip install snowflake-sqlalchemy

"""
#imports
try:
    import json
    import sys
    import os
    import snowflake.connector as sfc
    from sqlalchemy import create_engine
    import config
    import common
except Exception as err:
    exc_type, exc_obj, exc_tb = sys.exc_info()
    fname = os.path.split(exc_tb.tb_frame.f_code.co_filename)[1]
    print(f"Import Error: {err}. ExeptionType: {exc_type}, Filename: {fname}, Linenumber: {exc_tb.tb_lineno}")
    sys.exit()
###########################################
def addIndex(params):
    #check required
    if '-table' not in params:
        return ("mysqldb.addIndex error: No Table Specified")
    if '-fields' not in params:
        return ("mysqldb.addIndex error: No Fields Specified")
    #check for unique and fulltext
    fulltext = ''
    unique = ''
    prefix = ''
    if '-unique' in params:
        unique =' UNIQUE'
        prefix += 'U'
    if '-fulltext' in params:
        fulltext =' FULLTEXT'
        prefix += 'F'
    #build index name if not passed in
    if '-name' not in params:
        params['-name']="{}_{}_".format(prefix,params['-table']);
    #create query
    fieldstr = params['-fields'].replace(',','_')
    query="CREATE {} INDEX IF NOT EXISTS {} on {} ({})".format(unique,params['-name'],params['-table'],fieldstr);
    #execute query
    return executeSQL(query) 
###########################################
#Python’s default arguments are evaluated once when the function is defined, not each time the function is called.
def connect(params):
    try:
        dbconfig = {}
        #need account,user,password,database,schema,warehouse,role

        #check config.CONFIG
        if 'dbaccount' in config.CONFIG:
            dbconfig['account'] = config.CONFIG['dbaccount']

        if 'dbuser' in config.CONFIG:
            dbconfig['user'] = config.CONFIG['dbuser']

        if 'dbpass' in config.CONFIG:
            dbconfig['password'] = config.CONFIG['dbpass']

        if 'dbname' in config.CONFIG:
            dbconfig['database'] = config.CONFIG['dbname']

        if 'dbschema' in config.CONFIG:
            dbconfig['schema'] = config.CONFIG['dbschema']

        if 'dbwarehouse' in config.CONFIG:
            dbconfig['warehouse'] = config.CONFIG['dbwarehouse']

        if 'dbrole' in config.CONFIG:
            dbconfig['role'] = config.CONFIG['dbrole']
        
        #check params and override any that are passed in
        if 'dbaccount' in params:
            dbconfig['account'] = params['dbaccount']

        if 'dbuser' in params:
            dbconfig['user'] = params['dbuser']

        if 'dbpass' in params:
            dbconfig['password'] = params['dbpass']

        if 'dbname' in params:
            dbconfig['database'] = params['dbname']

        if 'dbschema' in params:
            dbconfig['schema'] = params['dbschema']

        if 'dbwarehouse' in params:
            dbconfig['warehouse'] = params['dbwarehouse']

        if 'dbrole' in params:
            dbconfig['role'] = params['dbrole']
        #test
        print(dbconfig)
        print('<hr>')
        conn_dict = {}
        conn_dict.update({'account': dbconfig['account']})
        conn_dict.update({'user': dbconfig['user']})
        conn_dict.update({'password': dbconfig['password']})
        conn_dict.update({'database': dbconfig['database']})
        conn_dict.update({'schema': dbconfig['schema']})
        conn_dict.update({'warehouse': dbconfig['warehouse']})
        conn_dict.update({'role': dbconfig['role']})

        print(conn_dict)
        print('<hr>')

        try:
            conn_snowflake = sfc.connect(**conn_dict)
            cur_snowflake = conn_snowflake.cursor(buffered=True)
        except:
            print('snowflake connect failed')
            return false
            
        #need to return both cur and conn so conn stays around
        return cur_snowflake, conn_snowflake
        
    except:
        print("snowflakedb.connect error")
        return false
###########################################
def dictFactory(cursor, row):
    d = {}
    for idx, col in enumerate(cursor.description):
        d[col[0]] = row[idx]
    return d
###########################################
def executeSQL(query,params):
    try:
        #connect
        cur_snowflake, conn_snowflake =  connect(params)
        #now execute the query
        cur_snowflake.execute(query)
        return True
        
    except:
        return ("snowflakedb.executeSQL")
###########################################
#conversion function to convert objects in recordsets
def convertStr(o):
    return f"{o}"
###########################################
def queryResults(query,params):
    try:
        #connect
        cur_snowflake, conn_snowflake =  connect(params)
        if 'warehouse' in params.keys():
            q = f"use warehouse {params['filename']}"
            cur_snowflake.execute(q)
        #now execute the query
        cur_snowflake.execute(query)
        if 'filename' in params.keys():
            jsv_file=params['filename']
            #get column names
            fields = [field_md[0] for field_md in cur_snowflake.description]
            #write file
            f = open(jsv_file, "w")
            f.write(json.dumps(fields,sort_keys=False, ensure_ascii=False, default=str).lower())
            f.write("\n")
            #write records
            for rec in cur_snowflake.fetchall():
                f.write(json.dumps(rec,sort_keys=False, ensure_ascii=True, default=convertStr))
                f.write("\n")
            f.close()
            cur_snowflake.close()
            conn_snowflake.close()
            return params['filename']
        else:
            recs = cur_snowflake.fetchall()
            tname=type(recs).__name__
            if tname == 'tuple':
                recs=list(recs)
                cur_snowflake.close()
                conn_snowflake.close()
                return recs
            elif tname == 'list':
                cur_snowflake.close()
                conn_snowflake.close()
                return recs
            else:
                cur_snowflake.close()
                conn_snowflake.close()
                return []
        
    except Exception as err:
        exc_type, exc_obj, exc_tb = sys.exc_info()
        fname = os.path.split(exc_tb.tb_frame.f_code.co_filename)[1]
        cur_mysql.close()
        conn_mysql.close()
        return (f"Error: {err}. ExeptionType: {exc_type}, Filename: {fname}, Linenumber: {exc_tb.tb_lineno}")
###########################################
