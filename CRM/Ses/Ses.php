<?php
use CRM_Ses_ExtensionUtil as E;

/**
 * Simple Email Service class. 
 */
class CRM_Ses_Ses {
	
	const SES_SETTINGS = 'Amazon SES Settings';

	public static function get_regions() {
		return [
			'us-east-1' => 'email.us-east-1.amazonaws.com',
			'us-west-2' => 'email.us-west-2.amazonaws.com',
			'eu-west-1' => 'email.eu-west-1.amazonaws.com'
		];
	}
}