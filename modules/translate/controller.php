<?php
	global $PAGE;
	global $MODULE;
	if(!isset($MODULE['title'])){
		$MODULE['title']='<span class="icon-translate w_success"></span> <translate>Translation Manager</translate>';
	}
	loadExtras('translate');
	switch(strtolower($_REQUEST['passthru'][0])){
		case 'locale':
			$locale=addslashes($_REQUEST['passthru'][1]);
			//echo $locale.printValue($_REQUEST);exit;
			setView('default');
		break;
		case 'list':
			$locale=addslashes($_REQUEST['passthru'][1]);
			setView('list',1);
			return;
		break;
		case 'edit':
			global $CONFIG;
			if(isset($CONFIG['translate_locale']) && strlen($CONFIG['translate_locale'])){
				$source_locale=$CONFIG['translate_locale'];
			}
			else{$source_locale='en-us';}
			$id=(integer)$_REQUEST['passthru'][1];
			$rec=getDBRecord(array('-table'=>'_translations','_id'=>$id,'-fields'=>'_id,locale,identifier'));
			$sopts=array('-table'=>'_translations','locale'=>$source_locale,'identifier'=>$rec['identifier'],'-fields'=>'translation');
			//echo $id.printValue($rec).printValue($sopts);exit;
			//echo printValue($sopts);exit;
			$source_rec=getDBRecord($sopts);
			$rec['source']=$source_rec['translation'];
			setView('edit',1);
			return;
		break;
		default:
			$locales=translateGetLocalesUsed();
			setView('default');
		break;
	}
?>