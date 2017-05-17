create function commissions.FN_CUSTOMER_VOLUME(
	pn_customer_id integer
	, pn_period_id integer)
	returns table (PV decimal(18,8)
		, PV_LRP decimal(18,8)
		, PV_LRP_TEMPLATE decimal(18,8)
		, PV_RETAIL decimal(18,8)
		, PV_FS decimal(18,8)
		, CV decimal(18,8)
		, CV_LRP decimal(18,8)
		, CV_LRP_TEMPLATE decimal(18,8)
		, CV_RETAIL decimal(18,8)
		, CV_FS decimal(18,8)
		, EGV decimal(18,8)
		, EGV_LRP decimal(18,8)
		, OV decimal(18,8)
		, TV decimal(18,8)
		, TW_CV decimal(18,8))
	LANGUAGE SQLSCRIPT
	DEFAULT SCHEMA commissions
as
BEGIN
	declare ln_count integer;
	select count(*) into ln_count from customer_history where period_id = :pn_period_id;
	if (:ln_count = 0) then
		--get from main table
		return
		select vol_1 as pv
			, vol_2 as pv_lrp
			, vol_3 as pv_lrp_template
			, vol_4 as pv_retail
			, vol_5 as pv_fs
			, vol_6 as cv
			, vol_7 as cv_lrp
			, vol_8 as cv_lrp_template
			, vol_9 as cv_retail
			, vol_10 as cv_fs
			, vol_11 as egv
			, vol_12 as egv_lrp
			, vol_13 as ov
			, vol_14 as tv
			, vol_15 as tw_cv
		from customer
		where customer_id = :pn_customer_id;
	else
		return
		select vol_1 as pv
			, vol_2 as pv_lrp
			, vol_3 as pv_lrp_template
			, vol_4 as pv_retail
			, vol_5 as pv_fs
			, vol_6 as cv
			, vol_7 as cv_lrp
			, vol_8 as cv_lrp_template
			, vol_9 as cv_retail
			, vol_10 as cv_fs
			, vol_11 as egv
			, vol_12 as egv_lrp
			, vol_13 as ov
			, vol_14 as tv
			, vol_15 as tw_cv
		from customer_history
		where customer_id = :pn_customer_id
			and period_id = :pn_period_id
			and batch_id = (select max(batch_id) from period_batch where period_id = :pn_period_id and viewable = 1);
	end if;
END;