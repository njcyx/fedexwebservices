Alternative Choice: FedEx plug-in based on REST API: 
https://www.zen-cart.com/showthread.php?229562-FedEx-Shipping-using-REST-API

# FedEx Web Service for Zen Cart Mod

(Under development, for personal purposes only)

Note: This plug-in requires FedEx production key/meter number. FedEx doesn't provide new product keys any more. For new users, please check the REST API plug-in above. This mod is only for old users. 

This module is based on the Numinix FedEx Shipping Module, v1.9.1 with some modifications to support Zen Cart 1.5.8 and PHP 8.0. PHP 8.1 is not tested so probably it still works. 

Original plug-in download Link: https://www.numinix.com/zen-cart-plugins-modules-shipping-c-179_250_373_163/fedex-web-services-shipping

Special thanks to Carlwhat (https://www.zen-cart.com/member.php?17577-carlwhat). I used his suggestion in this mod as following: 
https://www.zen-cart.com/showthread.php?229127-upgrade-to-1-5-8-fedex-webservice-depreciated&p=1391457#post1391457

If unable to fix the warning, add "error_reporting(0);" to the top line.

**Known bug:**
Sometimes I will receive the follow warning. Not sure how to fix. 

PHP Fatal error: Uncaught Error: Cannot use object of type stdClass as array in /public_html/includes/modules/shipping/fedexwebservices.php

Affected codes are the following:

if ($showAccountRates) {
$cost = $rateReply->RatedShipmentDetails[0]->ShipmentRateDetail->TotalNetCharge->Amount;
$cost = (float)round(preg_replace('/[^0-9.]/', '', $cost), 2);
}
