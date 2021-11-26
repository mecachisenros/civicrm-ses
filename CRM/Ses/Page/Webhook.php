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
   * @var string $verp_separator
   */
  protected $verp_separator;

  /**
   * CRM_Core_BAO_MailSettings::defaultLocalpart()
   *
   * @var string $localpart
   */
  protected $localpart;

  /**
   * The SES Notification object.
   *
   * @var object $snsEvent
   */
  protected $snsEvent;

  /**
   * The SES Message object.
   *
   * @var object $snsEventMessage
   */
  protected $snsEventMessage;

  /**
   * CiviCRM Bounce types.
   *
   * @var array $civi_bounce_types
   */
  protected $civi_bounce_types = [];

  /**
   * GuzzleHttp Client.
   *
   * @var object $client Guzzle\Client
   */
  protected $client;

  /**
   * See https://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-contents.html#bounce-types
   */
  public function getBounceTypeId($bounceType, $bounceSubType) {
    $sesBounceTypes['Undetermined']['Undetermined'] = 'Invalid';
    $sesBounceTypes['Permanent']['General'] = 'Invalid';
    $sesBounceTypes['Permanent']['NoEmail'] = 'Invalid';
    $sesBounceTypes['Permanent']['Suppressed'] = 'Invalid';
    $sesBounceTypes['Permanent']['OnAccountSuppressionList'] = 'Invalid';
    $sesBounceTypes['Transient']['General'] = 'Relay';
    $sesBounceTypes['Transient']['MailboxFull'] = 'Quota';
    $sesBounceTypes['Transient']['MessageTooLarge'] = 'Relay';
    $sesBounceTypes['Transient']['ContentRejected'] = 'Spam';
    $sesBounceTypes['Transient']['AttachmentRejected'] = 'Spam';
    $bounceTypeName = $sesBounceTypes[$bounceType][$bounceSubType];
    return array_search($bounceTypeName, $this->civi_bounce_types);
  }

  /**
   * Constructor.
   */
  public function __construct() {
    $this->client = new GuzzleHttp\Client();

    $this->verp_separator = Civi::settings()->get('verpSeparator');
    $this->localpart = CRM_Core_BAO_MailSettings::defaultLocalpart();
    $this->civi_bounce_types = $this->get_civi_bounce_types();
    // get json input
    $this->snsEvent = json_decode(file_get_contents('php://input'));
    // message object
    $this->snsEventMessage = json_decode($this->snsEvent->Message);

    parent::__construct();
  }

  /**
   * @return void|null
   * @throws \CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function run() {
    // verify sns signature
    if (!$this->verify_signature()) CRM_Utils_System::civiExit();

    switch ($this->snsEvent->Type) {
      case 'SubscriptionConfirmation':
        // Confirm the SNS subscription and exit
        $this->confirm_subscription();
        CRM_Utils_System::civiExit();
        break;

      case 'Notification':
        break;

      default:
        CRM_Utils_System::civiExit();
    }

    list($job_id, $event_queue_id, $hash) = $this->getVerpItemsFromSource();
    if (empty($job_id) || empty($event_queue_id) || empty($hash)
      || !CRM_Mailing_Event_BAO_Queue::verify($job_id, $event_queue_id, $hash)) {
      \Civi::log()->error("Invalid or missing ID for mailing with source address {$this->snsEventMessage->mail->source}. job_id={$job_id},event_queue_id={$event_queue_id},hash={$hash}");
      CRM_Utils_System::civiExit();
    }
    $bounce_params = [
      'job_id' => $job_id,
      'event_queue_id' => $event_queue_id,
      'hash' => $hash,
    ];

    switch ($this->snsEventMessage->notificationType) {
      case 'Bounce':
        $bounce_params = $this->set_bounce_type_params($bounce_params);
        if (empty($bounce_params['bounce_type_id'])) {
          // We couldn't classify bounce type - let CiviCRM try!
          $bounce_params['body'] = "Bounce Description: {$this->snsEventMessage->bounce->bounceType} {$this->snsEventMessage->bounce->bounceSubType}";
          civicrm_api3('Mailing', 'event_bounce', $bounce_params);
        }
        else {
          // We've classified the bounce type, record in CiviCRM
          CRM_Mailing_Event_BAO_Bounce::create($bounce_params);
        }
        break;

      case 'Complaint':
        $bounce_params = $this->map_complaint_types($bounce_params);
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
          \Civi::log()->info('ses: Set is_opt_out for contactID: ' . $contact_id);
        }
        break;
    }
    CRM_Utils_System::civiExit();
  }

  /**
   * Get header by name.
   *
   * @param string $name The header name to retrieve
   *
   * @return string The header value
   */
  protected function get_header_value($name) {
    foreach ($this->snsEventMessage->mail->headers as $header) {
      if ($header->name == $name)
        return $header->value;
    }
    return NULL;
  }

  /**
   * Get verp items.
   *
   * @param string $header_value The X-CiviMail-Bounce header
   *
   * @return array The verp items [ $job_id, $queue_id, $hash ]
   */
  protected function get_verp_items($header_value) {
    $verp_items = substr(substr($header_value, 0, strpos($header_value, '@')), strlen($this->localpart) + 2);
    return explode($this->verp_separator, $verp_items);
  }

  /**
   * Get verp items from a source address in format eg. b.13.6.1d49c3d4f888d58a@example.org
   *
   * @return array The verp items [ $job_id, $queue_id, $hash ]
   */
  protected function getVerpItemsFromSource(): array {
    $verpItems = substr(substr($this->snsEventMessage->mail->source, 0, strpos($this->snsEventMessage->mail->source, '@')), strlen($this->localpart) + 2);
    return explode($this->verp_separator, $verpItems);
  }

  /**
   * Set bounce type params.
   *
   * @param array $bounce_params The params array
   *
   * @return array The params array
   */
  protected function set_bounce_type_params($bounce_params) {
    $bounce_params['bounce_type_id'] = $this->getBounceTypeId($this->snsEventMessage->bounce->bounceType, $this->snsEventMessage->bounce->bounceSubType);

    $reasonParts = [];
    $reasonParts[] = $this->snsEventMessage->bounce->bounceType;
    $reasonParts[] = $this->snsEventMessage->bounce->bounceSubType;
    foreach ($this->snsEventMessage->bounce->bouncedRecipients as $recipient) {
      $recipientReason = '';
      if (!empty($recipient->emailAddress)) {
        $recipientReason .= "email:{$recipient->emailAddress};";
      }
      if (!empty($recipient->action)) {
        $recipientReason .= "action:{$recipient->action};";
      }
      if (!empty($recipient->status)) {
        $recipientReason .= "status:{$recipient->status};";
      }
      if (!empty($recipient->diagnosticCode)) {
        $recipientReason .= "status:{$recipient->diagnosticCode};";
      }
      if (!empty($recipientReason)) {
        $reasonParts[] = "[$recipientReason]";
      }
    }
    $bounce_params['bounce_reason'] = "Bounce via SES: " . implode(" ", $reasonParts);

    return $bounce_params;
  }

  /**
   * Map Amazon complaint types to Civi bounce types.
   *
   * @param array $params The params array
   *
   * @return array The params array
   */
  protected function map_complaint_types($params) {
    $params['bounce_type_id'] = array_search('Spam', $this->civi_bounce_types);

    foreach ($this->snsEventMessage->complaint->complainedRecipients as $recipient) {
      $recipientParts[] = $recipient->emailAddress;
    }

    $reasonParts = [];
    if (!empty($this->snsEventMessage->complaint->userAgent)) {
      $reasonParts[] = $this->snsEventMessage->complaint->userAgent;
    }
    if (!empty($this->snsEventMessage->complaint->complaintFeedbackType)) {
      $reasonParts[] = $this->snsEventMessage->complaint->complaintFeedbackType;
    }
    if (!empty($this->snsEventMessage->complaint->complaintSubType)) {
      $reasonParts[] = $this->snsEventMessage->complaint->complaintSubType;
    }
    if (!empty($recipientParts)) {
      $reasonParts[] = '[' . implode(";", $recipientParts) . ']';
    }
    if ($reasonParts) {
      $params['bounce_reason'] = "Complaint via SES: " . implode(" ", $reasonParts);
    } else {
      $params['bounce_reason'] = "Complaint via SES (no further details)";
    }

    return $params;
  }

  /**
   * Confirm SNS subscription to topic.
   *
   * @see https://docs.aws.amazon.com/sns/latest/dg/sns-message-and-json-formats.html#http-subscription-confirmation-json
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function confirm_subscription() {
    // To confirm the subscription we must "visit" the provided SubscribeURL
    $this->client->request('POST', $this->snsEvent->SubscribeURL);
    \Civi::log()->info('ses: SNS subscription confirmed');
  }

  /**
   * Verify SNS Message signature.
   *
   * @see https://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.verify.signature.html
   * @return bool true if successful
   */
  protected function verify_signature() {
    // keys needed for signature
    $keys_to_sign = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
    // for SubscriptionConfirmation the keys are slightly different
    if ($this->snsEvent->Type == 'SubscriptionConfirmation')
      $keys_to_sign = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

    // build message to sign
    $message = '';
    foreach ($keys_to_sign as $key) {
      if (isset($this->snsEvent->$key))
        $message .= "{$key}\n{$this->snsEvent->$key}\n";
    }

    // decode SNS signature
    $sns_signature = base64_decode($this->snsEvent->Signature);

    // get certificate from SigningCerURL and extract public key
    $public_key = openssl_get_publickey(file_get_contents($this->snsEvent->SigningCertURL));

    // verify signature
    $signed = openssl_verify($message, $sns_signature, $public_key, OPENSSL_ALGO_SHA1);

    if ($signed && $signed != -1)
      return TRUE;

    \Civi::log()->error('ses: SNS signature verification failed!');
    return FALSE;
  }

  /**
   * Get CiviCRM bounce types.
   *
   * @return array
   */
  protected function get_civi_bounce_types() {
    if (!empty($this->civi_bounce_types)) return $this->civi_bounce_types;

    $query = 'SELECT id,name FROM civicrm_mailing_bounce_type';
    $dao = CRM_Core_DAO::executeQuery($query);

    $civi_bounce_types = [];
    while ($dao->fetch()) {
      $civi_bounce_types[$dao->id] = $dao->name;
    }

    return $civi_bounce_types;
  }
}
