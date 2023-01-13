<?php
// Modified based on Numinix v1.9.0 https://www.numinix.com/zen-cart-plugins-modules-shipping-c-179_250_373_163/fedex-web-services-shipping
// Mode log 1)Resolve the warning error caused by php 8.0 or higher 
// 2) (Temp solution) Resolve the bug which will not display FedEx intl priority by using RateService_v20.wsdl instead of RateService_v31.wsdl
// 3) Resolve occasional warning, Invalid argument supplied for foreach()
//
class fedexwebservices {
 var $code, $title, $description, $icon, $sort_order, $enabled, $tax_class, $fedex_key, $fedex_pwd, $fedex_act_num, $fedex_meter_num, $country, $total_weight;

//Class Constructor
  function __construct() {
    global $order, $customer_id, $db;
    
    @define('MODULE_SHIPPING_FEDEX_WEB_SERVICES_KEY', '6W02EnQC0n9nO5NH');
    @define('MODULE_SHIPPING_FEDEX_WEB_SERVICES_PWD', 'tmZlwVIuLUHGNtasKOHQYkKKd');
    @define('MODULE_SHIPPING_FEDEX_WEB_SERVICES_INSURE', 0);
    $this->code             = "fedexwebservices";
    $this->title            = MODULE_SHIPPING_FEDEX_WEB_SERVICES_TEXT_TITLE;
    if (extension_loaded('soap')) {
    $this->description      = MODULE_SHIPPING_FEDEX_WEB_SERVICES_TEXT_DESCRIPTION;
    } else {
     $this->description      = MODULE_SHIPPING_FEDEX_WEB_SERVICES_TEXT_DESCRIPTION_SOAP;
     }
    $this->sort_order       = MODULE_SHIPPING_FEDEX_WEB_SERVICES_SORT_ORDER;
    //$this->icon = DIR_WS_IMAGES . 'fedex-images/fedex.gif';
    $this->icon = '';

    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING == 'true' || zen_get_shipping_enabled($this->code)) {
    	if (extension_loaded('soap')) {
      $this->enabled = ((MODULE_SHIPPING_FEDEX_WEB_SERVICES_STATUS == 'true') ? true : false);
      }
    }

    $this->tax_class        = MODULE_SHIPPING_FEDEX_WEB_SERVICES_TAX_CLASS;
    $this->fedex_key        = MODULE_SHIPPING_FEDEX_WEB_SERVICES_KEY;
    $this->fedex_pwd        = MODULE_SHIPPING_FEDEX_WEB_SERVICES_PWD;
    $this->fedex_act_num    = MODULE_SHIPPING_FEDEX_WEB_SERVICES_ACT_NUM;
    $this->fedex_meter_num  = MODULE_SHIPPING_FEDEX_WEB_SERVICES_METER_NUM;
    $this->total_weight = 0;
    if (defined("SHIPPING_ORIGIN_COUNTRY")) {
      if ((int)SHIPPING_ORIGIN_COUNTRY > 0) {
        $countries_array = zen_get_countries((int)SHIPPING_ORIGIN_COUNTRY, true);
        $this->country = $countries_array['countries_iso_code_2'];
        if(!strlen($this->country) > 0) { //when country failed to be retrieved, likely because running from admin.
          $this->country = $this->country_iso('', (int)SHIPPING_ORIGIN_COUNTRY);
        }
      } else {
        $this->country = SHIPPING_ORIGIN_COUNTRY;
      }
    } else {
      $this->country = STORE_ORIGIN_COUNTRY;
    }
    if ( ($this->enabled == true) && ((int)MODULE_SHIPPING_FEDEX_WEB_SERVICES_ZONE > 0) ) {
      $check_flag = false;
      $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_SHIPPING_FEDEX_WEB_SERVICES_ZONE . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
      while (!$check->EOF) {
        if ($check->fields['zone_id'] < 1) {
          $check_flag = true;
          break;
        } elseif ($check->fields['zone_id'] == $order->delivery['zone_id']) {
          $check_flag = true;
          break;
        }
        $check->MoveNext();
      }

      if ($check_flag == false) {
        $this->enabled = false;
      }
    }
    // BEGIN SHIPPING BOXES MANAGER EDIT
    $this->box_destination = '';
    if(isset($order->delivery['country']['id'])){
        $countryId = $order->delivery['country']['id'];
    }else{
        $countryId = $db->Execute("SELECT countries_id FROM ".TABLE_COUNTRIES." WHERE countries_name = '".$order->delivery['country']."'");
    }

