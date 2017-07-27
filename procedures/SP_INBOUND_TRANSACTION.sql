DROP PROCEDURE SP_INBOUND_TRANSACTION;
create procedure commissions.sp_inbound_transaction(
	pn_json			varchar(8388607)
	, out result 	table(transaction_id integer))
	language sqlscript
	default schema commissions
/*----------------------------------------------------
by Del Stirling
parses a json string and enters the information into the transaction_log table

sample call:
call commissions.sp_inbound_transaction('[
{"source_id":1,"source_key_id":1,"source_ref_id":40664882,"entry_date":2017-01-01,"bonus_date":2017-01-02,"customer_id":2356263,"customer_type_id":1,"period_id":1,"order_number":123456789,"type_id":1,"category_id":1,"country":USA,"currency":USD,"value_1":1.00,"value_2":2.00,"value_3":3.00,"value_4":4.00,"value_5":5.00}
{"source_id":1,"source_key_id":1,"source_ref_id":40664882,"entry_date":2017-01-01,"bonus_date":2017-01-02,"customer_id":2356263,"customer_type_id":1,"period_id":1,"order_number":123456789,"type_id":1,"category_id":1,"country":USA,"currency":USD,"value_1":1.00,"value_2":2.00,"value_3":3.00,"value_4":4.00,"value_5":5.00}
]', ?);
------------------------------------------------------------*/
as 
begin
	declare ln_record_num 			integer = 0;
	declare ln_column_num 			integer;
	declare ls_record 				varchar(5000) = '';
	declare ls_column_name 			varchar(5000);
	declare ls_column_val 			varchar(5000);
	declare valid 					integer = 1;
	declare currcount 				integer = 0;
	declare ln_ref_order			integer;
	declare ln_transaction_id		integer;
	
	declare la_transaction_id				integer array;
	declare la_transaction_ref_id			integer array;
	declare la_transaction_entry_date		date array;
	declare la_transaction_processed_date	date array;
	declare la_source_key_id				integer array;
	declare la_source_id					integer array;
	declare la_entry_date					date array;
	declare la_bonus_date					date array;
	declare la_customer_id					integer array;
	declare la_customer_type_id				integer array;
	declare la_period_id					integer array;
	declare la_order_number					integer array;
	declare la_type_id						integer array;
	declare la_category_id					integer array;
	declare la_country						varchar(4) array;
	declare la_currency						varchar(4) array;
	declare la_value_1						decimal(18,8) array;
	declare la_value_2						decimal(18,8) array;
	declare la_value_3						decimal(18,8) array;
	declare la_value_4						decimal(18,8) array;
	declare la_value_5						decimal(18,8) array;
	
	DECLARE EXIT HANDLER FOR SQLEXCEPTION
	BEGIN
	END;

	while 1=1 do
		--loop through each record
		ln_record_num = ln_record_num + 1;
		ln_column_num = 1;
		ls_column_name = '';
		select substr_regexpr('({[^{}]*})' in :pn_json occurrence :ln_record_num)
		into ls_record 
		from dummy;
		if ls_record is null then 
			break;
		end if;
		select transaction_id.nextval
		into ln_transaction_id
		from dummy;
		la_transaction_id[:ln_record_num] = :ln_transaction_id;
		la_transaction_entry_date[:ln_record_num] = current_date;
		
		while (:ls_column_name is not null) do 
			--loop through each column
			select substr_regexpr('"([a-z0-9_]+)":"?([a-z0-9.-]*)"?[,}]' flag 'i' in :ls_record occurrence :ln_column_num group 1) 
				, substr_regexpr('"([a-z0-9_]+)":"?([a-z0-9.-]*)"?[,}]' flag 'i' in :ls_record occurrence :ln_column_num group 2)
			into ls_column_name
				, ls_column_val
			from dummy;
			ln_column_num = :ln_column_num + 1;
			if (:ls_column_name is not null) then
				if lower(:ls_column_val) = 'null' then 
					ls_column_val = null; 
				end if;
				if lower(:ls_column_name) = 'source_key_id' then
					la_source_key_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'source_id' then
					la_source_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'source_ref_id' then
					select max(transaction_id)
					into ln_ref_order
					from transaction
					where order_number = to_number(:ls_column_val);
					la_transaction_ref_id[:ln_record_num] = :ln_ref_order;
				elseif lower(:ls_column_name) = 'entry_date' then
					la_entry_date[:ln_record_num] = to_date(:ls_column_val);
				elseif lower(:ls_column_name) = 'bonus_date' then
					la_bonus_date[:ln_record_num] = to_date(:ls_column_val);
				elseif lower(:ls_column_name) = 'customer_id' then
					la_customer_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'customer_type_id' then
					la_customer_type_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'period_id' then
					la_period_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'order_number' then
					la_order_number[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'type_id' then
					la_type_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'category_id' then
					la_category_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(: :pn_Period_Batch_id) a
			left outer join customer_type at
			 	on at.type_id = a.type_id
			left outer join gl_Exchange(:pn_Period_id) x2
			  	on x2.currency = a.currency
			left outer join customer_type t2
			  	on a.type_id = t2.type_id
			, gl_Volume_Pv_Detail(:pn_Period_id, :pn_Period_Batch_id) t
		Where t.customer_id = a.customer_id
		and c.customer_id = a.sponsor_id
		And ifnull(t2.has_retail,-1) = 1
		And ifnull(t1.has_downline,-1) = 1;
	--end if;
	
end; urrency				varchar(5)
			,pv							decimal(18,8)
			,cv							decimal(18,8))
	LANGUAGE SQLSCRIPT
	SQL SECURITY INVOKER
   	DEFAULT SCHEMA Commissions
