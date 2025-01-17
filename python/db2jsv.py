#! python
"""
Converts a database results to jsv (json separated value)
    ["_id", "_cdate", "_cuser", "_edate", "_euser", "code", "name", "country", "longitude", "latitude"]
    {"_id": 4892, "_cdate": null, "_cuser": 0, "_edate": null, "_euser": null, "code": "MI", "name": "Midlands Province", "country": "ZW", "longitude": "29.60354950", "latitude": "-19.05520090"}

Usage:
    python3 db2jsv.py dbname sqlfile

"""
#imports
import os
import sys
try:
    import json
    import config
    import common
except Exception as err:
    exc_type, exc_obj, exc_tb = sys.exc_info()
    fname = os.path.split(exc_tb.tb_frame.f_code.co_filename)[1]
    print("Import Error: {}. ExeptionType: {}, Filename: {}, Linenumber: {}".format(err,exc_type,fname,exc_tb.tb_lineno))
    sys.exit(3)

#get query_file from command line arg
if(len(sys.argv) < 3):
    print("db2jsv usage: python db2jsv dbname sqlfile")
    exit(2)

sys.stdout.flush()
sys.stderr.flush()

dbname=sys.argv[1]

#make sure the file exists
if(os.path.exists(sys.argv[2]) == False):
    print('file does not exists')
    print(sys.argv[1])
    exit()

#get query from file
sql_file=sys.argv[2]
f = open(sql_file, "r")
#read whole file to a string
query = f.read()
#close file
f.close()
params={}
params['filename']=sql_file.replace('.sql','.jsv')
outfile=''
try:
    if dbname in config.DATABASE:
        dbtype = config.DATABASE[dbname]['dbtype']
        #add DATABASE settings to params
        for k in config.DATABASE[dbname]:
            params[k] = config.DATABASE[dbname][k]
        #FIREBIRD
        if dbtype.startswith('firebird'):
            try:
                import firebirddb
            except Exception as err:
                common.abort(sys.exc_info(),err)

            outfile=firebirddb.queryResults(query,params)
            print(outfile)
        #HANA
        if dbtype.startswith('hana'):
            try:
                import hanadb
            except Exception as err:
                common.abort(sys.exc_info(),err)

            outfile=hanadb.queryResults(query,params)
            print(outfile)
        #MSSQL
        if dbtype.startswith('mssql'):
            try:
                import mssqldb
            except Exception as err:
                common.abort(sys.exc_info(),err)

            outfile=mssqldb.queryResults(query,params)
            print(outfile)
        #Mysql
        if dbtype.startswith('mysql'):
            try:
                import mysqldb
            except Exception as err:
                common.abort(sys.exc_info(),err)

            outfile=mysqldb.queryResults(query,params)
            print(outfile)
        #ORACLE
        if dbtype.startswith('oracle'):
            try:
                import oracledb
            except Exception as err:
                common.abort(sys.exc_info(),err)

            outfile=oracledb.queryResults(query,params)
            print(outfile)
        #SNOWFLAKE
        if dbtype.startswith('snowflake'):
            try:
                import snowflakedb
            except Exception as err:
                common.abort(sys.exc_info(),err)

            outfile=snowflakedb.queryResults(query,params)
            print(outfile)
        #SQLITE
        if dbtype.startswith('sqlite'):
            try:
                import sqlitedb
            except Exception as err:
                common.abort(sys.exc_info(),err)

            outfile=sqlitedb.queryResults(query,params)
            print(outfile)
        #POSTGRES
        if dbtype.startswith('postgre'):
            try:
                import postgresdb
            except Exception as err:
                common.abort(sys.exc_info(),err)

            outfile=postgresdb.queryResults(query,params)
            print(outfile)
        #MSACCESS
        if dbtype.startswith('msaccess'):
            try:
                import msaccessdb
            except Exception as err:
                common.abort(sys.exc_info(),err)

            outfile=msaccessdb.queryResults(query,params)
            print(outfile)
        #MSCSV
        if dbtype.startswith('mscsv'):
            try:
                import mscsvdb
            except Exception as err:
                common.abort(sys.exc_info(),err)
            
            outfile=mscsvdb.queryResults(query,params)
            print(outfile)
        #MSEXCEL
        if dbtype.startswith('msexcel'):
            try:
                import msexceldb
            except Exception as err:
                common.abort(sys.exc_info(),err)
            
            outfile=msexceldb.queryResults(query,params)
            print(outfile)
    else:
        print("Error: invalid database: {}".format(dbname))
except Exception as err:
    sys.stdout.flush()
    sys.stderr.flush()
    common.abort(sys.exc_info(),err)
