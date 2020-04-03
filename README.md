# Amazon SES Bounce/Complaint Handler

This extension exposes a webhook page to process bounces and complaints from Amazon SES (Simple Email Service)
through Amazon SNS (Simple Notification Service) notifications.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.1+
* CiviCRM 5.19+

## Installation

See: https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/#installing-a-new-extension

## Usage

The webhook verifies that the _Notification_ or _SubscriptionConfirmation_ it's been originated and sent by the SNS service (as per SNS [docs](https://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.verify.signature.html)).

### Events
#### SubscriptionConfirmation

If the _Notification_ is a _SubscriptionConfirmation_ it automatically _subscribes_ to it.

#### Bounce

If it's a Bounce, it maps SES' bounce type to Civi's bounce type.

##### Hard bounce mapping

| SES | CiviCRM |
|---|---|
| Undetermined | Syntax |
| General, NoEmail, Suppressed | Invalid |

##### Soft bounce mapping

| SES | CiviCRM |
|---|---|
| General | Syntax |
| MessageTooLarge, MailboxFull | Quota  |
| ContentRejected, AttachmentRejected | Spam |

#### Complaint

If the _Notification_ is a _Complaint_ it creates a bounce in CiviCRM of type **Spam** and sets the **opt-out** flag for the contact identified by the email address in the notification.

This is implemented in the same way as used by the coopsymbiotic fork of sparkpost: https://github.com/coopsymbiotic/sparkpost/blob/coopsymbiotic/CRM/Sparkpost/Page/callback.php#L151

### Additional Information


Please see Amazon's [guide and documentation](https://docs.aws.amazon.com/ses/latest/DeveloperGuide/configure-sns-notifications.html) on how to setup SNS notifications for SES.

Webhook url:

* WordPress: https://example.org/?page=CiviCRM&q=civicrm%2fses%2fwebhook
* Drupal: https://example.org/civicrm/ses/webhook

#### Future features/wishes/would like to do

* Send using API (instead of SMTP)
* UI to create Topic and Subscription without leaving CiviCRM
* Send statistics, and reputation dashlets

Also see: https://github.com/mecachisenros/aws which could replace this extension in the future.