AS

begin
	/*
	if gl_Period_isOpen(:pn_Period_id) = 1 then
		return
		Select 
		      t.period_id
		     ,t.batch_id
		     ,t.customer_id
		     ,t.transaction_id
		     ,t.transaction_ref_id
		     ,t.type_id
		     ,t.category_id
		     ,t.entry_date
		     ,t.order_number
		     ,t.from_country
		     ,t.from_currency
		     ,t.to_currency
		     ,t.pv
		     ,t.cv
		From  gl_Volume_Pv_Detail(:pn_Period_id, :pn_Period_Batch_id) t
			  left outer join gl_Volume_Pv_Detail(:pn_Period_id, :pn_Period_Batch_id) r
			  on t.transaction_ref_id = r.transaction_id
			, customer c
			  left outer join customer_type t1
			  on c.type_id = t1.type_id
	   	Where t.customer_id = c.customer_id
	   	And t.period_id = :pn_Period_id
	   	And ifnull(t1.has_downline,-1) = 1
	   	and ifnull(t.type_id,4) <> 0
	   	And days_between(ifnull(c.comm_status_date,c.entry_date),ifnull(r.entry_date,t.entry_date)) <= 60;
	else
	*/
		return
		Select 
		      t.period_id
		     ,t.batch_id
		     ,t.customer_id
		     ,t.transaction_id
		     ,t.transaction_ref_id
		     ,t.type_id
		     ,t.category_id
		     ,t.entry_date
		     ,t.order_number
		     ,t.from_country
		     ,t.from_currency
		     ,t.to_currency
		     ,t.pv
		     ,t.cv
		From  gl_Volume_Pv_Detail(:pn_Period_id, :pn_Period_Batch_id) t
			  left outer join gl_Volume_Pv_Detail(:pn_Period_id, :pn_Period_Batch_id) r
			  	on t.transaction_ref_id = r.transaction_id
			, gl_Customer(:pn_Period_id, :pn_Period_Batch_id) c
			  left outer join customer_type t1
			  	on c.type_id = t1.type_id
	   	Where t.customer_id = c.customer_id
	   	And t.period_id = c.period_id
	   	And c.period_id = :pn_Period_id
	   	and c.batch_id = :pn_Period_Batch_id
	   	And ifnull(t1.has_downline,-1) = 1
	   	and ifnull(t.type_id,4) <> 0
	   	And days_between(ifnull(c.comm_status_date,c.entry_date),ifnull(r.entry_date,t.entry_date)) <= 60;
	-