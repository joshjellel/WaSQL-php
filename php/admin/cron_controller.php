<?php
global $CONFIG;
if(isset($CONFIG['paging']) && $CONFIG['paging'] < 100){
	$CONFIG['paging']=100;
}
if(!isset($CONFIG['admin_form_url'])){
	$CONFIG['admin_form_url']='/php/admin.php';
}
switch(strtolower($_REQUEST['func'])){
	case 'run':
		$temppath=getWasqlPath('php/temp');
		$afile="{$temppath}/{$CONFIG['name']}_runnow.txt";
		$id=(integer)$_REQUEST['id'];
		$ok=setFileContents($afile,$id);
		usleep(250);
		$cron=cronDetails($id);
		setView('details',1);
		return;
	break;
	case 'kill':
		$id=(integer)$_REQUEST['id'];
		$path=getWaSQLPath('php/temp');
		$killfile="{$path}/{$CONFIG['name']}_cronkill_{$id}.txt";
		setFileContents($killfile,time());
		$ok=commonCronCleanup();
		usleep(250);
		$cron=cronDetails($id);
		setView('details',1);
		return;
	break;
	case 'add':
		//echo "add";exit;
		$id=0;
		setView('addedit',1);
		return;
	break;
	case 'edit':
		//echo "edit";exit;
		$id=(integer)$_REQUEST['id'];
		setView('addedit',1);
		return;
	break;
	case 'pause':
		$idstr=$_REQUEST['ids'];
		$ok=editDBRecordById('_cron',$idstr,array('paused'=>1));
		setView('list',1);
		return;
	break;
	case 'unpause':
		$idstr=$_REQUEST['ids'];
		$ok=editDBRecordById('_cron',$idstr,array('paused'=>0));
		setView('list',1);
		return;
	break;
	case 'details':
		//echo "details";exit;
		$id=(integer)$_REQUEST['id'];
		$cron=cronDetails($id);
		$ok=commonCronCleanup();
		setView('details',1);
		return;
	break;
	case 'cron_result':
		//echo "cron_result";exit;
		$id=(integer)$_REQUEST['id'];
		if($id==0){
			$id=(integer)$_REQUEST['cron_id'];
			$path=getWaSQLPath('php/temp');
			$commonCronLogFile="{$path}/{$CONFIG['name']}_cronlog_{$id}.txt";
			$log=array('run_error'=>'');
			if(file_exists($commonCronLogFile)){
				$t=time()-filectime($commonCronLogFile);
				$run_length=verboseTime($t);
				$log['bottom']="{$id},0,'{$run_length}'";
				$log['_id']=0;
				$log['cron_id']=$id;
				$log['run_result']=getFileContents($commonCronLogFile);
			}
			else{
				$log['run_result']='No longer running';
			}
			setView('cron_result',1);
			return;
		}
		$log=getDBRecordById('_cronlog',$id);
		setView('cron_result',1);
	break;
	case 'list':
		//echo "list";exit;
		setView('list',1);
		return;
	break;
	default:
		//echo "default";exit;
		$ok=cronCheckSchema();
		setView('default');
	break;
}
?>