<?php
	global $CONFIG;
	loadExtrasJs(array('moment','chart'));
	if(isset($_REQUEST['test'])){
		setView($_REQUEST['test'],1);
		switch(strtolower($_REQUEST['test'])){
			case 'chartjs':
				
			break;
		}
		return;
	}
	setView('default');
?>