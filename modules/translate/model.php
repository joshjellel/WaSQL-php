<?php
function translateShowLocaleSelections(){
	global $MODULE;
	$recs=translateGetLocalesUsed(1);
	$current_locale=isset($_SESSION['REMOTE_LANG'])?strtolower($_SESSION['REMOTE_LANG']):strtolower($_SERVER['REMOTE_LANG']);
	foreach($recs as $i=>$rec){
		if($current_locale ==$rec['locale']){
			$recs[$i]['name'].=' <span class="icon-mark w_green"></span>';
		}
	}
	$recs=sortArrayByKeys($recs,array('name'=>SORT_ASC,'locale'=>SORT_ASC));
	return databaseListRecords(array(
		'-list'=>$recs,
		'-anchormap'=>'name',
		'-tableclass'=>'table table-condensed table-bordered table-striped table-hover',
		'-listfields'=>'locale,name,country',
		'-trclass'=>'w_pointer',
		'-onclick'=>"return ajaxGet('/{$MODULE['page']}/setlocale/%locale%','modal',{setprocessing:0,cp_title:'Locale Set'})",
		'-hidesearch'=>1
	));
}
function translateGetLangSelections(){
	global $MODULE;
	$recs=translateGetLocales();
	$used=translateGetLocalesUsed(1);
	$current_locale=isset($_SESSION['REMOTE_LANG'])?strtolower($_SESSION['REMOTE_LANG']):strtolower($_SERVER['REMOTE_LANG']);
	foreach($recs as $i=>$rec){
		if($current_locale ==$rec['locale']){
			$recs[$i]['name'].=' <span class="icon-mark w_green"></span>';
		}
		elseif(isset($used[$rec['locale']])){
			$recs[$i]['name'].=' <span class="icon-mark w_orange"></span>';
		}
	}
	$recs=sortArrayByKeys($recs,array('name'=>SORT_ASC,'locale'=>SORT_ASC));
	return $recs;
}
function translateGetLangSelectionsExtra($recs){
	//echo printValue($used);exit;
	foreach($recs as $i=>$rec){

	}
	return $recs;
}
function translateListRecords($locale){
	global $MODULE;
	global $CONFIG;
	$source_locale=translateGetSourceLocale();
	$opts=array(
		'-table'=>'_translations',
		'-formaction'=>"/{$MODULE['page']}/locale/{$locale}",
		'-tableclass'=>'table table-condensed table-bordered table-hover table-bordered',
		'-trclass'=>'w_pointer',
		'-listfields'=>'_id,source,translation,confirmed',
		'confirmed_displayname'=>'<span class="icon-mark w_success"></span>',
		'-searchfields'=>'source,translation',
		'-searchopers'=>'ct',
		'source_displayname'=>"Source ({$source_locale})",
		'source_style'=>'white-space: normal;',
		'translation_displayname'=>"Translation ({$locale})",
		'translation_style'=>'white-space: normal;',
		'confirmed_style'=>'text-align:center',
		'-onclick'=>"return ajaxGet('/{$MODULE['page']}/edit/%_id%','modal',{setprocessing:0})",
		'locale'=>$locale,
		'wasql'=>0,
		'-order'=>'confirmed,_id',
		'-results_eval'=>'translateAddExtraInfo'
	);
	if(isset($CONFIG['translate_source_id']) && isNum($CONFIG['translate_source_id'])){
		$opts['source_id']=$CONFIG['translate_source_id'];
	}
	if(isset($_REQUEST['filter_field'])){
		switch(strtolower($_REQUEST['filter_field'])){
			case 'source':
				unset($_REQUEST['filter_field']);
				$v=str_replace('source-ct-','',$_REQUEST['_filters']);
				unset($_REQUEST['_filters']);
				$opts['-where']="identifier in (select identifier from _translations where locale='{$source_locale}' and translation like '%{$v}%')";
		}
	}
	//return printValue($_REQUEST);
	return databaseListRecords($opts);
}
function translateAddExtraInfo($recs){
	$locale=$recs[0]['locale'];
	$ids=array();
	foreach($recs as $rec){
		$ids[]=$rec['_id'];
	}
	$idstr=implode(',',$ids);
	//sourcemap
	$source_locale=translateGetSourceLocale();	
	$opts=array(
		'-table'=>'_translations',
		'-where'=>"wasql=0 and locale='{$source_locale}' and identifier in (select identifier from _translations where wasql=0 and locale='{$locale}' and _id in ({$idstr}))",
		'-index'=>'identifier',
		'-fields'=>'identifier,translation'
	);
	$sourcemap=getDBRecords($opts);
	if(!count($sourcemap)){return $recs;}
	foreach($recs as $i=>$rec){
		$key=$rec['identifier'];
		if(isset($sourcemap[$key])){
			$recs[$i]['source']=$sourcemap[$key]['translation'];
		}
		if($recs[$i]['confirmed']==1){
			$recs[$i]['confirmed']='<span class="icon-mark w_success"></span>';
		}
		else{
			$recs[$i]['confirmed']='<span class="icon-block w_danger"></span>';
		}
	}
	return $recs;
}
function translateListLocales(){
	global $MODULE;
	$opts=array(
		'-list'=>translateGetLocalesUsed(0,0),
		'-hidesearch'=>1,
		'-anchormap'=>'locale',
		'-tableclass'=>'table table-condensed table-bordered table-hover table-bordered condensed striped bordered hover',
		'-listfields'=>'flag4x3,locale,entry_cnt,confirmed_cnt',
		'locale_onclick'=>"return ajaxGet('/{$MODULE['page']}/list/%locale%','translate_results',{setprocessing:'processing'});",
		'entry_cnt_onclick'=>"return ajaxGet('/{$MODULE['page']}/list/%locale%','translate_results',{setprocessing:'processing'});",
		'entry_cnt_displayname'=>translateText('Entries','',1),
		'entry_cnt_style'=>'text-align:right;',
		'flag4x3_displayname'=>translateText('Location','',1),
		'confirmed_cnt_displayname'=>'<span class="icon-mark w_success"></span>',
		'confirmed_cnt_class'=>'align-right',
		'-results_eval'=>'translateListLocalesExtra'
	);
	return databaseListRecords($opts);
}
function translateListLocalesExtra($recs){
	global $MODULE;
	$current_locale=isset($_SESSION['REMOTE_LANG'])?strtolower($_SESSION['REMOTE_LANG']):strtolower($_SERVER['REMOTE_LANG']);
	$confirm_msg=translateText('Delete?','',1);
	foreach($recs as $i=>$rec){
		//echo printValue($rec);exit;

		$recs[$i]['flag4x3'] = <<<ENDOFFLAG
<div style="display:flex;justify-content:space-between;align-items:center">
	<img src="{$rec['flag4x3']}" style="height:20px;width:auto" />
	<div style="margin-left:5px;flex:1;align-self:left;">
		<a href="#" onclick="return ajaxGet('/{$MODULE['page']}/list/{$rec['locale']}','translate_results',{setprocessing:'processing'});" class="w_gray w_smaller">{$rec['name']}</a>
	</div>
	<div>
		<a href="#remove" data-confirm="{$confirm_msg} -- {$rec['name']}" onclick="if(!confirm(this.dataset.confirm)){return false;}return ajaxGet('/{$MODULE['ajaxpage']}/deletelocale_confirmed/{$rec['locale']}','translate_nulldiv');"><span class="icon-close w_smallest w_red"></span></a>
	</div>
</div>
ENDOFFLAG;
		if($current_locale ==strtolower($rec['locale'])){
			$recs[$i]['locale'].=' <span class="icon-mark w_green"></span>';
		}
	}
	return $recs;
}
function translateEditRec($rec){
	global $MODULE;
	$opts=array(
		'-action'		=> "/{$MODULE['page']}/list/{$rec['locale']}",
		'-onsubmit'		=> "return ajaxSubmitForm(this,'translate_results');",
		'-name'			=> 'translateEditForm',
		'setprocessing'	=> 0,
		'_menu'			=> 'translate',
		'func'			=> 'list',
		'locale'		=> $rec['locale'],
		'-table'		=> '_translations',
		'-fields'		=> translateEditFields(),
		'-editfields'	=> 'translation,confirmed',
		'-order'		=> 'confirmed',
		'translation_inputtype'	=> 'textarea',
		'translation_class'		=> 'form-control browser-default',
		'translation_style'		=> 'height:150px;max-width:100%;',
		'translation_wrap'		=> 'soft',
		'confirmed'		=> 1,
		'_id'			=> $rec['_id'],
		'-hide'			=> 'clone',
		'-save'			=> translateText('Save'),
		'-reset'		=> translateText('Reset'),
		'-delete'		=> translateText('Delete')
	);
	//return $opts['-fields'];
	return addEditDBForm($opts);
}
function translateEditFields(){
	return <<<ENDOFFIELDS
	<div>[translation]</div>
ENDOFFIELDS;
}
?>