<?php
use CRM_Ses_ExtensionUtil as E;

/**
 * Simple Email Service webhook page class.
 *
 * Listens and processes bounce events from Amazon SNS
 */
class CRM_Ses_Page_Webhook extends CRM_Core_Page {

  /**
   * Verp Separator.
   *
   * @access protected
   * @var string $verp_separator
   */
  protected $verp_separator;

  /**
   * Localpart.
   *
   * @access protected
   * @var string $localpart
   */
  protected $localpart;

  /**
   * The SES Notification object.
   *
   * @access protected
   * @var object $json
   */
  protected $json;

  /**
   * The SES Message object.
   *
   * @access protected
   * @var object $message
   */
  protected $message;

  /**
   * The SES Bounce object.
   *
   * @access protected
   * @var object $bounce
   */
  protected $bounce;

  /**
   * SES Permanent bounce types.
   *
   * Hard bounces
   * @access protected
   * @var array $ses_permanent_bounce_types
   */
  protected $ses_permanent_bounce_types = ['Undetermined', 'General', 'NoEmail', 'Suppressed'];

  /**
   * SES Transient bounce types.
   *
   * Soft bounces
   * @access protected
   * @var array $ses_transient_bounce_types
   */
  protected $ses_transient_bounce_types = ['General', 'MailboxFull', 'MessageTooLarge', 'ContentRejected', 'AttachmentRejected'];

  /**
   * CiviCRM Bounce types.
   *
   * @access protected
   * @var array $civi_bounce_types
   */
  protected $civi_bounce_types = [];

  /**
   * GuzzleHttp Clinet.
   *
   * @access public
   * @var object $client Guzzle\Client
   */
  protected $client;

  /**
   * Constructor.
   */
  public function __construct() {
    $this->client = new GuzzleHttp\Client();

    $this->verp_separator = Civi::settings()->get('verpSeparator');
    $this->localpart = CRM_Core_BAO_MailSettings::defaultLocalpart();
    $this->civi_bounce_types = $this->get_civi_bounce_types();
    // get json input
    $this->json = json_decode(file_get_contents('php://input'));
    // message object
    $this->message = json_decode($this->json->Message);
    // bounce object
    $this->bounce = $this->message->bounce;

    parent::__construct();
  }

  /**
   * Run.
   */
  public function run() {
    // verify sns signature
    if (!$this->verify_signature()) CRM_Utils_System::civiExit();

    // confirm subscription
    if ($this->json->Type == 'SubscriptionConfirmation') $this->confirm_subscription();

    if ($this->json->Type != 'Notification') CRM_Utils_System::civiExit();

    list($job_id, $event_queue_id, $hash) = $this->getVerpItemsFromSource();
    if (empty($job_id) || empty($event_queue_id) || empty($hash)
      || !CRM_Mailing_Event_BAO_Queue::verify($job_id, $event_queue_id, $hash)) {
      \Civi::log()->error("Invalid or missing ID for mailing with source address {$this->message->mail->source}. job_id={$job_id},event_queue_id={$event_queue_id},hash={$hash}");
      CRM_Utils_System::civiExit();
    }
    $bounce_params = [
      'job_id' => $job_id,
      'event_queue_id' => $event_queue_id,
      'hash' => $hash,
    ];

    switch ($this->message->notificationType) {
      case 'Bounce':
        $bounce_params = $this->set_bounce_type_params($bounce_params);
        if (empty($bounce_params['bounce_type_id'])) {
          // We couldn't classify bounce type - let CiviCRM try!
          $bounce_params['body'] = "Bounce Description: {$this->message->bounce->bounceType} {$this->message->bounce->bounceSubType}";
          civicrm_api3('Mailing', 'event_bounce', $bounce_params);
        }
        else {
          // We've classified the bounce type, record in CiviCRM
          CRM_Mailing_Event_BAO_Bounce::create($bounce_params);
        }
        break;

      case 'Complaint':
        $bounce_params = $this->map_complaint_types($bounce_params, 'Spam');
        // Opt out the contact and create entries for spam bounces (which only puts the email on hold).
        // This is because the contact likely reported the email as spam as a way to unsubscribe.
        // So opting out only the one email address instead of the contact risks getting any emails sent to their
        // secondary addresses flagged as spam as well, which can hurt our spam score.
        CRM_Mailing_Event_BAO_Bounce::create($bounce_params);
        $sql = "SELECT cc.id FROM civicrm_contact cc INNER JOIN civicrm_mailing_event_queue cmeq ON cmeq.contact_id = cc.id WHERE cmeq.id = %1";
        $sql_params = [1 => [$bounce_params['event_queue_id'], 'Integer']];
        $contact_id = CRM_Core_DAO::singleValueQuery($sql, $sql_params);

        if (!empty($contact_id)) {
          civicrm_api3('Contact', 'create', [
            'id' => $contact_id,
            'is_opt_out' => 1,
          ]);
        }
        break;
    }
    CRM_Utils_System::civiExit();
  }

  /**
   * Get header by name.
   *
   * @param  string $name The header name to retrieve
   * @return string $value The header value
   */
  protected function get_header_value( $name ) {
    foreach ($this->message->mail->headers as $key => $header) {
      if ($header->name == $name)
        return $header->value;
    }
    return NULL;
  }

