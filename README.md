# ses

This extension exposes a webhook page to process bounces from Amazon SES (Simple Email Service) 
through Amazon SNS (Simple Notification Service) notifications.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.1+
* CiviCRM 5.13+

## Installation

See: https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/#installing-a-new-extension

## Usage

The webhook verifies that the _Notification_ or _SubscriptionConfirmation_ it's been originated and sent by the SNS service (as per SNS [docs](https://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.verify.signature.html)), if the _Notification_ is a _SubscriptionConfirmation_ it automatically _subscribes_ to it, if it's a Bounce, it maps SES' bounce type to Civi's bounce type.

#### Hard bounce mapping
| SES | CiviCRM |
|---|---|
| Undetermined | Syntax |
| General, NoEmail, Suppressed | Invalid |

#### Soft bounce mapping
| SES | CiviCRM |
|---|---|
| General | Syntax |
| MessageTooLarge, MailboxFull | Quota  |
| ContentRejected, AttachmentRejected | Spam |


Please see Amazon's [guide and documentation](https://docs.aws.amazon.com/ses/latest/DeveloperGuide/configure-sns-notifications.html) on how to setup SNS notifications for SES.

Webhook url:

* WordPress: https://example.org/?page=CiviCRM&q=civicrm%2fses%2fwebhook
* Drupal: https://example.org/civicrm/ses/webhook

#### Future features/wishes/would like to do

* Send using API (instead of SMTP)
* UI to create Topic and Subscription without leaving CiviCRM
* Send statistics, and reputation dashlets

## Known Issues

None that I'm aware of, report here if you find any and **be aware that this extension is at an alpha stage**.
