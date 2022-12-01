Numinix updated this plug-in on Nov 29, 2022 (v1.9.0), so this project is "retired". Please use the plug-in from Numinix directly. 

https://www.numinix.com/zen-cart-plugins-modules-shipping-c-179_250_373_163/fedex-web-services-shipping

=========

# FedEx Web Service for Zen Cart Mod

(Under development, for personal purposes only)

This module is based on the Numinix FedEx Shipping Module, v1.8.1 with some modifications to support Zen Cart 1.58 & PHP 8.0. 

Original plug-in download Link: https://www.numinix.com/zen-cart-plugins-modules-shipping-c-179_250_373_163/fedex-web-services-shipping  

The original v1.8.1 plug-in is not compatible with PHP 8.0 and it will throw many errors and warnings. This modified plug-in seems to fix this issue. 

Only two files are changed.

1. includes/languages/english/modules/shipping/lang.fedexwebservices.php

Change the file "fedexwebservices.php" into zc1.58 new language format.

2. includes/modules/shipping/fedexwebservices.php

Fixed several undefined parameter/array key issues when using with php 8.0.