  /**
   * Get verp items.
   *
   * @param string $header_value The X-CiviMail-Bounce header
   * @return array $verp_items The verp items [ $job_id, $queue_id, $hash ]
   */
  protected function get_verp_items($header_value) {
    $verp_items = substr(substr($header_value, 0, strpos($header_value, '@')), strlen($this->localpart) + 2);
    return explode($this->verp_separator, $verp_items);
  }

  /**
   * Get verp items from a source address in format eg. b.13.6.1d49c3d4f888d58a@example.org
   *
   * @return array $verpItems The verp items [ $job_id, $queue_id, $hash ]
   */
  protected function getVerpItemsFromSource(): array {
    $verpItems = substr(substr($this->message->mail->source, 0, strpos($this->message->mail->source, '@')), strlen($this->localpart) + 2);
    return explode($this->verp_separator, $verpItems);
  }

  /**
   * Set bounce type params.
   *
   * @param array $bounce_params The params array
   * @return array $bounce_params Teh params array
   */
  protected function set_bounce_type_params($bounce_params) {
    // hard bounces
    if ($this->bounce->bounceType == 'Permanent' && in_array($this->bounce->bounceSubType, $this->ses_permanent_bounce_types))
      switch ($this->bounce->bounceSubType) {
        case 'Undetermined':
          $bounce_params = $this->map_bounce_types($bounce_params, 'Syntax');
          break;

        case 'General':
        case 'NoEmail':
        case 'Suppressed':
          $bounce_params = $this->map_bounce_types($bounce_params, 'Invalid');
          break;

      }
    // soft bounces
    if ($this->bounce->bounceType == 'Transient' && in_array($this->bounce->bounceSubType, $this->ses_transient_bounce_types)) {
      switch ($this->bounce->bounceSubType) {
        case 'General':
          $bounce_params = $this->map_bounce_types($bounce_params, 'Syntax'); // hold_threshold is 3
          break;

        case 'MessageTooLarge':
        case 'MailboxFull':
          $bounce_params = $this->map_bounce_types($bounce_params, 'Quota');
          break;

        case 'ContentRejected':
        case 'AttachmentRejected':
          $bounce_params = $this->map_bounce_types($bounce_params, 'Spam');
          break;

      }
    }
    return $bounce_params;
  }

  /**
   * Map Amazon bounce types to Civi bounce types.
   *
   * @param  array $bounce_params The params array
   * @param  string $type_to_map_to Civi bounce type to map to
   * @return array $bounce_params The params array
   */
  protected function map_bounce_types($bounce_params, $type_to_map_to) {
    $bounce_params['bounce_type_id'] = array_search($type_to_map_to, $this->civi_bounce_types);
    // it should be one recipient
    $recipient = count($this->bounce->bouncedRecipients) == 1 ? reset($this->bounce->bouncedRecipients) : false;
    if ($recipient)
      $bounce_params['bounce_reason'] = $recipient->status . ' => ' . $recipient->diagnosticCode;

    return $bounce_params;
  }

  /**
   * Map Amazon complaint types to Civi bounce types.
   *
   * @param  array $params The params array
   * @param  string $type_to_map_to Civi bounce type to map to
   * @return array $bounce_params The params array
   */
  protected function map_complaint_types($params, $type_to_map_to = 'Spam') {
    $params['bounce_type_id'] = array_search($type_to_map_to, $this->civi_bounce_types);
    // it should be one recipient
    foreach ($this->complaint->complainedRecipients as $recipient) {
      $params['complain_recipients'][] = $recipient->emailAddress;
    }
    if (empty($this->complaint->complaintFeedbackType)) {
      $params['bounce_reason'] = 'Message has been flagged as Spam by the recipient';
    }
    else {
      $params['bounce_reason'] = $this->complaint->complaintFeedbackType . ' => ' . $this->complaint->userAgent;
    }

    return $params;
  }

  /**
   * Confirm SNS subscription to topic.
   */
  protected function confirm_subscription() {
    // use guzzlehttp
    $this->client->request('POST', $this->json->SubscribeURL);
  }

  /**
   * Verify SNS Message signature.
   *
   * @see https://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.verify.signature.html
   * @return bool $signed true if succesful
   */
  protected function verify_signature() {
    // keys needed for signature
    $keys_to_sign = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
    // for SubscriptionConfirmation the keys are slightly different
    if ($this->json->Type == 'SubscriptionConfirmation')
      $keys_to_sign = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

    // build message to sign
    $message = '';
    foreach ($keys_to_sign as $key) {
      if (isset($this->json->$key))
        $message .= "{$key}\n{$this->json->$key}\n";
    }

    // decode SNS signature
    $sns_signature = base64_decode($this->json->Signature);

    // get certificate from SigningCerURL and extract public key
    $public_key = openssl_get_publickey(file_get_contents($this->json->SigningCertURL));

    // verify signature
    $signed = openssl_verify($message, $sns_signature, $public_key, OPENSSL_ALGO_SHA1);

    if ($signed && $signed != -1)
      return true;

    \Civi::log()->debug('AWS SNS signature verification failed!');
    return false;
  }

  /**
   * Get CiviCRM bounce types.
   *
   * @return $array $civi_bounce_types
   */
  protected function get_civi_bounce_types() {
    if (!empty( $this->civi_bounce_types)) return $this->civi_bounce_types;

    $query = 'SELECT id,name FROM civicrm_mailing_bounce_type';
    $dao = CRM_Core_DAO::executeQuery($query);

    $civi_bounce_types = [];
    while ($dao->fetch()) {
      $civi_bounce_types[$dao->id] = $dao->name;
    }

    return $civi_bounce_types;
  }
}
