<?php
use CRM_Ses_ExtensionUtil as E;

/**
 * Settings form controller class.
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_Ses_Form_Settings extends CRM_Admin_Form_Setting {

  /**
   * Settings.
   *
   * @see https://docs.civicrm.org/dev/en/latest/framework/setting/#adding-setting-config-to-admin-forms
   *
   * @access protected
   * @var array $_settings
   */
  protected $_settings = [
    'amazon_ses_access_key' => CRM_Ses_Ses::SES_SETTINGS,
    'amazon_ses_secret_key' => CRM_Ses_Ses::SES_SETTINGS,
    'amazon_ses_region' => CRM_Ses_Ses::SES_SETTINGS,
    'amazon_ses_use_api' => CRM_Ses_Ses::SES_SETTINGS,
  ];
}
