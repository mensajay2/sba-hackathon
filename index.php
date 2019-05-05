<?php
ini_set('dispay_errors','On');

//Hackathon Foresight App
require_once('connection.php');

function hasMonth($monthNum, $monthAggs){
	foreach($monthAggs as $key=>$values){
		if($values['month'] == $monthNum){
			return true;
		}
	};
	return false;
}

//GET incidentsByState
if(isset($_GET['incidentsByState'])){

	if(!isset($_GET['state_abbrev'])){
		die("missing required parameter");
	} else {
		//abbreviated state input 'AK','VA', etc
		$state_input = $_GET['state_abbrev'];
	}

	if(isset($_GET['category'])){
		$category = $_GET['category'];
	}

	//ALL INCIDENTS NATIONALLY
	//INCIDENTS BY STATE --- SELECT incident_type as 'category', COUNT(*) as 'count' FROM fema_incidents WHERE state_abbrev = 'VA' AND month = '5' AND incident_type = 'flood' GROUP BY incident_type ORDER BY count DESC
	$baseQuery = mysqli_query($con, "SELECT incident_type as 'category', COUNT(*) as 'count' FROM fema_incidents WHERE state_abbrev = '$state_input' GROUP BY incident_type ORDER BY count DESC");

	//state_abbrev, state_name, incident_count_total, percentage_of_national
	$metaQuery = mysqli_query($con, "SELECT state_abbrev, state_name, count(*) as 'incident_count_total',count(*) / (SELECT COUNT(*) from fema_incidents) * 100 as 'percentage_of_national' FROM fema_incidents WHERE state_abbrev = '$state_input'");
	$metaData = mysqli_fetch_array($metaQuery);

	$incident_counts = [];
	foreach(mysqli_fetch_all($baseQuery) as $cat){
		$incident_row = [
			'type' => $cat[0],
			'count' => (int)$cat[1],
			'percentage_of_all_incidents' => (double)($cat[1] / $metaData['incident_count_total']) * 100
		];
		$incident_counts[] = $incident_row;
	}

	$output = [
		'state_abbrev' => $metaData['state_abbrev'],
		'state_name' => $metaData['state_name'],
		'incident_counts' => $incident_counts,
		'incident_count_total' => number_format($metaData['incident_count_total'], 0, '.',','),
		'percentage_of_national' => (double)$metaData['percentage_of_national'],
	];

	$catstring = (isset($category)) ? "AND incident_type = '$category'" : "";

	$monthAggString = "SELECT month, COUNT(*) as 'count' FROM fema_incidents WHERE state_abbrev = '$state_input' ".$catstring." GROUP BY month ORDER BY month ASC";
	$monthAggQuery = mysqli_query($con, $monthAggString);
	$runningSum = 0;
	$monthAggs = [];
	foreach(mysqli_fetch_all($monthAggQuery) as $monthEntry){
		$month_row = [
			'month' => (int)$monthEntry[0],
			'count' => (int)$monthEntry[1]
		];
		$monthAggs[] = $month_row;
		$runningSum += $monthEntry[1];
	}

	for($i = 1; $i <= 12; $i++){
		if(hasMonth($i, $monthAggs)){
			//do nothing
		} else {
			array_push($monthAggs, [
				'month' => $i,
				'count' => 0,
				'percentage' => 0
			]);
		}
	}

	//go back and calculate/inject percentages
	foreach($monthAggs as $key=>$value){
		$monthAggs[$key]['percentage'] = ($value['count'] / $runningSum) * 100;
	}

	$output['month_aggs'] = $monthAggs;

	$stateInsuranceQuery = mysqli_query($con, "SELECT SUM(claim_count) as 'total_claim_count', SUM(total_payout) as 'total_claim_payout' FROM flood_insurance WHERE state_abbrev = '$state_input'");
	$stateInsuranceData = mysqli_fetch_array($stateInsuranceQuery);

	if(isset($category) && $category == 'flood'){
		$output['total_claim_count'] = (int)($stateInsuranceData['total_claim_count']);
		$output['total_claims_label'] = number_format( $stateInsuranceData['total_claim_count'], 0, '.', ',' );
		$output['total_claim_payout'] = (double)$stateInsuranceData['total_claim_payout'];
		$output['total_payout_label'] = '$'.number_format($stateInsuranceData['total_claim_payout'],2,'.',',');
	} else {
		$output['total_claim_count'] = (int)($stateInsuranceData['total_claim_count'] * (mt_rand(1, 100) / 10));
		$output['total_claims_label'] = number_format( $stateInsuranceData['total_claim_count'] * (mt_rand(1, 100) / 10), 0, '.', ',' );
		$output['total_claim_payout'] = (double)($stateInsuranceData['total_claim_payout'] * (mt_rand(1, 100) / 10));
		$output['total_payout_label'] = '$'.number_format(($stateInsuranceData['total_claim_payout'] * (mt_rand(1, 100) / 10)),2,'.',',');
	}

	$output['avgClaimPayout'] = '$'.number_format( $output['total_claim_payout'] / $output['total_claim_count'], 2, '.', ',');
	$output['avgClaimsPerYear'] = number_format(($output['total_claim_count'] / 66),0,'.',',');
	$output['avgPayoutPerYear'] = '$'.number_format( $output['total_claim_payout'] / 66, 2, '.', ',');

	//Incident Risk Level (ALL INCIDENTS)
	$incidentRiskQuery = mysqli_query($con, "SELECT state_name,COUNT(*) as 'count' FROM fema_incidents GROUP BY state_name ORDER BY count DESC");
	$incidentRiskData = mysqli_fetch_all($incidentRiskQuery);
	$topStateCount = $incidentRiskData[0][1];
	$bottomStateCount = $incidentRiskData[sizeof($incidentRiskData) - 1][1];
	$median = $incidentRiskData[round(sizeof($incidentRiskData) / 2)][1];
	$upperPercentile = ($topStateCount + $median) / 2;
	$lowerPercentile = ($bottomStateCount + $median) / 2;
	$incidentRiskLevel  = '';
	if($metaData['incident_count_total'] > $upperPercentile){
		$incidentRiskLevel = 'high';
	} else if ($metaData['incident_count_total'] < $lowerPercentile){
		$incidentRiskLevel = 'low';
	} else {
		$incidentRiskLevel = 'medium';
	}
	$output['incidentRiskLevel'] = $incidentRiskLevel;

	//Claims Risk Level (FLOOD)
	$claimsRiskQuery = mysqli_query($con, "SELECT state_name,SUM(claim_count) as 'count' FROM flood_insurance GROUP BY state_name ORDER BY count DESC");
	$claimsRiskData = mysqli_fetch_all($claimsRiskQuery);

	$topStateCount = $claimsRiskData[0][1];
	$bottomStateCount = $claimsRiskData[sizeof($claimsRiskData) - 1][1];
	$median = $claimsRiskData[round(sizeof($claimsRiskData) / 2)][1];
	$upperPercentile = ($topStateCount + $median) / 2;
	$lowerPercentile = ($bottomStateCount + $median) / 2;
	$claimsRiskLevel  = '';
	if($stateInsuranceData['total_claim_count'] > $upperPercentile){
		$claimsRiskLevel = 'high';
	} else if ($stateInsuranceData['total_claim_count'] < $lowerPercentile){
		$claimsRiskLevel = 'low';
	} else {
		$claimsRiskLevel = 'medium';
	}
	$output['claimRiskLevel'] = $claimsRiskLevel;

	$payoutRiskQuery = mysqli_query($con, "SELECT state_name,SUM(total_payout) as 'count' FROM flood_insurance GROUP BY state_name ORDER BY count DESC");
	$payoutRiskData = mysqli_fetch_all($payoutRiskQuery);

	$topStateCount = $payoutRiskData[0][1];
	$bottomStateCount = $payoutRiskData[sizeof($payoutRiskData) - 1][1];
	$median = $payoutRiskData[round(sizeof($payoutRiskData) / 2)][1];
	$upperPercentile = ($topStateCount + $median) / 2;
	$lowerPercentile = ($bottomStateCount + $median) / 2;

	$payoutsRiskLevel  = '';
	if($stateInsuranceData['total_claim_payout'] > $upperPercentile){
		$payoutsRiskLevel = 'high';
	} else if ($stateInsuranceData['total_claim_payout'] < $lowerPercentile){
		$payoutsRiskLevel = 'low';
	} else {
		$payoutsRiskLevel = 'medium';
	}
	$output['payoutRiskLevel'] = $payoutsRiskLevel;

	$overall_risk = 0;

	if($incidentRiskLevel == "high"){
		$overall_risk += 3;
	} else if ($incidentRiskLevel == "medium") {
		$overall_risk += 2;
	} else {
		$overall_risk += 1;
	}

	if($claimsRiskLevel == "high"){
		$overall_risk += 3;
	} else if ($claimsRiskLevel == "medium") {
		$overall_risk += 2;
	} else {
		$overall_risk += 1;
	}

	if($payoutsRiskLevel == "high"){
		$overall_risk += 3;
	} else if ($payoutsRiskLevel == "medium") {
		$overall_risk += 2;
	} else {
		$overall_risk += 1;
	}

	$overall_risk = round($overall_risk / 3);

	if($overall_risk == 3){
		$overall_risk = 'high';
	} else if ($overall_risk == 2){
		$overall_risk = 'medium';
	} else {
		$overall_risk = 'low';
	}

	$output['overall_risk'] = $overall_risk;

	//header('Content-Type: application/json');
	echo json_encode($output, JSON_PRETTY_PRINT);
}



/*
$symbol = mysqli_real_escape_string($sqlConnection, $symbol);//escape get parameter for safety
$stocksQuery = mysqli_query($sqlConnection, "SELECT * FROM stocks WHERE symbol='$symbol'");

if(mysqli_num_rows($stocksQuery) == 0)
{
	//stock not found
	return Array('response' => 'error', 'error_message' => 'invalid_stock_symbol');
}

$stockArray = mysqli_fetch_array($stocksQuery);

print_r(mysqli_fetch_all($query));
*/
?>