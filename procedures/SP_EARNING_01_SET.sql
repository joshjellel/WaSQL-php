DROP PROCEDURE SP_EARNING_01_SET;
create Procedure Commissions.sp_Earning_01_Set(
					 pn_Period_id		int
					,pn_Period_Batch_id	int)
   LANGUAGE SQLSCRIPT
   DEFAULT SCHEMA Commissions
AS
    
Begin
    declare ln_max      integer;
    declare ln_x        integer;
    
	Update period_batch
	Set beg_date_Earning_1 = current_timestamp
      ,end_date_Earning_1 = Null
   	Where period_id = :pn_Period_id
   	and batch_id = :pn_Period_Batch_id;
   	
   	commit;
   	
    -- Set Elephant Leg Flag
	replace customer_history (customer_id, period_id, batch_id, Earning_1_cap)
	select
		 c.customer_id
		,c.period_id
		,c.batch_id
		,r.value_2
	from customer_history c, cap_req r, customer_type t
	where c.rank_id = r.rank_id
	and c.type_id = t.type_id
	and c.period_id = r.period_id
   	and c.batch_id = r.batch_id
	and c.period_id = :pn_period_id
   	and c.batch_id = :pn_Period_Batch_id
   	and c.vol_13 <> 0
   	and t.has_downline = 1
   	and (select count(*) from customer_history where period_id = c.period_id and batch_id = c.batch_id and sponsor_id = c.customer_id) > 0
   	and round((select max(vol_13) from customer_history where period_id = c.period_id and batch_id = c.batch_id and sponsor_id = c.customer_id) / c.vol_13,2)*100 >= r.value_1;
   	
   	commit;
   	
   	-- Get Period Customers
    lc_Customers_Level = 
    	with lc_Customers as (
    		select 
				 c.customer_id
				,c.type_id
				,ifnull(c.comm_status_date,
					case when c.type_id in (1,4,5) then c.entry_date								-- Type Wellness, Professional and Wholesale default to entry_date
					else to_date('1/1/2000','mm/dd/yyyy') end) 					as comm_status_date -- All other default to 1/1/2000
				,c.entry_date
				,c.sponsor_id
				,c.period_id
				,c.batch_id
				,c.country
				,map(ifnull(f.flag_type_id,0),2,f.flag_value,c.currency) 		as currency			-- Use currency of flag 2 - Comm Payto Currency
				,c.rank_id
				,c.rank_qual
				,c.Earning_1_cap
				,c.hier_level
			from customer_history c
				  left outer join customer_history_flag f
					  on c.customer_id = f.customer_id
					  and c.period_id = f.period_id
					  and c.batch_id = f.batch_id
					  and f.flag_type_id = 2
			where c.period_id = :pn_Period_id
			and c.batch_id = :pn_Period_Batch_id
			and c.type_id not in (6))
		select
			 c.customer_id				as customer_id
			,c.period_id				as period_id 
			,c.batch_id					as batch_id
			,c.type_id					as type_id
			,c.country					as country
			,c.currency					as currency
			,c.comm_status_date			as comm_status_date
			,c.entry_date				as entry_date
			,c.rank_id					as rank_id
			,c.rank_qual				as rank_qual
			,c.Earning_1_cap				as Earning_1_cap
			,s.customer_id				as sponsor_id
			,ifnull(s.rank_id,1)		as sponsor_rank_id
			,ifnull(s.rank_qual,0)		as sponsor_rank_qual
			,s.currency					as sponsor_currency_code
			,s.country					as sponsor_country_code
			,c.hier_level					as hier_level
		from lc_Customers c
	    	, lc_Customers s 
	    where c.sponsor_id = s.customer_id;
		
	/*
	lc_Customers_Level = 
    	with lc_Customers as (
    		select 
				 c.customer_id
				,c.type_id
				,ifnull(c.comm_status_date,
					case when c.type_id in (1,4,5) then c.entry_date								-- Type Wellness, Professional and Wholesale default to entry_date
					else to_date('1/1/2000','mm/dd/yyyy') end) 					as comm_status_date -- All other default to 1/1/2000
				,c.entry_date
				,c.sponsor_id
				,c.period_id
				,c.batch_id
				,c.country
				,map(ifnull(f.flag_type_id,0),2,f.flag_value,c.currency) 		as currency			-- Use currency of flag 2 - Comm Payto Currency
				,c.rank_id
				,c.rank_qual
				,c.Earning_1_cap
			from customer_history c
				  left outer join customer_history_flag f
					  on c.customer_id = f.customer_id
					  and c.period_id = f.period_id
					  and c.batch_id = f.batch_id
					  and f.flag_type_id = 2
			where c.period_id = :pn_Period_id
			and c.batch_id = :pn_Period_Batch_id
			and c.type_id not in (6))
		select
			 c.customer_id				as customer_id
			,c.period_id				as period_id 
			,c.batch_id					as batch_id
			,c.type_id					as type_id
			,c.country					as country
			,c.currency					as currency
			,c.comm_status_date			as comm_status_date
			,c.entry_date				as entry_date
			,c.rank_id					as rank_id
			,c.rank_qual				as rank_qual
			,c.Earning_1_cap				as Earning_1_cap
			,s.customer_id				as sponsor_id
			,ifnull(s.rank_id,1)		as sponsor_rank_id
			,ifnull(s.rank_qual,0)		as sponsor_rank_qual
			,s.currency					as sponsor_currency_code
			,s.country					as sponsor_country_code
			,h.hierarchy_level			as hier_level
		from HIERARCHY ( 
			 	SOURCE ( select customer_id AS node_id, sponsor_id AS parent_id, a.customer_id, a.sponsor_id
			             from lc_Customers a
			             order by customer_id)
	    		Start where sponsor_id = 3) h
	    	, lc_Customers c
	    	, lc_Customers s 
	    where h.customer_id = c.customer_id
	    an :pn_Period_Batch_id) a
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
	--end if;

end; 										as sponsor_id
			,c.enroller_id													as enroller_id
			,c.country														as country
			,e.currency														as currency
			,e.rate															as exchange_rate
			,e.round_factor													as round_factor
			,ifnull(c.comm_status_date,
				case when ifnull(t1.has_faststart,0) = 1 then c.entry_date						-- Type Wellness, Professional and Wholesale default to entry_date
				else to_date('1/1/2000','mm/dd/yyyy') end) 					as comm_status_date -- All other default to 1/1/2000
			,c.entry_date													as entry_date
			,ifnull(v.version_id,1)				              g      create procedure commissions.CUSTOMER_FLAG_DELETE
/*--------------------------------------------------
* @author       Del Stirling
* @category     stored procedure
* @date			5/2/2017
*
* @describe     Deletes records in the customer_flag table based on JSON input
*
* @param		nvarchar pn_json
* @out_param	varchar ps_result
* @example      call customer_flag_delete('[{"pn_Customer_id":1247,"pn_Period_id":13,"pn_flag_type_id":2,"pn_flag_value":"USA","pn_beg_date":null,"pn_end_date":null,"pn_flag":1}{"pn_Customer_id":1248,"pn_Period_id":14,"pn_flag_type_id":1,"pn_flag_value":"CHN","pn_beg_date":null,"pn_end_date":null,"pn_flag":1}]', ?);
-------------------------------------------------------*/
	(
	pn_json 			nvarchar(5000)
	, out ps_result 	varchar(100))
	
	LANGUAGE SQLSCRIPT
	SQL SECURITY INVOKER
	DEFAULT SCHEMA commissions
