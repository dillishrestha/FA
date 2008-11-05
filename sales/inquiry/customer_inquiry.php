<?php

$page_security = 1;
$path_to_root="../..";
include($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/session.inc");

include_once($path_to_root . "/sales/includes/sales_ui.inc");
include_once($path_to_root . "/sales/includes/sales_db.inc");
include_once($path_to_root . "/reporting/includes/reporting.inc");

$js = "";
if ($use_popup_windows)
	$js .= get_js_open_window(900, 500);
if ($use_date_picker)
	$js .= get_js_date_picker();
page(_("Customer Transactions"), false, false, "", $js);


if (isset($_GET['customer_id']))
{
	$_POST['customer_id'] = $_GET['customer_id'];
}

//------------------------------------------------------------------------------------------------

start_form();

if (!isset($_POST['customer_id']))
	$_POST['customer_id'] = get_global_customer();

start_table("class='tablestyle_noborder'");
start_row();

customer_list_cells(_("Select a customer: "), 'customer_id', null, true);

date_cells(_("From:"), 'TransAfterDate', '', null, -30);
date_cells(_("To:"), 'TransToDate', '', null, 1);

if (!isset($_POST['filterType']))
	$_POST['filterType'] = 0;

cust_allocations_list_cells(null, 'filterType', $_POST['filterType'], true);

submit_cells('RefreshInquiry', _("Search"),'',_('Refresh Inquiry'), true);
end_row();
end_table();

end_form();

set_global_customer($_POST['customer_id']);

//------------------------------------------------------------------------------------------------

function display_customer_summary($customer_record)
{
	global $table_style;

	$past1 = get_company_pref('past_due_days');
	$past2 = 2 * $past1;
    if ($customer_record["dissallow_invoices"] != 0)
    {
    	echo "<center><font color=red size=4><b>" . _("CUSTOMER ACCOUNT IS ON HOLD") . "</font></b></center>";
    }

	$nowdue = "1-" . $past1 . " " . _('Days');
	$pastdue1 = $past1 + 1 . "-" . $past2 . " " . _('Days');
	$pastdue2 = _('Over') . " " . $past2 . " " . _('Days');

    start_table("width=80% $table_style");
    $th = array(_("Currency"), _("Terms"), _("Current"), $nowdue,
    	$pastdue1, $pastdue2, _("Total Balance"));
    table_header($th);

	start_row();
    label_cell($customer_record["curr_code"]);
    label_cell($customer_record["terms"]);
	amount_cell($customer_record["Balance"] - $customer_record["Due"]);
	amount_cell($customer_record["Due"] - $customer_record["Overdue1"]);
	amount_cell($customer_record["Overdue1"] - $customer_record["Overdue2"]);
	amount_cell($customer_record["Overdue2"]);
	amount_cell($customer_record["Balance"]);
	end_row();

	end_table();;
}
//------------------------------------------------------------------------------------------------

div_start('totals_tbl');
if ($_POST['customer_id'] != "" && $_POST['customer_id'] != reserved_words::get_all())
{
	$customer_record = get_customer_details($_POST['customer_id'], $_POST['TransToDate']);
    display_customer_summary($customer_record);
    echo "<br>";
}
div_end();


//------------------------------------------------------------------------------------------------
function systype_name($dummy, $type)
{
	return systypes::name($type);
}

function order_view($row)
{
	return $row['order_']>0 ?
		get_customer_trans_view_str(systypes::sales_order(), $row['order_'])
		: "";
}

function trans_view($trans)
{
	return get_trans_view_str($trans["type"], $trans["trans_no"]);
}

function due_date($row)
{
	return $row["type"] == 10 ? sql2date($row["due_date"]) : '';
}

function fmt_balance($row)
{
	return price_format($row["TotalAmount"] - $row["Allocated"]);
}

function gl_view($row)
{
	return get_gl_view_str($row["type"], $row["trans_no"]);
}

function fmt_debit($row)
{
	$value =
	    $row['type']==11 || $row['type']==12 || $row['type']==2 ?
		-$row["TotalAmount"] : $row["TotalAmount"];
	return $value>=0 ? price_format($value) : '';

}

function fmt_credit($row)
{
	$value =
	    !($row['type']==11 || $row['type']==12 || $row['type']==2) ?
		-$row["TotalAmount"] : $row["TotalAmount"];
	return $value>0 ? price_format($value) : '';
}

function alloc_link($row)
{
	if ($row['type'] == 10)
		if ($row["TotalAmount"] - $row["Allocated"] > 0)
			return pager_link(_("Allocation"),
				"/sales/allocations/customer_allocate.php"
				."?trans_no={$row['trans_no']}&trans_type="
				.$row['type']);
	return '';
}

function credit_link($row)
{
	return $row['type'] == 10 ?
		pager_link(_("Credit This"),
			"/sales/customer_credit_invoice.php?InvoiceNumber=".
			$row['trans_no'])
			: '';
}

function edit_link($row)
{
	$str = '';

	switch($row['type']) {
	case 10:
		$str = "/sales/customer_invoice.php?ModifyInvoice=".$row['trans_no'];
		break;
	case 11:
		if ($row['order_']==0) // free-hand credit note
		    $str = "/sales/credit_note_entry.php?ModifyCredit=".$row['trans_no'];
		else	// credit invoice
		    $str = "/sales/customer_credit_invoice.php?ModifyCredit=".$row['trans_no'];
		break;
	 case 13:
   		$str = "/sales/customer_delivery.php?ModifyDelivery=".$row['trans_no'];
		break;
	}
	return pager_link(_('Edit'), $str);
}

function prt_link($row)
{
  	if ($row['type'] != 12) // customer payment printout not defined yet.
 		return print_document_link($row['trans_no'], _("Print"), true, $row['type']);
}

function check_overdue($row)
{
	return $row['OverDue'] == 1
		&& (abs($row["TotalAmount"]) - $row["Allocated"] != 0);
}
//------------------------------------------------------------------------------------------------
    $date_after = date2sql($_POST['TransAfterDate']);
    $date_to = date2sql($_POST['TransToDate']);

  $sql = "SELECT 
  		trans.type, 
		trans.trans_no, 
		trans.order_, 
		trans.reference,
		trans.tran_date, 
		trans.due_date, 
		debtor.name, 
		branch.br_name,
		debtor.curr_code,
		(trans.ov_amount + trans.ov_gst + trans.ov_freight 
			+ trans.ov_freight_tax + trans.ov_discount)	AS TotalAmount, 
		trans.alloc AS Allocated,
		((trans.type = 10)
			AND trans.due_date < '" . date2sql(Today()) . "') AS OverDue
		FROM "
			.TB_PREF."debtor_trans as trans, "
			.TB_PREF."debtors_master as debtor, "
			.TB_PREF."cust_branch as branch
		WHERE debtor.debtor_no = trans.debtor_no
			AND trans.tran_date >= '$date_after'
			AND trans.tran_date <= '$date_to'";

   	if ($_POST['customer_id'] != reserved_words::get_all())
   		$sql .= " AND trans.debtor_no = '" . $_POST['customer_id'] . "'";

   	if ($_POST['filterType'] != reserved_words::get_all())
   	{
   		if ($_POST['filterType'] == '1')
   		{
   			$sql .= " AND (trans.type = 10 OR trans.type = 1) ";
   		}
   		elseif ($_POST['filterType'] == '2')
   		{
   			$sql .= " AND (trans.type = 10) ";
   		}
   		elseif ($_POST['filterType'] == '3')
   		{
			$sql .= " AND (trans.type = " . systypes::cust_payment() 
					." OR trans.type = 2) ";
   		}
   		elseif ($_POST['filterType'] == '4')
   		{
			$sql .= " AND trans.type = 11 ";
   		}
   		elseif ($_POST['filterType'] == '5')
   		{
			$sql .= " AND trans.type = 13 ";
   		}

    	if ($_POST['filterType'] == '2')
    	{
    		$today =  date2sql(Today());
    		$sql .= " AND trans.due_date < '$today'
				AND (trans.ov_amount + trans.ov_gst + trans.ov_freight_tax + 
				trans.ov_freight + trans.ov_discount - trans.alloc > 0) ";
    	}
   	}

//------------------------------------------------------------------------------------------------

$cols = array(
	_("Type") => array('type'=>'spec', 'fun'=>'systype_name', 'ord'=>''),
	_("#") => array('type'=>'spec', 'fun'=>'trans_view', 'ord'=>''),
	_("Order") => array('type'=>'spec', 'fun'=>'order_view'), 
	_("Reference"), 
	_("Date") => array('type'=>'date', 'ord'=>'desc'),
	_("Due Date") => array('type'=>'spec', 'fun'=>'due_date'),
	_("Customer"), 
	_("Branch"), 
	_("Currency"),
	_("Debit") => array('type'=>'spec', 'fun'=>'fmt_debit'), 
	_("Credit") => array('type'=>'insert', 'fun'=>'fmt_credit'), 
		array('type'=>'insert', 'fun'=>'alloc_link'),
		array('type'=>'insert', 'fun'=>'credit_link'),
		array('type'=>'insert', 'fun'=>'edit_link'),
		array('type'=>'insert', 'fun'=>'prt_link')
	);

if ($_POST['customer_id'] != reserved_words::get_all()) {
	array_remove($cols, 6);
	array_remove($cols, 8);
}


$table =& new_db_pager('trans_tbl', $sql, $cols);
$table->set_marker('check_overdue', _("Marked items are overdue."));


if(get_post('RefreshInquiry'))
{
	$table->set_sql($sql);
	$Ajax->activate('trans_tbl');
	$Ajax->activate('totals_tbl');
}

	start_form();
	display_db_pager($table);
	end_form();
end_page();


?>
