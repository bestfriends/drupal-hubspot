# Hubspot

This module provides a Hubspot API integration for Drupal.

## Features

The base functionality of this module is to provide the hubspot API code to
enable Drupal to send data to HubSpot. This project also provides integration
with the [Webform](https://drupal.org/project/webform) module to handle the
upload of webform data to hubspot.

## Dependencies

This project leverages the official [Hubspot API Client](https://github.com/HubSpot/hubspot-php)
for connecting to HubSpot.

## Setup

To enable the module, download and enable to module as with any other Drupal
module.

Once enabled, navigate to the HubSpot module config (`admin/config/services/hubspot`)
and enter your api integration settings. If you don't have an existing hubspot
app, create a new app with the instructions below. You will need to provide the
`oauth` scope to connect your site and additionally the `forms` and `contacts`
scopes if you wish to use the Webform functionality. If you are extending this
module, in order to use the api client service, please and any additional api
scopes in the HubSpot app config and Drupal module settings as required.

https://developers.hubspot.com/docs/api/developer-tools-overview

## Support

Please submit any bugs or features requests to the HubSpot module page on Drupal.org.

https://drupal.org/project/hubspot