as
BEGIN
	declare ln_record_num integer = 0;
	declare ln_column_num integer;
	declare ls_record varchar(5000) = '';
	declare ls_column_name varchar(5000);
	declare ls_column_val varchar(5000);
	
	declare la_customer_id integer array;
	declare la_flag_type_id integer array;
	declare la_flag_value varchar(100) array;
	declare la_beg_date date array;
	declare la_end_date date array;
	
	DECLARE EXIT HANDLER FOR SQLEXCEPTION
	BEGIN
	  ps_result = 'Error ' || ::SQL_ERROR_CODE || ' - ' || ::SQL_ERROR_MESSAGE;
	END;

	while :ls_record is not null do
		ln_record_num = ln_record_num + 1;
		ln_column_num = 1;
		ls_column_name = '';
		select substr_regexpr('({[^{}]*})' in :pn_json occurrence :ln_record_num)
		into ls_record 
		from dummy;

		while (:ls_column_name is not null) do 
			select substr_regexpr('"([a-z0-9_]+)":"?([a-z0-9]*)"?[,}]' flag 'i' in :ls_record occurrence :ln_column_num group 1) 
				, substr_regexpr('"([a-z0-9_]+)":"?([a-z0-9]*)"?[,}]' flag 'i' in :ls_record occurrence :ln_column_num group 2)
			into ls_column_name
				, ls_column_val
			from dummy;
			ln_column_num = :ln_column_num + 1;
			if (:ls_column_name is not null) then
				if lower(:ls_column_val) = 'null' then 
					ls_column_val = null; 
				end if;
				if lower(:ls_column_name) = 'pn_customer_id' then
					la_customer_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'pn_flag_type_id' then
					la_flag_type_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'pn_flag_value' then
					la_flag_value[:ln_record_num] = :ls_column_val;
				elseif lower(:ls_column_name) = 'pn_beg_date' then
					la_beg_date[:ln_record_num] = to_date(ls_column_val);
				elseif lower(:ls_column_name) = 'pn_end_date' then
					la_end_date[:ln_record_num] = to_date(ls_column_val);
				end if;
			end if;
		end while;
	end while;
	value_tab = UNNEST(:la_customer_id,:la_flag_type_id,:la_flag_value,:la_beg_date,:la_end_date) 
		AS ("CUSTOMER_ID","FLAG_TYPE_ID","FLAG_VALUE","BEG_DATE","END_DATE");

	delete 
	from customer_flag
	where exists (select customer_id, flag_type_id from :value_tab t where t.customer_id = customer_flag.customer_id and t.flag_type_id = customer_flag.flag_type_id);
	
	ps_result :='success';
