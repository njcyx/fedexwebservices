# FedEx Web Service for Zen Cart Mod

(Under development, for personal purposes only)

This module is based on the Numinix FedEx Shipping Module, v1.9.0 with some modifications to support Zen Cart PHP 8.0 or higher. 

Original plug-in download Link: https://www.numinix.com/zen-cart-plugins-modules-shipping-c-179_250_373_163/fedex-web-services-shipping

Mod Changelog
1) Resolve a variety of warning/error caused by php 8.0 or higher 
2) (Temp solution) Resolve the bug which will not display FedEx intl priority. This mod uses RateService_v20.wsdl instead of RateService_v31.wsdl
3) Resolve occasional warning, Invalid argument supplied for foreach()

Only one files are changed vs the files from Numinix

includes/languages/english/modules/shipping/lang.fedexwebservices.php
