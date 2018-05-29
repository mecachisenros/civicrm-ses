# ses

![Screenshot](/images/screenshot.png)

(*FIXME: In one or two paragraphs, describe what the extension does and why one would download it. *)

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v5.4+
* CiviCRM 4.7+

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl ses@https://github.com/mecachisenros/civicrm-ses/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/mecachisenros/civicrm-ses.git
cv en ses
```

## Usage

This extension exposes a webhook page to process bounces from Amazon SES (Simple Email Service) through Amazon SNS (Simple Notification Service) notifications. The webhook verifies that the _Notification_ or _SubscriptionConfirmation_ it's been originated and sent by the SNS service (as per SNS [docs](https://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.verify.signature.html)), if the _Notification_ is a _SubscriptionConfirmation_ it automatically _subscribes_ to it, if it's a Bounce, it maps SES' bounce type to Civi's bounce type.

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

* WordPress: http://example.org/?page=CiviCRM&q=civicrm/ses/webhook
* Drupal: http://example.org/civicrm/ses/webhook

#### Future features/wishes/would like to do

* Send using API (instead of SMTP)
* UI to create Topic and Subscription without leaving CiviCRM
* Send statistics, and reputation dashlets

## Known Issues

None that I'm aware of, report here if you find any and **be aware that this extension is at an alpha stage**.
