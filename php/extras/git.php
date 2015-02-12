<?php
/* 
	https://github.com/kbjr/Git.php

*/
$progpath=dirname(__FILE__);
require_once("{$progpath}/git/Git.php");
//---------- begin function gitStatus--------------------------------------
/**
* @describe executes git status command and returns result in a key/value array
* @param dir string - the directory of your git repo
* @return array
*	returns the results of executing the command in a key/value array
*		branch
*		status
*		new - array of uncommitted files found
*		modified - array of modified files found
*		raw - raw lines returned before parsing
*/
function gitStatus($dir='',$format='array'){
	$repo = Git::open($dir);
	return $repo->status($format);
}

//---------- begin function gitPull--------------------------------------
/**
* @describe executes git pull command and returns result in a key/value array
* @param dir string - the directory of your git repo
* @return array
*	returns the results of executing the command in a key/value array
*		from - repo pulled
*		status
*		changed - number of files updated
*		inserted - number of files inserted
*		deleted - number of files deleted
*		files - array of files as the key an the info as the val
*		raw - raw lines returned before parsing

D:\wasql>git pull
remote: Counting objects: 15, done.
remote: Compressing objects: 100% (6/6), done.
remote: Total 15 (delta 10), reused 13 (delta 8)
Unpacking objects: 100% (15/15), done.
From https://github.com/WaSQL/v2
   ce8fd1b..ec2ed99  master     -> origin/master
Updating ce8fd1b..ec2ed99
Fast-forward
 .gitignore               | 3 ++-
 cron.pl                  | 0
 cron_start.sh            | 0
 dc.pl                    | 0
 dirsetup.sh              | 0
 env.pl                   | 1 +
 exif.pl                  | 1 +
 ipnames.pl               | 0
 php/extras/tcpdf.php     | 1 +
 piegen.pl                | 0
 pop.pl                   | 0
 sh/db_backup.sh          | 0
 sh/db_backupall.sh       | 0
 sh/db_clone.sh           | 0
 sh/db_import.sh          | 0
 sh/db_list.sh            | 0
 sh/db_optimize.sh        | 0
 sh/db_prompt.sh          | 0
 sh/db_settings_sample.sh | 0
 sh/db_wipe.sh            | 0
 sh/install_php.sh        | 0
 sh/php_install.sh        | 0
 sh/php_prep.sh           | 0
 sh/php_versions.sh       | 0
 stock.pl                 | 0
 subs_calendar.pl         | 0
 subs_common.pl           | 0
 subs_database.pl         | 0
 subs_email.pl            | 0
 subs_manage.pl           | 0
 subs_socket.pl           | 0
 subs_uncommon.pl         | 0
 subs_wasql.pl            | 0
 subs_zip.pl              | 0
 test.pl                  | 0
 wasql.pl                 | 0
 wasqlmail.pl             | 0
 wb.pl                    | 0
 wget.pl                  | 0
 wpath.pl                 | 0
 xls2csv.pl               | 0
 41 files changed, 5 insertions(+), 1 deletion(-)
 mode change 100644 => 100755 cron.pl
 mode change 100644 => 100755 cron_start.sh
 mode change 100644 => 100755 dc.pl
 mode change 100644 => 100755 dirsetup.sh
 mode change 100644 => 100755 env.pl
 mode change 100644 => 100755 exif.pl
 mode change 100644 => 100755 ipnames.pl
 mode change 100644 => 100755 piegen.pl
 mode change 100644 => 100755 pop.pl
 mode change 100644 => 100755 sh/db_backup.sh
 mode change 100644 => 100755 sh/db_backupall.sh
 mode change 100644 => 100755 sh/db_clone.sh
 mode change 100644 => 100755 sh/db_import.sh
 mode change 100644 => 100755 sh/db_list.sh
 mode change 100644 => 100755 sh/db_optimize.sh
 mode change 100644 => 100755 sh/db_prompt.sh
 mode change 100644 => 100755 sh/db_settings_sample.sh
 mode change 100644 => 100755 sh/db_wipe.sh
 mode change 100644 => 100755 sh/install_php.sh
 mode change 100644 => 100755 sh/php_install.sh
 mode change 100644 => 100755 sh/php_prep.sh
 mode change 100644 => 100755 sh/php_versions.sh
 mode change 100644 => 100755 stock.pl
 mode change 100644 => 100755 subs_calendar.pl
 mode change 100644 => 100755 subs_common.pl
 mode change 100644 => 100755 subs_database.pl
 mode change 100644 => 100755 subs_email.pl
 mode change 100644 => 100755 subs_manage.pl
 mode change 100644 => 100755 subs_socket.pl
 mode change 100644 => 100755 subs_uncommon.pl
 mode change 100644 => 100755 subs_wasql.pl
 mode change 100644 => 100755 subs_zip.pl
 mode change 100644 => 100755 test.pl
 mode change 100644 => 100755 wasql.pl
 mode change 100644 => 100755 wasqlmail.pl
 mode change 100644 => 100755 wb.pl
 mode change 100644 => 100755 wget.pl
 mode change 100644 => 100755 wpath.pl
 mode change 100644 => 100755 xls2csv.pl
*/
function gitPull($dir=''){
	$results=cmdResults('git','pull',$dir);
	if(isset($results['stderr']) && strlen($results['stderr'])){
    	return "gitPull Error: {$results['stderr']}";
	}
	$lines=preg_split('/[\r\n]+/',$results['stdout']);
	$rtn=array('dir'=>$dir);
	$marker='';
	foreach($lines as $i=>$line){
    	$line=trim($line);
    	//skip blank lines
    	if(!strlen($line)){continue;}
    	if(preg_match('/^From (.+)$/i',$line,$m)){
			$rtn['from']=$m[1];
			continue;
		}
		if(stringContains($line,'up-to-date')){
			$rtn['status']='up-to-date';
			continue;
		}
		elseif(preg_match('/^([0-9]+?) files changed/i',$line,$m)){
        	$rtn['changed']=$m[1];
        	if(preg_match('/^([0-9]+?) insertions/i',$line,$m)){
				$rtn['inserted']=$m[1];
			}
			if(preg_match('/^([0-9]+?) deletion/i',$line,$m)){
				$rtn['deleted']=$m[1];
			}
        	continue;
		}
		elseif(preg_match('/^(.+?)\|(.+)$/i',$line,$m)){
			$file=trim($m[1]);
        	$rtn['files'][$file]=trim($m[2]);
        	continue;
		}
		//skip comment lines
		if(preg_match('/^\(/',$line)){continue;}
		if(preg_match('/^Untracked files\:/i',$line)){
        	$marker='new';
        	continue;
		}
		if(!strlen($marker)){continue;}
		$rtn[$marker][]=trim($line);
	}
	$rtn['raw']=$lines;
	return $rtn;
}

?>