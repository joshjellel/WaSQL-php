CREATE PROCEDURE "COMMISSIONS"."ACCT_MGMT_TEST"
	(
	IN CUSTOMER_ID INTEGER,
	IN PERIOD_ID INTEGER
	)
	
LANGUAGE SQLSCRIPT
DEFAULT SCHEMA Commissions

AS

BEGIN

SELECT
	CH."CUSTOMER_ID" AS "Customer ID",
	CH."CUSTOMER_NAME" AS "Customer Name",
	CH."TYPE_ID" AS "Type ID",
	CH."STATUS_ID" AS "Status ID",
	CH."SPONSOR_ID" AS "Sponsor ID",
	CH."ENROLLER_ID" "Enroller ID",
	CH."COUNTRY" AS "Country",
	CH."COMM_STATUS_DATE",
	CH."ENTRY_DATE" AS "Entry Date",
	CH."TERMINATION_DATE" AS "Termination Date",
	CH."RANK_ID" AS "Rank ID",
	CH."RANK_HIGH_ID" AS "Title Rank",
	CH."RANK_HIGH_TYPE_ID" AS "Title Rank ID",
	CH."RANK_QUAL",
	CS."DESCRIPTION" AS "Status Description",
	CT."DESCRIPTION" AS "Type Description",
	CH2."CUSTOMER_NAME" AS "Enroller Name",
	CH3."CUSTOMER_NAME" AS "Sponsor Name",
	CR."DESCRIPTION" AS "Rank Description",
	CR2."DESCRIPTION" AS "Title Rank Description"
	FROM "COMMISSIONS"."CUSTOMER_HISTORY" AS CH
	LEFT JOIN "COMMISSIONS"."CUSTOMER_STATUS" AS CS ON CH."STATUS_ID" = CS."STATUS_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER_TYPE" AS CT ON CH."TYPE_ID" = CT."TYPE_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER" AS CH2 ON CH."ENROLLER_ID" = CH2."CUSTOMER_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER" AS CH3 ON CH."SPONSOR_ID" = CH3."CUSTOMER_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER_RANK_TYPE" AS CR ON CH."RANK_ID" = CR."RANK_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER_RANK_TYPE" AS CR2 ON CH."RANK_HIGH_ID" = CR2."RANK_ID"
	WHERE CH."CUSTOMER_ID" = :CUSTOMER_ID AND CH."PERIOD_ID" = :PERIOD_ID
	
UNION

SELECT
	C."CUSTOMER_ID",
	C."CUSTOMER_NAME",
	C."TYPE_ID",
	C."STATUS_ID",
	C."SPONSOR_ID",
	C."ENROLLER_ID",
	C."COUNTRY",
	C."COMM_STATUS_DATE",
	C."ENTRY_DATE",
	C."TERMINATION_DATE",
	C."RANK_ID",
	C."RANK_HIGH_ID",
	C."RANK_HIGH_TYPE_ID",
	C."RANK_QUAL",
	CS."DESCRIPTION" AS "STATUS_DESCRIPTION",
	CT."DESCRIPTION" AS "TYPE_DESCRIPTION",
	CH2."CUSTOMER_NAME" AS "ENROLLER_NAME",
	CH3."CUSTOMER_NAME" AS "SPONSOR_NAME",
	CR."DESCRIPTION" AS "RANK_DESCRIPTION",
	CR2."DESCRIPTION" AS "RANK_HIGH_DESCRIPTION"
	FROM "COMMISSIONS"."CUSTOMER" AS C
	LEFT JOIN "COMMISSIONS"."CUSTOMER_STATUS" AS CS ON C."STATUS_ID" = CS."STATUS_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER_TYPE" AS CT ON C."TYPE_ID" = CT."TYPE_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER_HISTORY" AS CH2 ON C."ENROLLER_ID" = CH2."CUSTOMER_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER_HISTORY" AS CH3 ON C."SPONSOR_ID" = CH3."CUSTOMER_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER_RANK_TYPE" AS CR ON C."RANK_ID" = CR."RANK_ID"
	LEFT JOIN "COMMISSIONS"."CUSTOMER_RANK_TYPE" AS CR2 ON C."RANK_HIGH_ID" = CR2."RANK_ID"
	WHERE C."CUSTOMER_ID" = :CUSTOMER_ID;
	
END;