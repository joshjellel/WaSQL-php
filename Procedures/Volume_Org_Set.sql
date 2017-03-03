drop Procedure Commissions.Volume_Org_Set;
create Procedure Commissions.Volume_Org_Set()
   LANGUAGE SQLSCRIPT
   DEFAULT SCHEMA Commissions
AS
    
Begin
    declare ln_max      integer;
    declare ln_x        integer;
    
	Update period_batch
	Set beg_date_volume_org = current_timestamp
      ,end_date_volume_org = Null
   	Where period_id = 0
   	and batch_id = 0;
   	
   	commit;
    
	-- Get Period Tree Snapshot
   	lc_Period_Tree =
		select customer_id, sponsor_id, vol_1
		from customer;
    
    -- Add Tree Level to Snaphot
    lc_dist = 
		select
			 node_id 			as customer_id
			,vol_1				as vol_1
			,hierarchy_level	as level_id
		from HIERARCHY ( 
			 	SOURCE ( select customer_id AS node_id, sponsor_id AS parent_id, vol_1
			             from :lc_Period_Tree
			             order by customer_id)
	    		Start where sponsor_id = 3);
        
    -- Get Max Level
    select max(level_id)
    into ln_max
    from :lc_dist;
    
    -- Loop through all tree levels from bottom to top
    for ln_x in reverse 0..:ln_max do
    	-- Update Org Volume by rolling up PV
    	replace customer (customer_id, vol_12)
        select
            d.customer_id            						as customer_id
           ,sum(ifnull(o.vol_12,0)) + ifnull(d.vol_1,0)		as vol_12
        from :lc_dist d
			left outer join customer o 
			on d.customer_id = o.sponsor_id
        where d.level_id = :ln_x
        group by d.customer_id,d.vol_1;
        
        commit;
        
    end for;
   
   	Update period_batch
   	Set end_date_volume_org = current_timestamp
   	Where period_id = 0
   	and batch_id = 0;
   	
   	commit;
   	
End;
