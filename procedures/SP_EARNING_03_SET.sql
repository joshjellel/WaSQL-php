DROP PROCEDURE SP_EARNING_03_SET;
create Procedure Commissions.sp_Earning_03_Set(
					 pn_Period_id		int
					,pn_Period_Batch_id	int)
   LANGUAGE SQLSCRIPT
   DEFAULT SCHEMA Commissions
AS
    
Begin
	declare ln_Max_Lvl		integer;
	declare ln_Lvl			integer;
    
	Update period_batch
	Set beg_date_Earning_3 = current_timestamp
      ,end_date_Earning_3 = Null
   	Where period_id = :pn_Period_id
   	and batch_id = :pn_Period_Batch_id;
   	
   	commit;
   	
   	-- Get Customers
	lc_Customer =
		select 
			  c.hier_level 												as hier_level
			, c.period_id												as Period_id
			, c.batch_id												as Batch_id
			, c.customer_id												as Customer_id
			, c.sponsor_id												as Sponsor_id
			, map(ifnull(f.flag_type_id,0),2,f.flag_value,c.currency)	as Currency
			, c.type_id													as Type_id
   			, c.status_id												as Status_id
		from customer_history c
			  left outer join customer_history_flag f
				  on c.customer_id = f.customer_id
				  and c.period_id = f.period_id
				  and c.batch_id = f.batch_id
				  and f.flag_type_id in (2)
		where c.period_id = :pn_Period_id
		and c.batch_id = :pn_Period_Batch_id;
   	
   	/*
   	lc_Customer =
		select 
			  c.hierarchy_level 										as hier_level
			, c.period_id												as Period_id
			, c.batch_id												as Batch_id
			, c.customer_id												as Customer_id
			, c.sponsor_id												as Sponsor_id
			, map(ifnull(f.flag_type_id,0),2,f.flag_value,c.currency)	as Currency
			, c.type_id													as Type_id
   			, c.status_id												as Status_id
		from HIERARCHY ( 
			 	SOURCE (select customer_id AS node_id, sponsor_id AS parent_id, a.*
			            from customer_history a
			            where period_id = :pn_Period_id
						and batch_id = :pn_Period_Batch_id
			            order by customer_id)
	    		Start where customer_id = 3) c
			  left outer join customer_history_flag f
				  on c.customer_id = f.customer_id
				  and c.period_id = f.period_id
				  and c.batch_id = f.batch_id
				  and f.flag_type_id in (2);
	*/
   	
   	-- Get Retail Transactions
   	lc_Transaction =
   		select
			  transaction_id		as transaction_id
			, type_id				as transaction_type_id
			, customer_id			as Customer_id
			, customer_type_id		as type_id
			, currency				as Currency
			, value_5				as Bonus
   		from transaction
   		where period_id = :pn_Period_id
   		and type_id = 3
   		union all
   		select
			  r.transaction_id		as transaction_id
			, r.type_id				as transaction_type_id
			, r.customer_id			as Customer_id
			, r.customer_type_id	as type_id
			, r.currency			as Currency
			, r.value_5				as Bonus
   		from transaction t, transaction r
   		where t.transaction_id = r.transaction_ref_id
   		and t.period_id = :pn_Period_id
   		and t.type_id = 3;
   		
   	-- Get Exchange Rates
   	lc_Exchange = 
   		select *
   		from gl_Exchange(:pn_Period_id);
		
	-- Get Customer Type
	lc_Customer_Type =
		select *
		from customer_type;
		
	-- Get Customer Status
	lc_Customer_Status =
		select *
		from customer_status;
   		
   	-- Get Max Level
    select max(hier_level)
    into ln_Max_Lvl
    from :lc_Customer;
    
    -- Insert Transactions at level 0
	insert into Earning_03
	select 
		  c.period_id											as period_id
		, c.batch_id											as batch_id
		, t.transaction_id										as transaction_id
		, c.customer_id											as customer_id
		, 0														as lvl
		, 0														as lvl_paid
		, case
			when ifnull(t1.has_downline,-1) = 1
			and ifnull(s1.has_earnings,-1) = 1 then 1
			else 0 end											as qual_flag
		, fx.currency											as from_currency
		, tx.currency											as to_currency
		, round(tx.rate/fx.rate,7)								as exchange_rate
		, t.bonus												as bonus
		, round(t.bonus * (tx.rate/fx.rate), tx.round_factor)	as Bonus_Exchanged
	from :lc_Transaction t
	   	 left outer join :lc_Customer_Type t1
	   		on t.type_id = t1.type_id
		,:lc_Customer c
		   	 left outer join :lc_Customer_Status s1
		   		on c.status_id = s1.status_id
		,:lc_Exchange fx
		,:lc_Exchange tx
	where t.customer_id = c.customer_id
	and t.currency = fx.currency
	and c.currency = tx.currency;
	
	commit;

   	-- Loop through all tree levels from bottom to top
    for ln_Lvl in reverse 1..:ln_Max_Lvl do
    	insert into Earning_03
		select 
			  s.period_id											as period_id
			, s.batch_id											as batch_id
			, t.transaction_id										as transaction_id
			, s.customer_id											as customer_id
			, t.lvl + 1												as lvl
			, 1														as lvl_paid
			, case
				when ifnull(t1.has_downline,-1) = 1
				and ifnull(s1.has_earnings,-1) = 1 then 1
				else 0 end											as qual_flag
			, fx.currency											as from_currency
			, tx.currency											as to_currency
			, round(tx.rate/fx.rate,7)								as exchange_rate
			, t.bonus												as bonus
			, round(t.bonu :pn_Period_Batch_id) a
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
			  on c.t