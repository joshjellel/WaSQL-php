drop procedure Commissions.Period_Batch_Set;
create procedure Commissions.Period_Batch_Set(
					 pn_Period_id		int)
   LANGUAGE SQLSCRIPT
   DEFAULT SCHEMA Commissions
AS

begin
	update period_batch
	set viewable = 0
	where period_id = :pn_Period_id;
	
	-- Create New Batch
	insert into period_batch
	select
		(select ifnull(max(batch_id)+1,0) 
		 from period_batch 
		 where period_id = p.period_id)	as batch_id
		,p.period_id					as period_id
		,current_timestamp				as entry_date
		,1								as viewable
		,t.clear_flag					as clear_flag
		,t.set_volume					as set_volume
		,t.set_volume_lrp				as set_volume_lrp
		,t.set_volume_fs				as set_volume_fs
		,t.set_volume_retail			as set_volume_retail
		,t.set_volume_egv				as set_volume_egv
		,t.set_volume_tv				as set_volume_tv
		,t.set_volume_tw_cv				as set_volume_tw_cv
		,t.set_volume_org				as set_volume_org
		,t.set_rank						as set_rank
		,t.set_payout_1					as set_payout_1
		,t.set_payout_2					as set_payout_2
		,t.set_payout_3					as set_payout_3
		,t.set_payout_4					as set_payout_4
		,t.set_payout_5					as set_payout_5
		,t.set_payout_6					as set_payout_6
		,t.set_payout_7					as set_payout_7
		,t.set_payout_8					as set_payout_8
		,t.set_payout_9					as set_payout_9
		,t.set_payout_10				as set_payout_10
		,null							as beg_date_clear
		,null							as end_date_clear
		,null							as beg_date_run
		,null							as end_date_run
		,null							as beg_date_volume
		,null							as end_date_volume
		,null							as beg_date_volume_lrp
		,null							as end_date_volume_lrp
		,null							as beg_date_volume_fs
		,null							as end_date_volume_fs
		,null							as beg_date_volume_retail
		,null							as end_date_volume_retail
		,null							as beg_date_volume_egv
		,null							as end_date_volume_egv
		,null							as beg_date_volume_tv
		,null							as end_date_volume_tv
		,null							as beg_date_volume_tw_cv
		,null							as end_date_volume_tw_cv
		,null							as beg_date_volume_org
		,null							as end_date_volume_org
		,null							as beg_date_rank
		,null							as end_date_rank
		,null							as beg_date_payout_1
		,null							as end_date_payout_1
		,null							as beg_date_payout_2
		,null							as end_date_payout_2
		,null							as beg_date_payout_3
		,null							as end_date_payout_3
		,null							as beg_date_payout_4
		,null							as end_date_payout_4
		,null							as beg_date_payout_5
		,null							as end_date_payout_5
		,null							as beg_date_payout_6
		,null							as end_date_payout_6
		,null							as beg_date_payout_7
		,null							as end_date_payout_7
		,null							as beg_date_payout_8
		,null							as end_date_payout_8
		,null							as beg_date_payout_9
		,null							as end_date_payout_9
		,null							as beg_date_payout_10
		,null							as end_date_payout_10
	from period p, period_template t
	where p.period_type_id = t.period_type_id
	and p.period_id = :pn_Period_id;
			
	commit;
			
	-- Snapshot Customer and all supporting tables
	call Customer_Snap(:pn_Period_id);
	call Customer_Flag_Snap(:pn_Period_id);
	call Req_Qual_Leg_Snap(:pn_Period_id);
	call Req_Cap_Snap(:pn_Period_id);
	call Req_Unilevel_Snap(:pn_Period_id);
	call Req_Power3_Snap(:pn_Period_id);
	call Req_Pool_Snap(:pn_Period_id);

end;