    if ($countryId == STORE_COUNTRY) {
        $this->box_destination == 'domestic';
    } else {
        $this->box_destination == 'international';
    }
    // END
  }

  //Class Methods
   function build_request_common_elements() {
     $request = array();
     $request['WebAuthenticationDetail'] = array('UserCredential' =>
                                          array('Key' => $this->fedex_key, 'Password' => $this->fedex_pwd));
     $request['ClientDetail'] = array('AccountNumber' => $this->fedex_act_num, 'MeterNumber' => $this->fedex_meter_num);

     return $request;
   }


   function build_tracking_request($tracking_number) {
     $request = $this->build_request_common_elements();
     $request['TransactionDetail'] = array('CustomerTransactionId' => 'Track By Number_v10');
     $request['SelectionDetails'] = array('PackageIdentifier' => array('Type'=>'TRACKING_NUMBER_OR_DOORTAG', 'Value'=>$tracking_number));
     $request['Version'] = array('ServiceId' => 'trck', 'Major' => '10', 'Intermediate' => '0', 'Minor' => '0');
     return $request;
   }

  function build_request($client, $allow_0_weight_shipping = true) {
    /* FedEx integration starts */
    global $db, $shipping_weight, $shipping_num_boxes, $cart, $order, $all_products_ship_free;

    // shipping boxes manager
    if(!defined('MODULE_SHIPPING_BOXES_MANAGER_STATUS')){
        define('MODULE_SHIPPING_BOXES_MANAGER_STATUS', 'false');
    }
    if (MODULE_SHIPPING_BOXES_MANAGER_STATUS == 'true') {
      global $packed_boxes;
    }

    $this->types = array();
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_PRIORITY == 'true') {
      $this->types['INTERNATIONAL_PRIORITY'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_EXPRESS_HANDLING_FEE);
      $this->types['EUROPE_FIRST_INTERNATIONAL_PRIORITY'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_EXPRESS_HANDLING_FEE);
    }
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_ECONOMY == 'true') {
      $this->types['INTERNATIONAL_ECONOMY'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_EXPRESS_HANDLING_FEE);
    }
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_STANDARD_OVERNIGHT == 'true') {
      $this->types['STANDARD_OVERNIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
    }
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_FIRST_OVERNIGHT == 'true') {
      $this->types['FIRST_OVERNIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
    }
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_PRIORITY_OVERNIGHT == 'true') {
      $this->types['PRIORITY_OVERNIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
    }
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_2DAY == 'true') {
      $this->types['FEDEX_2_DAY'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
    }
    // because FEDEX_GROUND also is returned for Canadian Addresses, we need to check if the country matches the store country and whether international ground is enabled
    if ((MODULE_SHIPPING_FEDEX_WEB_SERVICES_GROUND == 'true' && $order->delivery['country']['id'] == STORE_COUNTRY) || (MODULE_SHIPPING_FEDEX_WEB_SERVICES_GROUND == 'true' && ($order->delivery['country']['id'] != STORE_COUNTRY) && MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_GROUND == 'true')) {
      $this->types['FEDEX_GROUND'] = array('icon' => '', 'handling_fee' => ($order->delivery['country']['id'] == STORE_COUNTRY ? MODULE_SHIPPING_FEDEX_WEB_SERVICES_HANDLING_FEE : MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_HANDLING_FEE));
      $this->types['GROUND_HOME_DELIVERY'] = array('icon' => '', 'handling_fee' => ($order->delivery['country']['id'] == STORE_COUNTRY ? MODULE_SHIPPING_FEDEX_WEB_SERVICES_HOME_DELIVERY_HANDLING_FEE : MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_HANDLING_FEE));
    }
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_GROUND == 'true') {
      $this->types['INTERNATIONAL_GROUND'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_HANDLING_FEE);
    }
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_SAVER == 'true') {
      $this->types['FEDEX_EXPRESS_SAVER'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
    }
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREIGHT == 'true') {
      $this->types['FEDEX_FREIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
      $this->types['FEDEX_NATIONAL_FREIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
      $this->types['FEDEX_1_DAY_FREIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
      $this->types['FEDEX_2_DAY_FREIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
      $this->types['FEDEX_3_DAY_FREIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE);
      $this->types['INTERNATIONAL_ECONOMY_FREIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_EXPRESS_HANDLING_FEE);
      $this->types['INTERNATIONAL_PRIORITY_FREIGHT'] = array('icon' => '', 'handling_fee' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_EXPRESS_HANDLING_FEE);
    }

    // customer details
    $street_address = $order->delivery['street_address'] ?? null;
    $street_address2 = $order->delivery['suburb'] ?? null;
    $city = $order->delivery['city'] ?? null;
    if(isset($order->delivery['country']['id'])){
        $state = zen_get_zone_code($order->delivery['country']['id'], $order->delivery['zone_id'], '');
    }else{
        $countryId = $db->Execute("SELECT countries_id FROM ".TABLE_COUNTRIES." WHERE countries_name = '".$order->delivery['country']."'");
        $state = zen_get_zone_code($countryId->fields['countries_id'], $order->delivery['zone_id'], '');
    }
    if ($state == "QC") $state = "PQ";
    $postcode = str_replace(array(' ', '-'), '', $order->delivery['postcode']);
    if(isset($order->delivery['country']['iso_code_2'])) {
        $country_id = $order->delivery['country']['iso_code_2'];
    }
    else {
        $country_id = $this->country_iso($order->delivery['country']);
    }

    $totals = $_SESSION['cart']->show_total();
    $this->_setInsuranceValue($totals);
    $request = $this->build_request_common_elements();

   /*    $request['TransactionDetail'] = array('CustomerTransactionId' => ' *** Rate Request using PHP ***');
    $request['Version'] = array('ServiceId' => 'crs', 'Major' => '31', 'Intermediate' => '0', 'Minor' => '0');*/
    $request['TransactionDetail'] = array('CustomerTransactionId' => ' *** Rate Request v20 using PHP ***');
    $request['Version'] = array('ServiceId' => 'crs', 'Major' => '20', 'Intermediate' => '0', 'Minor' => '0');
    $request['ReturnTransitAndCommit'] = true;
    $request['RequestedShipment']['DropoffType'] = $this->_setDropOff(); // valid values REGULAR_PICKUP, REQUEST_COURIER, ...
    $request['RequestedShipment']['ShipTimestamp'] = date('c');
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_CUTOFF_PICKUP_TIME == 'true' && is_numeric(MODULE_SHIPPING_FEDEX_WEB_SERVICES_CUTOFF_PICKUP_TIME_VALUE)) {
      //add 1 to ship on next day if order after cutoff time
      $now = mktime(date("G"), 0, 0, date("m"), date("d"), date("Y"));
      $cutoff_time = mktime(intval(MODULE_SHIPPING_FEDEX_WEB_SERVICES_CUTOFF_PICKUP_TIME_VALUE), 0, 0, date("m"), date("d"), date("Y"));
      if ($now >= $cutoff_time) {
        $ship  =  date('c', mktime(0, 0, 0, date("m"), date("d")+1, date("Y")));
        $request['RequestedShipment']['ShipTimestamp'] = $ship;
      }
    }
    // if we do not allow weekend pickups, then we need to run the following code
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_SATURDAY_PICKUP != 'true') {
        $now = getdate();
    	$day = $now['weekday'];
    	if ($day == "Saturday"){
    		//add 2 to ship on monday
    		$ship  =  date('c', mktime(0, 0, 0, date("m")  , date("d")+2, date("Y")));
    		$request['RequestedShipment']['ShipTimestamp'] = $ship;
    	}
    	if ($day == "Sunday"){
    		//add 1 to ship on monday
    		$ship  =  date('c', mktime(0, 0, 0, date("m")  , date("d")+1, date("Y")));
    		$request['RequestedShipment']['ShipTimestamp'] = $ship;
    	}
    }
    //if (zen_not_null($method) && in_array($method, $this->types)) {
      //$request['RequestedShipment']['ServiceType'] = $method; // valid values STANDARD_OVERNIGHT, PRIORITY_OVERNIGHT, FEDEX_GROUND, ...
    //}
    $request['RequestedShipment']['PackagingType'] = MODULE_SHIPPING_FEDEX_WEB_SERVICES_PACKAGE_TYPE; // valid values FEDEX_BOX, FEDEX_PAK, FEDEX_TUBE, YOUR_PACKAGING, ...
    $request['RequestedShipment']['TotalInsuredValue']=array('Amount'=> $this->insurance, 'Currency' => $_SESSION['currency']);
    $request['WebAuthenticationDetail'] = array('UserCredential' => array('Key' => $this->fedex_key, 'Password' => $this->fedex_pwd));
    $request['ClientDetail'] = array('AccountNumber' => $this->fedex_act_num, 'MeterNumber' => $this->fedex_meter_num);
    //print_r($request['WebAuthenticationDetail']);
    //print_r($request['ClientDetail']);
    //exit;
    // Address Validation
    $residential_address = true;
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_ADDRESS_VALIDATION == 'true') {
      $path_to_address_validation_wsdl =  DIR_WS_MODULES . 'shipping/fedexwebservices/wsdl/AddressValidationService_v4.wsdl';
      $av_client = new SoapClient($path_to_address_validation_wsdl, array('trace' => 1)); // Refer to http://us3.php.net/manual/en/ref.soap.php for more information
      $av_request['WebAuthenticationDetail'] = array('UserCredential' =>
                                            array('Key' => $this->fedex_key, 'Password' => $this->fedex_pwd));
      $av_request['ClientDetail'] = array('AccountNumber' => $this->fedex_act_num, 'MeterNumber' => $this->fedex_meter_num);
      $av_request['TransactionDetail'] = array('CustomerTransactionId' => ' *** Address Validation Request v4 using PHP ***');
      $av_request['Version'] = array('ServiceId' => 'aval', 'Major' => '4', 'Intermediate' => '0', 'Minor' => '0');
      $av_request['RequestTimestamp'] = date('c');
      $av_request['Options'] = array('CheckResidentialStatus' => 1,
      															 'VerifyAddress' => 1,
                                     'MaximumNumberOfMatches' => 10,
                                     'StreetAccuracy' => 'MEDIUM',
                                     'DirectionalAccuracy' => 'MEDIUM',
                                     'CompanyNameAccuracy' => 'MEDIUM',
                                     'ConvertToUpperCase' => 1,
                                     'RecognizeAlternateCityNames' => 1,
                                     'ReturnParsedElements' => 1);
      $av_request['AddressesToValidate'] = array(
        0 => array(
          'AddressId' => 'Customer Address',
          'Address' => array(
            'StreetLines' => array(utf8_encode($street_address), utf8_encode($street_address2)),
            'PostalCode' => $postcode,
            'City' => $city,
            'StateOrProvinceCode' => $state,
            'CompanyName' => ($order->delivery['company'] ?? null),
            'CountryCode' => $country_id
          )
        )
      );
      try {
        $av_response = $av_client->addressValidation($av_request);
        /*
        //echo '<!--';
        echo '<pre>';
        print_r($av_response);
        echo '</pre>';
        //echo '-->';
        die();
        */
        if ($av_response->HighestSeverity == 'SUCCESS') {
          if (($av_response->AddressResults->ProposedAddressDetails->ResidentialStatus ?? null) == 'BUSINESS' || $av_response->AddressResults->Classification == 'BUSINESS') {
            $residential_address = false;
          } // already set to true so no need for else statement
        }
      } catch (Exception $e) {
      }
    }

    $_SESSION['shipping_address_is_residential'] = $residential_address; //AKS mod: store this in a SESSION for use with OnTrac

    $request['RequestedShipment']['Shipper'] = array('Address' => array(
                                                     'StreetLines' => array(MODULE_SHIPPING_FEDEX_WEB_SERVICES_ADDRESS_1, MODULE_SHIPPING_FEDEX_WEB_SERVICES_ADDRESS_2), // Origin details
                                                     'City' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_CITY,
                                                     'StateOrProvinceCode' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_STATE,
                                                     'PostalCode' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_POSTAL,
                                                     'CountryCode' => $this->country));
    $request['RequestedShipment']['Recipient'] = array('Address' => array (
                                                       'StreetLines' => array(utf8_encode($street_address), utf8_encode($street_address2)), // customer street address
                                                       'City' => utf8_encode($city), //customer city
                                                       //'StateOrProvinceCode' => $state, //customer state
                                                       'PostalCode' => $postcode, //customer postcode
                                                       'CountryCode' => $country_id,
                                                       'Residential' => $residential_address)); //customer county code
    if (in_array($country_id, array('US', 'CA'))) {
      $request['RequestedShipment']['Recipient']['StateOrProvinceCode'] = $state;
    }
    //print_r($request['RequestedShipment']['Recipient'])  ;
    //exit;
    $request['RequestedShipment']['ShippingChargesPayment'] = array('PaymentType' => 'SENDER',
                                                                    'Payor' => array('AccountNumber' => $this->fedex_act_num, // Replace 'XXX' with payor's account number
                                                                    'CountryCode' => $this->country));
    $request['RequestedShipment']['RateRequestTypes'] = 'LIST';
    $request['RequestedShipment']['PackageDetail'] = 'INDIVIDUAL_PACKAGES';
    $request['RequestedShipment']['RequestedPackageLineItems'] = array();

    $dimensions_failed = false;

    //Update weight to 0 for products with free shipping, if this feature is enabled.
    $free_weight = 0; //for use later to alter packed boxes
    $all_products_ship_free = false; //for use later to set shipping cost to 0, when this is enabled
    if($allow_0_weight_shipping && (MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD == 'all methods' || MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD == 'Ground/Home only')) {
      $products = $_SESSION['cart']->get_products();
      $all_products_ship_free = true; //default
      foreach ($products as $product) {
        $dimensions_query = "SELECT product_is_always_free_shipping FROM " . TABLE_PRODUCTS . "
                               WHERE products_id = " . (int)$product['id'] . "
                               LIMIT 1;";
//print " + " . $product['weight'] . " id " . $product['id'];
         $dimensions = $db->Execute($dimensions_query);
         if($dimensions->fields['product_is_always_free_shipping'] == 1) {
             $free_weight += $product['weight'] * $product['quantity'];//give this product 0 weight so it ships free.
         }
         else {
           $all_products_ship_free = false;
         }
      }
    }

    $this->fedex_shipping_num_boxes = ($shipping_num_boxes > 0 ? $shipping_num_boxes : 1);
    $this->fedex_shipping_weight = $shipping_weight - $free_weight;
    if (MODULE_SHIPPING_BOXES_MANAGER_STATUS == 'true') {
      $shipping_num_boxes = sizeof($packed_boxes);
    }

    // shipping boxes manager
    if (MODULE_SHIPPING_BOXES_MANAGER_STATUS == 'true' && is_array($packed_boxes) && sizeof($packed_boxes) > 0) {
      $this->fedex_shipping_num_boxes = sizeof($packed_boxes);
      $this->fedex_shipping_weight = round(($this->fedex_shipping_weight / $shipping_num_boxes), 2); // use our number of packages rather than Zen Cart's calculation, package weight will still have to be an average since we don't know which products are in the box.

      //$shipping_weight = round(($this->total_weight / $shipping_num_boxes), 2); // use our number of packages rather than Zen Cart's calculation, package weight will still have to be an average since we don't know which products are in the box.
      $boxed_value = sprintf("%01.2f", $this->insurance / $this->fedex_shipping_num_boxes);
      $packages = array();
      foreach ($packed_boxes as $packed_box) {
        $packed_box['weight'] = $packed_box['weight'] - ($free_weight / count($packed_boxes));
        if ($packed_box['weight'] <= 0) $packed_box['weight'] = 0.1;

        $package = array(
          'Weight' => array(
            'Value' => $packed_box['weight'], // this is an averaged value
            'Units' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_WEIGHT
          ),
          'InsuredValue' => array(
            'Currency' => $_SESSION['currency'],
            'Amount' => $boxed_value
          ),
          'GroupPackageCount' => 1
        );
        if (isset($packed_box['length']) && isset($packed_box['width']) && isset($packed_box['height'])) {
          $package['Dimensions'] = array(
            'Length' => ($packed_box['length'] >= 1 ? $packed_box['length'] : 1),
            'Width' => ($packed_box['width'] >= 1 ? $packed_box['width'] : 1),
            'Height' => ($packed_box['height'] >= 1 ? $packed_box['height'] : 1),
            'Units' => (MODULE_SHIPPING_FEDEX_WEB_SERVICES_WEIGHT == 'LB' ? 'IN' : 'CM')
          );
        }
        $packages[] = $package;
      }

      $request['RequestedShipment']['RequestedPackageLineItems'] = $packages;
    } else {
      // check for ready to ship field
      if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_READY_TO_SHIP == 'true') {
        $products = $_SESSION['cart']->get_products();
        $packages = array('default' => 0);
        $new_shipping_num_boxes = 0;
        foreach ($products as $product) {
          $dimensions_query = "SELECT products_length, products_width, products_height, products_ready_to_ship, products_dim_type, product_is_always_free_shipping FROM " . TABLE_PRODUCTS . "
                               WHERE products_id = " . (int)$product['id'] . "
                               AND products_length > 0
                               AND products_width > 0
                               AND products_height > 0
                               LIMIT 1;";

          $dimensions = $db->Execute($dimensions_query);

         if(MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD == 'all methods' && $dimensions->fields['product_is_always_free_shipping'] == 1) {
           $products_weight = 0;
         }
         else{
           $products_weight = $product['weight'];
         }

          if ($dimensions->RecordCount() > 0 && $dimensions->fields['products_ready_to_ship'] == 1) {
            for ($i = 1; $i <= $product['quantity']; $i++) {
              $packages[] = array('weight' => $products_weight, 'length' => $dimensions->fields['products_length'], 'width' => $dimensions->fields['products_width'], 'height' => $dimensions->fields['products_height'], 'units' => strtoupper($dimensions->fields['products_dim_type']));
            }
          } else {
            $packages['default'] += $products_weight * $product['quantity'];
          }
        }
        if (count($packages) > 1) {
          $za_tare_array = preg_split("/[:,]/" , SHIPPING_BOX_WEIGHT);
          $zc_tare_percent= $za_tare_array[0];
          $zc_tare_weight= $za_tare_array[1];

          $za_large_array = preg_split("/[:,]/" , SHIPPING_BOX_PADDING);
          $zc_large_percent= $za_large_array[0];
          $zc_large_weight= $za_large_array[1];
        }
        foreach ($packages as $id => $values) {
          if ($id === 'default') {
            // divide the weight by the max amount to be shipped (can be done inside loop as this occurance should only ever happen once
            // note $values is not an array
            if ($values == 0) continue;
            $this->fedex_shipping_num_boxes = ceil((float)$values / (float)SHIPPING_MAX_WEIGHT);
            if ($this->fedex_shipping_num_boxes < 1) $this->fedex_shipping_num_boxes = 1;
            $this->fedex_shipping_weight = round((float)$values / $this->fedex_shipping_num_boxes, 2); // 2 decimal places max
            $boxed_value = sprintf("%01.2f", $this->insurance / $this->fedex_shipping_num_boxes);
            for ($i=0; $i<$this->fedex_shipping_num_boxes; $i++) {
              $new_shipping_num_boxes++;
              if (SHIPPING_MAX_WEIGHT <= $this->fedex_shipping_weight) {
                $this->fedex_shipping_weight = $this->fedex_shipping_weight + ($this->fedex_shipping_weight*($zc_large_percent/100)) + $zc_large_weight;
              } else {
                $this->fedex_shipping_weight = $this->fedex_shipping_weight + ($this->fedex_shipping_weight*($zc_tare_percent/100)) + $zc_tare_weight;
              }
              if ($this->fedex_shipping_weight <= 0) $this->fedex_shipping_weight = 0.1;
              $new_shipping_weight += $this->fedex_shipping_weight;
              $request['RequestedShipment']['RequestedPackageLineItems'][] = array('Weight' => array('Value' => $this->fedex_shipping_weight,
                                                                                                     'Units' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_WEIGHT
                                                                                                     ),
                                                                                   'GroupPackageCount' => 1,
                                                                                   'InsuredValue' => array(
                                                                                     'Currency' => $_SESSION['currency'],
                                                                                     'Amount' => $boxed_value
                                                                                   ),
                                                                                  );
            }
          } else {
            $boxed_value = sprintf("%01.2f", $this->insurance / count($packages));
            // note $values is an array
            //$new_shipping_num_boxes++;
            if ($values['weight'] <= 0) $values['weight'] = 0.1;
            $new_shipping_weight += $values['weight'];
            $request['RequestedShipment']['RequestedPackageLineItems'][] = array(
                  'Weight' => array('Value' => $values['weight'],
                  'Units' =>MODULE_SHIPPING_FEDEX_WEB_SERVICES_WEIGHT),
                  'Dimensions' => array('Length' => ($values['length'] >= 1 ? $values['length'] : 1),
                  'Width' => ($values['width'] >= 1 ? $values['width'] : 1),
                  'Height' => ($values['height'] >= 1 ? $values['height'] : 1),
                  'Units' => $values['units']
                ),
                'InsuredValue' => array(
                  'Currency' => $_SESSION['currency'],
                  'Amount' => $boxed_value
                 ),
                 'GroupPackageCount' => 1
                 );
          }
        }
        $this->fedex_shipping_num_boxes = $new_shipping_num_boxes;
        $this->fedex_shipping_weight = round($new_shipping_weight / $this->fedex_shipping_num_boxes, 2);
      } else {
        // Zen Cart default method for calculating number of packages

        // check if cart contains free shipping items (module would be disabled unless strictly enabled to still quote for always free shipping products)
        /*
        if ($_SESSION['cart']->in_cart_check('product_is_always_free_shipping','1')) {
          // cart contains free shipping, get products weights
          $shipping_weight = 0;
          $products = $_SESSION['cart']->get_products();
          foreach ($products as $product) {
            $shipping_weight += $product['weight'] * $product['quantity'];
          }
          $shipping_weight = $shipping_weight / $shipping_num_boxes;
        }
        */
        $boxed_value = sprintf("%01.2f", $this->insurance / $this->fedex_shipping_num_boxes);
        if (!($this->fedex_shipping_weight > 0)) $this->fedex_shipping_weight = 0.1;

        if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_SIGNATURE_OPTION >= 0 && $totals >= MODULE_SHIPPING_FEDEX_WEB_SERVICES_SIGNATURE_OPTION) {
            $specialServices = array(
              'SpecialServicesRequested' => array(
                'SpecialServiceTypes' => 'SIGNATURE_OPTION',
                'SignatureOptionDetail' => array(
                  'OptionType' => 'Adult'
                  )
                )
              );
        }
        for ($i=0; $i<$this->fedex_shipping_num_boxes; $i++) {
          $request['RequestedShipment']['RequestedPackageLineItems'][] = array('Weight' => array('Value' => $this->fedex_shipping_weight,
                                                                                                 'Units' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_WEIGHT),
                                                                               'GroupPackageCount' => 1,
                                                                               'InsuredValue' => array(
                                                                                 'Currency' => $_SESSION['currency'],
                                                                                 'Amount' => $boxed_value
                                                                               ),
                                                                              );
        }
      }
    }
    $request['RequestedShipment']['PackageCount'] = $this->fedex_shipping_num_boxes;

    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_SATURDAY == 'true') {
      $request['VariableOptions'][] = 'SATURDAY_DELIVERY';
    }

    // FedEx One Rate for US shipments
    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_ONE_RATE == 'true' && $this->box_destination == 'domestic') {
      $request['RequestedShipment']['SpecialServicesRequested']['SpecialServiceTypes'][] = 'FEDEX_ONE_RATE';
    }

    if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_SIGNATURE_OPTION >= 0 && $totals >= MODULE_SHIPPING_FEDEX_WEB_SERVICES_SIGNATURE_OPTION) {
      $request['RequestedShipment']['SpecialServicesRequested'] = 'SIGNATURE_OPTION';
    }
    //echo '<!-- shippingWeight: ' . $shipping_weight . ' ' . $shipping_num_boxes . ' -->';

    /*
    echo '<!-- ';
    echo '<pre>';
    print_r($request);
    echo '</pre>';
    echo ' -->';
    */

    return $request;
  }

  function quote($method = '') {
    /* FedEx integration starts */
    global $db, $shipping_weight, $shipping_num_boxes, $cart, $order, $all_products_ship_free;
    require_once(DIR_WS_INCLUDES . 'library/fedex-common.php5');


    //if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_SERVER == 'test') {
      //$request['Version'] = array('ServiceId' => 'crs', 'Major' => '7', 'Intermediate' => '0', 'Minor' => '0');
      //$path_to_wsdl = DIR_WS_INCLUDES . "wsdl/RateService_v7_test.wsdl";
    //} else {
   // $path_to_wsdl = DIR_WS_MODULES . 'shipping/fedexwebservices/wsdl/RateService_v31.wsdl';
    $path_to_wsdl = DIR_WS_MODULES . 'shipping/fedexwebservices/wsdl/RateService_v20.wsdl';
    //}
    ini_set("soap.wsdl_cache_enabled", "0");
    //IF SOAP COMPILED WITH PEAR UNCOMMENT BELOW
    //require_once('SOAP/Client.php');
    $client = new SoapClient($path_to_wsdl, array('trace' => 1, 'connection_timeout' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_CONNECTION_TIMEOUT));


    $request = $this->build_request($client, true);
    $this->quotes = $this->do_request($method, $request, $client);

    if(MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD == 'Ground/Home only' && !$all_products_ship_free) {
      //in this case, we need to do a second request and combine the results
      $request = $this->build_request($client, false); //third parameter false to disallow free (0 weight) shipping

      $full_price_quotes = $this->do_request($method, $request, $client);
      $zero_weight_quotes = $this->quotes;
      $this->quotes['methods'] = $full_price_quotes['methods']; //default to full price quotes
      //replace with zero weight quote for ground methods
      foreach($this->quotes['methods'] as $method_id => $method) {
        if(in_array($method['id'], array('GROUNDHOMEDELIVERY', 'GROUND_HOME_DELIVERY', 'FEDEX_GROUND', 'INTERNATIONAL_GROUND'))){
          foreach($zero_weight_quotes['methods'] as $zero_weight_id => $zero_weight_method){
            if($method['id'] == $zero_weight_method['id']) {
              $this->quotes['methods'][$method_id] = $zero_weight_quotes['methods'][$zero_weight_id];
            }
          }
        }
      }
    }
    return $this->quotes;

   }

   function track($tracking_number) {
    global $db;
    require_once(DIR_WS_INCLUDES . 'library/fedex-common.php5');
    $path_to_wsdl = DIR_WS_MODULES . 'shipping/fedexwebservices/wsdl/TrackService_v10.wsdl';
    ini_set("soap.wsdl_cache_enabled", "0");
    $client = new SoapClient($path_to_wsdl, array('trace' => 1, 'connection_timeout' => MODULE_SHIPPING_FEDEX_WEB_SERVICES_CONNECTION_TIMEOUT));
    $request = $this->build_tracking_request($tracking_number);

    $tracking = array();
    $tracking['carrier'] = 'FedEx';
    $tracking['expected_delivery'] = "N/A";
    $tracking['service'] = "N/A";
    $tracking['weight'] = "N/A";
    $tracking['events'] = array();
//var_dump($request);
    try {
      $response = $client->track($request);


//print "<pre>";
//   var_dump($response->CompletedTrackDetails->TrackDetails);
// print "</pre>";
    $response_data = $response->CompletedTrackDetails->TrackDetails;
//var_dump($response_data->Notification->Message);

    if(strpos($response_data->Notification->Message, 'This tracking number cannot be found') !==false){//has not been submitted to shipper yet
      $tracking['status'] = 'Processing';
      return $tracking;
    }
    else if(strlen($response->Notification->Severity == 'ERROR') > 0) {
        return array('error'=>$response->TrackDetails->Message);
      }
    elseif(strpos($response_data->Notification->Message, 'Invalid tracking numbers') !==false){
      return array('error'=> $response_data->Notification->Message);
    }

    $tracking['expected_delivery'] = ($response_data->ActualDeliveryTimestamp > 0) ? $response_data->ActualDeliveryTimestamp : $response_data->EstimatedDeliveryTimestamp;
    $tracking['expected_delivery'] = date('Y-m-d', strtotime($tracking['expected_delivery']));

    //status can be 'Processing', 'In Transit', or 'Delivered' for the FE template to work
    $tracking['status'] = ($response_data->StatusDetail->Code == 'DL') ?  'Delivered' : 'In Transit';
    $tracking['status_description'] = $response_data->StatusDetail->Description;
    $tracking['carrier'] = $response_data->OperatingCompanyOrCarrierDescription;
    $tracking['service'] = $response_data->Service->Description;
    $tracking['weight'] = $response_data->ShipmentWeight->Value . ' ' . $response_data->ShipmentWeight->Units;

     // foreach($response_data->Events as $event) {
      $event = $response_data->Events; //it appears that fedex only returns one event.
        $tracking['events'][] = array(
          'date' =>date('Y-m-d  g:i A', strtotime($event->Timestamp)),
          'event' =>$event->EventDescription . ' ' . $event->StatusExceptionDescription,
          'location' => $event->Address->City . ', ' . $event->Address->StateOrProvinceCode . ' ' . $event->Address->PostalCode
          );
      //}

        $sql = 'UPDATE ' . TABLE_ORDERS_SHIPPING . ' SET status = \'' . $tracking['status'] . '\' WHERE tracking_code = ' . $tracking_number;
        $db->execute($sql);

     } catch (Exception $e) {

     }
     return $tracking;

  }

  function do_request($method = '', $request = '', $client = '') {
  global $db, $shipping_weight, $shipping_num_boxes, $cart, $order, $all_products_ship_free, $show_box_weight;
    try {
      $response = $client->getRates($request);
    /*
      echo '<!-- ';
      echo '<pre>';
      print_r($response);
      echo '</pre>';
      echo ' -->';
      */
      if( MODULE_SHIPPING_FEDEX_WEB_SERVICES_DEBUG == 'true' ){
        $log_time_stamp = microtime();
        error_log('['. strftime("%Y-%m-%d %H:%M:%S") .'] '. var_export($request, true), 3, DIR_FS_LOGS . '/fedexwebservices-requests-' . $log_time_stamp . '.log');
        error_log('['. strftime("%Y-%m-%d %H:%M:%S") .'] '. var_export($response, true), 3, DIR_FS_LOGS . '/fedexwebservices-responses-' . $log_time_stamp . '.log');
      }

      if ($response->HighestSeverity != 'FAILURE' && $response->HighestSeverity != 'ERROR' && is_array($response->RateReplyDetails) || is_object($response->RateReplyDetails)) {
        if (is_object($response->RateReplyDetails)) {
          $response->RateReplyDetails = get_object_vars($response->RateReplyDetails);
        }
        //echo '<pre>';
       // print_r($response->RateReplyDetails);
        //echo '</pre>';
        /*
        switch (SHIPPING_BOX_WEIGHT_DISPLAY) {
          case (0):
          $show_box_weight = '';
          break;
          case (1):
          $show_box_weight = ' (' . $shipping_num_boxes . ' ' . TEXT_SHIPPING_BOXES . ')';
          break;
          case (2):
          //echo '<!-- ' . $this->fedex_shipping_weight . ' ' . $this->fedex_shipping_num_boxes . ' -->';
          $show_box_weight = ' (' . number_format($this->fedex_shipping_weight * $this->fedex_shipping_num_boxes,2) . TEXT_SHIPPING_WEIGHT . ')';
          break;
          default:
          $show_box_weight = ' (' . $this->fedex_shipping_num_boxes . ' x ' . number_format($this->fedex_shipping_weight,2) . TEXT_SHIPPING_WEIGHT . ')';
          break;
        }
        */
        $quotes = array('id' => $this->code,
                              'module' => $this->title . $show_box_weight,
                              'info' => $this->info());
        $methods = array();
        foreach ($response->RateReplyDetails as $rateReply) {
          // bof modified for BPL-364 : Change code for FedEx 2 Day Saturday Delivery in FedEx Web Services Shipping
          if (array_key_exists($rateReply->ServiceType, $this->types) && ($method == '' || str_replace('_', '', $rateReply->ServiceType) == $method || str_replace('_', '', $rateReply->ServiceType.'_'.$rateReply->AppliedOptions) == $method)) {
          // eof modified for BPL-364 : Change code for FedEx 2 Day Saturday Delivery in FedEx Web Services Shipping
            $showAccountRates = true;
            if(MODULE_SHIPPING_FEDEX_WEB_SERVICES_RATES=='LIST') {
              foreach($rateReply->RatedShipmentDetails as $ShipmentRateDetail) {
                if($ShipmentRateDetail->ShipmentRateDetail->RateType=='PAYOR_LIST_PACKAGE') {
                  $cost = $ShipmentRateDetail->ShipmentRateDetail->TotalNetCharge->Amount;
                  $cost = (float)round(preg_replace('/[^0-9.]/', '',  $cost), 2);
                  if ($cost > 0) $showAccountRates = false;
                }
              }
            }
            if ($showAccountRates) {
              $cost = $rateReply->RatedShipmentDetails[0]->ShipmentRateDetail->TotalNetCharge->Amount;
              $cost = (float)round(preg_replace('/[^0-9.]/', '',  $cost), 2);
            }
            $transitTime = '';
            if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_TRANSIT_TIME == 'true' && in_array($rateReply->ServiceType, array('GROUND_HOME_DELIVERY', 'FEDEX_GROUND', 'INTERNATIONAL_GROUND'))) {
              $transitTime = ' (' . str_replace(array('_', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen'), array(' business ', 1,2,3,4,5,6,7,8,9,10,11,12,13,14), strtolower($rateReply->TransitTime)) . ')';
            }

            // added condition that cost must be greater than 0.  Rate can still be made free using handling fees.
            if ($cost > 0) {
              $new_cost = (float)round($cost + (strpos($this->types[$rateReply->ServiceType]['handling_fee'], '%') ? ($cost * (float)$this->types[$rateReply->ServiceType]['handling_fee'] / 100) : (float)$this->types[$rateReply->ServiceType]['handling_fee']), 2);

              // Ignore user defined handling_fee if set to List
              //if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_RATES=='LIST') {
                //$new_cost = $cost;
              //}

              //if all items in the order are "Always Free Shipping" items, show free shipping as specified in config
              if($all_products_ship_free) {
                if(MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD == 'all methods') {
                  $new_cost = 0;
                }
                else if(MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD == 'Ground/Home only') {
                  if(in_array($rateReply->ServiceType, array('GROUND_HOME_DELIVERY', 'FEDEX_GROUND', 'INTERNATIONAL_GROUND'))) {
                    $new_cost = 0;
                  }
                }
              }

              if(MODULE_SHIPPING_FEDEX_WEB_SERVICES_ORDER_TOTAL_DISCOUNTS != '' && MODULE_SHIPPING_FEDEX_WEB_SERVICES_ORDER_TOTAL_DISCOUNTS !== 0) {
                $discounts_input = explode(',', MODULE_SHIPPING_FEDEX_WEB_SERVICES_ORDER_TOTAL_DISCOUNTS);
                $discounts = array();
                foreach($discounts_input as $discount_input) { //put data into associative array
                  list($amount, $discount) = explode(':', $discount_input);
                  $discounts[$amount] = $discount;
                }
                //find largest discount that applies
                ksort($discounts);
                while(count($discounts) > 0) {
                  $discount = array_pop($discounts);
                  if(key($discount) <= $order->info['total']) {
                    $final_discount = $discount;
                    continue;
                  }
                }
                if($final_discount > 0) {
                  $new_cost -= $final_discount;
                }

              } //end ORDER_TOTAL_DISCOUNT

              // bof modified for AKS-917 : Show accurate ETA with shipping quotes
              if($transitTime != ''){
                $quote_title = ucwords(strtolower(str_replace('_', ' ', $rateReply->ServiceType))) . $transitTime;
              } else {
                if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_TRANSIT_TIME == 'true') {
                    $day_of_week_array = array(
                        "mon" => "Monday",
                        "tue" => "Tuesday",
                        "wed" => "Wednesday",
                        "thu" => "Thursday",
                        "fri" => "Friday",
                        "sat" => "Saturday",
                        "sun" => "Sunday"
                    );

                    $day_of_week = $day_of_week_array[strtolower($rateReply->DeliveryDayOfWeek)];

                    if(isset($rateReply->DeliveryTimestamp)){
                        $estimated_delivery_date = join(" ", explode("T", $rateReply->DeliveryTimestamp));
                        $est_date = new DateTime($estimated_delivery_date);
                        $formatted_estimated_delivery_date = " " . "(" . "ETA: " . $day_of_week . ' ' . $est_date->format('M d') . ")";
                    }
                }
                global $formatted_estimated_delivery_date;
                $quote_title = ucwords(strtolower(str_replace('_', ' ', $rateReply->ServiceType))) . $formatted_estimated_delivery_date;
              }

              // check if it's saturday delivery
              $saturday = false;
              if (MODULE_SHIPPING_FEDEX_WEB_SERVICES_SATURDAY == 'true') {
                foreach($rateReply->RatedShipmentDetails as $ShipmentRateDetail) {
               //     foreach($ShipmentRateDetail->ShipmentRateDetail->Surcharges as $surcharge) {
                foreach((array)($ShipmentRateDetail->ShipmentRateDetail->Surcharges) as $surcharge) {
                        if ($surcharge->SurchargeType == 'SATURDAY_DELIVERY') $saturday = true;
                    }
                }
              }

              if ($new_cost < 0) $new_cost = 0;
              // bof modified for BPL-364 : Change code for FedEx 2 Day Saturday Delivery in FedEx Web Services Shipping
              $methods[] = array('id' => str_replace('_', '', ($saturday) ? $rateReply->ServiceType.'_SATURDAY_DELIVERY' : $rateReply->ServiceType),
                                 'title' => $quote_title . ($saturday ? ' (' . MODULE_SHIPPING_FEDEX_WEB_SERVICES_TEXT_SATURDAY . ')' : ''),
              // eof modified for BPL-364 : Change code for FedEx 2 Day Saturday Delivery in FedEx Web Services Shipping
                                 'cost' => $new_cost);
              // eof modified for AKS-917 : Show accurate ETA with shipping quotes
            }
          }
        }
        if (sizeof($methods) == 0) return false;
        $quotes['methods'] = $methods;
        if ($this->tax_class > 0) {
            if(isset($order->delivery['country']['id'])) {
                $country_id = $order->delivery['country']['id'];
            }
            else {
                $country_id = $this->country_iso($order->delivery['country']);
            }

          $quotes['tax'] = zen_get_tax_rate($this->tax_class, $country_id, $order->delivery['zone_id']);
        }
      } else {
        $message = 'Error in processing transaction.<br /><br />';
        $message .= $response->Notifications->Severity;
        $message .= ': ';
        $message .= $response->Notifications->Message . '<br />';
        $quotes = array('module' => $this->title,
                              'error'  => $message);
      }
      if (zen_not_null($this->icon)) $this->quotes['icon'] = zen_image($this->icon, $this->title);
    } catch (Exception $e) {
      $quotes = array('module' => $this->title,
                            'error'  => 'Sorry, the FedEx.com server is currently not responding, please try again later.');
    }
    //echo '<!-- Quotes: ';
    //print_r($this->quotes);
    //print_r($_SESSION['shipping']);
    //echo ' -->';
    return $quotes;
  }

  // method added for expanded info in FEAC
  function info() {
    return MODULE_SHIPPING_FEDEX_WEB_SERVICES_INFO; // add a description here or leave blank to disable
  }

  function _setInsuranceValue($order_amount){
    if ($order_amount > (float)MODULE_SHIPPING_FEDEX_WEB_SERVICES_INSURE) {
      $this->insurance = sprintf("%01.2f", $order_amount);
    } else {
      $this->insurance = 0;
    }
  }

  function objectToArray($object) {
    if( !is_object( $object ) && !is_array( $object ) ) {
      return $object;
    }
    if( is_object( $object ) ) {
      $object = get_object_vars( $object );
    }
    return array_map( 'objectToArray', $object );
  }

  function _setDropOff() {
    switch(MODULE_SHIPPING_FEDEX_WEB_SERVICES_DROPOFF) {
      case '1':
        return 'REGULAR_PICKUP';
        break;
      case '2':
        return 'REQUEST_COURIER';
        break;
      case '3':
        return 'DROP_BOX';
        break;
      case '4':
        return 'BUSINESS_SERVICE_CENTER';
        break;
      case '5':
        return 'STATION';
        break;
    }
  }

  /*
   * Function to get the country iso code from either the country name or id.
   */

  function country_iso($country_name='', $country_id=STORE_COUNTRY) {
    global $db;
    $sql = 'SELECT countries_iso_code_2 FROM ' . TABLE_COUNTRIES . ' WHERE ';
    if(strlen($country_name) > 0) {
      $sql .= ' countries_name = \'' . $country_name . '\'';
    }
    elseif($country_id > 0) {
      $sql .= ' countries_id = ' . $country_id;
   }

    $result = $db->Execute($sql);
    return $result->fields['countries_iso_code_2'];

  }

  function check(){
    global $db;
    if(!isset($this->_check)){
      $check_query  = $db->Execute("SELECT configuration_value FROM ". TABLE_CONFIGURATION ." WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_STATUS'");
      $this->_check = $check_query->RecordCount();
      if ($this->_check && defined('MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION')) {
        switch(MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION) {
          case '1.4.0':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.4.1' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
            if (!defined('MODULE_SHIPPING_FEDEX_WEB_SERVICES_INSURE'))
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Insurance', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INSURE', '0', 'Insure packages when order total is greated than:', '6', '25', now())");
            // do not break and continue to the next version
          case '1.4.1':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.4.2' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.4.2':
            $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SHIPPING_BOXES_MANAGER' LIMIT 1;");
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.4.3' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.4.3':
            $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SHIPPING_BOXES_MANAGER' LIMIT 1;");
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.4.4' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
            if (!defined('MODULE_SHIPPING_FEDEX_WEB_SERVICES_CONNECTION_TIMEOUT'))
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Connection Timeout', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CONNECTION_TIMEOUT', '15', 'Enter the maximum time limit in seconds that the server should wait when connecting to the FedEx server.', '6', '10', now())");
            break;
          case '1.4.4':
            $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SHIPPING_BOXES_MANAGER' LIMIT 1;");
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.4.5' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
            break;
          case '1.4.5':
            $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SHIPPING_BOXES_MANAGER' LIMIT 1;");
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.4.6' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
            break;
          case '1.4.6':
            $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SHIPPING_BOXES_MANAGER' LIMIT 1;");
            if (!defined('MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD'))
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Allow Always Free Shipping items for', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD', '1', 'Allow ALWAYS FREE SHIPPING Items to ship for free with:', '6', '30', 'zen_cfg_select_option(array(\'no methods\',\'Ground/Home only\',\'all methods\'),', now())");
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.4.7' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
            break;
          case '1.4.7':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.4.8' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
            break;
          case '1.4.8':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.4.9' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.4.9':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.5.0' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.5.0':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.5.1' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.5.1':
            if (!defined('MODULE_SHIPPING_FEDEX_WEB_SERVICES_DEBUG'))
              $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Debug', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_DEBUG', 'false', 'Turn On Debugging?', '6', '99', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
            $db->Execute("delete from " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('MODULE_SHIPPING_FEDEX_WEB_SERVICES_KEY', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_PWD');");
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.5.2' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.5.2':
          case '1.5.3':
          case '1.5.4':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.5.5' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.5.5':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.5.6', set_function = 'zen_cfg_select_option(array(\'1.5.6\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.5.6':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.6.0' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable One Rate', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ONE_RATE', 'false', 'Enable FedEx One Rate (requires package type to be set to one of the following: FEDEX_ENVELOPE, FEDEX_SMALL_BOX, FEDEX_MEDIUM_BOX, FEDEX_LARGE_BOX, FEDEX_EXTRA_LARGE_BOX, FEDEX_PAK, FEDEX_TUBE)', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Package Type', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_PACKAGE_TYPE', 'YOUR_PACKAGING', 'Select your package type', '6', '10', 'zen_cfg_select_option(array(\'YOUR_PACKAGING\', \'FEDEX_ENVELOPE\', \'FEDEX_SMALL_BOX\', \'FEDEX_MEDIUM_BOX\', \'FEDEX_LARGE_BOX\', \'FEDEX_EXTRA_LARGE_BOX\', \'FEDEX_PAK\', \'FEDEX_TUBE\'), ', now())");
          case '1.6.0':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.6.1', set_function = null WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.6.1':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.6.2', set_function = 'zen_cfg_select_option(array(\'1.6.2\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.6.2':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.6.3', set_function = 'zen_cfg_select_option(array(\'1.6.3\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.6.3':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.6.4', set_function = 'zen_cfg_select_option(array(\'1.6.4\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.6.4':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.6.5', set_function = 'zen_cfg_select_option(array(\'1.6.5\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.6.5':
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Saturday Pickup', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SATURDAY_PICKUP', 'false', 'Enable Saturday Pickup (surcharge added to rates)', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.6.6', set_function = 'zen_cfg_select_option(array(\'1.6.6\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.6.6':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.6.7', set_function = 'zen_cfg_select_option(array(\'1.6.7\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.6.7':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.6.8', set_function = 'zen_cfg_select_option(array(\'1.6.8\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.6.8':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.0', set_function = 'zen_cfg_select_option(array(\'1.7.0\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.0':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.1', set_function = 'zen_cfg_select_option(array(\'1.7.1\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.1':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.2', set_function = 'zen_cfg_select_option(array(\'1.7.2\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.2':
            $db->Execute("REPLACE INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Show Estimated Transit Time', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_TRANSIT_TIME', 'false', 'Display the transit time?', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
            $db->Execute("REPLACE INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Discounts for order total', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ORDER_TOTAL_DISCOUNTS', '', 'Discounts for order totals over specified amount.  Sample input: 100:10,1000:20.  This will give a $10 discount on orders over $100 and a $20 discount on orders over $1000', '6', '25', now())");
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.3', set_function = 'zen_cfg_select_option(array(\'1.7.3\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.3':
            $db->Execute("REPLACE INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Cutoff Pickup Time', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CUTOFF_PICKUP_TIME', 'false', 'Enable Cutoff Pickup Time', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
            $db->Execute("REPLACE INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Enter Cutoff Pickup Hour', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CUTOFF_PICKUP_TIME_VALUE', '0', 'Enter the hour limit to pickup in the same day (0 to 23)', '6', '10', now())");
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.4', set_function = 'zen_cfg_select_option(array(\'1.7.4\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.4':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.5', set_function = 'zen_cfg_select_option(array(\'1.7.5\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.5':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.6', set_function = 'zen_cfg_select_option(array(\'1.7.6\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.6':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.7', set_function = 'zen_cfg_select_option(array(\'1.7.7\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.7':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.8', set_function = 'zen_cfg_select_option(array(\'1.7.8\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.8':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.7.9', set_function = 'zen_cfg_select_option(array(\'1.7.9\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.7.9':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.8.0', set_function = 'zen_cfg_select_option(array(\'1.8.0\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.8.0':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.8.1', set_function = 'zen_cfg_select_option(array(\'1.8.1\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.8.1':
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '1.9.0', set_function = 'zen_cfg_select_option(array(\'1.9.0\'),' WHERE configuration_key = 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION' LIMIT 1;");
          case '1.9.0':
            break; // this break should only appear on the last case
        }
      }
    }
    return $this->_check;
  }

  function install() {
    global $db;
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable FedEx Web Services','MODULE_SHIPPING_FEDEX_WEB_SERVICES_STATUS','true','Do you want to offer FedEx shipping?','6','0','zen_cfg_select_option(array(\'true\',\'false\'),',now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Version Installed', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION', '1.9.0', '', '6', '0', set_function = 'zen_cfg_select_option(array(\'1.9.0\'),', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('FedEx Account Number', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ACT_NUM', '', 'Enter FedEx Account Number', '6', '3', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('FedEx Meter Number', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_METER_NUM', '', 'Enter FedEx Meter Number (You can get one at <a href=\"http://www.fedex.com/us/developer/\" target=\"_blank\">http://www.fedex.com/us/developer/</a>)', '6', '4', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable Address Validation','MODULE_SHIPPING_FEDEX_WEB_SERVICES_ADDRESS_VALIDATION','false','Would you like to use the FedEx Address Validation service to determine if an address is residential or commercial?','6','9','zen_cfg_select_option(array(\'true\',\'false\'),',now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Weight Units', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_WEIGHT', 'LB', 'Weight Units:', '6', '10', 'zen_cfg_select_option(array(\'LB\', \'KG\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('First line of street address', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ADDRESS_1', '', 'Enter the first line of your ship-from street address, required', '6', '20', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Second line of street address', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ADDRESS_2', '', 'Enter the second line of your ship-from street address, leave blank if you do not need to specify a second line', '6', '21', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('City name', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CITY', '', 'Enter the city name for the ship-from street address, required', '6', '22', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('State or Province name', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_STATE', '', 'Enter the 2 letter state or province name for the ship-from street address, required for Canada and US', '6', '23', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Postal code', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_POSTAL', '', 'Enter the postal code for the ship-from street address, required', '6', '24', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Phone number', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_PHONE', '', 'Enter a contact phone number for your company, required', '6', '25', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable for Always Free Shipping', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING', 'false', 'Should this module be enabled even when all items in the cart are marked as ALWAYS FREE SHIPPING?', '6', '30', 'zen_cfg_select_option(array(\'true\',\'false\'),', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Allow Always Free Shipping items for', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD', '1', 'Allow ALWAYS FREE SHIPPING Items to ship for free with:', '6', '30', 'zen_cfg_select_option(array(\'no methods\',\'Ground/Home only\',\'all methods\'),', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Drop off type', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_DROPOFF', '1', 'Dropoff type (1 = Regular pickup, 2 = request courier, 3 = drop box, 4 = drop at BSC, 5 = drop at station)?', '6', '30', 'zen_cfg_select_option(array(\'1\',\'2\',\'3\',\'4\',\'5\'),', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable One Rate', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ONE_RATE', 'false', 'Enable FedEx One Rate (requires package type to be set to one of the following: FEDEX_ENVELOPE, FEDEX_SMALL_BOX, FEDEX_MEDIUM_BOX, FEDEX_LARGE_BOX, FEDEX_EXTRA_LARGE_BOX, FEDEX_PAK, FEDEX_TUBE)', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Express Saver', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_SAVER', 'true', 'Enable FedEx Express Saver', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Standard Overnight', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_STANDARD_OVERNIGHT', 'true', 'Enable FedEx Express Standard Overnight', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable First Overnight', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_FIRST_OVERNIGHT', 'true', 'Enable FedEx Express First Overnight', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Priority Overnight', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_PRIORITY_OVERNIGHT', 'true', 'Enable FedEx Express Priority Overnight', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable 2 Day', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_2DAY', 'true', 'Enable FedEx Express 2 Day', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable International Priority', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_PRIORITY', 'true', 'Enable FedEx Express International Priority', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable International Economy', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_ECONOMY', 'true', 'Enable FedEx Express International Economy', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Ground', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_GROUND', 'true', 'Enable FedEx Ground', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable International Ground', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_GROUND', 'true', 'Enable FedEx International Ground', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Freight', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREIGHT', 'true', 'Enable FedEx Freight', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Saturday Delivery', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SATURDAY', 'false', 'Enable Saturday Delivery', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Saturday Pickup', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SATURDAY_PICKUP', 'false', 'Enable Saturday Pickup (surcharge added to rates)', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Domestic Ground Handling Fee', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_HANDLING_FEE', '', 'Add a domestic handling fee or leave blank (example: 15 or 15%)', '6', '25', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Domestic Express Handling Fee', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE', '', 'Add a domestic handling fee or leave blank (example: 15 or 15%)', '6', '25', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Home Delivery Handling Fee', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_HOME_DELIVERY_HANDLING_FEE', '', 'Add a home delivery handling fee or leave blank (example: 15 or 15%)', '6', '25', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('International Ground Handling Fee', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_HANDLING_FEE', '', 'Add an international handling fee or leave blank (example: 15 or 15%)', '6', '25', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('International Express Handling Fee', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_EXPRESS_HANDLING_FEE', '', 'Add an international handling fee or leave blank (example: 15 or 15%)', '6', '25', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('FedEx Rates','MODULE_SHIPPING_FEDEX_WEB_SERVICES_RATES','LIST','FedEx Rates (LIST = FedEx default rates, ACCOUNT = Your discounted rates)','6','0','zen_cfg_select_option(array(\'LIST\',\'ACCOUNT\'),',now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Signature Option', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SIGNATURE_OPTION', '-1', 'Require a signature on orders greater than or equal to (set to -1 to disable):', '6', '25', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Ready to Ship', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_READY_TO_SHIP', 'false', 'Enable using products_ready_to_ship field (requires Numinix Product Fields optional dimensions fields) to identify products which ship separately?', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Package Type', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_PACKAGE_TYPE', 'YOUR_PACKAGING', 'Select your package type', '6', '10', 'zen_cfg_select_option(array(\'YOUR_PACKAGING\', \'FEDEX_ENVELOPE\', \'FEDEX_SMALL_BOX\', \'FEDEX_MEDIUM_BOX\', \'FEDEX_LARGE_BOX\', \'FEDEX_EXTRA_LARGE_BOX\', \'FEDEX_PAK\', \'FEDEX_TUBE\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Show Estimated Transit Time', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_TRANSIT_TIME', 'false', 'Display the transit time?', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Connection Timeout', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CONNECTION_TIMEOUT', '15', 'Enter the maximum time limit in seconds that the server should wait when connecting to the FedEx server.', '6', '10', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Discounts for order total', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ORDER_TOTAL_DISCOUNTS', '', 'Discounts for order totals over specified amount.  Sample input: 100:10,1000:20.  This will give a $10 discount on orders over $100 and a $20 discount on orders over $1000', '6', '25', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Insurance', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INSURE', '', 'Insure packages when order total is greated than:', '6', '25', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Shipping Zone', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ZONE', '0', 'If a zone is selected, only enable this shipping method for that zone.', '6', '98', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Tax Class', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_TAX_CLASS', '0', 'Use the following tax class on the shipping fee.', '6', '25', 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort Order', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SORT_ORDER', '0', 'Sort order of display.', '6', '999', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Shipping Info', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INFO', '', 'Add a description that will display in Fast and Easy AJAX Checkout', '6', '99', 'zen_cfg_textarea(', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Debug', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_DEBUG', 'false', 'Turn On Debugging?', '6', '99', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Cutoff Pickup Time', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CUTOFF_PICKUP_TIME', 'false', 'Enable Cutoff Pickup Time', '6', '10', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Enter Cutoff Pickup Hour', 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CUTOFF_PICKUP_TIME_VALUE', '0', 'Enter the hour limit to pickup in the same day (0 to 23)', '6', '10', now())");
  }

  function remove() {
    global $db;
    $db->Execute("DELETE FROM ". TABLE_CONFIGURATION ." WHERE configuration_key in ('". implode("','",$this->keys()). "')");
  }

  function keys() {
    return array('MODULE_SHIPPING_FEDEX_WEB_SERVICES_STATUS',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_VERSION',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ACT_NUM',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_METER_NUM',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ADDRESS_VALIDATION',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_WEIGHT',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ADDRESS_1',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ADDRESS_2',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CITY',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_STATE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_POSTAL',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_PHONE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_DROPOFF',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREE_SHIPPING_METHOD',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ONE_RATE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_SAVER',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_STANDARD_OVERNIGHT',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_FIRST_OVERNIGHT',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_PRIORITY_OVERNIGHT',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_2DAY',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_PRIORITY',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_ECONOMY',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_GROUND',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_FREIGHT',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INTERNATIONAL_GROUND',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SATURDAY',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SATURDAY_PICKUP',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_TAX_CLASS',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_HANDLING_FEE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_HOME_DELIVERY_HANDLING_FEE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_EXPRESS_HANDLING_FEE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_HANDLING_FEE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INT_EXPRESS_HANDLING_FEE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SIGNATURE_OPTION',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INSURE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_RATES',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_READY_TO_SHIP',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_PACKAGE_TYPE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ORDER_TOTAL_DISCOUNTS',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_TRANSIT_TIME',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CONNECTION_TIMEOUT',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_ZONE',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_INFO',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_DEBUG',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_SORT_ORDER',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CUTOFF_PICKUP_TIME',
                 'MODULE_SHIPPING_FEDEX_WEB_SERVICES_CUTOFF_PICKUP_TIME_VALUE'
                 );
  }
}