END; stomer c
			left outer join :lc_Exchange x1
			  	on x1.currency = c.currency
			left outer join customer_type t1
			  	on c.type_id = t1.type_id
			left outer join customer_type ct
			 	on ct.type_id = c.type_id
			, :lc_Customer a
			left outer join customer_type at
			 	on at.type_id = a.type_id
			left outer join :lc_Exchange x2
				on x2.currency = a.currency
			left outer join customer_type t2
			  	on a.type_id = t2.type_id
			, gl_Volume_Pv_Detail(:pn_Period_id, 0) t
		Where t.customer_id = c.customer_id
		and c.customer_id = a.sponsor_id
		And ifnull(t2.has_retail,-1) = 1
		And ifnull(t1.has_downline,-1) = 1;
	else
	*/
		return
		Select 
		      t.period_id
		     ,t.batch_id
		     ,t.source_id
		     ,t.source
		     ,c.customer_id
		     ,c.customer_name
		     ,t.customer_type_id
		     ,ct.description								as customer_type
		     ,a.cust              �      create procedure commissions.CUSTOMER_FLAG_UPSERT
/*--------------------------------------------------
* @author       Del Stirling
* @category     stored procedure
* @date			4/28/2017
*
* @describe     updates or inserts records into the customer_flag table based on JSON input
*
* @param		nvarchar pn_json
* @out_param	varchar result
* @example      call customer_flag_delete('[{"pn_Customer_id":1247,"pn_Period_id":13,"pn_flag_type_id":2,"pn_flag_value":"USA","pn_beg_date":null,"pn_end_date":null,"pn_flag":1}{"pn_Customer_id":1248,"pn_Period_id":14,"pn_flag_type_id":1,"pn_flag_value":"CHN","pn_beg_date":null,"pn_end_date":null,"pn_flag":1}]', ?);
-------------------------------------------------------*/
	(
	pn_json 		nvarchar(8388607)
	, out result 	varchar(100))
	LANGUAGE SQLSCRIPT
	SQL SECURITY INVOKER
	DEFAULT SCHEMA commissions
as
BEGIN
	declare ln_record_num integer = 0;
	declare ln_column_num integer;
	declare ls_record varchar(5000) = '';
	declare ls_column_name varchar(5000);
	declare ls_column_val varchar(5000);
	declare valid integer = 1;
	declare currcount integer = 0;
	
	declare la_customer_id integer array;
	declare la_customer_flag_id integer array;
	declare la_flag_type_id integer array;
	declare la_flag_value varchar(100) array;
	declare la_beg_date date array;
	declare la_end_date date array;
	
	DECLARE EXIT HANDLER FOR SQLEXCEPTION
	BEGIN
	  result = 'Error ' || ::SQL_ERROR_CODE || ' - ' || ::SQL_ERROR_MESSAGE;
	END;
	
	while :ls_record is not null do
		ln_record_num = ln_record_num + 1;
		ln_column_num = 1;
		ls_column_name = '';
		select substr_regexpr('({[^{}]*})' in :pn_json occurrence :ln_record_num)
		into ls_record 
		from dummy;

		while (:ls_column_name is not null) do 
			select substr_regexpr('"([a-z0-9_]+)":"?([a-z0-9]*)"?[,}]' flag 'i' in :ls_record occurrence :ln_column_num group 1) 
				, substr_regexpr('"([a-z0-9_]+)":"?([a-z0-9]*)"?[,}]' flag 'i' in :ls_record occurrence :ln_column_num group 2)
			into ls_column_name
				, ls_column_val
			from dummy;
			ln_column_num = :ln_column_num + 1;
			if (:ls_column_name is not null) then
				if lower(:ls_column_val) = 'null' then 
					ls_column_val = null; 
				end if;
				if lower(:ls_column_name) = 'pn_customer_id' then
					la_customer_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'pn_customer_flag_id' then
					la_customer_flag_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'pn_flag_type_id' then
					la_flag_type_id[:ln_record_num] = to_number(:ls_column_val);
				elseif lower(:ls_column_name) = 'pn_flag_value' then
					la_flag_value[:ln_record_num] = :ls_column_val;
				elseif lower(:ls_column_name) = 'pn_beg_date' then
					la_beg_date[:ln_record_num] = to_date(ls_column_val);
				elseif lower(:ls_column_name) = 'pn_end_date' then
					la_end_date[:ln_record_num] = to_date(ls_column_val);
				end if;
			end if;
		end while;
		if (:la_customer_flag_id[:ln_record_num] is null) then
			select 