<?php

require_once 'ses.civix.php';
use CRM_Ses_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function ses_civicrm_config(&$config) {
  _ses_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 */
function ses_civicrm_xmlMenu(&$files) {
  _ses_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 */
function ses_civicrm_install() {
  _ses_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 */
function ses_civicrm_postInstall() {
  _ses_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 */
function ses_civicrm_uninstall() {
  _ses_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 */
function ses_civicrm_enable() {
  _ses_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 */
function ses_civicrm_disable() {
  _ses_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 */
function ses_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _ses_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function ses_civicrm_managed(&$entities) {
  _ses_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 */
function ses_civicrm_angularModules(&$angularModules) {
  _ses_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 */
function ses_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _ses_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 */
function ses_civicrm_entityTypes(&$entityTypes) {
  _ses_civix_civicrm_entityTypes($entityTypes);
}
