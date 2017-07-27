select 
	 dist_id
	,null				as customer_name
	,sponsor_dist_id	as sponsor_id
	,enroller_dist_id	as enroller_id
	,status
	,country_code		as country
	,paid_rank			as rank_id
	,end_rank			as high_rank_id
	,to_char(entry_date,'dd-Mon-yyyy') as entry_date
	,null				as comm_status_date
	,null				as terminated_date
	,vol1				as pv
	,vol4				as cv
	,vol3				as ov
	,case when upper(qflg2) = 'X' then 1 else 0 end		as qflg2
	,case when upper(lf33) = 'X' then 1 else 0 end		as lf33
	,bnc1
	,bnc2
	,bnc3
	,bnc4
	,bnc5
	,bnc6
	,bnc7
	,bnc8
	,bnc9
	,bnc10
	,bnc11
	,bnc12
	,bnc14
from admin.bwt201705
where dist_bus_ctr = 1
and dist_id < 2000000000
